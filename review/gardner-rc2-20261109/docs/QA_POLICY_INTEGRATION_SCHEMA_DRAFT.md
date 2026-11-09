# Предлагаемые (НЕ ПРИМЕНЯЕМЫЕ) изменения БД и Laravel

**Статус:** проект для code review. Не исполнять этот документ как миграцию на production.

## Минимальная схема

`gardner_v22_qa_tasks`: `id`, `generation_id`, `generation_identity_sha256`, `field_key`, `base_sha256`, `quality_policy_sha256`, `machine_issue_codes JSON`, `status`, `attempt_local`, `attempt_editor`, `attempt_regen`, `lease_token_sha256`, `lease_expires_at`, `next_run_at`, `last_error_code`, `created_at`, `updated_at`. Уникальный ключ `(generation_id, field_key, base_sha256, quality_policy_sha256)`. Отклонённый fragment не заменяет оригинальный.

`gardner_v22_qa_revisions`: `id`, `task_id`, `revision`, `parent_revision_sha256`, `candidate_sha256`, `scanners_sha256`, `factual_guard_sha256`, `status`, `recorded_at`. Большие тексты — только в существующем зашифрованном приватном хранилище, а не в SQL/уведомлениях.

`gardner_v22_recovery_events`: `event_key` unique, `generation_id`, `stage`, `reason_code`, `attempt_id`, `created_at`, `sender_status`; MAX admin recipient statuses отдельно. Никогда не записывать raw body/error с секретами.

`gardner_v22_qa_generation_state`: не подменять существующий `status='failed'` и `done`; добавить *ортогональный* `qa_state` (`pending`, `scanning`, `repair_pending`, `editor_pending`, `fallback_pending`, `owner_attention`, `pass`) и `artifact_revision_sha256`. Факт primary chunks readiness отдельно от языкового качества.

## Обязательная семантика транзакций

- `generation_identity` не меняется; исходный scores JSON immutable.
- При приёме chunk транзакционно записывается checkpoint и intent `qa_scan`, после commit запускается очередь.
- Ошибка scanner меняет только QA sidecar state; не удаляет входной checkpoint и не сбрасывает provider ledger.
- После определения проблемных полей один update/insert на каждую уникальную комбинацию. Два воркера не создают дублей.
- Отдельный журнал затрат GigaChat (или существующий validated ledger), сериализация reserve и freeze при unknown; никогда не использовать «model retry» как generic queue retry.
- После полного PASS приватный отчёт монтируется к точной версии и подаётся на approval; создание файлика не равно публикации.

## Что нельзя делать до schema review

- Менять `gardner_v22_generations.status` на новое значение без проверки DB constraints/ORM и jobs.
- Копировать SQLite прототип непосредственно в Laravel production (он предназначен для моделирования contract).
- Выполнять `php artisan migrate` на сервере.
- Подменять `quality.json` или «принимать» 51/181 замечаний без фактического чтения реальных scanner receipts.
- Удалять специфическое восстановление сессии 106 до миграции исторического ledger.
