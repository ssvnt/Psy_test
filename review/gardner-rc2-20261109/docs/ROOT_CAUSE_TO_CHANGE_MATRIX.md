# RC2 — реальные узлы остановки, исправления и доказательства

**Базовый код:** серверный snapshot 2026-11-08, выгрузка 2026-11-09. Номера строк — внутри исходных файлов.

| Источник | Строки | Сейчас | Требование RC2 / тест |
|---|---:|---|---|
| `GardnerV22DeepSeekHttpTransport.php` | 29–32,41–54 | `timeout(300)` и `provider_transport_failed` для непредсказуемой ошибки | Пооперационная трасса connect/read/deadline, доказательство *до/после отправки*; после отправки считать billing unknown и не делать платный автоповтор. Политика проектного timeout 600 с требует согласования с API/worker/queue, а не слепой замены числа. |
| `GardnerV22MeteredProvider.php` | 17–95 | RAW receipt сохраняется; после timeout `billing_unknown`; некоторые схемные ошибки допускают единственный retry | Сохранить шифрование/ledger; отдельный resolver по evidence, конечные повторы по policy, восстановление RAW без запроса. |
| `GardnerV22AttemptLedger.php` | 27–95 | request, token, cost caps выбрасывают исключение | Экземпляр заказа в `BUDGET_HOLD` с конкретной причиной и fallback/owner-task; никогда не повышать cap. Существующая тарифная конфигурация не меняется. |
| `config/gardner_report_v22.php` | 28–37 | в исходном *default* несколько тарифных полей 0/null | Нельзя по одному config.php сделать вывод о runtime-разрешённом бюджете; проверить `config:show` только на изолированном стенде с фиктивными секретами. |
| `GardnerV22NativeGenerator.php` | 31–34 | scanner preflight **до** любой генерации, может блокировать всё | Разделить generator readiness и scanner readiness; scanner outage блокирует только QA/publish, не сохранение независимых RAW. |
| `GardnerV22NativeGenerator.php` | 42–116 | цикл независимых запросов, сохраняет checkpoints; частичные схемные ошибки откладывает при определённом условии, затем бросает исключение | Усовершенствовать journal заявок и разнести задачи; не трогать принятый checkpoint; причина каждого item локальна. |
| `GardnerV22NativeGenerator.php` | 118–134 | полный QA в конце; `quality_rejected` и **не** `assemble` | Проводить QA после каждой сохранённой части и глобальный финальный цикл; FAIL → задачи, не terminal failed; private versioned render после factual/shape guard. |
| `GenerateGardnerV22ReportJob.php` | 79–93 | любое исключение ⇒ `status=failed` | Специальный state/event для `QA_REPAIR_PENDING`; изменение ORM/migrations/systemd отдельно, CAS и lease не ломать. |
| `GardnerV22TextQuality.php` | 11–24,34–61 | scanner policy SHA закреплён, реальный humanizer/pishi subprocess, timeout 120 | Сохранить действующие хэши и scanner, release policy v1.1 отдельной версией, не объявлять PASS в обход двух real scanners. |
| `GardnerV22TextNormalizer.php` | 10–24 | нормализация 1-й версии и SHA changes | Не переписывать старую нормализацию; локальный корректор RC2 — отдельная версия с оригиналом и повторной проверкой. |
| `GardnerV22EditorialPipeline.php` | 20–117 | старый opt-in редактор с собственными стилистическими и API кодами, до 2 LLM запросов | Не считать его готовым RC2 без provider ledger, хэшированного языка QA и сохранения байтов по каждому полю. |
| `opt/dz-gardner-r27-live/server/recovery_tick.php` | 165–200 | специальная ветка `sessionId=106` | Не распространять одноразовую ветку на другие сессии, не удалять до доказанной миграции protected receipt. |
| `opt/dz-gardner-r27-live/server/finalize.py` | 97–136 | требует native `status=done`; при QA fail не доходит до сборки | Поддержка private draft preview и `QA_REPAIR_PENDING`, финальный publish только после real QA PASS + owner approval. |
| `Outbox.php` + MAX | проверено этапами 1–2 | SMTP and MAX unknown outcomes отдельно, доставка не доказана scan-событием | Durable event/idempotence, message transport receipts и SLA владельцу, без слепых повтора при unknown. |

Раздел 19 **отменяет субъективный смысловой QA**, но не фактические и структурные защиты. Приоритет: безопасность личных данных, отсутствие двойной тарификации, структура/шкалы/числа, затем языковая редактура. Сессия 105 и teen R27 не затрагиваются.
