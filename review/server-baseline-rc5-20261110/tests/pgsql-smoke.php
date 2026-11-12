<?php

declare(strict_types=1);

use App\Models\{Admin, TestSession};
use App\Services\Children\V2\GardnerV22\Production\GardnerV22PaidFlow;
use App\Services\GardnerCommerce\{Consultations, Orders};
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\{DB, Http, Mail};
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\GardnerCommerceReady;

// Run from the reconstructed Laravel root against a newly migrated local QA database.
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

function verify(bool $condition, string $context): void
{
    if (!$condition) {
        throw new RuntimeException($context);
    }
}

verify(app()->environment('testing') && DB::getDriverName() === 'pgsql'
    && config('database.connections.pgsql.host') === '127.0.0.1'
    && DB::selectOne('select current_database() as db')->db === 'dz_qa', 'A local dz_qa PostgreSQL database is required');
verify(!TestSession::query()->exists() && !Admin::query()->exists(), 'Use a newly migrated empty QA database');
Mail::fake();
Http::preventStrayRequests();
config(['gardner_commerce.live_payments' => true, 'gardner_report_v22.enabled_for_new_sessions' => true,
    'services.tochka.jwt' => 'synthetic-test-token', 'services.tochka.customer_code' => '123456789',
    'services.tochka.api_base_url' => 'https://bank.example.test', 'services.tochka.merchant_id' => null]);
GardnerCommerceReady::configure();
DB::beginTransaction();
try {
    $session = TestSession::create(['parent_name' => 'QA', 'child_name' => 'QA', 'child_age' => 9,
        'phone' => '+79990000001', 'email' => 'qa@example.invalid', 'test_type' => 'children',
        'status' => 'completed', 'payment_status' => 'pending', 'consent_personal' => true]);
    app(GardnerV22PaidFlow::class)->enroll($session);
    GardnerCommerceReady::answers($session);
    \App\Models\PromoCode::create(['code' => 'PG-QA', 'discount_amount' => 100, 'active' => true]);
    $session->forceFill(['promo_code' => 'PG-QA'])->save();
    $orders = app(Orders::class);
    $operation = (string) Str::uuid();
    $currency = null;
    Http::fake(['https://bank.example.test/*' => function ($request) use ($session, $operation, &$currency) {
        if ($request->method() === 'POST') {
            return Http::response(['Data' => ['operationId' => $operation, 'paymentLink' => 'https://tochkaplace.com/ia/qa-only']]);
        }
        $order = DB::table('gardner_orders')->where('test_session_id', $session->id)->first();
        return Http::response(['Data' => ['Operation' => [['operationId' => $operation, 'paymentLinkId' => $session->public_id,
            'amount' => '2390.00', 'currency' => $currency, 'purpose' => $order->provider_purpose, 'customerCode' => '123456789',
            'status' => 'APPROVED']]]]);
    }]);
    $order = $orders->create($session);
    verify($session->fresh()->price === 2490 && $session->fresh()->discount_amount === 100
        && (int) $order->amount_kopecks === 239000, 'Frozen price and discount');
    verify($orders->create($session)->id === $order->id, 'Order replay');
    config(['gardner_commerce.bank_rub_contract_id' => null]);
    try {
        $orders->check($session);
        throw new RuntimeException('Missing currency accepted');
    } catch (HttpExceptionInterface $error) {
        verify($error->getStatusCode() === 409 && !$orders->paid($session), 'Unverified payment');
    }
    $currency = 'RUB';
    $orders->check($session);
    $orders->check($session);
    verify($orders->paid($session) && DB::table('gardner_mail_outbox')->where('kind', 'payment_confirmed')->count() === 1,
        'Repeated confirmation must produce one paid event');
    $specialist = Admin::create(['login' => 'pg-qa', 'name' => 'QA', 'password' => bcrypt('synthetic')]);
    DB::table('gardner_specialists')->insert(['admin_id' => $specialist->id, 'name' => 'QA', 'active' => true]);
    $consultations = app(Consultations::class);
    $when = CarbonImmutable::now('Asia/Irkutsk')->next('Wednesday')->setTime(10, 0);
    $slot = $consultations->publishSlot($specialist->id, ['starts_at' => $when->toIso8601String(),
        'ends_at' => $when->addMinutes(20)->toIso8601String(), 'timezone' => 'Asia/Irkutsk']);
    verify(array_column($consultations->slots(), 'id') === [$slot], 'Free slots');
    $request = ['request_id' => (string) Str::uuid(), 'version' => 0, 'action' => 'book', 'slot_id' => $slot,
        'timezone' => 'Asia/Irkutsk', 'share_report' => false];
    verify($consultations->change($session, $request)['version'] === 1, 'Booking');
    verify($consultations->change($session, $request)['version'] === 1 && $consultations->slots() === [], 'Replay and availability');
    $request = array_replace($request, ['request_id' => (string) Str::uuid(), 'version' => 1, 'action' => 'cancel']);
    verify($consultations->change($session, $request)['status'] === 'cancelled', 'Cancellation');
    verify(array_column($consultations->slots(), 'id') === [$slot], 'Slot available after cancellation');
    Http::assertSentCount(4);
    Mail::assertNothingSent();
    echo "PostgreSQL PASS: one order, unverified currency rejected, paid confirmation deduplicated, booking/replay/cancellation.\n";
} finally {
    DB::rollBack();
}
