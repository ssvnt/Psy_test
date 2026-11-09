# RC2: native DeepSeek/QA/ledger/systemd — обязательная отдельная реализация

**Это дизайн, не включённая в RC1 работающая редактура. Не использовать как разрешение на production.** Снимок 08.11.2026 содержит native `quality_rejected` с историческими `61/61` chunks и `51/181` отклонённых полей. История `27/27, EDITOR_BILLING_RECONCILIATION_REQUIRED` у supervisor104 относится к **teen R27**, не к заказу Гарднера.

## 1. Почему нельзя ослабить QA

`GardnerV22NativeGenerator.php:118–134` делает аудит всех полей, записывает `quality.json` и на `FAIL` бросает `quality_rejected` до `PaidArtifactStore::assemble`. `GenerateGardnerV22ReportJob.php:79–93` ставит generation `failed`. `server/finalize.py:105–109` требует `done`. `GardnerV22PublicationPolicy` дополнительно требует `quality=PASS`, `approved`, SHA и policy ID. **Это независимые рубежи**: понижать их нельзя.

`GardnerV22TextQuality::fingerprint()` включает байты `GardnerV22TextNormalizer.php` и файлы policy/scanners. Изменение нормализатора без версии и migration plan меняет отпечаток, что может сделать исторический QA несопоставимым и нарушить восстановление.

## 2. Безопасная адресная редактура

1. Прочитать **в приватном окружении** `quality.json`, исходные chunk receipts и native checkpoint SHA. Снаружи выводить только коды нарушений, числа, хэши; использовать `scripts/quality_diagnose_readonly.py`, ничего не переписывать.
2. Заморозить snapshot `generation_identity`, `session_binding`, исходный JSON скоринга, 61 принятый chunk и расходы; подготовить immutable `repair_intent` с версиями scanner policy и hash набора полей.
3. Сформировать allowlist **только** отклонённых листовых полей `expert_####`. Детерминированная локальная замена типографики с доказуемой сохранностью смысла; если неоднозначно, пометить `owner_review_required`.
4. Только после отдельного owner approval бюджета/editor policy — точечный вызов DeepSeek по доказанным нарушениям без полной регенерации. Каждой попытке нужны durable request identity, attempt ledger, provider receipt, billing reserve, максимум безопасных повторов.
5. После каждого изменения подтверждать SHA, числовые значения, отрицания, ссылки на исходные ответы, персональные утверждения и семантику; выполнять **исходные** `humanizer-ru` и `pishi-normalno` в реальном режиме, а не заявлять фиктивный PASS.
6. Повторно проверить все 181 полей и каждый раздел. Только когда `quality.status=PASS` и все status provenance валидны, создаётся **новый** signed private artifact. Старый остаётся, не перезаписывается.
7. Перед publication: отдельный owner review точной версии, PDF proof (полнота текста, страницы, макет, изображения), платёж/сессия/consent binding. Отправка и доступ — разные подтверждаемые этапы.

## 3. Финансовые ограничения и восстановление

- `GardnerV22DeepSeekHttpTransport.php:11–55`: timeout/stream failure даёт `provider_transport_failed` или `provider_stream_incomplete` **без подтверждения, списал ли провайдер деньги**.
- `GardnerV22MeteredProvider.php:17–177` сохраняет raw receipt и `GardnerV22AttemptLedger` хранит состояние `billing_unknown`/`in_flight`. `R27 recovery_tick.php:52–78` умеет восстановить auth raw evidence; повторно вызывать API можно только при подтверждённом безопасном лимите.
- Не переводить `billing_unknown` в `not_charged` без независимого доказательства (`reconciliation_sha256`), не делать полный re-run; `one_retry` допускается только как отдельная явно разрешённая платная попытка в допустимом бюджете.
- Убрать hardcoded ветку `sessionId=106` **после** переноса её proof/receipt в универсальную миграцию и прохождения regression именно оплаченной исторической сессии, не раньше.
- Возможные остановки: `queue_dispatch_failed`, `pipeline_disabled`, `generation_lease_lost`, `billing_reconciliation_required`, `report_request_budget_exhausted`, `report_cost_budget_exhausted`, `request_token_limit`, `session_inputs_changed`, `quality_unavailable`, `quality_source_integrity`, `quality_rejected`, provider 401/429/502/503/504/timeout, `partial_chunks_owner_review_required`, filesystem full/permission, Python scanner unavailable, paused manual publication.

## 4. Реальный оркестратор и delivery

На сервере подтвердить настоящие unit/timer, `ExecStart`, `Restart`, `ActiveState`, scheduler heartbeat и последний запуск **не по наличию файлов в /opt**. Нужны отдельные durable events: `paid`, `queued`, `started`, `stalled`, `QA_FAIL`, `review_requested`, `approved`, `published`, `mail_accepted/unknown`, `MAX_accepted/unknown`. Явно указать SLA, где вмешательство человека неизбежно (оплата unknown, опасная редактура, ручное утверждение), и выводить эти остановки владельцу.

`send_customer.php` использует регистрацию email+paid order и проверяет HTTPS/HTML/PDF хэши; `GardnerCommerce/Outbox.php` использует другой путь. **Выбрать канонический путь**, определить кто утверждает, публикует, отправляет, кто проводит reconcile, кто отвечает за ссылку в кабинете. Пока два источника истины, пользователь может не получить report даже при `done`.

## 5. Условия RC2 GO

- Два новых синтетических платных заказа и старый anonymized incident replay; ноль дублированных платежей и незапланированных модельных вызовов.
- Срыв сетевого запроса до/после возможной тарификации, crash после raw receipt и после ledger commit, две очереди, рестарт worker, expired lease, отложенная публикация.
- Стиль PASS на текущей реальной версии scanner policy; ни одно числовое значение/скоринг/акт оплаты не изменено, все исходные receipts сохранены.
- PDF/HTML содержательно полноценны, одобрены владельцем и доступны в кабинете; email и MAX имеют подтверждённые (не вымышленные) внешние статусы.
- Все 57 старых сценариев плюс RC1-T01–T20 проходят в изолированном staging, отдельная проверка teen report/booking/notifications.
