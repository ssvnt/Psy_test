# Gardner P0 — regression matrix after first paid-order incident

**Status:** TEST SPECIFICATION, NOT EXECUTED. Date 2026-11-09. These tests must run against the latest Laravel/Vue snapshot and an isolated staging DB. Never use a real bank operation, real customer email, real MAX delivery, or paid model request without explicit staging controls.

## Source of evidence

See [incident report](./2026-11-gardner-paid-order.md). Sources: server command transcripts from 2026-11-06–08 and file manifest exported at 2026-11-08T22:50:35Z. This is not proof that any particular PHP branch has now passed. Runtime gates and processes were not captured in the code-only export.

## P0 tests — executable acceptance requirements

| Test ID | Scenario | Expected observable outcome | Regression target |
|---|---|---|---|
| T-001 | Finish a free Gardner test, authenticated buyer with required contact/consent | Correct personalized free result; durable completion event; no unintended billing | TestController, ResultPage |
| T-002 | Paywall, sale enabled | HTTPS payment URL is returned, saved, and shown; can reload after redirect | Availability, Orders, GardnerAccountPage |
| T-003 | Bank create timeout; same buyer repeats click | No duplicate active payment obligation; same order/valid link or safe retry status; no blind second charge | Orders, PaymentGateway |
| T-004 | Sale closed / price unavailable | Clear explicit status and owner alert; never silently hide CTA as though available | Availability and frontend |
| T-005 | Completion transactional outbox, SMTP temporarily down | Event remains queued and retries; record accepted_by_mailer separately from delivered; consent enforced for marketing | SendTestInterpretationPaymentEmailJob, UserNotificationService |
| T-006 | Duplicate test completion | At most one logical offer notification per version, retries recorded without losing event | completion mail outbox |
| T-007 | Valid paid webhook, browser closed | Single paid order and single durable R27 dispatch intent | Orders, paid bridge |
| T-008 | Duplicate/reordered payment callbacks | Monotonic state; never revert paid; no duplicate generation or charge | payment webhook/dispatcher |
| T-009 | Crash immediately after paid DB commit | Reconciler finds missing dispatch and resumes automatically | GardnerCommerceTick, paid dispatch |
| T-010 | Restart workers while long model call in flight | Accepted checkpoints survive; lease fencing prevents concurrent owners; no unledgered repeat | GenerateGardnerV22ReportJob, supervisor |
| T-011 | DeepSeek read timeout / stream cut off | Classify reason, preserve unknown billing reserve, bounded recovery; no duplicate request without policy | DeepSeekHttpTransport, AttemptLedger |
| T-012 | Response persisted, crash before parsing | Complete from durable receipt, not a second paid API call | MeteredProvider, checkpoints |
| T-013 | Synthetic quality.json matches known 51 failed fields from incident | Local Unicode normalization on only affected leaves; passing fields untouched; rerun QA; no full regeneration | TextNormalizer, TextQuality, EditorialPipeline |
| T-014 | Humanizer hard ban still present | Bounded field repair or owner_attention; no made-up PASS; preserved numeric results | humanizer/pishi |
| T-015 | All 61 chunks ready, QA FAIL | Never publish, but continue repair/revalidation; owner MAX incident event | assembler/publication/outbox |
| T-016 | Report approved but publication feature flag off | State remains pending, durable owner actionable alert, no false delivery status | PublicationPolicy |
| T-017 | Approve report with wrong SHA / wrong buyer / wrong generated version | Block publication and log safe error code; no cross-client access | Publication, PaidArtifactStore |
| T-018 | PDF rendering fails, HTML already valid | Retry rendering only, no LLM regeneration, same report_json SHA | renderer |
| T-019 | Buyer email not OTP-verified after permitted checkout flow | Delivery policy handles verified ownership without relying on deprecated mandatory OTP; never sends to unverified arbitrary address | BuyerAccess, Outbox |
| T-020 | SMTP unavailable after publication | Buyer can securely access report in cabinet; report_ready event retained for retry; MAX tells owner of delivery issue | Outbox, customer account |
| T-021 | MAX API unavailable / 429 | Event remains durable, deduped and retryable; no progress spam; errors observable | AdminMaxNotificationService, MaxBotApiService |
| T-022 | Worker/timer disabled | Watchdog detects stuck paid orders, reports next action, restarts only in safe approved policy | systemd/recovery |
| T-023 | Installer aborts after files copied but before activation | Transactional staging + intact rollback, restart safe; never false ALREADY_DEPLOYED | deploy scripts |
| T-024 | Current site hashes differ from package pins | Hard stop before any files/services change; produce diff report | installer |
| T-025 | Two simultaneous paid customers | No checkpoint leakage, no wrong child identity, separate ledger, separate email and artifacts | all shared services |
| T-026 | Existing known-good teen report (e.g. order 102 synthetic equivalent) | Existing route stays accessible and unchanged, including hashes and signed URLs | teen publication |
| T-027 | Teen report user already booked consultation, or no slots | Report-ready mail not blocked by booking form availability | TeenApprovedMailDelivery |

## Evidence to collect for each result

Store `test_id, source_commit, package_sha256, test_environment, PASS/FAIL, assertions, sanitized event ids, runtime unit states, artifact hashes`. No private report text, test answers, email address, card/bank identifiers, API tokens or raw webhook bodies in public GitHub.

## Release gates

1. **Static:** unpacked source hash and manifest match, PHP lint, composer/npm dependencies, secret scan; no embedded customer PII.
2. **Unit:** parser, text normalizer, ledger, idempotency, QA, outbox semantics.
3. **Integration:** Laravel services, isolated DB and fake SMTP/MAX/bank/model; all T-001–T-027 pass.
4. **Browser:** mobile+desktop, free result to checkout, payment return, account state and download.
5. **Staging incident replay:** restore anonymized s106 state, not real user data; leave s105 untouched.
6. **Predeploy:** backup, release manifest, migration dry-run, worker / timer supervision, rollback plan.
7. **Go-live:** only after all gates; canary synthetic order; owner explicitly approves rollback-free publication.

## Prohibited shortcuts

Never mark QA PASS by directly changing a DB flag; never decrease a spending ledger to zero; never suppress fatal error with success echo; never use `quality_rejected` as the sole terminal disposition; never force-replace the production source if the SHA baseline differs; never equate SMTP accepted with mailbox delivered.
