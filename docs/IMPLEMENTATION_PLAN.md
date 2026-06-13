# Instructor Revenue Ledger — Implementation Plan

## Phase 0 — Starting state (already in repo)

Mock payment provider is built and tested. Don't rebuild it.

- `app/Services/Payments/MockPaymentProvider.php` — `chargeMoney()` / `sendMoney()` with idempotency keys
- `app/Models/MockPaymentOperation.php` — `mock_payment_operations` table
- `app/Http/Controllers/MockPaymentProviderController.php` + 3 form requests
- `database/migrations/2026_06_11_000000_create_mock_payment_operations_table.php`
- `tests/Feature/MockPaymentProviderTest.php` (9 tests) + `MockPaymentProviderApiTest.php` (8 tests)
- `routes/api.php` — `/api/mock-provider/{charges,sends,operations/.../status}`
- Outcomes: `succeeded` / `failed` / `timeout_after_success` (deterministic or random)
- Deterministic mode via `useDeterministicOutcomes([...])` — keep this, your tests will use it

**Missing:** the actual money core (schema, allocation, payouts, refund, Filament, docs).

---

## Phase 1 — Foundation

**On disk:**

- [x] `config/ledger.php` — `platform_cut_bps` (3000), `min_payout_cents` (1000), `currency` (USD), plus `idempotency` key namespaces (`charge` / `send` / `refund`); all env-overridable
- [x] `app/Support/Money.php` — minimal value object: `readonly int $cents`, `readonly string $currency = 'USD'`, `add(self)`, `subtract(self)`, currency-mismatch guard
- [x] `app/Enums/PlanInterval.php` — `Monthly` / `Quarterly` / `Annual` with `interval(int)` and `advance(CarbonImmutable, int)`
- [x] `tests/Unit/Support/MoneyTest.php` — construction, currency default + override, add/subtract, immutability, currency mismatch throws, zero, negatives
- [x] `pint.json` — `laravel` preset + `declare_strict_types`

**Still to do in this phase:**

- [ ] `app/Models/Plan.php` + migration (`name`, `interval` enum, `interval_count`, `amount_cents`, `currency`, `duration_days`) + factory
- [x] `instructors` storage: **separate `instructors` table**, FK to `users` (`user_id` nullable)
- [x] Course access model: **subscription → all active courses** for the period; no `enrollments` table
- [ ] First migration run (`php artisan migrate:fresh`) — push, review

> `Money` stays minimal by design. Allocator does its own math in Phase 3 with `intdiv` + largest-remainder — not as `Money` methods. Allocator owns its math; `Money` stays a dumb container.

---

## Phase 2 — Domain schema

Migrations + models + factories + seeders.

- [ ] `instructors` + model
- [ ] `courses` + model
- [ ] `course_instructor` pivot + model
- [ ] `subscriptions` + model — `platform_cut_bps` snapshot column, `status` enum
- [ ] `revenue_allocations` + model — signed `amount_cents`, `kind` enum (`accrual`/`reversal`), append-only enforced in code
- [ ] `refunds` + model
- [ ] `payout_periods` + model — unique `(year, month)`
- [ ] `payouts` + model — `status` enum (`pending`/`sent`/`failed`/`reconciling`, no `processing`)
- [ ] `payout_attempts` + model — `status` enum (`succeeded`/`failed`/`timeout`)
- [ ] Composite uniques: `(year, month)` on payout_periods, `payout:{period_id}:{instructor_id}` on payouts, `(subscription_id, kind, source_allocation_id)` on revenue_allocations
- [ ] Factories + seeders: 1 plan, 3 instructors, 2 courses with course_instructor rows, 10 subscriptions

**Push after this phase. Reviewer checks schema before any money-moving code lands.**

---

## Phase 3 — Revenue allocation

- [ ] `app/Services/Revenue/RevenueAllocator.php`
    - `allocate(Subscription)` inside `DB::transaction()` with `lockForUpdate()` on the subscription row
    - Idempotency: any existing `revenue_allocations` for this subscription → return early
    - `platform_share = intdiv(charged_amount × platform_cut_bps, 10000)` (cents)
    - `instructor_pool = charged_amount − platform_share`
    - Pull `(instructor_id, revenue_weight)` for every instructor with at least one active course in the subscription period
    - **Largest-remainder allocation:** `floor(pool × weight / total_weight)` per share, distribute leftover cents one at a time highest-remainder-first, tie-break by lowest `instructor_id`
    - Insert one `revenue_allocations` row per instructor (`kind=accrual`, signed positive)
    - `SUM(inserted.amount_cents) === instructor_pool` always
- [ ] `tests/Unit/Services/Revenue/RevenueAllocatorTest.php`
    - Single instructor → all pool to them
    - Two equal weights → exact 50/50
    - Three equal weights with `pool=100` → `[33, 33, 34]` (sum 100, deterministic)
    - Pool that doesn't divide evenly across unequal weights → all cents allocated, highest remainder first
    - Platform cut applied correctly
    - Idempotent: second call returns the same row set
    - Invariant: `SUM(allocations.amount_cents) = subscriptions.charged_amount_cents − platform_share`

---

## Phase 4 — Refunds

- [ ] `app/Services/Refunds/RefundSubscriptionAction.php`
    - `refund(Subscription, int $amountCents, string $reason): Refund`
    - Inside `DB::transaction()` with `lockForUpdate()` on subscription
    - Validate: `amount_cents <= (charged_amount − SUM(prior refunds))`
    - Call provider's `refundMoney(...)` with deterministic idempotency key
    - Write `refunds` row
    - **Reversals mirror original accrual distribution:** for each accrual row, `reversal_amount = floor(accrual.amount × refund_amount / charged_amount)`, remainder assigned to highest-weight instructor (tie-break by lowest instructor_id)
    - Insert one `revenue_allocations` row per affected instructor with `kind=reversal`, `amount_cents = -reversal_amount`, `source_allocation_id = accrual.id`
    - If fully refunded → `subscriptions.status = 'refunded'`
- [ ] `tests/Feature/Refunds/RefundTest.php` — full refund, partial refund, refund-after-payout, invariant still holds

---

## Phase 5 — Payout engine

- [ ] `app/Console/Commands/RunPayoutsCommand.php` — `php artisan ledger:run-payouts`
    - Resolve target period (default: previous calendar month; `--year` / `--month` flags)
    - Load-or-create `payout_periods` row (unique on `(year, month)`)
    - If period is `completed` and `--force` not passed, exit early
    - Inside a transaction: mark period `pending`, compute instructor earnings, create `payouts` rows (idempotency key `payout:{period_id}:{instructor_id}`)
    - Dispatch one `PayInstructorJob` per pending payout
- [ ] `app/Jobs/PayInstructorJob.php`
    - `implements ShouldBeUnique`, `uniqueId = payout->id`, lock TTL 600s
    - Constructor takes `int $payoutId` only (never the Eloquent model)
    - `lockForUpdate()` on payout; if `status != pending` → return (idempotent exit)
    - Call `$provider->sendPayout(payout.idempotency_key, ...)`
    - `succeeded` → `payout.status = sent`, set `provider_reference`, `sent_at`; insert `payout_attempts` row
    - `failed` (permanent) → `payout.status = failed`, `failed_reason`; do **not** retry
    - `timeout` → `payout.status = reconciling`; dispatch `ReconcilePayoutJob` with backoff; insert `payout_attempts` row with `status=timeout`
- [ ] `app/Jobs/ReconcilePayoutJob.php`
    - Backoff: 10s, 30s, 2m, 5m, 30m. 5 attempts total
    - Query provider via `checkStatus($provider_reference)` if known, else `checkStatusByIdempotencyKey(...)`
    - `succeeded` → `sent`; `failed` → `failed`; `unknown` → release back to queue
    - **After final attempt with state still unknown → `failed` + `reconciliation_exhausted` flag in Filament**
- [ ] `tests/Feature/Payouts/RunPayoutsCommandTest.php` — double-run, totals match, no duplicates
- [ ] `tests/Feature/Payouts/PayInstructorJobTest.php` — retried job still makes one provider call
- [ ] `tests/Feature/Payouts/ReconcilePayoutJobTest.php` — timeout → reconciling → eventually settled

---

## Phase 6 — Filament

- [ ] `composer require filament/filament:"^3.2" -W`
- [ ] `php artisan filament:install --panels`
- [ ] `app/Filament/Resources/InstructorResource.php` (read-only)
    - Columns: name, **earned_cents** (accruals − reversals), **paid_cents** (sum sent payouts), **outstanding_cents** (signed), last_payout_at
    - Use a custom accessor or query scope — don't materialize
    - Negative outstanding → visual flag ("balance owed back")
- [ ] `app/Filament/Resources/PayoutResource.php` (read-only)
    - Columns: instructor, period, amount, status, sent_at, provider_reference
    - Filters: instructor, period, status, date range
- [ ] `app/Filament/Resources/PayoutPeriodResource.php` (bonus) — list periods, status, completed_at, payout count, total cents
- [ ] Admin user seeder

---

## Phase 7 — Critical tests (rubric)

- [ ] `tests/Feature/Payouts/DoubleRunTest.php` — `RunPayoutsCommand` twice, same period → no duplicate payouts, totals match
- [ ] `tests/Feature/Payouts/RetriedJobTest.php` — `PayInstructorJob` retried via `Bus::fake()` → still one provider call (assert with `Mockery::spy`)
- [ ] `tests/Feature/Payouts/ProviderTimeoutTest.php` — `useDeterministicOutcomes(OUTCOME_TIMEOUT_AFTER_SUCCESS)` → eventually settles to `sent` via reconciliation
- [ ] `tests/Feature/Refunds/RefundAfterPayoutTest.php` — pay out, then refund, balances go negative correctly
- [ ] `tests/Feature/LedgerIntegrityTest.php` — for N random subscriptions, `SUM(allocations) = charged_amount − platform_share`
- [ ] `tests/Unit/Services/Revenue/RevenueAllocatorTest.php` — full vector coverage (built in Phase 3)
- [ ] All existing mock provider tests still pass (`vendor/bin/pest`)

---

## Phase 8 — Docs

- [ ] `README.md` — rewrite (currently Laravel boilerplate)
    - Project summary
    - Setup: `composer install`, `cp .env.example .env`, `php artisan key:generate`, `php artisan migrate --seed`
    - Run tests: `vendor/bin/pest`
    - Run demo: `php artisan ledger:run-payouts`, etc.
    - Assumptions (platform cut 30%, monthly settlement, single currency, all-active-instructors allocation)
- [ ] `docs/ARCHITECTURE.md`
    - Domain model diagram (ASCII)
    - Database design rationale (append-only ledger, why `payout_periods` is first-class)
    - Revenue allocation strategy (largest-remainder)
    - Idempotency: 3 layers
    - Provider timeout handling: 3 outcomes, reconciliation worker
    - Refund flow + signed-outstanding limitation
    - Scaling notes
    - Known limitations (no real bank clawback, no multi-currency, no engagement-based allocation)
- [ ] `docs/AI_USAGE.md` (challenge requirement)
    - How AI was used: trade-off discussions, edge-case brainstorming, test-vector suggestions
    - What AI generated vs what was designed
    - Engineering decisions made personally
    - What differentiates this from a typical AI submission
    - Trade-offs and improvements intentionally chosen
- [ ] `docs/VIDEO_OUTLINE.md` — 15–20 min, 6 sections (intro, architecture, failure scenarios, testing, AI usage, future improvements)

---
