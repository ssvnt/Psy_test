<?php

namespace Tests\Feature;

use App\Models\{Admin, PromoCode, Setting, TestSession, GardnerV22Generation};
use App\Services\GardnerCommerce\{Availability, BuyerAccess, Consultations, Orders, Outbox, Prices, Publication};
use App\Services\Children\V2\GardnerV22\Production\{GardnerV22PaidArtifactStore, GardnerV22PaidFlow, GardnerV22SessionCompiler, GardnerV22ConfiguredCloudRuCredentials};
use Illuminate\Support\Facades\{Artisan, DB, Http, Mail, Crypt};
use Illuminate\Support\Str;
use Tests\Support\{GardnerCommerceReady, GardnerV22Stage210Fixture, GardnerV22FakeDeepSeekTransport};
use Tests\TestCase;

class PasswordlessReadyMailTest extends TestCase
{
    private TestSession $session;
    private string $token;
    private string $operation;
    private array $bank = [];
    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame(':memory:', config('database.connections.sqlite.database'));
        self::assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));
        Http::preventStrayRequests(); Mail::fake();
        config(['gardner_report_v22.enabled_for_new_sessions' => true, 'gardner_report_v22.local_preview_enabled' => true,
            'gardner_commerce.live_payments' => true, 'services.tochka.jwt' => 'synthetic-bank-token',
            'services.tochka.customer_code' => '123456789', 'services.tochka.merchant_id' => null,
            'services.tochka.api_base_url' => 'https://bank.test', 'gardner_report_v22.private_disk' => 'server-qa',
            'filesystems.disks.server-qa' => ['driver' => 'local', 'root' => base_path('tmp/server-qa-'.Str::uuid()), 'throw' => true]]);
        GardnerCommerceReady::configure();
        $this->session = TestSession::create(['parent_name' => 'QA', 'child_name' => 'QA_SYNTHETIC_CHILD', 'child_age' => 9,
            'phone' => '+79990000001', 'email' => 'buyer@example.invalid', 'test_type' => 'children', 'status' => 'completed',
            'payment_status' => 'pending', 'consent_personal' => true, 'allow_communications' => true]);
        $this->token = app(GardnerV22PaidFlow::class)->enroll($this->session);
        GardnerCommerceReady::answers($this->session);
        DB::table('gardner_buyers')->insert(['test_session_id' => $this->session->id, 'email' => $this->session->email,
            'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->operation = (string) Str::uuid();
        $this->withHeaders(['X-Gardner-Access' => $this->token, 'X-Session-Phone' => '+79990000001']);
        Http::fake(function ($request) {
            if ($request->method() === 'POST') return Http::response(['Data' => ['operationId' => $this->operation, 'paymentLink' => 'https://tochkaplace.com/ia/qa-order']]);
            $order = DB::table('gardner_orders')->where('test_session_id', $this->session->id)->first();
            return Http::response(['Data' => ['Operation' => [array_replace(['operationId' => $this->operation,
                'paymentLinkId' => $this->session->public_id, 'amount' => $order->amount_kopecks / 100, 'currency' => 'RUB',
                'purpose' => $order->provider_purpose, 'customerCode' => '123456789', 'status' => 'APPROVED'], $this->bank)]]]);
        });
    }
    private function url(string $suffix = ''): string { return '/api/gardner-commerce/'.$this->session->public_id.$suffix; }
    private function paid(): void
    {
        $this->postJson($this->url('/order'))->assertOk();
        $this->postJson($this->url('/check'))->assertOk()->assertJsonPath('status', 'paid');
    }
    private function admin(): Admin
    {
        $admin = Admin::create(['login' => 'qa-reviewer', 'name' => 'QA', 'password' => bcrypt('synthetic-unused')]);
        $admin->forceFill(['api_token' => hash('sha256', 'qa-reviewer-token')])->save();
        $this->withHeader('Authorization', 'Bearer qa-reviewer-token');
        return $admin;
    }
    private function generate(): void
    {
        self::assertSame(0, Artisan::call('queue:work', ['connection' => 'gardner_v22', '--queue' => 'gardner-v22',
            '--once' => true, '--sleep' => 0, '--tries' => 1, '--timeout' => 7200]));
        $run = GardnerV22Generation::where('test_session_id', $this->session->id)->firstOrFail();
        self::assertSame('done', $run->status, $run->error_code ?? '');
    }

    public function test_first_ready_email_opens_exact_approved_files_and_booking_without_browser_credentials(): void
    {
        $this->paid(); $this->generate(); $this->admin();
        $url = '/api/admin/gardner-reports/'.$this->session->public_id;
        $snapshot = $this->getJson($url)->assertOk()->json();
        $this->postJson($url, ['request_id' => (string) Str::uuid(), 'decision' => 'approved',
            'artifact_sha256' => $snapshot['artifact_sha256'], 'generation_identity' => $snapshot['generation_identity'],
            'reason' => 'Synthetic QA approval, no customer data or real delivery.', 'content_reviewed' => true,
            'field_hashes' => $snapshot['field_hashes']])->assertOk();
        $expectedHtml = $this->get('/api/test/'.$this->session->public_id.'/gardner-v22/report')->assertOk()->getContent();
        $expectedPdf = $this->get('/api/test/'.$this->session->public_id.'/gardner-v22/report.pdf')->assertOk()->getContent();
        self::assertSame(0, DB::table('gardner_mail_outbox')->where('kind', 'report_ready')->where('status', 'sent')->count());
        $id = DB::table('gardner_mail_outbox')->where('kind', 'report_ready')->value('id');
        app(Outbox::class)->deliver($id); app(Outbox::class)->deliver($id);
        $mail = Mail::sent(\App\Mail\GardnerCommerceMail::class, fn ($m) => $m->kind === 'report_ready')->sole();
        $this->withoutHeader('Authorization')->withoutHeader('X-Gardner-Access')->withoutHeader('X-Session-Phone');
        self::assertSame($expectedHtml, $this->get($mail->accountUrl)->assertOk()->getContent(), 'The ready link led to a login shell instead of the approved report');
        self::assertSame($expectedPdf, $this->get($mail->details['pdf_url'])->assertOk()->getContent());
        $this->get($mail->details['booking_url'])->assertOk()->assertSee('консультаци');
        $text = view('emails.gardner-commerce-text', ['title' => 'QA', 'kind' => 'report_ready',
            'accountUrl' => $mail->accountUrl, 'details' => $mail->details])->render();
        self::assertStringContainsString($mail->accountUrl, $text);
        self::assertStringContainsString($mail->details['booking_url'], $text);
        self::assertStringNotContainsString('&amp;', $text);
        $this->get($mail->accountUrl.'&tampered=1')->assertForbidden();
        // Existing one-off corrective links retain their original signature and binding contract.
        $old = app(\App\Services\GardnerCommerce\ReadyMailAccess::class)->release($this->session->fresh(), 'native');
        $oldUrl = \Illuminate\Support\Facades\URL::temporarySignedRoute('gardner.customer.ready-mail', now()->addDay(),
            ['session' => $this->session->public_id, 'action' => 'report', 'source' => 'native', 'version' => $old['binding']]);
        self::assertSame($expectedHtml, $this->get($oldUrl)->assertOk()->getContent());
        DB::table('gardner_orders')->where('test_session_id', $this->session->id)->update(['status' => 'refunded']);
        $this->get($mail->accountUrl)->assertNotFound(); $this->get($oldUrl)->assertNotFound();
        Mail::assertSent(\App\Mail\GardnerCommerceMail::class, 1);
    }
}
