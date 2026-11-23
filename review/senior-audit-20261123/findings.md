# Находки Senior review

Ревью исходного PR #5 (`c1d415e5cbe62438f69dd840855d33b209131ebd`) и восстановленного Laravel/Vue с учётом RC2–RC4. Ниже указаны **строки до исправления**; для файлов, существующих только в приватном снимке, точные контексты и новые строки приведены в [runtime.patch](runtime.patch). Все десять находок исправлены в этой ветке. Critical с доказанным сценарием не найдено. Это не утверждение об отсутствии иных дефектов.

## 1. High — первая штатная отправка снова ведёт в кабинет с запросом доступа

**Файлы:** `app/Services/GardnerCommerce/Outbox.php:148`, `resources/views/emails/gardner-commerce.blade.php:77–81`, `resources/views/emails/gardner-commerce-text.blade.php:43,53`; RC4 `laravel/app/Services/MailJourneys/LaravelJourneyContextProvider.php:65–68`.

**Сценарий:** оплаченный и утверждённый детский отчёт; родитель открывает первое report_ready письмо в другом браузере. Outbox всегда подставляет `/children/account/{uuid}`. В браузере нет `X-Gardner-Access`, поэтому API закрывает данные и интерфейс требует восстановления доступа. Обе кнопки письма ведут в тот же кабинет. Разовое корректирующее письмо не меняет эту штатную отправку. Если просто подставить подписанные URL в существующий text/plain Blade, `{{ ... }}` превратит `&` в `&amp;` и повредит подпись.

**Исправление:** выдавать отдельные ограниченные подписанные ссылки на опубликованные HTML/PDF/booking. На каждом открытии проверяются оплаченный заказ, email регистрации/покупателя, актуальная утверждённая версия и хеши опубликованных файлов. Первая доставка не зависит от уже отправленного письма. Старый corrective-binding сохранён отдельно; глобальные проверки доступа не снимаются.

```diff
- $url = $appUrl.'/children/account/'.$session->public_id;
+ if ($event->kind === 'report_ready') {
+     $links = app(ReadyMailAccess::class)->linksForDelivery($session);
+     $url = $links['html'];
+     $payload['booking_url'] = $links['booking'];
+     $payload['pdf_url'] = $links['pdf'];
+ }
```

В HTML URL по-прежнему экранируются. Только в text/plain доверенные сформированные URL выводятся без HTML-кодирования. RC4 использует тот же issuer для детского готового отчёта.

**RED → GREEN:** `PasswordlessReadyMailTest` до исправления получал HTML оболочки Vue вместо утверждённого отчёта. После — точные HTML/PDF, гостевой календарь, отсутствие `&amp;` в текстовых ссылках, 403 при изменении подписи, 404 после возврата оплаты, действительность старого формата corrective URL и однократная отправка.

## 2. High — исправленное письмо обходило блокирующий QA R27

**Файл:** `review/server-baseline-rc5-20261110/customer-links/ReadyMailAccess.php:70–73`, метод `r27()`.

**Сценарий:** `qa.blocked=1`, review APPROVED, `ack_qa_warnings=true`, совпадающий QA hash и корректные HTML/PDF. Основной исправленный R27-маршрут требует нулевые блокирующие замечания, но альтернативный reader письма разрешал выдачу после исторического acknowledgement. Подпись ссылки не компенсирует нарушение правила публикации.

```diff
- ... blocked > 0 && (!ack_qa_warnings || decision.qa_sha256 !== qaHash)
+ if ($qaHash !== ($review['qa_sha256'] ?? null)
+     || ($qa['blocked'] ?? null) !== 0
+     || $qa['blocked'] !== ($review['blocked'] ?? null)) {
+     throw new RuntimeException('R27_QA_DECISION_UNCONFIRMED');
+ }
```

**RED → GREEN:** `ReviewMailAdaptersTest::test_corrective_mail_cannot_bypass_blocking_r27_qa_with_an_old_acknowledgement` сначала возвращал файлы. После исправления отклоняет blocked=1 и принимает тот же корректный комплект при blocked=0. Неблокирующие замечания не приравниваются к blocked.

## 3. High — сборка теряла уже развёрнутый обработчик клиентских ссылок

**Файл:** `review/server-baseline-rc5-20261110/prepare_candidate.py:53–57`.

**Сценарий:** заменить рабочее дерево результатом штатного сборщика RC5. Архив customer-links есть в GitHub, но четыре файла и подключение маршрута не попадают в кандидат. Ранее отправленные рабочие URL перестают обслуживаться.

```diff
  for name, hashes in extra['patched_files'].items():
      check_file(site / name, hashes['after'])
+ stats.update(apply_review(site))
  source.rename(output / 'source')
```

`apply_review` переносит четыре runtime-файла по хешам, применяет маршрут и остальные проверенные исправления. Он не запускает отправляющий `apply.py`. Проверка всего входа происходит до записи; несовпадающий исходник блокирует сборку.

**RED → GREEN:** `SeniorReleaseRegressionTest::test_rebuilt_release_contains_previously_deployed_customer_links` падал на отсутствующем классе. После новой сборки классы, Blade и именованный маршрут присутствуют. Проверена побайтовая идентичность восемнадцати изменённых/добавленных runtime-файлов с протестированным деревом.

## 4. High — RC4 доверял квитанции другой банковской операции

**Файлы:** `review/dz-emails-cutover-rc4-20261109/laravel/app/Services/MailJourneys/LaravelJourneyContextProvider.php:46–51`, `LaravelJourneyHooks.php:44–46`.

**Сценарий:** сессия teen имеет `payment_id=current`, paid-флаг и сохранённую квитанцию для `previous`, либо recorder вызывается с чужим operationId. Старая проверка сравнивала email, валюту и наличие proof hash, но не текущий идентификатор операции. Данные ошибочно становились `bank_verified=true`; recorder сохранял конфликтующий факт до вызова координатора. Это дефект review-only адаптера, а не утверждение о списании денег в production.

```diff
  && $s->payment_status === 'paid'
+ && is_string($s->payment_id) && trim($s->payment_id) !== ''
+ && hash_equals($receipt->payment_operation_hash, hash('sha256', $s->payment_id))
```

```diff
  if (/* existing proof checks */
+     || !is_string($session->payment_id)
+     || !hash_equals($session->payment_id, $operationId)) {
      throw new InvalidArgumentException('VERIFIED_PAYMENT_PROOF_INVALID');
  }
```

**RED → GREEN:** два теста `ReviewMailAdaptersTest`: устаревшая квитанция перестала подтверждать заказ; чужая операция отклоняется до insert. Совпадающая операция допускается, refunded снова запрещается. Проверка суммы доверенным банковским обработчиком по-прежнему обязательна: один хеш proof не является самостоятельной банковской сверкой.

## 5. High — потенциальный stored XSS в админском просмотре отчёта

**Файл:** `resources/js/components/admin/GigaChatReport.vue:567–568` и места `v-html="rd(...)"`.

**Сценарий:** раздел сохранённого/model-generated отчёта содержит `<img src="/missing.png" onerror="...">` или `javascript:` ссылку. `GigaChatHtmlRepair` исправляет синтаксис HTML, но не очищает его. `rd()` возвращал строку непосредственно в v-html. В проверенной production-сборке отображение большинства секций было замаскировано отдельной ошибкой №6; после восстановления секций необработанный HTML становится исполняемым. Поэтому обе ошибки исправлены вместе. Факт эксплуатации действующего сайта не установлен.

```diff
+ import DOMPurify from 'dompurify';
  function rd(key: string): string {
-     return props.reportData?.[key] ?? '';
+     const html = props.reportData?.[key];
+     return DOMPurify.sanitize(typeof html === 'string' ? html : '',
+         { USE_PROFILES: { html: true } });
  }
```

**RED → GREEN:** после исправления секций браузерный тест обнаружил два опасных атрибута/URL; после sanitizer их нет, контрольный JS не выполняется, обычный текст сохраняется. Использована уже существующая зависимость DOMPurify.

## 6. High — сохранение ссылки встречи для teen возвращало 500

**Файлы:** `app/Http/Controllers/Api/GardnerCommerceController.php:213–215`, `app/Services/GardnerCommerce/Outbox.php:132–148`.

**Сценарий:** оплаченный подростковый тест записан через общий календарь. Для него нет строки `gardner_buyers`. Специалист сохраняет HTTPS meeting URL: чтение `$buyer->email` падает, транзакция откатывает URL. Простой null-safe email недостаточен: старый Outbox затем проверяет детский заказ и направляет подростка в детский кабинет.

```diff
+ $recipient = $session->test_type === 'teen' ? $session->email : $buyer?->email;
+ abort_unless(is_string($recipient) && filter_var($recipient, FILTER_VALIDATE_EMAIL), 409);
- ... 'consultation_meeting_link', $buyer->email, ...
+ ... 'consultation_meeting_link', $recipient, ...
```

Outbox для teen consultation-событий проверяет paid-состояние, актуальную версию записи и совпадение получателя с регистрацией; URL ведёт в `/teen/payment/{uuid}`. Правила детских платежей и остальных видов писем сохранены. Этот кабинет использует существующее подтверждение телефона; обещание «без пароля» относится к персональным готовым отчётам/booking URL, а не к снятию авторизации со всех кабинетов.

**RED → GREEN:** синтетическая teen-запись без gardner_buyers до патча — 500 `Attempt to read property email on null`; после — 200, URL сохранён, одно письмо в правильный продукт. Неверный получатель/refunded подавлены; без admin-токена 401, `javascript:` URL 422.

## 7. Medium — production bundle не отображал разделы отчёта администратору

**Файл:** `resources/js/components/admin/GigaChatReport.vue:373–400`.

**Сценарий:** администратор открывает готовый отчёт. Локальный объект ReportSection содержит строку `template`, требующую runtime-компилятора Vue. Vite компилирует SFC, но не такой JS-объект; используемый runtime-only Vue не строит секции. Ошибки HTTP нет, текст отчёта отсутствует.

```diff
- const ReportSection = { /* props/setup */, template: `...` };
+ import ReportSection from './ReportSection.vue';
```

Шаблон вынесен в отдельный SFC и компилируется Vite. Сохранены слоты/состояние раскрытия; добавлены `type="button"`, `aria-expanded`, `aria-controls`, уникальный id панели.

**RED → GREEN:** настоящий production bundle с синтетическим admin API раньше не показывал контрольный абзац; после показывает его. Не подменялся Vue runtime полным compiler build.

## 8. Medium — свободное время исчезало из календаря

**Файл:** `app/Services/TeenApprovedReports.php:77–80`.

**Сценарий:** первые 100 будущих опубликованных legacy-слотов не соответствуют расписанию, а следующий слот допустим. SQL применяет limit(100), затем PHP отбрасывает все выбранные строки. Клиент видит «нет времени», хотя оно есть. Метод также используется подписанным детским календарём.

```diff
- ->orderBy('s.starts_at')->limit(100)->get([...]);
+ ->orderBy('s.starts_at')->orderBy('s.id')->select([...])->lazy(200);
  return $rows->filter(/* owner schedule */)
+     ->take(100)
      ->map(...)->values()->all();
```

**RED → GREEN:** 100 неподходящих слотов в понедельник + один допустимый в среду: до патча `[]`, после — id среды. Дополнительная сортировка по id стабилизирует порядок страниц; загрузка пакетная, не весь календарь в память.

## 9. Medium — опрос оплаты жил после ухода со страницы и пересекался сам с собой

**Файлы:** `resources/js/views/TeenPaymentSuccessPage.vue:49–81,147–154`, `resources/js/views/GardnerAccountPage.vue:79–90`.

**Сценарии:** уйти с teen-страницы, пока первый status-запрос ещё выполняется: cleanup вызывается до создания таймера, а поздний onMounted устанавливает interval и focus listener. Несколько focus-событий отправляют параллельные status-запросы; поздний pending-ответ способен затереть более новый paid. В детском кабинете завершение банковской проверки после unmount запускает повторную загрузку и следующий timeout.

```diff
+ let disposed = false;
+ let refreshing = false;
+ const statusAbort = new AbortController();
  async function refresh(...) {
+     if (disposed || refreshing) return;
+     refreshing = true;
      // axios получает signal; после каждого await проверяется disposed
+     // finally: refreshing = false
  }
+ onUnmounted(() => { disposed = true; statusAbort.abort(); /* clear timer/listener */ });
```

Для Gardner сохранено ограничение числа проверок и добавлен disposed-guard до запроса, после await и перед reschedule. Выполненный сервером запрос не «отменяется задним числом»; новые запросы после ухода не запускаются.

**RED → GREEN:** браузер воспроизводил interval после unmount, 5 status-запросов вместо 2 и повторную загрузку детского заказа после ухода. Теперь эти три сценария проходят.

## 10. Medium — неизвестный URL отдавал 200 и пустой RouterView

**Файлы:** `resources/js/router.js:238` (нет catch-all), `routes/web.php:62–63`.

**Сценарий:** открыть `/not-a-real-page` или отсутствующий файл. Общий Laravel view-route отдавал 200, Vue не находил компонент. Посетитель получал пустую центральную область, мониторинг мог считать страницу рабочей.

```diff
+ { path: '/:pathMatch(.*)*', name: 'not-found',
+   component: () => import('./views/NotFoundPage.vue') }
```

На сервере успешный SPA-document ограничен известными клиентскими маршрутами; остальные web-адреса получают 404 с тем же доступным экраном. API исключён из fallback regex: неизвестный GET/POST сохраняет 404, а зарегистрированные endpoints и их method restrictions остаются у Laravel.

**RED → GREEN:** до — HTTP 200 без объяснения; после — HTTP 404, заголовок и ссылка на главную при 390/1440 px. Проверены известные лендинги, оплата, legal/admin routes и старый `/result/{id}`. Общий прогон обнаружил первоначальный побочный 405 для неизвестного POST API; fallback уточнён, существующий тест сохранён и проходит.

## Что не считается доказанной находкой

- Старый `/result/:id` не объявлен сломанным из-за отсутствия `query` в redirect-объекте: Vue Router сохраняет query/hash при таком редиректе.
- Привязка `id` в детском кабинете не объявлена stale-route багом: App перемонтирует RouterView по `route.path`.
- SSR/hydration не относятся к текущему клиентскому Vite/Vue приложению.
- Нет доказанного SQLi/CSRF по просмотренным платёжным, почтовым и консультационным путям. Это не результат полного penetration test всей кодовой базы.
- Не оценивалось качество новых платных ответов реальной модели: внешняя генерация была заменена fake transport, а детерминированная логика проверялась существующими тестами.

Оставшиеся требования и ограничения production вынесены в [requirements.md](requirements.md), фактические результаты запусков — в [validation.md](validation.md).
