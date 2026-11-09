# Релизный статус RC3

- `REVIEW_BRANCH`: yes (после загрузки в GitHub)
- `REAL_SOURCE_ZIP_TEXT_VERIFIED`: **NO**
- `USER_APPROVED_COPY`: **NO**
- `LARAVEL_INTEGRATED`: **NO**
- `SMTP_SENT`: **NO**
- `PAYMENTS`: **NO**
- `24H_SLA_CONFIRMED`: **NO**
- `COMPLETED_OFFER_DOUBLE_SEND_GUARDED_IN_EXISTING_LARAVEL`: **NO**, только референсный алгоритм
- `RC3_POLICY_UNIT_TESTS`: **PASS**
- `PRODUCTION`: **NO-GO**

Готовый пакет следует держать в новой ветке относительно RC2 review; не заменять файлы `main` Next.js и не включать `MAIL_RC3_ENABLED` на production. Отдельный PR с корректными Laravel source refs и исполненными интеграционными тестами обязателен до начала рассылок.
