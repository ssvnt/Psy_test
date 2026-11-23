# Senior review и регрессионные исправления

База: PR #5, commit `c1d415e5cbe62438f69dd840855d33b209131ebd` в `ssvnt/Psy_test`. Исправления предлагаются отдельной веткой к RC5. Сборщик восстанавливает приватный серверный снимок и применяет только проверенные изменения. Полный архив, клиентские данные и секреты в публичный GitHub не добавляются.

- [Находки, сценарии и минимальные diff](findings.md)
- [Требования к страницам и письмам](requirements.md)
- [Результаты и границы проверки](validation.md)
- [Точный патч Laravel/Vue](runtime.patch)
- [Контрольные суммы входов, результата и добавлений](manifest.json)
- [Backend-регрессии](tests/SeniorReleaseRegressionTest.php), [гостевые ссылки первой отправки](tests/PasswordlessReadyMailTest.php), [адаптеры RC4 и QA](tests/ReviewMailAdaptersTest.php), [браузер](tests/browser-regressions.cjs)

## Повтор сборки

Из корня клона, с PHP 8.4 и Python 3 в PATH:

```bash
python3 -B review/server-baseline-rc5-20261110/prepare_candidate.py /private/source-archives /tmp/dz-review-candidate
```

Выходной каталог должен быть новым и приватным. Старый `customer-links/apply.py` не является шагом сборки: он также отправляет письмо. Сборщик переносит только четыре runtime-файла обработчика, подключает маршрут и применяет `runtime.patch`; `apply_review.py` сначала сверяет все входные SHA-256 и наличие добавлений. При несовпадении входов он останавливается.

Далее установите зависимости по lock-файлам в изолированном Laravel, как описано в [RC5 README](../server-baseline-rc5-20261110/README.md). `ReviewMailAdaptersTest.php` загружает отключённые адаптеры RC4 явно: его не нужно устанавливать в production-каталог тестов. Из каталога Laravel:

```bash
DZ_REVIEW_REPO=/absolute/path/to/Psy_test php vendor/bin/phpunit \
  tests/Feature/SeniorReleaseRegressionTest.php \
  tests/Feature/PasswordlessReadyMailTest.php \
  /absolute/path/to/Psy_test/review/senior-audit-20261123/tests/ReviewMailAdaptersTest.php
```

Для общего прогона задайте `GARDNER_QA_OUTPUT` в приватный временный каталог, обе переменные Stage 210 из RC5 README и установите Python-зависимости снимка. Используйте SQLite в памяти, тестовый APP_KEY, fake-границы и блокировку внешней сети; production .env не копировать.

Чтобы включить все 1 252 теста (в том числе три внешних adapter-теста), укажите оба каталога и отдельный файл:

```bash
DZ_REVIEW_REPO=/absolute/path/to/Psy_test php vendor/bin/phpunit \
  tests/Unit tests/Feature \
  /absolute/path/to/Psy_test/review/senior-audit-20261123/tests/ReviewMailAdaptersTest.php
```

Браузер запускается на локальной сборке Laravel/Vite с отдельной SQLite. `DZ_DEMO_ORIGIN` принимает только localhost/127.0.0.1; `DZ_CHROME` — путь к Chromium. Нужен Playwright. Все API в новых сценариях подменены синтетическими ответами, внешние запросы блокируются.

```bash
DZ_DEMO_ORIGIN=http://127.0.0.1:18764 DZ_CHROME=/path/to/chromium \
  node review/senior-audit-20261123/tests/browser-regressions.cjs
```

Для PHP development server рабочий каталог — `public/`, router script — `vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`. Сервер и браузер должны находиться в одной сетевой среде.

## Ограничения выкладки

Ни merge, ни deploy, ни изменение конфигурации рабочего сервера этим PR не выполняются. Перед переключением нужна свежая сверка файлов и очередей, установка отключённых адаптеров RC2–RC4 с исключением двойной доставки, план отката и QA реальных внешних интеграций. Подписанные ссылки остаются персональными: не публикуйте их в Issues, логах и аналитике.
