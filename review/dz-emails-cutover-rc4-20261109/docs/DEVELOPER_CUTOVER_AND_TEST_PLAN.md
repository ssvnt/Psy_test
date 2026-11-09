# RC4 — последовательная замена старых писем и обработчиков, без дублей

## Старые системы, которые необходимо сохранить до испытаний

- **Гарднер:** `TestController::submit`/`sendInterpretationPaymentEmailIfNeeded`, `SendTestInterpretationPaymentEmailJob`, `GardnerCommerce/Outbox::add/ready/deliver`, `GardnerCommerceTick`, `Orders::check`, `GardnerCommerce/Publication` и `GardnerAccountPage.vue`. В RC1 ранее добавлен `Outbox::freeOffer`; не запускать его параллельно с новым `test_completed_offer`.
- **Профориентация:** `TeenApprovedMailDelivery::sendIfReady`, `TeenApprovedReports::publication/content/links`, подростковый обработчик завершения и подтверждения оплаты. Источник финального teen email уже содержит `pending → sending → accepted_by_mailer/delivery_unknown`. Нельзя просто заменить на новый `Mail::send`.
- **Тестовые результаты:** scorer/JSON, 35 вопросов Гарднера и подростковый scoring не изменяются. Корректность результата определяется старым доменным слоем.

## Новый контракт

| Событие | Доказательство | Текст и кнопки | Условие отмены |
|---|---|---|---|
| `test_completed_offer` | completed + новое явное маркетинговое согласие + правильный email | Бесплатный результат, полезность полного отчёта, CTA в кабинет | Paid/pending/unknown, opt-out, старая сессия, ранее отправлено старым sender |
| `unpaid_12h` | неизменяемый `completed_at + 43200` | Напоминание, CTA покупки | Любой existing bank order, paid, refund/unknown, отписка и старый sender |
| `payment_confirmed` | уже проверенные банк, сумма, RUB, session binding | Подтверждение заказа и этапы подготовки; 24ч только если SLA доказан | Нет проверенного receipt, mismatch buyer, refund, старый email pending/sent |
| `report_ready` | QA PASS + owner approved + published HTML/PDF SHA + безопасный URL | **Две кнопки:** отчёт, консультация | Любой непроверенный артефакт, hash mismatch, older email reserved |

Сервисные сообщения не зависят от маркетингового согласия, но требуют привязки адреса покупателя и подтверждения оплаты. Реальный report/PDF не прикрепляется к письму.

## Реальный cutover в Laravel, требующий code review (не выполнен)

1. Создать отдельную приватную копию **действующей Laravel/Vue ревизии** с phpunit/vendor/node_modules staging и отдельной PostgreSQL; сохранить SHA всех старых Mailable/Jobs/Outbox/Controllers. В публичный `Psy_test/main` не копировать исходники клиентов, пароли и банковские операции.
2. Применить новые migrations **только к тестовой БД**. `dz_mail_journey_events`, `dz_mail_marketing_consents`, `dz_mail_test_completions`, `dz_mail_verified_payments` создаются независимо от исторических журналов. Rollback миграций отдельно от реальных писем/банка.
3. Подключить `LaravelJourneyHooks::recordMarketingConsent` только к старому authenticated consent endpoint, после реального подтверждения владельца сессии. Никакого автоматического backfill из старого `allow_communications`.
4. В **одной** completion-ветке детского теста после commit вызвать `Hooks::completed($session,'gardner')`. При включении canonical marketing для новых сессий отключить legacy `SendTestInterpretationPaymentEmailJob` и RC1 `Outbox::freeOffer` *только в этой ветке*. В старых сессиях не менять логику.
5. Аналогично вызвать `Hooks::completed($session,'teen')` в **реальном** подростковом completion-контроллере, который ещё требуется получить и хэшировать. Не вставлять в детский `TestController` за подростков.
6. Банковский callbacks: после transactionally verified `APPROVED` + operationId + amount + session binding вызвать `Hooks::verifiedPayment(...)` и сохранить proof hash. **Нельзя** вызывать из browser-success URL или `markPaid` без банковской квитанции. Отключить старое платёжное письмо только для новых canonical events после успешной записи intent. Сохранить legacy receipts.
7. После публикации Гарднера через `Publication::decide`/`Outbox::ready` вызывать `Hooks::reportPublished($session,'gardner')`, но только с успешными QA/HTML/PDF проверками. В контексте `LaravelJourneyContextProvider` проверяются опубликованные байты и hashes. На стороне teen переход после `TeenApprovedReports` и после проверки состояния `TeenApprovedMailDelivery`.
8. Добавить подписанный route сниппет из `laravel/routes/mail-journeys-review-only.php` в `routes/web.php` (в один middleware `web`); проверить CSRF, GET-only confirmation, POST opt-out, повторный POST и окончание подписи.
9. Зарегистрировать `DzMailJourneyDrain` в Laravel Console Kernel/commands и **не регистрировать второй cron**, пока не проверена периодичность старых тикеров. Запуск тестов `--send` только с `Mail::fake` и отдельной тестовой БД. Флаги `DZ_MAIL_RC4_*` по умолчанию выключены.
10. Проверить old-vs-new на 2 синтетических покупателях: **один реальный отправитель на одно событие**. Принятая новая задача должна быть единственным событием, старый mail-ledger нельзя обнулять. При сбое SMTP `delivery_unknown` требует reconciliation, не слепой resend.
11. Новую копию пользовательских писем сравнить со вложенным `DZ-Emails(1).zip` при его доступности. Пока тексты — предложения RC3; макеты старой рассылки не затрагивать.

## Проверки, обязательные перед GO

- PhpUnit/Laravel `Mail::fake`, fixed UTC clock, PostgreSQL Testcontainers/отдельная DB, гонка двух drain workers, crash после SMTP acceptance до SQL commit.
- Для обоих продуктов: завершение → правильный результат → CTA; +12h и отмена после paid/pending/unknown; банк 429/timeout; оплачено → service receipt без принуждения к покупке; ready → точные две CTA и approved hashes.
- Два email разных семей и изоляция доступа; token не попадает в email/test logs; старыми ссылками нельзя открыть чужой отчёт.
- GET unsubscribe от email prefetch не изменяет статус; подписанный POST прекращает маркетинг сразу; сервисный receipt после opt-out остаётся разрешённым.
- 24 часа: при флаге OFF письмо не обещает срок; при ON используется утверждённый SLA, владельцу отправляется эскалация при превышении; отсутствие слотов консультаций не блокирует письмо о готовности.
- Восстановление после падения worker/БД, `delivery_unknown` без автодублирования, unknown bank без повторного invoice.
- Регрессии teen approved route, Gardner native V2.2+R27, booking, пользовательская страница оплаты, сборка Vite, уведомления MAX. Зафиксировать результаты каждого теста, без ФИО/ответов ребёнка.

**Никаких команд `migrate`, `restart`, `send`, `env on` или `deploy` этот пакет автоматически не выполняет.**
