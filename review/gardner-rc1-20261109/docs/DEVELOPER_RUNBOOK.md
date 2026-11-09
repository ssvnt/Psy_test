# Runbook для разработчика и QA — RC1 (не для production)

## Перед началом

1. Прочитайте `README.md`, `AUDIT_PHASE1_DETAILED.md` и `AUDIT_PHASE1_PHASE2_REFERENCE_v1.1.md`; сохраняйте границы `children / teen` и `native V2.2 / внешняя Gardner R27 / universal teen R27`.
2. Зафиксируйте текущие `sha256sum` реальных исходников и снимок схемы. **Не добавляйте `.env`, тестовые ответы, ФИО, банковские ID и токены в GitHub или общий Диск.**
3. На сервере **только для просмотра:** `bash scripts/host_readonly_preflight.sh`. Отдельно проверить, какие exact systemd service/timer реально существуют и активны. `GARDNER_PROGRESS_SCAN` в `--check` режиме не равно успешной отправке MAX.
4. Перед `--stage` гарантируйте отдельную staging-папку вне `/var/www/bribon` и `/opt/dz-gardner-r27-live`. Пакет **не меняет** приложение и БД.

## Строгие релизные флаги, исходно OFF

- `GARDNER_FREE_OFFER_MAIL_ENABLED=false` (никаких новых free-offer писем)
- `GARDNER_FREE_OFFER_ROLLOUT_FROM` не задан (дополнительный предохранитель от старого backfill)
- `GARDNER_REGISTERED_EMAIL_SERVICE_NOTICE_ENABLED=false` (старое `verified_at` условие готовности)
- `GARDNER_R27_ACCOUNT_LINK_ENABLED=false` (старый кабинет без внешнего отчёта)
- `GARDNER_OWNER_PAID_MAX_RECONCILE_ENABLED=false` (никогда не массово отправлять старые paid/MAX события)
- `GARDNER_OWNER_PAID_MAX_RECONCILE_FROM` не задан (дополнительный обязательный cutoff)

Сначала в **staging** на синтетических пользователях проверить consent/отправки/доступы. Не включать боевую рассылку до авторизации владельцем, проверки правовых оснований и успешного E2E. `r27_account_link` включать только если маршрут `gardner.r27.customer.show` реально зарегистрирован и сам файл отчёта уже approval+QA clean+hash-validated.

## Контракты тестирования RC1 (дополнительные к 57 из аудита)

| ID | Шаг | Ожидаемый результат |
|---|---|---|
| RC1-T01 | Завершить новую `children` сессию с 35 ответами | Записаны прежние баллы и JSON без изменений; `free_offer` идемпотентный, если consent=true и дата после rollout |
| RC1-T02 | То же, без согласия коммуникаций | Никакой рекламной почты; бесплатный экран доступен |
| RC1-T03 | Повторить тот же `submit` после завершения | 200/ok, те же ответы и hash; повторной тарификации/пересчёта нет |
| RC1-T04 | Изменить любой ответ после завершения или оплаты | Валидационная ошибка; в БД нет мутирующих запросов к Answer и JSON |
| RC1-T05 | Предложить email после уже созданного `pending` заказа | Никакой новой `free_offer`; банк не вызывается |
| RC1-T06 | Прервать queue worker во время post-submit | Результат сохраняется; mail intent добирается минутным reconciliation; без дублей |
| RC1-T07 | Отчёт готов, buyer `verified_at=NULL`, email регистрации совпадает и order paid | При флаге ON создаётся только service-ready письмо с защищённым cabinet URL; без токена/файла |
| RC1-T08 | Buyer.email не совпадает с registration email | Ready notification блокируется; доступ к детским данным не раскрывается |
| RC1-T09 | Выключить service notice флаг | Сохраняется прежняя политика `verified_at`, старые уведомления `payment_confirmed/report_waiting` работают |
| RC1-T10 | MAX временно недоступен, оплата подтверждена | Поле уведомления остаётся пустым; tick повторяет сообщение (учесть неопределённый результат перед повтором) |
| RC1-T11 | Одному заказу не удаётся вызвать `dispatchConfirmed` | Другие оплаченные заказы и pending outbox всё равно обрабатываются |
| RC1-T12 | MAX HTTP error содержит fake secret/PII | В журнале только HTTP код, SHA тела и тип исключения, без raw body |
| RC1-T13 | R27 review `blocked=1`, POST APPROVED и ack | 422; не создаётся клиентский доступ и почтовое уведомление |
| RC1-T14 | R27 review `blocked=0`, owner approved, HTML/PDF hashes корректны | Стейджинг и защищённый URL работают (без письма) |
| RC1-T15 | HTML/PDF хэш неверен, route отсутствует, token expired, review не approved | `r27_report_url=null` и 404/409 по целевому маршруту |
| RC1-T16 | Пользователь подтверждает email из кабинета | OTP с текущим throttle, `verified_at` установлен, ключ доступа не раскрывается в письме |
| RC1-T17 | Платный клиент записан на консультацию | «К моему отчёту» ведёт в paid view или на утверждённую R27 страницу, не в бесплатный режим |
| RC1-T18 | Флаг продаж выключен и старый pending заказ есть | Пользователь видит понятный статус; новый invoice не создаётся; глобальный запрет не обходится |
| RC1-T19 | Искусственный подростковый отчёт (teen) | Ссылка, PDF, почта, MAX, слоты и генерация работают как до изменений |
| RC1-T20 | Отключить все новые флаги и сравнить baseline | Новые изменения не вызывают free email/R27 URL/registered fallback; правки QA всё ещё требуют 0 blockers |

### Отдельные обязательные проверки до релиза

1. `php -l` всех PHP candidate файлов, Python syntax + unit tests; `node`/Vue template compile (`@vue/compiler-sfc`) и `npm run build` **только в независимом проектном staging**.
2. Laravel PHPUnit/Pest с isolated PostgreSQL + fake bank operation; прогнать существующие тесты оплаты и отчёта, Laravel queue и `schedule:run`.
3. Mock SMTP, MAX (200, 429, 503, timeout), Cloud.ru (HTTP broken stream, 5xx, returned raw body, billing unknown). Реальная сеть не нужна для QA.
4. Журналировать только технические IDs тестовых записей и событий, без детских ответов или контактов.
5. Проверить стабильность SHA баллов и исходных JSON/ReportViewModel для замкнутого теста; при `quality_rejected` **никаких подмен QA PASS**.
6. Проверить `Gardner` free result и 3 viewport, конкретные deep links, возврат из банка, повторное открытие кабинета.
7. Отдельный regression `teen` по опубликованному не изменённому отчёту — ни один файл универсального движка teen в RC1 не меняется.
8. QA подписи релиза по всем тестам из 57-сценарной матрицы предыдущего аудита: для каждого `PASS/FAIL`, commit / hash, синтетический case ID, Laravel logs code only.

## Изоляция проверок

`--check` читает действующий код, проверяет SHA, не меняет ни одного байта. `--stage` берёт точные исходники и создаёт private copy + `.patch`; **не надо** копировать его `candidate/` напрямую в `/var/www/bribon` без миграций, тестов и отдельного atomic deploy+rollback.

## Если SHA baseline отличается

Скрипт завершится `DZ_RC1_BLOCKED`, код `BASELINE_SHA256_MISMATCH`. Это **правильное поведение**. Не править `spec.py` наугад и не применять `patch -f`; собрать новый read-only export, сравнить смысл изменений, создать новый согласованный RC version.

## При срыве проверки

- Нет SQL/migrations в этом RC1. Ни правки Orders, ни квитанции банка, ни старые артефакты не изменены.
- Если нет Vite, PHP dependencies, route, timers или Redis, фиксировать блокер и не выставлять готовность `production-ready`.
- При неудачной SMTP/МАХ отправке со статусом UNKNOWN не повторять автоматически «вслепую» — требуется receipt reconciliation. Процедура обозначена в RC2, без обнуления расходов.
