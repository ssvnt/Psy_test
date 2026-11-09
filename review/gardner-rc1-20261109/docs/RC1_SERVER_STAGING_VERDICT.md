# Добрый Жук — решение по RC1 после серверного staging

**Дата:** 9 ноября 2026. **Статус:** `STAGED_PASS / CODE_REVIEW_ONLY / PRODUCTION_NO_GO`.

## Подтверждённые факты

1. Серверный `stage_candidate.py --check`: `DZ_RC1_PREFLIGHT_PASS`, 13 исходных файлов и 4 новых файла; проверка SHA-256 и точных мест изменений — PASS.
2. Серверный `--stage`: `DZ_RC1_STAGED`, `live_writes=0`, `bank_calls=0`, `model_calls=0`, `mail_sends=0`, `max_sends=0`.
3. Артефакты созданы вне сайта: `/root/dz-gardner-rc1-staged-20261109/{baseline,candidate}`, `manifest.json` и `RC1_REVIEW_ONLY.patch`; SHA-256 manifest из журнала: `8d272c6c09512edc2e82230da2ff13f783c65a7283fb5ab1e49a304115da43bf`.
4. Серверные проверки: 11/11 Python structural PASS и 11/11 synthetic PHP behavioral PASS. Подтверждают корректность условий применения, а **не** реальные Laravel/PHPUnit/Vite/E2E сценарии.
5. Рантаймы `php`, `python3`, `node`, `npm` доступны. Диск: `/dev/vda3`, 40 GB, занято 31 GB, свободно около 6.8 GB (82%); inode 17%.
6. На момент выгрузки `dz-gardner-r27-queue.service`: `active/running`, `NRestarts=6`; установить причину перезапусков без unit journal нельзя. Установлены и запланированы таймеры `dz-gardner-commerce`, `dz-gardner-r27-paid`, `...-finalize`, `...-delivery`, `...-max-progress`, `...-one-retry`. `not-found` у других проверенных имён не означает отсутствия этих установленных units.
7. Реальный сайт `/var/www/bribon` — Laravel/Vue, а GitHub `ssvnt/Psy_test` `main` является историческим Next.js-приложением (папки `app`, `prisma`, `next.config.ts`, `next-env.d.ts`). **Запрещено помещать Laravel-кандидат в корень `main` и сливать ревью-ветку с `main` до миграции в канонический репозиторий реального сайта.**
8. GitHub public `ssvnt/Psy_test`: создана отдельная ветка `review/dz-gardner-rc1-20261109`, в `review/gardner-rc1-20261109/` помещены 14 текстовых файлов RC1 (без исходников оплаченных сессий и без локальных реальных банковских операций). Непубличный ZIP с полным контекстом остаётся на Google Диске.

## Решение

- **Принимается:** сверенный и безопасно сформированный RC1-кандидат как исходная точка для code review и изолированных интеграционных тестов.
- **Не принимается:** продуктовая готовность, публикация в `main`, изменение production-файлов/БД/флагов, включение продаж.
- **Существенные незакрытые дефекты:** native `quality_rejected` (на предыдущем инциденте 51/181 полей), billing unknown, одноразовое восстановление сессии 106, `creation_unknown` банка, отсутствие гарантированного MAX outbox, самостоятельного QA repair с бюджетом, две конкурирующие ветви публикации и доставки.
- **Интеграционные риски RC1:** изменения email-on-completion, ветвление в `Outbox::deliver`, отложенный отправитель MAX, проверка `verified_at`, UI Vue, routing R27. Флаги нового функционала исходно OFF, что необходимо сохранить до стендового E2E.

## Обязательные действия разработчика

1. Найти каноническую версию действующего Laravel/Vue сайта и сохранить её в **приватном** GitHub-репозитории/ветке после секрет-сканирования, не поверх Next.js `main`.
2. Поднять отдельную копию Laravel/Vue из точного текущего source+dependencies с отдельной БД и fake Tochka/Mail/MAX/Cloud.ru; исходный scoring/JSON не менять.
3. Проверить `php -l`/PHPUnit/Pest, Vite build, route list, full 57+20 сценариев из аудит-документов, отдельную проверку старого teen-процесса.
4. Исследовать реальные `systemctl cat dz-gardner-r27-queue.service`, `systemctl show`, последние **обезличенные** unit failures, всё без экспорта секретов и данных детей.
5. Только после доказанного end-to-end и обратимого staged release разрешать отдельное изменение production; не повторять банковские счета, MAX/SMTP с неизвестным исходом, запросы DeepSeek при `billing_unknown`.

**Релизная оценка:** REVIEW READY / INTEGRATION TESTS NOT RUN / PRODUCTION NO-GO.
