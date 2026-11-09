# Интеграционная передача RC2 — СТРОГО STAGING

## Что уже действительно протестировано

`src/dz_rc2`: автономный Python-прообраз классификатора ошибок, реестра QA-полей и хэшированного журнала событий/попыток в SQLite. **58 исполняемых локальных Python unit/integration тестов PASS, 20 PHP-контрактных проверок PASS** на синтетических данных; из них тесты сохраняемости SQLite, двух клиентов одного файла, восстановления после reopening, бюджета и заморозки 61 checkpoints. Это не Laravel PHPUnit, не Vite, не GigaChat/DeepSeek и не интеграция с рабочей БД.

### Границы и запреты

- Никакого запуска `artisan migrate`, `queue:work`, `schedule:run` или HTTP-запросов банка/LLM/SMTP/MAX против production.
- Никаких изменений `.env`, `app/`, `resources/`, `config/` и `/opt/` работающего сайта.
- Модель-классификатор **сама не авторизует платных повторов**. Для `billing_unknown` требуется внешнее доказательство. В прототипе метод `billing_proven_no_charge` принимает уже проверенный fingerprint; в Laravel-адаптер его нельзя подключать, пока источник независимого подтверждения/проверка подписей не реализованы.
- Языковая редактура выполняется только при реальном scanner receipt `humanizer-ru` + `pishi-normalno`. Фиктивные тестовые сигналы PASS не подменяют production.
- Не добавлять исходные данные ребёнка, операции банка, адреса родителей в commit, Google Drive, MAX и диагностику.

## Порядок реальной интеграции в Laravel (будущий PR)

1. В отдельной копии сайта проверить текущую схему `gardner_v22_generations` и статусные значения, уникальные ключи для per-field repairs, `gardner_v22_provider_attempts` и outbox. Для RC2 требуется обратимая миграция `qa_repair_tasks`, `qa_repair_revisions`, `qa_repair_events` и статусы без нарушения старого `failed`/`done`.
2. Подключить отдельные очереди с `afterCommit`/idempotent event keys; генератор пишет raw и фактический checksum до постановки языкового QA. Подписчик QA не может менять source JSON или банковский order.
3. Снять синхронную зависимость от scanner `quality->audit(probe)` **только после** утверждения нового gate: при scanner unavailable генератор продолжает сохранять raw и ставит `QA_WAIT`, но приватная сборка/публикация не трактуются как ready.
4. При `quality_rejected` не выставлять terminal failed. Записать durable request `QA_REPAIR_PENDING`; запуск остальных независимых частей не блокируется. Перегенерация всего отчёта из-за поля запрещена.
5. `LOCAL_REPAIR` одну попытку, затем `GigaChat editor` отдельным ledger, потом при доказанном бюджете `one-field generation`, затем approved fallback или owner-attention. Не выполнять автоматическое изменение политик тарифов и SHA нормализатора.
6. Подключить реальные scanners. Только после factual/structure/language PASS и ручного owner review разрешить версионированный HTML/PDF; native и R27 должны согласовать один канонический путь публикации.
7. MAX owner outbox: milestone, request/retry, exact transport status, unknown hold, персональная квитанция каждого администратора и anti-spam; почта клиента — независимая доставка.
8. После E2E на 2 синтетических заказах, replay инцидента 106 и проверки неизменности 105, teen R27 — staging deploy, дублирующий prod реальные флаги OFF, и только затем approval release.

## Рекомендуемый набор E2E (все на фейковых сервисах)

- 300s HTTP timeout vs утверждённое desired 600s, сетевой обрыв перед write и после отправки, header/content-length/SSE partial.
- HTTP 429 с гарантией no-charge и без неё; 401/403 configuration; 502/503/504.
- `BILLING_UNKNOWN` с сохранённой частичной копией: replay, отсутствие второй тарификации; crash после raw-save до ledger-commit.
- provider hard cap before generation + mid-run, exhausted editor cap; использование approved text bank; нет автоповышения budget.
- 61 checkpoints immutable; 51 QA field fail создают 51 задач; после 48 локальных исправлений 3 переходят редактору; другие части продолжают собираться.
- Провал editor, два воркера одновременно, restart worker/lease timeout, retry after clock jump, SQLite/DB down.
- Humanizer-ru/pishi-normalno реальные сканеры: PASS/FAIL/timeout/version mismatch; генерация не простаивает на ожидании стиля.
- Фрагмент с отрицанием/числом: редактирование не должно менять 5 в 6, 82% в 83%, «не» на отсутствие «не».
- Проверка старого JSON scoring и 8 шкал, не меняя реальный source.
- Проверка полных HTML/PDF на приватном URL, owner approval, mail accepted vs delivered и MAX statuses; не публиковать draft.
- RC1 regression suite, консалтинг, teen, GDPR/children data, mailbox unknown.

## Решение по состоянию на 2026-11-09

**RC2-POLICY-PREVIEW / STAGING-ONLY.** Прототип и 56 офлайн-тестов существуют и проверены. Текущий Laravel/Vue сайт не менялся; миграции, рабочий GigaChat editor, humanizer subprocess на исходных 51 замечаниях, E2E, Laravel PHPUnit и системы очередей ещё не испытаны. Продавать на этой основе как «готовое исправление» нельзя.
