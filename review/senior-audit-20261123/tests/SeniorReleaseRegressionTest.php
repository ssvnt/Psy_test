<?php

namespace Tests\Feature;

use App\Mail\GardnerCommerceMail;
use App\Models\{Admin, TestSession};
use App\Services\GardnerCommerce\Outbox;
use App\Services\TeenApprovedReports;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{Artisan, DB, Http, Mail, Route};
use Tests\TestCase;

class SeniorReleaseRegressionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame(':memory:', config('database.connections.sqlite.database'));
        Artisan::call('migrate:fresh', ['--force' => true]);
        Http::preventStrayRequests();
        Mail::fake();
        config(['gardner_commerce.enabled' => true, 'app.url' => 'https://site.example.test']);
        $this->travelTo(CarbonImmutable::parse('2026-11-23 00:00:00', 'UTC'));
    }

    private function specialist(): int
    {
        $admin = Admin::create(['login' => 'audit-specialist', 'name' => 'QA', 'password' => bcrypt('unused')]);
        $admin->forceFill(['api_token' => hash('sha256', 'audit-admin')])->save();
        return DB::table('gardner_specialists')->insertGetId([
            'admin_id' => $admin->id, 'name' => 'QA', 'email' => 'staff@example.test', 'active' => true,
        ]);
    }

    private function slot(int $specialist, CarbonImmutable $start): int
    {
        return DB::table('gardner_consultation_slots')->insertGetId([
            'specialist_id' => $specialist, 'starts_at' => $start->utc(),
            'ends_at' => $start->addMinutes(20)->utc(), 'timezone' => 'Asia/Irkutsk', 'published' => true,
        ]);
    }

    public function test_valid_slots_after_one_hundred_legacy_slots_are_visible(): void
    {
        $specialist = $this->specialist();
        $monday = CarbonImmutable::parse('2026-11-23 10:00:00', 'Asia/Irkutsk');
        for ($i = 0; $i < 100; $i++) $this->slot($specialist, $monday->addMinutes($i));
        $valid = $this->slot($specialist, CarbonImmutable::parse('2026-11-25 10:00:00', 'Asia/Irkutsk'));
        self::assertSame([$valid], array_column(app(TeenApprovedReports::class)->availableSlots(), 'id'));
    }

    private function teenBooking(): array
    {
        $slot = $this->slot($this->specialist(), CarbonImmutable::parse('2026-11-25 10:00:00', 'Asia/Irkutsk'));
        $session = TestSession::create(['parent_name' => 'QA', 'email' => 'buyer@example.test',
            'phone' => '+79990000001', 'child_name' => 'QA', 'child_age' => 15, 'test_type' => 'teen',
            'status' => 'completed', 'payment_status' => 'paid', 'consent_personal' => true]);
        $booking = DB::table('gardner_consultations')->insertGetId(['test_session_id' => $session->id,
            'slot_id' => $slot, 'status' => 'confirmed', 'version' => 1, 'timezone' => 'Asia/Irkutsk']);
        return [$session, $booking, $slot];
    }

    public function test_specialist_can_save_teen_meeting_and_deliver_correct_product_notice_once(): void
    {
        [$session, $booking, $slot] = $this->teenBooking();
        $url = '/api/admin/gardner-consultations/'.$booking.'/meeting';
        $this->putJson($url, ['meeting_url' => 'https://meet.example.test/qa'])->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer audit-admin');
        $this->putJson($url, ['meeting_url' => 'javascript:alert(1)'])->assertUnprocessable();
        $this->putJson($url, ['meeting_url' => 'https://meet.example.test/qa'])->assertOk();
        self::assertSame('https://meet.example.test/qa', DB::table('gardner_consultation_slots')->find($slot)->meeting_url);
        $event = DB::table('gardner_mail_outbox')->where('kind', 'consultation_meeting_link')->sole();
        self::assertSame('buyer@example.test', $event->recipient);
        app(Outbox::class)->deliver($event->id);
        app(Outbox::class)->deliver($event->id);
        Mail::assertSent(GardnerCommerceMail::class, 1);
        Mail::assertSent(GardnerCommerceMail::class, fn ($mail) => $mail->accountUrl ===
            'https://site.example.test/teen/payment/'.$session->public_id);
    }

    public function test_teen_consultation_notice_cannot_go_to_a_changed_recipient_or_refunded_order(): void
    {
        [$session] = $this->teenBooking();
        foreach (['wrong-address', 'refunded'] as $case) {
            app(Outbox::class)->add($session, $case, 'consultation_updated',
                $case === 'wrong-address' ? 'other@example.test' : $session->email, ['version' => 1]);
            if ($case === 'refunded') $session->forceFill(['payment_status' => 'refunded'])->save();
            $id = DB::table('gardner_mail_outbox')->where('event_key', $session->public_id.':'.$case)->value('id');
            app(Outbox::class)->deliver($id);
            self::assertSame('suppressed', DB::table('gardner_mail_outbox')->find($id)->status);
        }
        Mail::assertNothingSent();
    }

    public function test_unknown_web_paths_return_404_and_known_spa_routes_still_load(): void
    {
        foreach (['/children/not-a-page/extra', '/missing-image.png', '/api'] as $path) $this->get($path)->assertNotFound();
        $this->getJson('/api/does-not-exist')->assertNotFound();
        $this->postJson('/api/does-not-exist')->assertNotFound();
        foreach (['/', '/children', '/children/payment/synthetic-session', '/teen/payment', '/teen/test',
            '/result/synthetic-session?view=report', '/privacy', '/admin/articles/12/edit'] as $path) {
            $this->get($path)->assertOk()->assertSee('id="app"', false);
        }
    }

    public function test_rebuilt_release_contains_previously_deployed_customer_links(): void
    {
        self::assertTrue(class_exists(\App\Services\GardnerCommerce\ReadyMailAccess::class));
        self::assertTrue(class_exists(\App\Http\Controllers\ReadyMailController::class));
        self::assertTrue(is_file(resource_path('views/gardner-mail-booking.blade.php')));
        self::assertTrue(Route::has('gardner.customer.ready-mail'));
    }
}
