# «Добрый Жук» — RC1 + RC2: сводная передача разработчику и ответственному за QA

**Дата:** 9 ноября 2026 года  
**Версия:** `DZ-GARDNER-RC1-RC2-HANDOFF-v1.0`  
**Результат ревью:** **RC2 OFFLINE PASS / CODE REVIEW READY / LARAVEL INTEGRATION NOT DONE / PRODUCTION NO-GO**  
**Источники доказательств:** архив `DZ_GARDNER_RC2_QA_V11_STAGING_ONLY_20261109.zip`, SHA-256 `edbdb45dcdaa5c225a1f477511c572a3b6124649e88fde97aaa253826217fe54`; серверный вывод PowerShell/SSH 09.11.2026; серверный read-only snapshot 08.11.2026 (18 checkout + 18 generation + 21 delivery + 64 orchestration file extracts); ревью RC1 и сводный аудит P0 v1.1.

> **Интерпретация статуса.** RC1 содержит кандидатные замены отдельных файлов сайта, но не был внедрён. RC2 содержит исполняемый *алгоритмический прототип* и контракты двух чистых PHP-классов, но **ещё не подключён** к реальному Laravel, PostgreSQL, очередям, DeepSeek, GigaChat, рассылке и сборке отчётов. «Тесты пройдены» относится только к синтетическим офлайн-испытаниям. Не выдавать клиенту отчёт и не открывать продажи, полагаясь на этот статус.

## 1. Где искать исходные материалы

| Материал | Точное место | Назначение |
|---|---|---|
| RC1 review | `review/gardner-rc1-20261109/` в GitHub `review/dz-gardner-rc1-20261109` | SHA-anchored 29 точечных замен в 13 файлах, 4 новых файла, тесты и staging builder |
| RC1 staged output (на сервере) | `/root/dz-gardner-rc1-staged-20261109/{baseline,candidate}` | Изолированные копии, не подключённые к production |
| RC2 review | `review/gardner-rc2-20261109/` в новой review-ветке | Полный неизменённый исходный RC2 ZIP, разложенный на 28 элементов (26 файлов манифеста + 2 метафайла) |
| RC2 server staging | `/root/dz-rc2-review/DZ_GARDNER_RC2_20261109` | Прототип Python, PHP-контракты, синтетические тесты |
| RC1/RC2 handoff | Этот файл в GitHub и приватной папке Google Диска | Граница готовности, корневые причины, матрица работ и тестирования |
| P0 аудит v1.1 | Google Drive, папка «Добрый Жук — P0 аудит и релиз 2026-11-09» | 18 дефектов checkout и 25 дефектов/рисков второго этапа |

**Важная особенность репозитория:** `ssvnt/Psy_test` — публичный GitHub-репозиторий с историческим **Next.js** кодом в `main`. Настоящий сайт `/var/www/bribon` — **Laravel/Vue**. Текущую RC1/RC2 review-ветку нельзя сливать в `main` и нельзя считать канонической веткой продакшена. После secret scan необходим отдельный приватный Laravel-репозиторий/ветка для реального интеграционного патча.

## 2. Что действительно подтвердилось на сервере 9 ноября

**RC1:** `DZ_RC1_PREFLIGHT_PASS`, 13 исходных SHA и 29 точных anchors PASS; `DZ_RC1_STAGED`, добавления 4, `live_writes=0`. 11/11 структурных тестов и 11/11 синтетических проверок безопасной R27-ссылки прошли. `manifest_sha256=8d272c6c09512edc2e82230da2ff13f783c65a7283fb5ab1e49a304115da43bf`.

**RC2:** удалённый ZIP присутствует и распакован (`SSH_CONNECTION_OK`, `RC2_ARCHIVE_PRESENT`, `RC2_EXTRACTED_OK`). Затем `bash scripts/run_offline_tests.sh` завершился `RC2_OFFLINE_QA_AND_RECOVERY_TESTS=PASS`, **58 Python-тестов**, **20 PHP-контрактных кейсов** PASS; 25 cross-runtime случаев входят в Python suite, **не прибавлять их к 58**. Два PHP-файла проходят `php -l`. `RC2_OFFLINE_TESTS_PASS`, `RC2_HOST_PREFLIGHT_PASS`, `live_site_writes=0`, вызовы банка, LLM, SMTP, MAX = 0.

**Независимая контрольная проверка файла в этом сеансе:** размер ZIP `58 615` байт; внешний SHA-256 совпал; ZIP читается без ошибок; все 26 позиций `SHA256SUMS.txt` подтвердились; повторный офлайн-запуск `bash scripts/run_offline_tests.sh` также завершился exit code 0. Автоматический скан по шаблонам приватных ключей, API-токенов, email и реальных банковских идентификаторов не выявил совпадений, но это **не эквивалент полноценного security review**.

**Состояние systemd на момент пользовательского журнала:** `dz-gardner-r27-queue.service` — `active/running`, `NRestarts=7`; `bribon-gardner-v22-queue.service` — `active/running`, `NRestarts=24`. Таймеры `dz-gardner-r27-paid`, `dz-gardner-r27-finalize`, `dz-gardner-r27-one-retry`, `dz-gardner-commerce` — `active/waiting`. Диск: `/dev/vda3`, 40 ГБ, занято 31 ГБ (82%), свободно 6.8 ГБ. **Причины 7 и 24 перезапусков из счётчиков не следуют**: проверить `journalctl`, exit statuses, watchdog и oom/disk signals, не экспортируя детские ответы/токены.

**Исправление описки:** файл `docs/RC2_INTEGRATION_HANDOFF.md` в последнем абзаце пишет «56 офлайн-тестов», хотя его же начало и фактический stdout подтверждают **58 Python + 20 PHP**. Не редактировать опубликованный пакет с пересчитанными hashes молча; в новом Laravel PR устранить неточность, приложить новый manifest.

## 3. Архитектурный контракт и фактический источник ошибок

Пользователь уточнил приоритет **QA v1.1**: **смысловая/субъективная оценка интерпретаций отключена**. При этом обязательны защита исходного скоринга восьми шкал и чисел, соответствие исходному JSON, сохранение структуры и неизменяемой идентичности, техническая корректность, реальный `humanizer-ru` и `pishi-normalno`. Редактор GigaChat правит **только проблемный текст**, без изменения фактов, отрицаний, чисел, структуры, других полей. Первичная модель генерации — DeepSeek. Резервные тексты допускаются лишь из утверждённого банка и после той же критической проверки. Черновики и отчёты с недоказанной целостностью **не публикуются**.

Исходная причина остановки Гарднера подтверждена кодом:

| Симптом | Подтверждающая часть действующего исходника | Правильное исправление |
|---|---|---|
| Таймаут/обрыв | `GardnerV22DeepSeekHttpTransport.php:29` — `connectTimeout(10)->timeout(300)`; `:41–54` stream errors | Раздельно классифицировать connect, wall-clock, stream incomplete, 429 и 5xx; не перезапускать неизвестно тарифицированный request без доказательств |
| Превышение лимита | `GardnerV22AttemptLedger.php:27–37,51–95` — policy, reserve, request/token/cost caps | Сохранить абсолютные лимиты, сделать `FALLBACK_OR_OWNER` и отдельный ledger редактора, не обнулять reserved/unknown |
| Стиль останавливает весь заказ | `GardnerV22NativeGenerator.php:118–125` — финальный `quality.json`, затем `quality_rejected` | Все checkpoints сохранить; создавать задачи по полям, сначала локальная нормализация, далее GigaChat редактор, затем адресный regen/fallback/owner |
| Локальный fail превращается в run failed | `GenerateGardnerV22ReportJob.php:79–93` — catch `status=failed`, rethrow | `QA_REPAIR_PENDING` ортогонально генерации, не подменяя критические ошибки целостности |
| R27 finalizer ждёт complete | `/opt/dz-gardner-r27-live/server/finalize.py:105–109` — требует `status='done'` | Разделить `primary_chunks_ready`, `language_qa`, `private_render`, `owner_approved`, `customer_published` |
| Клиентский отчёт дополнительно блокируется | Laravel `GardnerCommerce/Outbox.php`, `GardnerV22PublicationPolicy`, внешний R27 review/delivery | Один канонический путь выдачи с проверкой оплаченности, source hash, owner approval и HTML/PDF; уведомления отдельными outbox |

**Историческое свидетельство сессии 106 (из ранее приведённых журналов, не из live PostgreSQL в этой проверке):** 61/61 saved chunks, 67 provider attempts, 51/181 языковых QA-полей требует исправлений. Полная перегенерация 61 фрагмента запрещена. Сессия 105 не затрагивается. **Не путать:** `27/27`, `supervisor104.py`, `EDITOR_BILLING_RECONCILIATION_REQUIRED` относится к отдельному пилоту/процессу профориентации, не доказывает состояние детской сессии 106.

### 3.1 Целевая непрерываемая цепочка

```text
paid (verified) → generation intent → checkpointed DeepSeek chunks
      ├→ per-chunk language scan → accepted / qa_repair_tasks
      ├→ independent chunks continue even if one language field fails
      └→ primary_chunks_complete

qa_repair_tasks → LOCAL_REPAIR (bounded) → real language recheck
  → GigaChat EDITOR (separate ledger, precise fields, bounded spend)
  → FIELD_REGEN (only changed field; authorized ledger)
  → VERIFIED_FALLBACK (approved text bank, same guards)
  → OWNER_ATTENTION_REQUIRED (if no safe automatic path)

when all critical guards and language QA pass → versioned private JSON+HTML+PDF
  → manual owner review of exact SHA → publish → buyer account access
  → independent email/MAX delivery receipts, retry/reconcile/owner alerts
```

При ошибке сканера его временная недоступность не должна отменять сохранённую генерацию, но и **не означает QA=PASS**. Неизвестный биллинг может остановить *конкретный финансовый запрос* до reconciliation; независимая работа продолжается. Публикация всегда fail-closed.

## 4. Что содержит RC2 (код и тесты)

- `src/dz_rc2/policy.py`: чистый классификатор по типизированным `Evidence`/`Decision`, действия `RETRY_LOCAL`, `RETRY_PROVEN_FREE`, `REPLAY_VERIFIED_RECEIPT`, `RECONCILE_UNKNOWN_BILLING`, `FALLBACK_OR_OWNER`, `WAIT_EXTERNAL_SERVICE`, `OWNER_ATTENTION`, `ENQUEUE_QA_FIELD_REPAIRS`. Выдача действия **не запускает API** и **не авторизует billable retry**.
- `src/dz_rc2/quality.py`: parser quality receipt, проверка наличия имён двух scanners и SHA формата, idempotent task IDs, только `expert_####` поля; локальная Unicode-нормализация и простые numeric/negation locks; проверка неизменности других полей и Report JSON.
- `src/dz_rc2/store.py`: учебная SQLite модель durable чекпойнтов, identity/hash bindings, per-chunk QA, retries/leases, single-claim, event key, reserve budget и unknown hold, отсутствие разрешения на публикацию.
- `src/dz_rc2/simulator.py` и `fixtures/QA_106_SYNTHETIC_181_51_FAIL.json`: **только синтетический** пример 61 chunks / 181 fields / 51 tasks. Не содержит фактических результатов ребёнка.
- `php/App/Services/Children/V2/GardnerV22/Recovery/GardnerRc2StopPolicy.php`, `GardnerRc2QualityPlan.php`: чистые PHP-контракты для будущего Laravel adapter; не пишут в БД.
- `tests/test_classifier.py`, `tests/test_quality.py`, `tests/test_persistence_integration.py`, `tests/test_contract_parity.py`, `tests/test_php_policy.php`, `tests/php_policy_json_cli.php`: 58 Python + 20 PHP и 25 parity scenario (в составе Python).
- `architecture/Dobry_Zhuk_Report_Orchestrator_Architecture_RECOVERED_v1.1.md`: восстановленные из предоставленного текста разделы 18/19 и детализированные требования; **не** копия пропавшего серверного файла и **не** притворяется разделами 1–17.

### 4.1 Примеры реально пройденных проверок

| Группа | Фактические тесты/утверждения | Защищаемый инвариант |
|---|---|---|
| Ошибки | `test_transport_after_send_is_unknown`, `test_stream_incomplete_is_unknown`, `test_timeout_bound` | Не обещать бесплатный повтор после потенциального списания |
| Rate limit | `test_http_429_not_assumed_free`, `test_http_429_verified_no_charge`, `test_http_429_limit` | 429 сам по себе не доказывает `not_charged` |
| Финансы | `test_budget_is_hard`, `test_budget_and_unknown_protected`, `test_billed_result_cannot_be_marked_free` | Лимит не повышается, billed не переклассифицируется самопроизвольно |
| QA | `test_181_fields_51_tasks`, `test_per_chunk_qa_does_not_wait_for_remaining_chunks`, `test_qa_fail_queues_tasks_instead_of_failed_order` | Ошибки становятся задачами, не теряются checkpoints |
| Конкуренция | `test_concurrent_claim_distinct_tasks`, `test_expired_local_lease_is_not_unlimited`, `test_restarts_durable` | Нет одновременной работы над одним полем и бесконечных повторов |
| Идентичность | `test_immutable_identity`, `test_immutable_chunk_replay`, `test_chunk_qa_hash_must_match_accepted_checkpoint` | QA относится к сохранённым байтам/объектам, не к другой версии |
| Язык и факты | `test_local_unicode_only`, `test_numeric_lock`, `test_negation_lock`, `test_protected_other_fields` | Локальная обработка и редактор не меняют явно фиксированные признаки |
| UI/публикация | `test_no_publish_if_all_qa_tasks_accepted_standalone`, `test_full_qa_pass_cannot_be_invalidated_by_late_chunk_patch` | Не трактовать локальный QA как разрешение выдать отчёт клиенту |
| Платформы | `test_php_and_python_actions_match_synthetic_incidents` + PHP-контракты | Совместимость действий классификатора для заявленных кейсов |

**Статус покрытия:** Python SQLite integration tests — действительно исполнялись с временной локальной БД; это **не Laravel integration**, не PostgreSQL migration test, не deployment/production smoke.

## 5. Невыполненное и необходимые исправления до интеграции

1. **Реальные humanizer-ru и pishi-normalno ещё не запускались** на историческом `quality.json`; parser лишь проверяет названия в *предъявленном* JSON. Продакшен адаптер должен проверять происхождение, версию, формат, SHA и фактический запуск сканера, а не доверять строке `scanners_executed`.
2. **Подтверждение отсутствия списания в прототипе не самодостаточно.** `store.billing_proven_no_charge(request_id,evidence_sha)` проверяет синтаксис SHA, но не удостоверяет независимую квитанцию провайдера. Это контракт для внешнего проверенного reconciler, **не** метод для прямого подключения к настоящему биллингу. Требуется криптографически/операционно проверяемое подтверждение, immutable source и авторизованная транзакция.
3. **`local_result` получает `scanner_pass` и `guards_pass` от вызывающего кода.** В production только подписанный/хэш-привязанный receipt от реально выполненных scanner+fact guard может подтвердить acceptance. Игнорирование этого — возможность фиктивного PASS.
4. **Нормализация с числовыми и отрицательными словами — ограниченный guard.** Regex/строковая эквивалентность не является полной семантической проверкой всех форм русского языка; отдельно тестировать числительные, интервалы, исключения и отрицательные конструкции. Текущая политика исключает субъективную проверку психологической «глубины», но фактические invariants сохраняет.
5. **GigaChat editor, отдельный Laravel attempt ledger и вызовы DeepSeek ещё не подключены.** Нельзя включать бюджеты/ретраи только по алгоритмическому тесту.
6. **Нет реальной интеграции с Laravel:** нет миграций `gardner_v22_qa_tasks`/`qa_revisions`, адаптера к PostgreSQL, job scheduling, `afterCommit` outbox, канонической публикации, HTML/PDF переподготовки, безопасной переделки `quality_rejected` ветки.
7. **Нет доказанной гарантированной доставки MAX.** Нужен owner outbox с recipient-level receipts и `SENDING_OUTCOME_UNCERTAIN` pause; не повторять неизвестный отправленный MAX автоматически, не спамить историческими paid-событиями.
8. **Состояние очередей:** `NRestarts=7/24` требует анализа `journalctl` и действительных `systemctl cat`/`ExecStart`/memory/timeout; активный воркер не доказывает корректное продвижение конкретного заказа.
9. **Не согласован канонический owner publication/delivery flow native vs внешний R27.** Не создавать второй «готов» статус, дублирующее SMTP-письмо или общедоступный child report.
10. **Не проверены реальные 51 историческое замечание, их классы и план нормализации.** Во избежание утечки данных ребёнка выгружать с production только обезличенные типы ошибок и хэши; сами тексты обрабатывать в закрытом staging.

## 6. Пошаговый план разработчика (отдельные PR, release gates по умолчанию OFF)

**PR-RC2-1 — источник истины и модель данных.** Импортировать действующий Laravel/Vue без секретов в приватный репозиторий, создать isolated test DB. Утвердить schema с отдельным `qa_state` и неизменяемым run `generation_identity`, hashed `field_key`, `base_sha`, `policy_sha`, immutable revision receipts, `lease_token`, `next_at`; не менять scoring и не переписывать исторические paid attempts. Обратимая миграция только на staging.

**PR-RC2-2 — stop taxonomy + durable scheduler.** Подключить PHP-классификатор к реальному `GenerateGardnerV22ReportJob`/recovery tick, отделить локальные/внешние/budget/QA/integrity/unknown-billing состояния. После worker failure записывать reason code, event, retry_at и bounded owner_attention. Не возобновлять запросы с неизвестной оплатой без receipt. Перепроверить 300с timeout и сервисные лимиты: изменение самого `timeout` не заменяет правильный checkpoint/retry.

**PR-RC2-3 — per-field QA и editor.** Из проверенного сохранённого checkpoint запускать реальные сканеры; `DETECT → CLASSIFY → LOCAL_REPAIR → RESCAN → EDITOR_GIGACHAT (bounded) → RESCAN → FIELD_REGEN (separately authorized) → VERIFIED_FALLBACK → OWNER_ATTENTION`. Персональные факты, 8 шкал, числовые оценки и отрицания неизменны. Модель редактора не имеет права заменять отсутствующие поля, структуру JSON или соседние sections.

**PR-RC2-4 — private render + approval + customer access.** Привязать новый Report JSON к private HTML/PDF по SHA; при изменении текста корректно инвалидировать старую review-версию, пересобрать артефакты, выполнить PDF/HTML QA; только отдельное утверждение владельца точных байтов разрешает публикацию. Согласовать R27 и native пути.

**PR-RC2-5 — уведомления и диагностика.** Durable events `QA_REPAIR_STARTED`, `QA_REPAIR_COMPLETED`, `QA_REGENERATION_REQUIRED`, `QA_FALLBACK_APPLIED`, `QA_OWNER_ATTENTION`, `REPORT_ARTIFACTS_READY`; MAX receipt на каждого адресата, e-mail outbox с отдельным delivery status, административная панель причин ожидания, прозрачный SLA, без child answer в сообщениях.

**PR-RC2-6 — интеграционные испытания и развёртывание.** В изолированном Laravel-контейнере/каталоге: PHP lint + PHPUnit/Pest, Vite build, test routes, fake bank, fake SMTP/MAX/DeepSeek/GigaChat, queue+schedule, job crash/lease/restart, approved fallback, PDF. Прогнать **57 сценариев сводного аудита + 20 RC1-сценариев** (77 сценариев подлежат выполнению), плюс отдельные RC2 кейсы ниже и подростковый regression. Предусмотреть rollback без отката оплаченных событий/квитанций.

## 7. Обязательная матрица интеграционных испытаний RC2

| ID | Испытание на тестовой БД/фейковых сервисах | Ожидаемое |
|---|---|---|
| INT-01 | 61 synthetic checkpoint + 181 fields + 51 FAIL | 51 durable QA tasks; `generation` не терминальный `failed` только из-за языка |
| INT-02 | QA локально исправил 48, 3 отправлены GigaChat | 48 локальных receipts, 3 отдельные редакторские попытки; 0 новых primary requests |
| INT-03 | Время ответа > текущие 300 секунд | Чёткий код deadline, проверенный reconnect/recovery; не второй платный запрос без статуса |
| INT-04 | HTTP 429 до/после подтверждённого send | only-proven-free может повторить; ambiguous держит reserve |
| INT-05 | Сбой после получения raw до ledger commit | Replay существующего encrypted receipt без нового DeepSeek bill |
| INT-06 | Лимит cost/token/requests до и в середине run | Статус budget hold + approved fallback / owner_attention, ноль автоповышений |
| INT-07 | `quality_unavailable` и реальный scanner timeout | Raw сохранены, QA_WAIT, независимая генерация движется, publish=false |
| INT-08 | GigaChat вернул новую цифру, удалил «не», изменил 8 шкал | Кандидат отвергнут, оригинал сохранён, change audit создан |
| INT-09 | Два воркера взяли одну поле-задачу | Только один lease winner, нет дубля editor вызова |
| INT-10 | Worker убит, срок lease истёк | Восстановление durable task, без потери 61 checkpoint; bounded no-progress attempts |
| INT-11 | Все scanner PASS, но source SHA отличается | Publish BLOCKED; owner attention |
| INT-12 | Сохранён старый HTML/PDF, затем редактура изменила текст | Новый versioned artifacts и hash, старый approval invalidated |
| INT-13 | Действующий оплаченный заказ 106, историческая сессия 105 | Только read-only historical replay; 105 не меняется; 106 не получает вторую оплату |
| INT-14 | SMTP/MAX 429/timeout/accepted-but-unknown | Статусы не выдуманы, неизвестный исход не пересылается вслепую |
| INT-15 | Teen/proforientation regression + консультации + бесплатная страница | Старые маршруты не изменены; no cross-family access |
| INT-16 | Восстановление после переполнения диска / потери SQLite | Failed stage + owner alert, без повреждения receipts |

**Ни один из INT-01…INT-16 на реальном Laravel staging в текущем этапе не выполнялся.** Офлайн-тесты RC2 проверяют только модель поведения.

## 8. Критерии допуска в следующую стадию

- `php -l` всех изменяемых PHP, PHPUnit/Pest, Vue/Vite build, E2E + 77 прежних интеграционных сценариев и 16 RC2 кейсов PASS на *полном независимом Laravel staging*, без реального банка и моделей.
- Фактические scanner binaries/версии и их криптографические receipt-хэши проверены; редактор GigaChat выполняет адресные запросы и не меняет source/числа/отрицания; расходы ограничены.
- Установлена реальная причина (или документирован установленный уровень неопределённости) перезапусков 7 и 24; очереди подтверждают обработку после restart.
- Синтетический оплаченный заказ проходит end-to-end до private Report JSON, owner approval, HTML/PDF, корректного email и MAX; статус SMTP `accepted` не именуется «доставлено» без внешнего proof.
- `quality_rejected` не теряет work; `billing_unknown` никогда автоматически не считается бесплатным; критическая целостность и owner approval по-прежнему блокируют публикацию.
- Подтверждён план атомарного релиза с rollback и hash-проверкой текущей версии; никакой слепой миграции production или копирования из публичного Next.js репозитория.

**Итог: допустить RC2 в отдельную GitHub review-ветку и в интеграционный backlog; production release и merge в `main` запрещены до закрытия критериев.**

## 9. Контрольный перечень локальных тестов

Фактический stdout Python и PHP с синтетическими кейсами включён в исходный RC2 архив (`report/OFFLINE_TEST_RUN.txt`); сравнить с `scripts/run_offline_tests.sh` и текущим серверным журналом. По количеству: `58 Python`, `20 PHP`, `25 cross-runtime cases (подмножество Python)`. Все эти проверки — PASS. Критический пропуск: **0 Laravel/PHPUnit и 0 SMTP/MAX/Bank/DeepSeek/GigaChat network/E2E тестов**.

### Ссылки на доказательства

- [RC1 review в GitHub](https://github.com/ssvnt/Psy_test/tree/review/dz-gardner-rc1-20261109/review/gardner-rc1-20261109)
- [RC2 исходный приватный ZIP на Google Диске](https://drive.google.com/file/d/1JTa__TxI3ri5AOe_hQi8s4KNZoUXealL/view)
- [Архитектура QA v1.1 на Google Диске](https://drive.google.com/file/d/1oUIWrHlLnLviLZRphfMQ2BkrEAoFYx_Y/view)
- [Сводный аудит P0 v1.1](https://drive.google.com/file/d/1tufJELUik7J3nnt57seQwj2HPLCahIdZ/view)
- [RC1 проверка и решение](https://drive.google.com/file/d/1DF9-ovmFWd3iUP9GX9S1eVGao7ic9eGE/view)
