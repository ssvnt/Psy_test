# Добрый Жук: построчный аудит воронки Гарднера — P0, этап 1

Дата: 9 ноября 2026. Базовая ревизия: серверный экспорт 8 ноября, исходники /var/www/bribon. Источник: DZ_SOURCE_EXCERPTS_CHECKOUT_20261109.txt, JSON-манифест, 18 файлов. Нумерация ниже — нумерация в исходном PHP/Vue-файле, а не строка общего TXT. Проверено 18 блоков исходников, 17 статических контрактных наблюдений. Это НЕ 17 успешных интеграционных тестов.

**Статус: NO-GO. Ни одна правка не внедрена в production и main.** Анализ по этой выгрузке не включает фактический runtime журнал и 70 файлов R27: необходим отдельный этап. Реальные email, идентификаторы сессии, банковские operationId и назначения платежей не перепечатываются.

Обозначения: **К** — подтверждено текущим кодом; **И** — проявилось в историческом журнале Вадима; **Д** — ещё требуется дополнительный исходник или runtime-доказательство. Если указан код ошибки, он взят из сохранённых логов 6–8 ноября, а не из этого текстового экспорта.

## 1. Точные дефекты и исправления

### DZ-C01. Письмо после бесплатного теста отсутствует в новом потоке [P0; К+И]

Код: app/Http/Controllers/Api/TestController.php, строки 253–254 и 301–307: по завершении вызывает SendTestInterpretationPaymentEmailJob. Но app/Jobs/SendTestInterpretationPaymentEmailJob.php, строки 39–43, немедленно возвращается, если сессия использует новый платный Gardner V2.2. В проверенных точках никакой замены на событие free_offer не видно.

**Причина:** старый email-hook остался подключённым, но для нового потока он намеренно выключен. **Решение:** после транзакционного завершения теста создавать durable test_completed + free_result_offer event; отдельный email outbox с CTA и согласием на маркетинговую коммуникацию, без автоматического создания платежа; независимая повторная отправка. Учитывать принятие письма SMTP отдельно от фактической доставки.
**Тест:** завершение новой сессии с согласием → одно событие; без согласия → никаких рекламных писем; повторный submit/ошибка SMTP → без потерь и дублей. Платёж и DeepSeek при завершении не вызываются.

### DZ-C02. Ссылка оплаты не видна, даже для ранее выпущенного pending-заказа [P0; К+И]

Лог: CURRENT_BLOCKERS=gardner_commerce_sales_enabled_disabled.

Код: app/Services/GardnerCommerce/Availability.php:20–31,77–84 запрещает продажу при отключённом sales_enabled (кроме настроенного canary) и других gate. app/Http/Controllers/Api/GardnerCommerceController.php:41–52 возвращает confirmation_url для pending только при пустом списке *текущих* блокеров. resources/js/views/GardnerAccountPage.vue:97–101 скрывает кнопку, если sales_available=false. app/Services/GardnerCommerce/Orders.php:21–24 заново запускает assertCanSell при существующем pending.

**Причина:** перемешаны запрет на создание новых заказов и доступ к уже созданному индивидуальному заказу. **Решение:** явные отдельные поля состояния new_checkout_available, existing_order_payment_action, reasons. При бизнес-запрете новых платежей нельзя обходить запрет; в иных случаях ранее созданная банковская ссылка доступна по безопасной политике. Если платить нельзя — показывать причину и дальнейшее действие, а не скрывать существование заказа.
**Тест:** sales on/off, existing pending, нет оплаты, старый link после смены флага, банк недоступен, customer token потерян. Риска повторного платежа быть не должно.

### DZ-C03. Неопределённый результат создания банковской ссылки превращается в тупик [P0; К]

Код: Orders.php:27–52 фиксирует creating до сетевого вызова; строки 55–70 переводят любой сбой создания ссылки в creation_unknown; строки 21–24 при последующем вызове возвращают прежний заказ без сверки; GardnerAccountPage.vue:98–100 выводит «обратитесь в поддержку», но не предлагает backend reconciliation.

**Причина:** правильно предотвращается слепой повторный счёт, но отсутствует автоматическое разрешение неизвестного состояния. **Решение:** банковский reconciliation с immutable идентичностью заказа и допустимым поиском по merchant/paymentLinkId, durable attempted receipt и owner escalation по SLA; никогда не создавать вторую операцию без подтверждения отсутствия первой.
**Тест:** timeout до и после bank create, падение процесса между ответом банка и update DB, две вкладки, повтор после reboot. При неопределённости — ноль двойных списаний.

### DZ-C04. Отложенная выдача останавливает запуск отчёта [P0; К+И]

Код: Orders.php:119–132 записывает gardner_generation_dispatches после APPROVED, но Orders.php:137–143 возвращается без tryStart при deferred_fulfillment_enabled=true. На строках 145–158, если отсутствует generation row, уже есть provider_calls или неизвестный код ошибки, тоже нет гарантии последующей обработки. Нормальный native route GardnerCommerceController.php:113–116 использует Orders::check.

**Причина:** durable pending intent есть, но он не гарантирует работающего обработчика и пробуждения после снятия флага. **Решение:** единая state machine paid → queued → generating → QA → publish_pending → published → delivery, самостоятельный reconciler и watchdog всех оплаченных заказов, задокументированный SLA deferred услуги.
**Тест:** клиент оплатил и закрыл браузер; флаг deferred включён/выключен; очередь или воркер умерли; run отсутствует; два воркера; возобновление без повторных запросов к ИИ при неизвестном billing.

### DZ-C05. Уведомление владельца в MAX об оплате проходит мимо основного триггера [P0; К]

Код: Orders.php:122–125 выставляет test_sessions.payment_fulfilled_at внутри bank-approved транзакции. PaymentFulfillmentService.php:20–36 выполняет notifyPaymentAccepted только после успешного обновления, требующего payment_fulfilled_at IS NULL. GardnerCommerceController.php:113–116 не вызывает PaymentFulfillmentService вообще. В новом пути payment_fulfilled_at означает оплату, но не факт доставки MAX.

**Решение:** транзакционно создавать отдельные события owner_order_paid и generation_requested при смене банковского статуса; sender MAX с уникальным event key, статусом, retry и журналом принятия API. Не использовать payment_fulfilled_at как одноразовый флаг уведомления.
**Тест:** два подтверждения оплаты, MAX 429, рестарт sender, ошибка после DB commit; одно логическое событие сохраняется.

### DZ-C06. Уведомления MAX о начале/окончании теста нестойкие [P0; К+Д]

Код: TestController.php:119–129 немедленно вызывает notifyNewSession в web request, при исключении только report($e). Завершение TestController.php:253–265 вызывает email-job и fulfillment, но без прямого notifyTestCompleted. PaymentFulfillmentService.php:50–58 по неоплаченному тесту сразу выходит.

**Решение:** надёжные outbox-события started, completed, free_result_ready и checkout_created; никакие сетевые сообщения не должны блокировать завершение теста. Проверить, существует ли и работает отдельный подписчик на завершение вне этих 18 файлов.
**Тест:** MAX выключен, 429, 500, repeated POST, восстановление через несколько часов: каждое событие сохраняется с дедупликацией.

### DZ-C07. Старые обработчики start/ready/failed не оповещают владельца о native-генерации [P0; К+Д]

Код: UserNotificationService.php:24–31 native-ветка возвращается прежде notifyMaxGenerationStarted; строки 65–68 вызывают только GardnerCommerce/Outbox::ready и выходят до notifyMaxReportReady; 133–136 возвращаются до notifyMaxGenerationFailed. Возможен независимый R27 watcher, код которого пока не изучен.

**Решение:** единый набор owner-событий queued, provider_started, progress_checkpoint, stalled, QA_rejected, publication_blocked, published, customer_mail_pending, customer_mail_accepted. Отдельный MAX outbox, дедуп и rate control, а не просто progress scan.
**Тест:** каждый переход, потеря сети MAX, duplicates, reboot; событие сохраняется и имеет status receipt.

### DZ-C08. В новом потоке не завершена пользовательская верификация email [P0; К+Д]

Код: Orders.php:33–45 создаёт buyer без обязательного OTP до оплаты. UserNotificationService.php:26–29 требует verified_at IS NOT NULL для письма о начале native-генерации. BuyerAccess.php:58–77 поддерживает подтверждение email. GardnerCommerceController.php:59–71 содержит /verify-email, но в GardnerAccountPage.vue:1–148 нет интерфейса для вызова этого endpoint.

**Историческая связь:** прежний листинг GardnerCommerce/Outbox.php::ready также требовал verified_at; последнюю версию Outbox из текущего snapshot ещё надо извлечь. Нельзя объявить текущий ready-email блокер окончательно подтверждённым без чтения Outbox.

**Решение:** доступная кнопка безопасного подтверждения email в кабинете, сервисные уведомления с разграничением согласия, защищённые ссылки на отчёт, самостоятельные email retry. Нельзя просто снять проверку адреса и раскрыть детский отчёт случайному получателю.
**Тест:** paid buyer verified_at=null; прошёл OTP после генерации; SMTP упал; только подтверждённый адрес может восстановить доступ к данным.

### DZ-C09. HTTP завершения теста зависит от постановки письма в очередь [P1; К]

Код: TestController.php:201–249 сохраняет completed+scoring в транзакции, затем строки 253–254 и 301–307 вызывают dispatch вне try/catch. Сбой dispatch может вернуть 500, хотя результат уже записан; блок fulfillment обёрнут catch, а email dispatch — нет.

**Решение:** outbox intent внутри транзакции, отправку выполнять независимо; ответ клиенту должен отражать фактическую успешность сохранения, не доступность SMTP.
**Тест:** очередь недоступна в момент submit; completed сохранён, endpoint отвечает безопасно и retry уведомления работает.

### DZ-C10. После завершения/оплаты можно попытаться переписать ответы и вернуть статус in_progress [P0 integrity; К+Д]

Код: TestController.php:201–249 повторно upsert-ит ответы и ставит completed или in_progress на 238–247, без проверки предыдущего статуса и payment_status. routes/api.php:90–91 содержит throttle; дополнительный SubmitTestRequest.php вне текущих 18 файлов, поэтому полномасштабную эксплуатационную проверку ещё выполнить нельзя.

**Решение:** immutable snapshot ответов после completed и paid; повторные идентичные submit идемпотентны, изменяющие — блокируются и логируются, не меняя результат и JSON для генератора.
**Тест:** late batch, duplicate completed, paid answers changed, race. Контрольные SHA ответа и отчёта остаются прежними.

### DZ-C11. Родительское согласие обновляется по UUID без явной проверки владения [P1 security; К+Д]

Код: TestController.php:153–191 принимает session_id UUID и updateOrCreate consent без вызова ensureSessionAccessByPhone/BuyerAccess; routes/api.php:88–89 указывает throttle. Существование внешней авторизации нужно ещё проверить.

**Решение:** обязательное владение сессией, одноразовые/идемпотентные подтверждения, аудит consent, минимизация PII.
**Тест:** недопустимы обновления чужой сессии, корректная семья может подтвердить.

### DZ-C12. После подтверждения консультации кнопка ведёт на бесплатную страницу [P1 UX; К]

Код: GardnerAccountPage.vue:109 ведёт на /children/result/{id} с подписью «К моему отчёту». ResultPage.vue:742–743 разрешает платный режим только с параметром view=report и paid=true. Режим default — бесплатная страница (ResultPage.vue:51–58).

**Решение:** после оплаты ссылка «К моему отчёту» должна вести на /children/result/{id}?view=report. Отображение публикации и авторизация на сервере остаются обязательными.
**Тест:** paid+booking, paid+no booking, unpaid, unpublished, signed PDF.

### DZ-C13. Фронтенд скрывает сбои банковской сверки [P1; К]

Код: ResultPage.vue:1141–1150 подавляет ошибку POST /check-payment пустым catch и загружает результат. GardnerAccountPage.vue:68–76 прекращает автоматическую проверку после пяти попыток, не имея автономного backend-resume в этой части.

**Решение:** показывать разные состояния bank_pending, bank_unavailable, confirmed, conflict; не предлагать повторную оплату, если банк временно не отвечает; backend poll/reconciler обязателен.
**Тест:** timeout банка, подтверждение после паузы, закрытие браузера, неверный token.

### DZ-C14. Промокод сохраняется до успешного создания заказа [P1; К]

Код: GardnerCommerceController.php:95–108 сохраняет promo_code на TestSession до Orders::create и его Availability::assertCanSell. При блокировке sales/банка остаётся промежуточная мутация.

**Решение:** quote без побочного эффекта, фиксация quote и создания заказа в одной транзакции.
**Тест:** 503 bank/gate, concurrent promo, existing immutable order.

### DZ-C15. В production-коде зашиты данные конкретной исторической банковской операции [P0 release/security; К]

Код: Orders.php:86–95 содержит отдельный literal для UUID сессии, operationId и назначения реального платежа; Prices.php:42–54 содержит literal canary сессии и суммы. **Сами идентификаторы не воспроизводятся в GitHub и документе.**

**Решение:** только после проверки сохранности прав реального покупателя перенести исторический receipt в защищённый immutable DB или мигрируемый secret-free административный реестр; убрать конкретного человека из общих исходников и заменить fixtures синтетическими.
**Тест:** реальное право на оплаченный отчёт сохраняется; другой заказ не проходит по этому исключению; GitHub secret/PII scan PASS.

### DZ-C16. В logs могут попадать полные банковские ответы [P1 security; К]

Код: TochkaPaymentService.php:140–141, 259–261 и 460–462 логирует response->body. Реальное попадание чужих личных данных в текущий журнал не подтверждено, но код это допускает.

**Решение:** структурированные ошибки с редактированием, без raw response body, токенов и buyer PII; ограниченное сохранение только технических кодов и correlation IDs.
**Тест:** поддельный bank error с email/ключом в теле; лог не содержит исходных секретов.

### DZ-C17. Восстановление доступа до оплаты фактически недоступно [P1 UX; К]

Код: BuyerAccess.php:46 запрещает recover для неоплаченного заказа. GardnerAccountPage.vue:89–94 предлагает неоплатившему покупателю вернуться в тот же браузер. При потере sessionStorage покупатель может выпасть из воронки.

**Решение:** безопасная одноразовая email verification для неоплаченной completed session, строгая привязка к buyer/email и rate limiting; не выдавать ключ без проверки.
**Тест:** новая вкладка, новый телефон, неверный/просроченный код, изоляция двух семей.

### DZ-C18. Deferred sale может принимать деньги без согласованного срока выдачи [P1 business; К]

Код: Availability.php:22–28 допускает deferred checkout без рабочих provider/publication gates; GardnerAccountPage.vue:97,112 предупреждает об ожидании, но не определяет SLA или автоматическое действие при превышении срока.

**Решение:** согласованный срок подготовки, отображаемый статус и owner escalation. Не выдавать ложный «генерация уже идёт».
**Тест:** оплата + ожидание сверх SLA, уведомление владельца, отсутствие дубля платежа.

## 2. Сопоставление с журналами Вадима (6–8 ноября)

| Историческое наблюдение | Кодовый узел | Уровень уверенности |
|---|---|---|
| CURRENT_BLOCKERS=gardner_commerce_sales_enabled_disabled | DZ-C02 | прямая семантика gate в коде; текущее env не экспортировалось |
| Нет письма после бесплатного теста | DZ-C01 | return native job подтверждён |
| После оплаты требовалось ручное восстановление | DZ-C04 | deferred dispatch early return подтверждён |
| provider_transport_failed, billing_unknown | R27 supervisor / DeepSeek, не в текущих 18 файлах | логи ранее; исходный механизм проверить отдельно |
| 61/61 задач, 67 attempts, quality_rejected 51/181 | QA/editorial | только исторические логи |
| PUBLICATION_ENABLED=NO, PUBLICATION_POLICY=NOT_READY | publication gate, Outbox | нужен следующий экспорт |
| GARDNER_PROGRESS_SCAN, нет MAX receipt | DZ-C05/C06/C07 + systemd | хуки пропущены; фактический independent watcher проверить |

## 3. Следующий набор исходников — для завершения причинно-следственной цепочки

1. app/Services/GardnerCommerce/Outbox.php, GardnerCommerce/Publication.php, GardnerV22PublicationPolicy.php.
2. app/Services/AdminMaxNotificationService.php, MaxBotApiService.php, Console/Commands/GardnerCommerceTick.php, routes/console.php.
3. app/Jobs/GenerateGardnerV22ReportJob.php, GardnerV22PaidFlow.php, GardnerV22AttemptLedger.php, GardnerV22DeepSeekHttpTransport.php и R27 supervisor.
4. app/Http/Requests/SubmitTestRequest.php, StartTestRequest.php, config/gardner_commerce.php, config/queue.php, config/mail.php.
5. Безопасный обезличенный runtime: systemd service/timer status, pending owner/mail outbox counts, paid-without-generation statuses; не экспортировать ФИО, контакты или тексты детских отчётов.

## 4. Порядок исправлений, который не должен ломать работающий сайт

1. Сохранить хэш-ориентированный снимок и обратимый staging, не исправлять напрямую /var/www/bribon.
2. Добавить независимые durable события completed/paid/started/ready и контроль доступа, без изменения существующей JSON-логики баллов.
3. Исправить банк reconciliation/unknown state; нельзя слепо создавать вторую операцию.
4. Включить фоновые reconciler/worker/watchdog только после тестов идемпотентности и лимитов расходов.
5. Подключить verified email/ready outbox и MAX, не подменять принятый SMTP статус доказательством доставки.
6. Исправить ссылку paid report, состояние оплаты и recovery; проверить мобильный/десктопный путь.
7. Изолированный тест двух искусственных клиентов: test→result→checkout→paid→R27→QA→immutable HTML/PDF→email/MAX; утрата воркера, SMTP и MAX; отсутствие дублей.
8. Проверить legacy teen test и страницы, сохранить исходные данные и предыдущие опубликованные отчёты.
9. Только после прохождения тестов и secret/PII scan загружать безопасный код в GitHub main, затем отдельный staged production deploy/rollback.

**Фактические проверки:** серверный extractor сообщил FILES=18 MISSING=0 и SHA OK; текстовые блоки построчно полны; 17/17 статических code-path индикаторов найдены. **Не выполнялись:** PHPUnit, Vite, Playwright, реальная банковская проверка, отправка email/MAX, DeepSeek, production deployment. Указанные 17/17 не означают исправления.
