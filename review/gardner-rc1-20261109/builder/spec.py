"""All modifications are applied ONLY to staged copies after original SHA verification.
All replacement anchors are narrowly drawn from the read-only 2026-11-08 server snapshot.
"""
from dataclasses import dataclass
from typing import List

@dataclass(frozen=True)
class Op:
    label: str
    before: str
    after: str
    count: int = 1

@dataclass(frozen=True)
class Target:
    relative: str
    sha256: str
    ops: tuple[Op, ...]


def op(label, before, after, count=1):
    return Op(label, before, after, count)

TARGETS: List[Target] = []
def target(path, sha, *operations):
    TARGETS.append(Target(path, sha, operations))

# Completion can be re-submitted after a client timeout, but its immutable answer
# snapshot must never be edited after completion or payment.
target('app/Http/Controllers/Api/TestController.php',
       'a012ca230dca0026b6d8f5a90b9a2d6fa89547cc5e712035e30375880db1f0d5',
       op('immutable_completed_answers',
'''            if (($session->test_type ?? 'children') !== 'children') {
                throw ValidationException::withMessages([
                    'session_id' => 'Эта сессия не относится к детскому тесту.',
                ]);
            }

            $hasSavedAnswers = Answer::query()''',
'''            if (($session->test_type ?? 'children') !== 'children') {
                throw ValidationException::withMessages([
                    'session_id' => 'Эта сессия не относится к детскому тесту.',
                ]);
            }

            // P0 immutable input: retries may repeat identical values only.
            // The saved scoring/JSON and paid generation identity remain unchanged.
            if ($session->status === 'completed') {
                if ($session->respondent_type !== $data['respondent_type']) {
                    throw ValidationException::withMessages([
                        'respondent_type' => 'Нельзя менять способ ответа после завершения теста.',
                    ]);
                }
                $saved = Answer::query()->where('test_session_id', $session->id)
                    ->get(['question_id', 'value'])->keyBy('question_id');
                foreach ($data['answers'] as $submitted) {
                    $row = $saved->get($submitted['question_id']);
                    if ($row === null || (string) $row->value !== (string) $submitted['value']) {
                        throw ValidationException::withMessages([
                            'answers' => 'Тест уже завершён. Изменять сохранённые ответы нельзя.',
                        ]);
                    }
                }
                return $session;
            }
            if ($session->payment_status === 'paid') {
                throw ValidationException::withMessages([
                    'session_id' => 'Оплаченные результаты нельзя изменять.',
                ]);
            }

            $hasSavedAnswers = Answer::query()'''),
       op('email_without_queue_blocking_test_submit',
'''        SendTestInterpretationPaymentEmailJob::dispatch($session->public_id)->afterCommit();
    }

    public function markPaid''',
'''        // Native Gardner uses a durable mail outbox; the legacy teen route stays unchanged.
        // Failure of an optional email event must not undo an already completed test.
        try {
            if (app(\\App\\Services\\Children\\V2\\GardnerV22\\Production\\GardnerV22PaidFlow::class)->isEnrolled($session)) {
                app(\\App\\Services\\GardnerCommerce\\Outbox::class)->freeOffer($session);
                return;
            }
            SendTestInterpretationPaymentEmailJob::dispatch($session->public_id)->afterCommit();
        } catch (\\Throwable $e) {
            report($e);
        }
    }

    public function markPaid'''))

# Legacy job keeps its old payment behaviour for pre-native sessions.
target('app/Jobs/SendTestInterpretationPaymentEmailJob.php',
       'f428d429032618bb4aba7405a384fbdef839230e88fb7513a23feeababe85e7b',
       op('native_free_offer_fallback_job',
'''        if ($session && app(\\App\\Services\\Children\\V2\\GardnerV22\\Production\\GardnerV22PaidFlow::class)->isEnrolled($session)) {
            return; // Native orders use the verified-buyer outbox and explicit communication consent.
        }''',
'''        if ($session && app(\\App\\Services\\Children\\V2\\GardnerV22\\Production\\GardnerV22PaidFlow::class)->isEnrolled($session)) {
            // No checkout or model request. Outbox owns consent, identity and deduplication.
            app(\\App\\Services\\GardnerCommerce\\Outbox::class)->freeOffer($session);
            return;
        }'''))

# Feature flags fail closed. Rollout cutoff prevents unsolicited historical backfill.
target('config/gardner_commerce.php',
       'b95172085f4bbfe3c2fe0548dc9c99caf317510124733f85a914fdd0ab338de1',
       op('release_gates',
"    'live_mail' => filter_var(env('GARDNER_COMMERCE_LIVE_MAIL', false), FILTER_VALIDATE_BOOLEAN),",
"    'live_mail' => filter_var(env('GARDNER_COMMERCE_LIVE_MAIL', false), FILTER_VALIDATE_BOOLEAN),\n"
"    // Feature-gated transactional offer, only for newly completed consented tests.\n"
"    'free_offer_mail_enabled' => filter_var(env('GARDNER_FREE_OFFER_MAIL_ENABLED', false), FILTER_VALIDATE_BOOLEAN),\n"
"    'free_offer_rollout_from' => env('GARDNER_FREE_OFFER_ROLLOUT_FROM') ?: null,\n"
"    // Service-only ready notice to immutable registration email; never exposes an access token.\n"
"    'registered_email_service_notice_enabled' => filter_var(env('GARDNER_REGISTERED_EMAIL_SERVICE_NOTICE_ENABLED', false), FILTER_VALIDATE_BOOLEAN),\n"
"    // Only an already owner-authorized session may receive an approved R27 account link.\n"
"    'r27_account_link_enabled' => filter_var(env('GARDNER_R27_ACCOUNT_LINK_ENABLED', false), FILTER_VALIDATE_BOOLEAN),\n"
"    // Paid MAX reconciliation has no delivery receipt yet: explicit opt-in and new orders only.\n"
"    'owner_paid_max_reconcile_enabled' => filter_var(env('GARDNER_OWNER_PAID_MAX_RECONCILE_ENABLED', false), FILTER_VALIDATE_BOOLEAN),\n"
"    'owner_paid_max_reconcile_from' => env('GARDNER_OWNER_PAID_MAX_RECONCILE_FROM') ?: null,"))

# The existing mail outbox remains the single sender and never republishes a draft.
target('app/Services/GardnerCommerce/Outbox.php',
       '37ecf3bd25771b2aee501b7838c0cf73350da4c027c6660a46a92784c74d8314',
       op('free_offer_and_verified_registered_buyer',
'''    public function ready(TestSession $session): void
    {''',
'''    public function freeOffer(TestSession $session): void
    {
        if (!config('gardner_commerce.enabled') || !config('gardner_commerce.free_offer_mail_enabled')) return;
        $from = config('gardner_commerce.free_offer_rollout_from');
        if (!is_string($from) || strtotime($from) === false) return;
        $session = $session->fresh();
        if (!$session || !$session->created_at || $session->created_at->lt(\\Carbon\\Carbon::parse($from))
            || $session->test_type !== 'children' || $session->status !== 'completed'
            || $session->payment_status === 'paid' || !$session->allow_communications
            || !filter_var($session->email, FILTER_VALIDATE_EMAIL)
            || !app(GardnerV22PaidFlow::class)->isEnrolled($session)
            || DB::table('gardner_orders')->where('test_session_id', $session->id)->exists()) return;
        $this->add($session, 'free-offer-v1', 'free_offer', mb_strtolower(trim((string) $session->email)));
    }

    /** Service notice only. Binding to paid buyer and registered contact is mandatory. */
    private function paidRegisteredBuyer(TestSession $session): ?object
    {
        $registered = mb_strtolower(trim((string) $session->email));
        if (!filter_var($registered, FILTER_VALIDATE_EMAIL)) return null;
        $buyer = DB::table('gardner_buyers')->where('test_session_id', $session->id)->first();
        if (!$buyer || !is_string($buyer->email)
            || !hash_equals($registered, mb_strtolower(trim($buyer->email)))) return null;
        if ($buyer->verified_at === null
            && config('gardner_commerce.registered_email_service_notice_enabled') !== true) return null;
        if (!DB::table('gardner_orders')->where('test_session_id', $session->id)
            ->where('buyer_id', $buyer->id)->where('status', 'paid')
            ->where('operation_id', $session->payment_id)->exists()) return null;
        return $buyer;
    }

    public function ready(TestSession $session): void
    {'''),
       op('ready_buyer_immutable_binding',
"DB::table('gardner_buyers')->where('test_session_id', $session->id)->whereNotNull('verified_at')->first();",
"$this->paidRegisteredBuyer($session);", count=2),
       op('free_offer_send_gate',
'''            $allowed = $session !== null;
            if ($allowed && $row->kind === 'payment_reminder')''',
'''            $allowed = $session !== null;
            if ($allowed && $row->kind === 'free_offer') {
                $email = mb_strtolower(trim((string) $session->email));
                $from = config('gardner_commerce.free_offer_rollout_from');
                $allowed = config('gardner_commerce.free_offer_mail_enabled') === true
                    && is_string($from) && strtotime($from) !== false
                    && $session->created_at !== null && $session->created_at->gte(\\Carbon\\Carbon::parse($from))
                    && $session->test_type === 'children' && $session->status === 'completed'
                    && $session->payment_status !== 'paid' && $session->allow_communications
                    && filter_var($email, FILTER_VALIDATE_EMAIL)
                    && hash_equals($email, mb_strtolower(trim((string) $row->recipient)))
                    && !DB::table('gardner_orders')->where('test_session_id', $session->id)->exists();
            }
            if ($allowed && $row->kind === 'payment_reminder')'''),
       op('service_notice_buyer_check_on_send',
'''            if ($allowed && in_array($row->kind, ['payment_confirmed','report_waiting','report_ready','report_technical_ready'], true)) $allowed = app(Orders::class)->paid($session);''',
'''            if ($allowed && in_array($row->kind, ['payment_confirmed','report_waiting','report_ready','report_technical_ready'], true)) {
                // Preserve the pre-existing payment-confirmed and waiting mail policy.
                $allowed = app(Orders::class)->paid($session);
            }
            if ($allowed && in_array($row->kind, ['report_ready','report_technical_ready'], true)) {
                $buyer = $this->paidRegisteredBuyer($session);
                $allowed = $buyer !== null
                    && hash_equals(mb_strtolower(trim((string) $buyer->email)), mb_strtolower(trim((string) $row->recipient)));
            }'''),
       op('mail_offer_uses_separate_templates',
'''            Mail::to($event->recipient)->send(new GardnerCommerceMail($event->kind,
                rtrim(config('app.url'), '/').(!empty($payload['specialist']) ? '/admin/consultations' : '/children/account/'.$session->public_id), $payload));''',
'''            $url = rtrim(config('app.url'), '/').(!empty($payload['specialist']) ? '/admin/consultations' : '/children/account/'.$session->public_id);
            $mail = $event->kind === 'free_offer'
                ? new \\App\\Mail\\GardnerFreeOfferMail($url)
                : new GardnerCommerceMail($event->kind, $url, $payload);
            Mail::to($event->recipient)->send($mail);'''))

# Tick must never allow one broken paid order or outbox entry to block all customers.
target('app/Console/Commands/GardnerCommerceTick.php',
       '0e81497a1d4e30d725b909c25d790dd735d575e9b00af335932e99910478c209',
       op('reconcile_free_completed_with_bounded_limit',
'''        DB::table('gardner_orders')->where('status','paid')->orderBy('id')->chunkById(100,function ($rows) use ($outbox) {''',
'''        // Backfill only newly created, consented, unpaid native sessions from an
        // explicitly approved rollout timestamp. This is bounded and idempotent.
        $cutoff = config('gardner_commerce.free_offer_rollout_from');
        if (config('gardner_commerce.free_offer_mail_enabled') === true
            && is_string($cutoff) && strtotime($cutoff) !== false) {
            $candidates = DB::table('test_sessions as s')
                ->join('gardner_v22_generations as g', 'g.test_session_id', '=', 's.id')
                ->leftJoin('gardner_orders as o', 'o.test_session_id', '=', 's.id')
                ->leftJoin('gardner_mail_outbox as m', function ($join) {
                    $join->on('m.test_session_id', '=', 's.id')->where('m.kind', '=', 'free_offer');
                })
                ->whereNull('m.id')->whereNull('o.id')->where('s.test_type', 'children')->where('s.status', 'completed')
                ->where('s.allow_communications', true)->where('s.created_at', '>=', $cutoff)
                ->where(function ($q) { $q->whereNull('s.payment_status')->orWhere('s.payment_status', '!=', 'paid'); })
                ->orderBy('s.id')->limit(100)->get(['s.id']);
            foreach ($candidates as $candidate) {
                try {
                    if ($session = TestSession::find($candidate->id)) $outbox->freeOffer($session);
                } catch (\\Throwable $e) {
                    \\Illuminate\\Support\\Facades\\Log::warning('gardner_free_offer_reconcile_failed',
                        ['session_id' => $candidate->id, 'exception_class' => $e::class]);
                }
            }
        }
        DB::table('gardner_orders')->where('status','paid')->orderBy('id')->chunkById(100,function ($rows) use ($outbox) {'''),
       op('paid_max_retry_and_order_isolation',
'''            foreach ($rows as $row) if ($session = TestSession::find($row->test_session_id)) {
                app(Orders::class)->dispatchConfirmed($session);
                $outbox->ready($session);
            }''',
'''            foreach ($rows as $row) if ($session = TestSession::find($row->test_session_id)) {
                try { app(Orders::class)->dispatchConfirmed($session); }
                catch (\\Throwable $e) { \\Illuminate\\Support\\Facades\\Log::warning('gardner_paid_dispatch_reconcile_failed',
                    ['session_id' => $session->id, 'exception_class' => $e::class]); }
                try { $outbox->ready($session); }
                catch (\\Throwable $e) { \\Illuminate\\Support\\Facades\\Log::warning('gardner_ready_enqueue_failed',
                    ['session_id' => $session->id, 'exception_class' => $e::class]); }
                // Disabled until a bounded owner-approved rollout; never notify historical paid orders.
                $maxFrom = config('gardner_commerce.owner_paid_max_reconcile_from');
                if (config('gardner_commerce.owner_paid_max_reconcile_enabled') === true
                    && is_string($maxFrom) && strtotime($maxFrom) !== false
                    && $session->created_at !== null && $session->created_at->gte(\\Carbon\\Carbon::parse($maxFrom))
                    && !$session->max_payment_notified_at && app(Orders::class)->paid($session)) {
                    try { app(\\App\\Services\\AdminMaxNotificationService::class)->notifyPaymentAccepted($session); }
                    catch (\\Throwable $e) { \\Illuminate\\Support\\Facades\\Log::warning('gardner_paid_max_reconcile_failed',
                        ['session_id' => $session->id, 'exception_class' => $e::class]); }
                }
            }'''),
       op('isolate_mail_outbox_delivery_errors',
'''            foreach ($rows as $row) $outbox->deliver($row->id);''',
'''            foreach ($rows as $row) {
                try { $outbox->deliver($row->id); }
                catch (\\Throwable $e) { \\Illuminate\\Support\\Facades\\Log::warning('gardner_mail_outbox_drain_failed',
                    ['outbox_id' => $row->id, 'exception_class' => $e::class]); }
            }'''))

# Owner MAX must not claim generation started when only payment is confirmed.
target('app/Services/AdminMaxNotificationService.php',
       '0a57bcb0b25e6093ac3e2e3c49f8f901f972a257a6f94245c4cd401c34e7a433',
       op('honest_paid_max_message',
"            '**Запущена автоматическая генерация AI-отчёта.**',",
"            '**Оплата подтверждена. Запуск генерации и готовность отслеживаются отдельно.**',"))

# Never log raw MAX error bodies or network exception messages, which may include identifiers.
target('app/Services/MaxBotApiService.php',
       '3cc1161da7a436cf901fc3a62367a6df178b76eb846f6144d865d36bb62126c0',
       op('max_transport_log_redaction',
'''        } catch (Throwable $e) {
            report($e);
            return ['ok' => false, 'error' => 'Ошибка соединения с MAX API: '.$e->getMessage()];
        }''',
'''        } catch (Throwable $e) {
            \\Illuminate\\Support\\Facades\\Log::warning('max_api_transport_failed', ['exception_class' => $e::class]);
            return ['ok' => false, 'error' => 'MAX_API_TRANSPORT_FAILED'];
        }'''),
       op('max_http_body_redaction',
'''        if (! $res->successful()) {
            $raw = (string) $res->body();
            report('MAX API sendMessage failed: HTTP '.$res->status().' '.$raw);

            return [
                'ok' => false,
                'error' => 'MAX API вернул HTTP '.$res->status().': '.Str::limit($raw, 400),
            ];
        }''',
'''        if (! $res->successful()) {
            \\Illuminate\\Support\\Facades\\Log::warning('max_api_http_failure',
                ['status' => $res->status(), 'body_sha256' => hash('sha256', (string) $res->body())]);
            return ['ok' => false, 'error' => 'MAX_API_HTTP_'.$res->status()];
        }'''))

# Owner approval with uncorrected blocking QA was impossible to deliver through the
# client route. Make every gate agree: blocking findings are never publishable.
target('app/Http/Controllers/GardnerR27OwnerReviewController.php',
       '784b48cb1a58460384a1174519db43dd7505fdaa4f27abce827bf686dc310e02',
       op('owner_approval_requires_zero_qa_blocks',
'''            if ($blocked>0) {
                $out.='<p style="color:#8a3200"><strong>Есть замечания QA.</strong> Перед утверждением прочитайте отчёт и откройте список замечаний. Ваше решение будет записано вместе с SHA-256 файлов.</p>';
                $out.='<label><input type="checkbox" name="ack_qa_warnings" value="1"> Я проверила(проверил) замечания и сознательно согласовываю эту версию</label><p></p>';
            }
            $out.='<button type="submit" name="decision" value="APPROVED">Согласовать отчёт</button> ';
            $out.='<button type="submit" name="decision" value="REJECTED">На доработку</button></form>';''',
'''            if($blocked>0) {
                $out.='<p style="color:#8a3200"><strong>Есть блокирующие замечания QA.</strong> До их исправления отчёт нельзя согласовать и отправить покупателю.</p>';
                $out.='<button type="submit" name="decision" value="REJECTED">На доработку</button></form>';
            } else {
                $out.='<button type="submit" name="decision" value="APPROVED">Согласовать отчёт</button> ';
                $out.='<button type="submit" name="decision" value="REJECTED">На доработку</button></form>';
            }'''),
       op('owner_approval_http_gate',
'''            if($decision==='APPROVED' && $hasWarnings && !$ack)abort(422,'Подтвердите, что изучили замечания QA.');''',
'''            if($decision==='APPROVED' && $hasWarnings)abort(422,'Сначала исправьте блокирующие замечания QA.');'''))

target('opt/dz-gardner-r27-live/core/server/stage_customer.py',
       '724c19fb3019bb9a69ffffc657cdb2fad182a62945356ecc4fb830221d4fa922',
       op('r27_stage_requires_clean_qa',
'''    if qa_data['blocked']>0 and (m.get('decision',{}).get('ack_qa_warnings') is not True or
                              m['decision'].get('qa_sha256')!=m.get('qa_sha256')):
        raise RuntimeError('OWNER_WARNING_ACK_REQUIRED')''',
'''    if qa_data['blocked']>0:
        raise RuntimeError('QA_BLOCKING_FINDINGS_REQUIRE_REPAIR')'''))

target('opt/dz-gardner-r27-live/core/server/send_customer.php',
       '469890429ae6cec28412d25b8c657aabcc62d9ac831467ae52d484981a5e06aa',
       op('r27_send_requires_clean_qa',
'''    $acceptedWarnings=$warnings===0 || (
        ($review['decision']['ack_qa_warnings']??false)===true &&
        is_string($review['qa_sha256']??null) &&
        hash_equals($review['qa_sha256'],(string)($review['decision']['qa_sha256']??'')));''',
'''    // The customer route itself rejects blocked>0. Do not accept a version
    // that passes the mail step but yields an inaccessible 404 link.
    $acceptedWarnings=$warnings===0;'''))

# Read-only progress observer will notify owner if native mail outbox is unknown.
target('opt/dz-gardner-r27-live/server/notify_generation_progress.php',
       'a53e619834aa5c03d69fdbbd4669671c1a3dc01800b153717debf56b9fafeffc',
       op('observe_native_mail_unknown_count',
"""    return ['session_id'=>$id,'order_id'=>$order?->id,'native'=>$native,'error_code'=>(string)($run->error_code??''),""",
"""    $nativeMailUnknown=(int)DB::table('gardner_mail_outbox')->where('test_session_id',$id)
        ->where('status','delivery_unknown')->count();
    return ['session_id'=>$id,'order_id'=>$order?->id,'native'=>$native,'error_code'=>(string)($run->error_code??''),
        'native_mail_unknown_count'=>$nativeMailUnknown,"""),
       op('owner_native_mail_incident_event',
"""    if(($s['customer_mail_receipt']??'')==='SENDING_OUTCOME_UNCERTAIN')$events[]=['key'=>'customer-mail-outcome-unknown',""",
"""    if(($s['native_mail_unknown_count']??0)>0)$events[]=['key'=>'native-mail-delivery-unknown-'.(int)$s['native_mail_unknown_count'],
        'text'=>'В почтовой очереди Гарднера есть письмо с неизвестным исходом SMTP. Нельзя отправлять повторно без сверки с почтовым провайдером.'];
    if(($s['customer_mail_receipt']??'')==='SENDING_OUTCOME_UNCERTAIN')$events[]=['key'=>'customer-mail-outcome-unknown',"""))

# Vue minimal fixes: verified email UI, report route, clear blocked status.
target('resources/js/views/GardnerAccountPage.vue',
       '07d8937811767b1751533a8e68fd79cdde2ffe97fcf571df288040299c886867',
       op('email_verification_state',
"const challenge = ref(''), code = ref(''), day = ref(''), selected = ref(null), questions = ref(''), share = ref(false), editing = ref(false);",
"const challenge = ref(''), code = ref(''), day = ref(''), selected = ref(null), questions = ref(''), share = ref(false), editing = ref(false);\nconst verifyChallenge = ref(''), verifyCode = ref('');"),
       op('email_verification_handlers',
'''async function pay() { await action(async () => {''',
'''async function requestVerify() { await action(async () => {
    await axios.post(`${base}/verify-email`, {}, {headers:headers()});
    notice.value = 'Если адрес доступен, на него поступит код подтверждения. Введите код и номер запроса из письма.';
}); }
async function confirmVerify() { await action(async () => {
    await axios.post(`${base}/verify-email-confirm`, {challenge_id:verifyChallenge.value, code:verifyCode.value}, {headers:headers()});
    verifyChallenge.value = ''; verifyCode.value = ''; notice.value = 'Адрес электронной почты подтверждён.';
    await load();
}); }
async function pay() { await action(async () => {'''),
       op('pending_checkout_message',
'''<p v-if="!state.sales_available">Новые оплаты временно приостановлены. Уже оплаченные заказы сохраняются.</p>''',
'''<p v-if="!state.sales_available">{{ state.order_status === 'pending' ? 'Для вашей сессии уже создан заказ, но новые переходы к оплате временно приостановлены. Не создавайте повторный платёж; проверьте статус или обратитесь в поддержку.' : 'Новые оплаты временно приостановлены. Уже оплаченные заказы сохраняются.' }}</p>'''),
       op('service_notice_no_false_mail_claim',
'''<p>Спасибо за доверие. Ваш заказ на детский профиль интеллекта подтверждён. Письмо с информацией об оплате отправляется на указанный email.</p>''',
'''<p>Спасибо за доверие. Ваш заказ на детский профиль интеллекта подтверждён. Статус подготовки можно проверить здесь; письмо отправим, когда почтовый сервис будет готов принять уведомление.</p>'''),
       op('paid_email_verification_form',
'''        <template v-else-if="state?.paid">
            <h1 class="hero">''',
'''        <template v-else-if="state?.paid">
            <section v-if="state.recovery_available && !state.email_verified" class="account-card access-card" aria-label="Подтверждение электронной почты">
                <h2>Подтвердите email для уведомлений</h2>
                <p>Это поможет безопасно восстановить доступ. Оплату повторять не нужно; подтверждение адреса не меняет результаты тестирования.</p>
                <button class="secondary" :disabled="busy" @click="requestVerify">Получить код подтверждения</button>
                <form @submit.prevent="confirmVerify"><label>Номер запроса из письма<input v-model="verifyChallenge" required autocomplete="off" /></label><label>Восьмизначный код<input v-model="verifyCode" required pattern="[0-9]{8}" maxlength="8" inputmode="numeric" autocomplete="one-time-code" /></label><button class="primary" :disabled="busy">Подтвердить email</button></form>
            </section>
            <h1 class="hero">'''),
       op('confirmed_booking_does_not_link_free_as_paid',
'''<RouterLink class="primary" :to="`/children/result/${id}`">К моему отчёту</RouterLink>''',
'''<RouterLink v-if="state.report?.ready" class="primary" :to="`/children/result/${id}?view=report`">К моему отчёту</RouterLink><a v-else-if="state.r27_report_url" class="primary" :href="state.r27_report_url">К моему отчёту</a><RouterLink v-else class="primary" :to="`/children/result/${id}?free=1`">Бесплатный результат</RouterLink>'''),
       op('r27_account_access_without_mail',
'''                <section class="report-status"><strong>{{ state.report?.ready''',
'''                <div v-if="state.r27_report_url" class="report-status"><strong>Утверждённый полный отчёт доступен в личном кабинете, даже если письмо задержалось.</strong><a class="primary" :href="state.r27_report_url">Открыть полный отчёт</a></div>
                <section class="report-status"><strong>{{ state.report?.ready'''))

# The native account URL for an R27-approved report is derived only after existing
# owner-access authorization, payment binding and current exact file hashes.
target('app/Http/Controllers/Api/GardnerCommerceController.php',
       '5f01afe3e5685cb7712bc9d4a344f5c5eb905d536b3565355d5f880975d56704',
       op('account_exposes_only_verified_r27_link',
'''            'report' => app(GardnerV22PaidFlow::class)->metadata($session),''',
'''            'report' => app(GardnerV22PaidFlow::class)->metadata($session),
            'r27_report_url' => $paid ? app(\\App\\Services\\GardnerCommerce\\R27AccountLink::class)->forPaidSession($session) : null,'''))
