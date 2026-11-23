<?php

namespace Tests\Feature;

use App\Models\TestSession;
use App\Services\MailJourneys\{LaravelJourneyContextProvider, LaravelJourneyCoordinatorFactory, LaravelJourneyHooks};
use Illuminate\Support\Facades\{Artisan, DB, Http, Mail};
use Tests\TestCase;

/** Explicitly loads RC4 review adapters without installing/enabling their sender. */
class ReviewMailAdaptersTest extends TestCase
{
    private string $review;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame(':memory:', config('database.connections.sqlite.database'));
        Artisan::call('migrate:fresh', ['--force' => true]);
        Http::preventStrayRequests(); Mail::fake();
        $this->review = rtrim((string) getenv('DZ_REVIEW_REPO'), '/').'/review';
        $rc4 = $this->review.'/dz-emails-cutover-rc4-20261109/laravel';
        require_once $rc4.'/app/Services/MailJourneys/Core/JourneyContextProvider.php';
        foreach (['LaravelJourneyContextProvider', 'LaravelJourneyCoordinatorFactory', 'LaravelJourneyHooks'] as $class) {
            require_once $rc4.'/app/Services/MailJourneys/'.$class.'.php';
        }
        foreach (['000002_create_dz_mail_marketing_consents', '000003_create_dz_mail_verified_facts'] as $migration) {
            (require $rc4.'/database/migrations/2026_11_09_'.$migration.'.php')->up();
        }
    }

    private function teen(): TestSession
    {
        return TestSession::create(['parent_name' => 'QA', 'email' => 'buyer@example.test', 'phone' => '+79990000001',
            'child_name' => 'QA', 'child_age' => 15, 'test_type' => 'teen', 'status' => 'completed',
            'payment_status' => 'paid', 'payment_id' => 'current-bank-operation', 'consent_personal' => true]);
    }

    public function test_stale_receipt_does_not_verify_a_different_current_bank_operation(): void
    {
        $session = $this->teen();
        $id = DB::table('dz_mail_verified_payments')->insertGetId(['product' => 'teen',
            'session_key_hash' => hash('sha256', $session->public_id),
            'payment_operation_hash' => hash('sha256', 'previous-bank-operation'),
            'email_sha256' => hash('sha256', $session->email), 'verification_proof_sha256' => str_repeat('a', 64),
            'amount_kopecks' => 349000, 'currency' => 'RUB', 'verified_at' => now()]);
        $provider = new LaravelJourneyContextProvider();
        self::assertFalse($provider->snapshot('teen', $session->public_id)['bank_verified']);
        DB::table('dz_mail_verified_payments')->where('id', $id)->update([
            'payment_operation_hash' => hash('sha256', $session->payment_id)]);
        self::assertTrue($provider->snapshot('teen', $session->public_id)['bank_verified']);
        $session->forceFill(['payment_status' => 'refunded'])->save();
        self::assertFalse($provider->snapshot('teen', $session->public_id)['bank_verified']);
    }

    public function test_recorder_rejects_another_operation_before_persisting_a_verified_receipt(): void
    {
        $session = $this->teen();
        config(['dz_mail_journeys.enabled' => true]);
        try {
            (new LaravelJourneyHooks(new LaravelJourneyCoordinatorFactory()))->verifiedPayment(
                $session, 'teen', 'another-operation', str_repeat('a', 64), 349000);
            self::fail('Mismatched payment operation accepted');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('VERIFIED_PAYMENT_PROOF_INVALID', $e->getMessage());
        } catch (\RuntimeException $e) {
            self::assertSame(0, DB::table('dz_mail_verified_payments')->count(),
                'Foreign operation was persisted before the disabled adapter reached its sender');
            throw $e;
        }
        self::assertSame(0, DB::table('dz_mail_verified_payments')->count());
        Mail::assertNothingSent();
    }

    public function test_corrective_mail_cannot_bypass_blocking_r27_qa_with_an_old_acknowledgement(): void
    {
        if (!class_exists(\App\Services\GardnerCommerce\ReadyMailAccess::class)) {
            require_once $this->review.'/server-baseline-rc5-20261110/customer-links/ReadyMailAccess.php';
        }
        $session = new TestSession(); $session->id = random_int(100000000, 999999999);
        $owner = storage_path('app/gardner-r27-owner-review/'.$session->id);
        $customer = storage_path('app/gardner-r27-customer/'.$session->id);
        self::assertDirectoryDoesNotExist($owner); self::assertDirectoryDoesNotExist($customer);
        mkdir($owner, 0700, true); mkdir($customer, 0700, true);
        try {
            $qa = json_encode(['blocked' => 1]); $sha = hash('sha256', $qa);
            $html = '<html><body>Synthetic approved report</body></html>'; $pdf = '%PDF-1.4 synthetic';
            $token = str_repeat('q', 60);
            file_put_contents($owner.'/qa.json', $qa);
            file_put_contents($owner.'/review.json', json_encode(['session_id' => $session->id,
                'state' => 'APPROVED', 'blocked' => 1, 'qa_sha256' => $sha,
                'decision' => ['value' => 'APPROVED', 'ack_qa_warnings' => true, 'qa_sha256' => $sha],
                'html_sha256' => hash('sha256', $html), 'pdf_sha256' => hash('sha256', $pdf)]));
            file_put_contents($customer.'/manifest.json', json_encode(['session_id' => $session->id,
                'expires' => time() + 3600, 'token_sha256' => hash('sha256', $token),
                'html_sha256' => hash('sha256', $html), 'approved_html_sha256' => hash('sha256', $html),
                'pdf_sha256' => hash('sha256', $pdf), 'approved_pdf_sha256' => hash('sha256', $pdf)]));
            file_put_contents($customer.'/token.secret', $token);
            file_put_contents($customer.'/report.html', $html); file_put_contents($customer.'/report.pdf', $pdf);
            $method = new \ReflectionMethod(\App\Services\GardnerCommerce\ReadyMailAccess::class, 'r27');
            $rejected = false;
            try { $method->invoke(new \App\Services\GardnerCommerce\ReadyMailAccess(), $session); }
            catch (\RuntimeException $e) { $rejected = $e->getMessage() === 'R27_QA_DECISION_UNCONFIRMED'; }
            self::assertTrue($rejected, 'A blocked report was exposed through the corrective-mail reader');
            $clean = json_encode(['blocked' => 0]);
            file_put_contents($owner.'/qa.json', $clean);
            $review = json_decode(file_get_contents($owner.'/review.json'), true);
            $review['blocked'] = 0; $review['qa_sha256'] = hash('sha256', $clean);
            file_put_contents($owner.'/review.json', json_encode($review));
            self::assertSame([$html, $pdf], $method->invoke(new \App\Services\GardnerCommerce\ReadyMailAccess(), $session));
        } finally {
            foreach ([$owner, $customer] as $directory) { foreach (glob($directory.'/*') as $path) unlink($path); rmdir($directory); }
        }
    }
}
