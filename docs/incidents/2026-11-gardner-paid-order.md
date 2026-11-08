# Добрый Жук — инцидент платного отчёта Гарднера (обезличенный P0-аудит)

**Режим:** черновик до production release. **Статус: NO-GO.** Клиентские данные, UUID, банковские реквизиты и тексты отчётов в этот публичный репозиторий не загружались.

Основание: сохранённые журналы 6–8 ноября 2026 года и контрольный манифест исходников от 8 ноября (1 730 файлов сайта, 70 файлов оркестратора). Архивная версия GitHub на Next.js не совпадает с действующим Laravel/Vue: этот документ НЕ обозначает синхронизацию работающего сайта с GitHub.

## Трасса заказа (без персональных идентификаторов)

1. Бесплатный тест завершён, но покупатель не увидел пригодную ссылку на оплату. На этапе проверки продажи сервис возвращал `CURRENT_BLOCKERS=gardner_commerce_sales_enabled_disabled`. После временного снятия флага: `UNIVERSAL_CHECKOUT_PREFLIGHT=PASS`.
2. Позднее платёж был подтверждён: `ORDER=paid`, отложенный dispatch создан, `payment_confirmed:sent`. Последний статус письма означает принятие почтовым транспортом, НЕ доставку покупателю.
3. Генерация прерывалась: `provider_transport_failed`, `billing_unknown`. В момент проверки воркер был `inactive`, recovery-файл отсутствовал, одноразовый установщик отклонял несовпадающий SHA `UNEXPECTED_SITE_VERSION_...DeepSeekProvider.php`.
4. Генерация была затем завершена: **61/61** задач, **67** попыток. Последующий языковой QA завершился `FAIL`: **51/181** полей, главным образом `unicode-dotted-letter`, дополнительно hard-ban humanizer.
5. В позднем снимке `GENERATION=done`, но `PUBLICATION_ENABLED=NO`, `PUBLICATION_POLICY=NOT_READY`; outbox содержал письмо об оплате, но не `report_ready`. Поэтому утверждать сквозную выдачу отчёта нельзя.
6. Прогресс-таймеры MAX выдавали `GARDNER_PROGRESS_SCAN` без доказанной доставки владельцу. Попытка записать документацию в `/root/dz-docs` закончилась `No such file or directory`, а последующее `ARCHITECTURE_UPDATED=YES` было ложным подтверждением.

## Реестр блокеров и тесты для каждого маршрута

| ID | Пользовательский/владельческий этап | Точки в действующем Laravel/Vue | Требуемое исправление | Минимальный тест |
|---|---|---|---|---|
| C-01 | Бесплатный результат → платёжная ссылка | `GardnerCommerce/Availability.php`, `Orders.php`, `PaymentController.php`, `GardnerCommerceController.php`, `ResultPage.vue`, `GardnerAccountPage.vue` | Явный backend checkout state + повторное получение одной order-bound HTTPS-ссылки; UI не прячет 503 и 409 | две новые сессии, 2 клика, bank timeout, закрытый sale gate |
| C-02 | Завершение → письмо с предложением отчёта | `TestController.php`, `SendTestInterpretationPaymentEmailJob.php`, `UserNotificationService.php` | Durable outbox после completed, отдельное согласие на маркетинг, bounded retry | no-consent, SMTP down, duplicate submit |
| C-03 | Оплата → запуск отчёта | `GardnerCommerce/Orders.php`, `GardnerV22PaidFlow.php`, `GardnerCommerceTick.php`, R27 paid bridge | DB-атомарный генерационный intent, идемпотентный reconciler вне браузера | повтор webhook, закрыт браузер, падение между paid и queued |
| C-04 | Долгая генерация | `GardnerV22DeepSeekHttpTransport.php`, `GardnerV22AttemptLedger.php`, `GenerateGardnerV22ReportJob.php`, systemd | durable receipt до decode, lease fencing, watchdog, неизвестный биллинг резервируется; retry только с политикой | kill -9, timeout, SSE partial, two workers, no double charge |
| C-05 | QA/редактор | `GardnerV22TextNormalizer.php`, `GardnerV22TextQuality.php`, `GardnerV22EditorialPipeline.php`, humanizer/pishi scanner | адресное исправление языковых ошибок, сохранение принятых checkpoint, без бесконечных повторов | 51 проблемное поле, неизменность шкал/JSON, budget cap |
| C-06 | HTML/PDF → публикация → письмо | `GardnerCommerce/Publication.php`, `GardnerV22PublicationPolicy.php`, `GardnerCommerce/Outbox.php`, `GardnerAccountPage.vue` | immutable artifact receipt, validated HTML/PDF, защищённый доступ, отдельный reliable outbox | QA fail, publication off, wrong hash, no verified email, SMTP outage |
| O-01 | MAX: клиентский путь | `AdminMaxNotificationService.php`, `MaxBotApiService.php`, `GardnerCommerce/Outbox.php` | изолированный MAX-outbox + dedup per event revision | completed, paid, started, error, ready, duplicate |
| O-02 | MAX: незакрытая ошибка | R27 owner progress watcher, supervisor, systemd | SLA watchdog всех незавершённых paid, конкретный код/следующий шаг | остановка worker, отключён timer, 429 API |
| O-03 | Достоверность операций | installer, deploy scripts, docs | check→stage→test→activate, контроль SHA и проверка успешной записи | mismatch SHA, missing dir, disk full, rollback |

**Статус причин:** `paid`, `provider_transport_failed`, `billing_unknown`, `quality_rejected`, `publication disabled` подтверждены журналами. Причины отсутствия письма *после бесплатного теста* и отсутствия MAX требуют отдельного чтения live outbox и конфигурации: отсутствие письма как факт указано владельцем, но receipt этого типа отсутствует в имеющихся журналах.

**Особый риск:** в просмотренном коде `GardnerCommerce/Outbox.php::ready()` использует `whereNotNull('verified_at')`, при том что обязательный OTP до оплаты был отключён. Если у покупателя `verified_at=NULL`, письмо готовности вообще не создастся. Это условие необходимо проверить; просто убрать email verification без защищённого кабинета нельзя.

**Примечание по публикации:** в просмотренном `Publication.php::decide()` есть файловые `put/write` внутри SQL-транзакции. Это *риск*, а не доказанный сбой: DB rollback не отменяет запись файлов.

## Критерии GO

- 100% оплаченных заказов имеют durable processing intent и проверяемый следующий шаг.
- Два разных синтетических клиента прошли test→free result→order→paid callback→R27→QA→HTML/PDF→protected access→mailer/MAX без ручных шагов.
- Все состояние/файлы привязаны к одному `generation_identity`, SHA и владельцу.
- Повторный платёжный callback, retry/worker reboot и SMTP/MAX сбой не создают дубль и не теряют заказ.
- Клиент видит статус в кабинете независимо от доставляемости письма; нет доступного без авторизации PDF.
- Сборка / тесты / миграции / политика release / откат проверены на staging.
- Исходный серверный код и GitHub commit имеют совпадающий release manifest, без секретов и приватных данных.

**Изменений на production пока нет.** Код сайта не заменять из старых hotfix ZIP и не делать force-overwrite SHA без сравнения текущей ревизии.
