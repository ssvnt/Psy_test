<?php
declare(strict_types=1);

namespace App\Services\GardnerCommerce;

use App\Models\{GardnerV22Generation, TestSession};
use App\Services\Children\V2\GardnerV22\Production\{GardnerV22SessionCompiler, GardnerV22PaidArtifactStore};
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ReadyMailAccess
{
    public function buyer(TestSession $s): array
    {
        if ($s->test_type !== 'children' || !config('gardner_commerce.enabled') || !app(Orders::class)->paid($s)) {
            throw new RuntimeException('PAID_CHILD_ORDER_UNCONFIRMED');
        }
        $email = strtolower(trim((string) $s->email));
        $buyer = DB::table('gardner_buyers')->where('test_session_id', $s->id)->first();
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !$buyer || strtolower(trim((string) $buyer->email)) !== $email) {
            throw new RuntimeException('BUYER_EMAIL_MISMATCH');
        }
        $order = DB::table('gardner_orders')->where('test_session_id', $s->id)->where('buyer_id', $buyer->id)
            ->where('status', 'paid')->where('operation_id', $s->payment_id)->first();
        $event = DB::table('gardner_mail_outbox')->where('test_session_id', $s->id)->where('kind', 'report_ready')
            ->where('status', 'sent')->whereNotNull('sent_at')->whereRaw('lower(trim(recipient)) = ?', [$email])
            ->orderByDesc('id')->first();
        if (!$order || !$event) throw new RuntimeException('ORIGINAL_MAIL_OR_ORDER_NOT_BOUND');
        return [$email, $order->id, $event->id];
    }

    private function native(TestSession $s): array
    {
        $compiler = app(GardnerV22SessionCompiler::class);
        $compiled = $compiler->compile($s);
        $run = GardnerV22Generation::where('test_session_id', $s->id)->first();
        if (!$run || $run->status !== 'done' || $run->generation_identity !== $compiled['generationIdentity']) {
            throw new RuntimeException('NATIVE_GENERATION_NOT_READY');
        }
        $artifact = app(GardnerV22PaidArtifactStore::class)->read($s, $compiled);
        $pub = app(Publication::class);
        $html = $pub->publishedBytes($s, $artifact, false);
        $pdf = $pub->publishedBytes($s, $artifact, true);
        return [$html, $pdf];
    }

    private function bytes(string $root, string $name): string
    {
        $path = $root.'/'.$name;
        if (is_link(dirname($root)) || is_link($root) || is_link($path) || !is_file($path) ||
            ($bytes = file_get_contents($path)) === false) throw new RuntimeException('R27_FILE_UNAVAILABLE');
        return $bytes;
    }

    private function r27(TestSession $s): array
    {
        $owner = storage_path('app/gardner-r27-owner-review/'.$s->id);
        $cust = storage_path('app/gardner-r27-customer/'.$s->id);
        $review = json_decode($this->bytes($owner, 'review.json'), true, flags: JSON_THROW_ON_ERROR);
        $manifest = json_decode($this->bytes($cust, 'manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $decision = $review['decision'] ?? [];
        if (($review['session_id'] ?? null) !== (int) $s->id || ($manifest['session_id'] ?? null) !== (int) $s->id
            || ($review['state'] ?? '') !== 'APPROVED' || ($decision['value'] ?? '') !== 'APPROVED') {
            throw new RuntimeException('R27_OWNER_APPROVAL_UNCONFIRMED');
        }
        $qaBytes = $this->bytes($owner, 'qa.json');
        $qa = json_decode($qaBytes, true, flags: JSON_THROW_ON_ERROR);
        $qaHash = hash('sha256', $qaBytes);
        if ($qaHash !== ($review['qa_sha256'] ?? null) || !is_int($qa['blocked'] ?? null) || $qa['blocked'] < 0
            || $qa['blocked'] !== ($review['blocked'] ?? null) || ($qa['blocked'] > 0 &&
                (($decision['ack_qa_warnings'] ?? null) !== true || ($decision['qa_sha256'] ?? null) !== $qaHash))) {
            throw new RuntimeException('R27_QA_DECISION_UNCONFIRMED');
        }
        $token = trim($this->bytes($cust, 'token.secret'));
        if (!is_int($manifest['expires'] ?? null) || $manifest['expires'] <= time()
            || !preg_match('/^[A-Za-z0-9_-]{50,100}$/D', $token)
            || hash('sha256', $token) !== ($manifest['token_sha256'] ?? null)) {
            throw new RuntimeException('R27_PUBLICATION_EXPIRED_OR_REVOKED');
        }
        $bytes = [];
        foreach (['html', 'pdf'] as $ext) {
            $bytes[] = $body = $this->bytes($cust, 'report.'.$ext);
            $hash = hash('sha256', $body);
            if ($hash !== ($manifest[$ext.'_sha256'] ?? null) || $hash !== ($manifest['approved_'.$ext.'_sha256'] ?? null)
                || $hash !== ($review[$ext.'_sha256'] ?? null)) throw new RuntimeException('R27_PUBLICATION_HASH_MISMATCH');
        }
        return $bytes;
    }

    public function release(TestSession $s, string $source): array
    {
        [$email, $order, $event] = $this->buyer($s);
        [$html, $pdf] = match ($source) {
            'native' => $this->native($s),
            'r27' => $this->r27($s),
            default => throw new RuntimeException('UNKNOWN_SOURCE')
        };
        if (!preg_match('~<(?:html|body)\b~i', $html) || !str_starts_with($pdf, '%PDF-')) {
            throw new RuntimeException('REPORT_FORMAT_INVALID');
        }
        $binding = hash_hmac('sha256', json_encode([$s->public_id, $email, $order, $event, $source,
            hash('sha256', $html), hash('sha256', $pdf)], JSON_THROW_ON_ERROR), (string) config('app.key'));
        return compact('html','pdf','binding','email','source');
    }
}
