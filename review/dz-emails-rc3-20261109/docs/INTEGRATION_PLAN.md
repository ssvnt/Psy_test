# RC3 — план интеграции писем без нарушения продакшен-сайта

## Точка истины

Проект существует в двух формах: серверный Laravel/Vue (`/var/www/bribon`) и исторический Next.js в `ssvnt/Psy_test/main`. Они не совпадают. Разработчик должен работать с **версионированным отдельным приватным Laravel source mirror**; нельзя мержить Laravel-патчи поверх Next.js `main`.

## Контракты продукции и маршрутов

**Гарднер:** `TestController::submit` завершает тест, RC1 уже вводит `Outbox::freeOffer`, а в legacy `SendTestInterpretationPaymentEmailJob` есть ранний `return` для native V2.2. Не добавлять второй отправитель на `complete` и не создавать новый банковский счёт из письма. `Orders::check` — проверка оплаты через банкинг и запись заказов; `GardnerCommerce/Outbox::ready` — отдельное письмо готовности только после publication. Адрес и `buyer/verified_at` требуют согласованной схемы.

**Профориентация:** отдельный `/teen` клиентский путь, R27 `TeenApprovedMailDelivery` и отчётные артефакты; существующие email-обработчики/триггеры должны быть просмотрены по текущему SHA до врезки. Не переносить туда 35 вопросов, 8 интеллектов или JSON Гарднера. Не считать `gigachat_status=done` доказательством публикации R27.

**Общий event router** возможен только на уровне фактов: `product + session + event_kind + order/report_version`. Контент и destination URL обязан разрешать продуктовый backend adapter. Путь `/children/account/{id}` сам по себе не гарантирует доступ на другом устройстве. Ни один Mailable не должен содержать банковский operationId, детские ответы и access token.

## Outbox и cron

Требуется отдельная миграция/расширение схемы (не включено): `(product, session_id, event_kind, version)` уникально, durable intent, `scheduled_at`, `attempts`, `status` (`pending, sending, accepted_by_mailer, delivery_unknown, suppressed`), `consent_proof_ref`, `report_approval_ref`, `linked_order_ref`, timestamps. При планировании и **повторно непосредственно перед отправкой** запускать `MailJourneyPolicy::evaluate()` на транзакционном снимке. Сервисный sender отправляет только после commit, idempotence по event_key. При SMTP unknown не повторять вслепую: отдельно reconcile transport receipts.

- `test_completed_offer`: после commit теста. Не блокировать API завершения ошибкой email. Допускается только explicit marketing consent; `unsubscribe` должен действовать.
- `unpaid_12h`: `completed_at+43200` по UTC, cron каждые 5 минут, без принудительного старого backfill; не отправлять до due, после `paid`, `pending`, `creating`, `creation_unknown` или `ever_paid`, с остановленным согласием. Не создавать заказ. Всегда перепроверять банковский статус при неопределённости.
- `payment_confirmed`: ТОЛЬКО после сверенного `approved` у банка по сумме/валюте/операции; сервисное письмо не зависит от доступности DeepSeek, QA и календаря. Копия `24h` только после проверки фактического исполнения SLA; иначе `status_only`, не молчание.
- `report_ready`: только когда закончен QA, owner review, проверены HTML/PDF SHA, опубликована точная версия, работает защищённый URL и есть маршрут записи на консультацию. При нуле слотов отправлять письмо всё равно, давая доступ к кабинету консультации; статус `no slots` показывать там.

## Интеграция с кодом без повторной отправки

1. Создать immutable normalized event contract; **не** запускать новый Mailable в `TestController` параллельно со старым job.
2. Перенести legacy/RC1 `free_offer` под один канонический event key или под existing native outbox, сохранив доставку для старых клиентов согласно согласиям.
3. В teen проверить актуальную ветку завершения и письма до подписки на completion: добор 12h не должен дублировать старое письмо с платежной ссылкой.
4. Разнести маркетинговые и сервисные типы и шаблоны; отдельные категории consent.
5. Обеспечить безопасные account URLs, не включающие токены; если пользователь потерял ключ до оплаты, показать реальное восстановление вместо тупика.
6. Добавить test adapters: no bank calls, Mail::fake, fake MAX, internal test URLs, fixed clock, две искусственные семьи. Сохранить score/JSON/данные.
7. Никакого переписывания исторических email-статусов. Для старых `sent` и `delivery_unknown` только reconciliation; не пересылать по умолчанию.
8. Сначала интеграционные Laravel/Pest/PHPUnit и 5 viewport; только затем staged activation по новым session IDs; контролировать owner MAX и SMTP receipts.

## Предохранители включения

Рекомендуемые feature flags, исходно `false`:

- `DZ_MAIL_RC3_ENABLED=false`
- `DZ_MAIL_MARKETING_GARDNER_ENABLED=false`
- `DZ_MAIL_MARKETING_TEEN_ENABLED=false`
- `DZ_MAIL_12H_FOLLOWUP_ENABLED=false`
- `DZ_MAIL_24H_SLA_TEXT_APPROVED=false`
- `DZ_MAIL_SERVICE_GARDNER_ENABLED=false`
- `DZ_MAIL_SERVICE_TEEN_ENABLED=false`

Одно глобальное `true` не включает всё: отдельные product-specific canary, новое согласие, rollout cutoff и тестовый транспорт. Формальный release gate — матрица `docs/TEST_MATRIX.md`.
