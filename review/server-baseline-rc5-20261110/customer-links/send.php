<?php
declare(strict_types=1);
use App\Models\TestSession;
use App\Services\GardnerCommerce\{ReadyMailAccess, Consultations};
use App\Services\TeenApprovedReports;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\{Mail, URL};

if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
ini_set('display_errors', '0');
function stopMail(string $code): never { throw new RuntimeException($code); }
function checkedRelease(TestSession $s): array
{
    $access = app(ReadyMailAccess::class);
    $access->buyer($s);
    $codes = [];
    foreach (['native', 'r27'] as $source) {
        try { return $access->release($s, $source); }
        catch (Throwable $e) {
            $code = $e->getMessage();
            $codes[] = preg_match('/^(?:R27_|NATIVE_|REPORT_)[A-Z_]{3,60}$/D', $code) ? $code : strtoupper($source).'_NOT_VERIFIED';
        }
    }
    stopMail(implode('+', $codes));
}
function writeState(string $file, string $state, ?string $message = null): void
{
    $out = json_encode(['state'=>$state,'time'=>gmdate('c'),'message_id_sha256'=>$message], JSON_THROW_ON_ERROR);
    $temp = tempnam(dirname($file), 'ready-');
    $handle = fopen($temp, 'wb');
    if (!$handle || fwrite($handle,$out) !== strlen($out) || !fflush($handle) || !fsync($handle)) stopMail('RECEIPT_IO_ERROR');
    fclose($handle);
    if (!rename($temp,$file)) stopMail('RECEIPT_IO_ERROR');
    $parent = fopen(dirname($file), 'r');
    if (!$parent || !fsync($parent)) stopMail('RECEIPT_IO_ERROR');
    fclose($parent);
}
function guestGet(string $url, string $type, ?string $digest = null): string
{
    if (!str_starts_with($url,'https://xn--90aauvej.xn--p1ai/children/mail-access/')) stopMail('LINK_ORIGIN_INVALID');
    $c = curl_init($url);
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_TIMEOUT=>22,
        CURLOPT_CONNECTTIMEOUT=>6, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2]);
    $body = curl_exec($c);
    $http = curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    $mime = strtolower(explode(';',(string)curl_getinfo($c,CURLINFO_CONTENT_TYPE))[0]);
    if (!is_string($body)) stopMail('GUEST_HTTPS_FAILED_'.curl_errno($c));
    if ($mime !== $type && $http === 200) stopMail('GUEST_MEDIA_TYPE_INVALID');
    if ($http !== 200) stopMail('GUEST_'.strtoupper(basename((string)parse_url($url,PHP_URL_PATH))).'_HTTP_'.$http);
    if ($digest !== null && !hash_equals($digest,hash('sha256',$body))) stopMail('GUEST_REPORT_BYTES_DIFFER');
    return $body;
}
try {
    [$program, $mode, $id] = array_pad($argv, 3, '');
    if (!in_array($mode,['plan','send'],true) || !ctype_digit($id) || (int)$id < 1) stopMail('INVALID_INPUT');
    umask(0077);
    require getcwd().'/vendor/autoload.php';
    $app = require getcwd().'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (!class_exists(ReadyMailAccess::class)) require __DIR__.'/ReadyMailAccess.php';
    $s = TestSession::findOrFail((int)$id);
    $stateFile = storage_path('app/dz-ready-mail-corrections-v1').'/'.(int)$id.'.json';
    if ($mode === 'send') {
        $dir = dirname($stateFile);
        if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) stopMail('RECEIPT_IO_ERROR');
        $lock = fopen($stateFile.'.lock','c');
        if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) stopMail('ANOTHER_SEND_IN_PROGRESS');
    }
    if (is_file($stateFile)) {
        $receipt = json_decode((string)file_get_contents($stateFile),true);
        if (($receipt['state'] ?? '') === 'SMTP_ACCEPTED') { echo "ALREADY_ACCEPTED_BY_SMTP\n"; exit(0); }
        stopMail('DELIVERY_UNCERTAIN_CHECK_MAIL_LOG_BEFORE_RETRY');
    }
    $release = checkedRelease($s);
    $booking = app(Consultations::class)->current($s);
    if ($booking ? $booking['status'] !== 'confirmed' : !app(TeenApprovedReports::class)->availableSlots()) {
        stopMail('NO_BOOKABLE_TIME_OR_CONFIRMED_APPOINTMENT');
    }
    if ($mode === 'plan') { echo "APPROVED_REPORT_AND_BOOKING_FOUND source=".$release['source']."\n"; exit(0); }
    URL::forceRootUrl('https://xn--90aauvej.xn--p1ai');
    URL::forceScheme('https');
    $until = now()->addDays(30);
    $urls = [];
    foreach (['report','pdf','booking'] as $action) {
        $urls[$action] = URL::temporarySignedRoute('gardner.customer.ready-mail',$until,
            ['session'=>$s->public_id,'action'=>$action,'source'=>$release['source'],'version'=>$release['binding']]);
    }
    guestGet($urls['report'],'text/html',hash('sha256',$release['html']));
    guestGet($urls['pdf'],'application/pdf',hash('sha256',$release['pdf']));
    $calendarHtml = guestGet($urls['booking'],'text/html');
    if (!(str_contains($calendarHtml,'data-booking="confirmed"') ||
          (str_contains($calendarHtml,'data-booking="available"') && str_contains($calendarHtml,'name="slot_id"')))) stopMail('GUEST_BOOKING_UNAVAILABLE');
    $transport = Mail::mailer()->getSymfonyTransport();
    if (!$transport instanceof \Symfony\Component\Mailer\Transport\Smtp\SmtpTransport || !config('gardner_commerce.live_mail')) {
        stopMail('LIVE_SMTP_NOT_CONFIGURED');
    }
    $check = app(ReadyMailAccess::class)->release($s->fresh(),$release['source']);
    if (!hash_equals($check['binding'],$release['binding'])) stopMail('PUBLICATION_CHANGED');
    $body = "Здравствуйте!\n\nОтправляем вам новые персональные ссылки. Отчёт и запись открываются без пароля.\n\n"
        ."Открыть отчёт\n".$urls['report']."\n\nСкачать PDF\n".$urls['pdf']."\n\n"
        ."Записаться на консультацию или посмотреть свою запись\n".$urls['booking']
        ."\n\nСохраните это письмо и не пересылайте ссылки: они открывают ваш персональный отчёт. Если понадобится помощь, ответьте на письмо.\n\nКоманда «Добрый Жук»";
    // Validate the final envelope after Laravel's global recipient override, before SMTP.
    $expectedEmail = $release['email'];
    $override = config('mail.to.address');
    if ($override && strcasecmp(trim((string)$override), $expectedEmail) !== 0) stopMail('MAIL_RECIPIENT_OVERRIDE');
    \Illuminate\Support\Facades\Event::listen(\Illuminate\Mail\Events\MessageSending::class,
        static function ($event) use ($expectedEmail) {
            $mail = $event->message;
            $targets = $mail->getTo();
            if (count($targets) !== 1 || strcasecmp($targets[0]->getAddress(), $expectedEmail) !== 0
                || $mail->getCc() || $mail->getBcc()) stopMail('MAIL_RECIPIENT_OVERRIDE');
        });
    writeState($stateFile,'SMTP_ATTEMPT_OUTCOME_UNKNOWN');
    // No retries after an ambiguous SMTP outcome, even after process termination.
    try {
        $sent = Mail::raw($body, function ($m) use ($release) {
            $m->to($release['email'])->subject('Добрый Жук — ваш отчёт и запись на консультацию без пароля');
        });
        if (!$sent || !$sent->getMessageId()) stopMail('SMTP_OUTCOME_UNKNOWN');
        writeState($stateFile,'SMTP_ACCEPTED',hash('sha256',$sent->getMessageId()));
    } catch (Throwable) { stopMail('DELIVERY_UNCERTAIN_CHECK_MAIL_LOG_BEFORE_RETRY'); }
    echo "SMTP_ACCEPTED REPORT_HTML_OK PDF_OK BOOKING_OK\n";
} catch (Throwable $e) {
    $m = $e->getMessage();
    $code = preg_match('/^[A-Z][A-Z0-9_+]{2,150}$/D', $m) ? $m : 'APPLICATION_CHECK_FAILED';
    fwrite(STDERR,"CORRECTION_STOPPED ".$code."\n");
    exit(1);
}
