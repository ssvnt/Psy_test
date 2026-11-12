<?php
/** Support lookup. Run from the Laravel root via PHP CLI. All output is redacted. */
declare(strict_types=1);
ini_set('display_errors', '0');

use App\Models\TestSession;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function receiptData(string $file): array
{
    if (is_link($file) || !is_file($file) || filesize($file) > 262144) {
        return [];
    }
    $data = json_decode((string) file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function stateCode(mixed $value): ?string
{
    return is_string($value) && preg_match('/^[a-zA-Z_]{1,45}$/D', $value) ? $value : null;
}

// Always use the project's HTTPS origin; never follow redirects or send cookies.
// Stream an artifact hash instead of printing or retaining its contents.
function inspectUrl(string $path, ?string $expectedHash = null): array
{
    $hash = hash_init('sha256');
    $curl = curl_init('https://xn--90aauvej.xn--p1ai'.$path);
    curl_setopt_array($curl, [
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 18,
        CURLOPT_WRITEFUNCTION => static function ($handle, string $bytes) use ($hash): int {
            hash_update($hash, $bytes);
            return strlen($bytes);
        },
    ]);
    $completed = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $type = strtolower(explode(';', (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE))[0]);
    curl_close($curl);
    $digest = hash_final($hash);
    $out = ['http' => $status, 'transfer_complete' => $completed !== false];
    if ($expectedHash !== null) {
        $out['approved_html_matches'] = $completed !== false && $status === 200
            && $type === 'text/html' && hash_equals($expectedHash, $digest);
    }
    return $out;
}

function inspectSession(TestSession $s): array
{
    $id = (int) $s->id;
    $out = ['session' => $id, 'product' => $s->test_type,
        'registration_email_valid' => (bool) filter_var($s->email, FILTER_VALIDATE_EMAIL),
        'notification_marked_at' => (string) $s->report_ready_notified_at ?: null];
    if ($s->test_type === 'teen') {
        if (Schema::hasTable('teen_approved_reports')) {
            $row = DB::table('teen_approved_reports')->where('test_session_id', $id)->first();
            $out['approved_publication'] = stateCode($row->status ?? null);
            $out['original_mail_state'] = stateCode($row->mail_status ?? null);
            $out['mail_sent_at'] = $row->mail_sent_at ?? null;
        }
        return $out;
    }
    if (Schema::hasTable('gardner_mail_outbox')) {
        $out['ready_mail_events'] = DB::table('gardner_mail_outbox')->where('test_session_id', $id)
            ->where('kind', 'report_ready')->orderByDesc('id')->limit(5)->get(['status','sent_at','recipient'])
            ->map(static fn ($r) => ['status' => stateCode($r->status),
                'sent_at' => $r->sent_at, 'matches_registration_email' =>
                    strcasecmp(trim((string) $r->recipient), trim((string) $s->email)) === 0])->all();
    }
    $dir = storage_path('app/gardner-r27-customer/'.$id);
    $receipt = receiptData($dir.'/receipt.json');
    $manifest = receiptData($dir.'/manifest.json');
    $out['r27_receipt_state'] = stateCode($receipt['mail_status'] ?? null);
    $out['r27_mail_time'] = $receipt['mail_at'] ?? null;
    $out['r27_receipt_matches_publication'] = ($receipt['session_id'] ?? null) === $id
        && ($manifest['session_id'] ?? null) === $id && is_string($manifest['pdf_sha256'] ?? null)
        && hash_equals($manifest['pdf_sha256'], (string) ($receipt['report_version'] ?? ''));
    $out['guest_account_api'] = inspectUrl('/api/gardner-commerce/'.rawurlencode((string) $s->public_id));
    $secret = $dir.'/token.secret';
    if (($manifest['session_id'] ?? null) === $id && is_file($secret) && !is_link($secret)
        && filesize($secret) < 200 && ($manifest['expires'] ?? 0) > time()) {
        $token = trim((string) file_get_contents($secret));
        if (preg_match('/^[A-Za-z0-9_-]{50,100}$/D', $token)
            && hash_equals((string) ($manifest['token_sha256'] ?? ''), hash('sha256', $token))
            && preg_match('/^[a-f0-9]{64}$/D', (string) ($manifest['html_sha256'] ?? ''))) {
            $out['guest_r27_report'] = inspectUrl('/api/gardner-r27-customer-report/'.$id.'/'.$token.'/html',
                $manifest['html_sha256']);
        }
    }
    return $out;
}

try {
    if (PHP_SAPI !== 'cli') {
        http_response_code(404);
        exit(1);
    }
    $term = trim((string) base64_decode($argv[1] ?? '', true));
    if (!filter_var($term, FILTER_VALIDATE_EMAIL) && !preg_match('/^\p{L}[\p{L}-]{1,60}$/uD', $term)) {
        throw new InvalidArgumentException();
    }
    require getcwd().'/vendor/autoload.php';
    $app = require getcwd().'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $query = TestSession::query()->where('payment_status', 'paid')
        ->whereIn('test_type', ['children', 'gardner', 'gardner_v22', 'teen']);
    $term = mb_strtolower($term);
    if (filter_var($term, FILTER_VALIDATE_EMAIL)) {
        $query->whereRaw('lower(trim(email)) = ?', [$term]);
    } else {
        $query->where(function ($q) use ($term) {
            foreach (['parent_name', 'child_name'] as $column) {
                foreach ([$term, mb_convert_case($term, MB_CASE_TITLE), mb_strtoupper($term)] as $variant) {
                    foreach ([$variant, $variant.' %', '% '.$variant, '% '.$variant.' %'] as $pattern) {
                        $q->orWhereRaw("lower(trim($column)) like lower(?)", [$pattern]);
                    }
                }
            }
        });
    }
    $matches = $query->limit(2)->get(['id','public_id','email','test_type','report_ready_notified_at']);
    if ($matches->count() !== 1) {
        echo json_encode(['lookup' => $matches->isEmpty() ? 'NO_PAID_MATCH' : 'MULTIPLE_MATCHES_USE_ORDER_EMAIL']).PHP_EOL;
        exit(0);
    }
    echo json_encode(inspectSession($matches->first()), JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $e) {
    file_put_contents('php://stderr', "CHECK_UNAVAILABLE: ".get_class($e)."\n");
    exit(1);
}
