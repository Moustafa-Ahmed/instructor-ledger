# Instructor Revenue Ledger — Implementation Plan

> Project: `D:\Personal\Demo-projects\Instructor-ledger\instructor-ledger`
> Stack: Laravel 11 · Pest 4 · Filament v3 · SQLite (tests) · MySQL (prod) · Mock Payment Provider
> Approach: **You build, I guide + review** (no team spawn).

---

## 0. Starting state (already in repo)

The mock payment provider is already built and tested. Don't rebuild it.

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

## 1. Design decisions (locked in by user)

| Decision | Choice |
|---|---|
| Who does the work | You implement, I guide + review |
| Allocation split | **Weighted by courses taught** (`course_instructor.revenue_weight`, default 1) |
| Platform cut | **Config-driven basis points**, default 3000 (= 30%) in `config/ledger.php` |
| Rounding | **Banker's rounding** (round-half-to-even) per share; sub-cent dust → synthetic platform allocation row |
| Money representation | **Integer cents only**, `App\Support\Money` value object — no floats |
| Allocations | **Append-only ledger** (`revenue_allocations` rows); balance = SUM(allocations) − SUM(paid) |
| Idempotency layers | 3: (a) charge key, (b) `payout_attempts.provider_reference` unique, (c) job `uniqueId()` + `lockForUpdate()` |

**Allocation invariant:** `SUM(revenue_allocations.amount_cents per subscription) = subscriptions.charged_amount_cents` for every subscription, always.

---

## 2. Build order (sequential, tests as you go)

### T1. Foundation
- [ ] `config/ledger.php` — `platform_cut_bps` (default 3000), `min_payout_cents` (default 1000), `payout_currency` (default 'USD')
- [ ] `app/Support/Money.php` — value object wrapping `int $cents`, `Currency $currency`
  - Methods: `of()`, `plus()`, `minus()`, `multiply()`, `__toString()`, `bankerRound(int $divisor): array{cents, dust}`
  - Static `split(Money $total, array<int> $weights): array{shares: array<int, int>, dust: int}` — banker's rounding, returns dust separately
- [ ] `app/Models/Plan.php` + migration — `name`, `interval` (monthly/quarterly/annual), `interval_count`, `amount_cents`, `currency`, `duration_days`
- [ ] Decide: **separate `instructors` table vs flag on `users`** — see §3 below; my rec: separate table

### T2. Domain schema (migrations + models + factories + seeders)
- [ ] `instructors` — `id`, `user_id` FK, `display_name`, `payout_destination` (string, opaque to us), `default_revenue_weight` int default 1, timestamps
- [ ] `courses` — `id`, `title`, `slug`, `is_active` bool, timestamps
- [ ] `course_instructor` — `course_id`, `instructor_id`, `revenue_weight` int default 1, composite PK + indexes
- [ ] `subscriptions` — `id`, `student_user_id` FK, `plan_id` FK, `status` (pending/active/refunded/cancelled), `started_at`, `ends_at`, `charged_amount_cents`, `currency`, `provider_charge_reference` UNIQUE, `charged_at`, timestamps
- [ ] `revenue_allocations` (**append-only ledger**) — `id`, `subscription_id` FK, `instructor_id` FK nullable (NULL = platform dust), `kind` enum(accrual|reversal|dust), `amount_cents` (signed? keep unsigned + use `kind` for sign), `source_allocation_id` FK nullable (for reversals), `idempotency_key` UNIQUE, timestamps
  - Indexes: `(subscription_id, kind)`, `(instructor_id, created_at)`
- [ ] `payout_batches` — `id`, `period_start`, `period_end`, `status` enum(building|processing|completed|failed), `total_amount_cents`, `instructor_count`, `created_at`, `completed_at`
- [ ] `payouts` — `id`, `batch_id` FK, `instructor_id` FK, `amount_cents`, `status` enum(pending|sent|failed|reconciling|clawed_back), `idempotency_key` UNIQUE, `provider_reference` nullable, `sent_at`, `failed_reason`, timestamps
- [ ] `payout_attempts` — `id`, `payout_id` FK, `provider_reference` nullable, `status`, `attempted_at`, `response_payload` json, `error_message` nullable
- [ ] `refunds` — `id`, `subscription_id` FK, `amount_cents`, `reason`, `provider_refund_reference` UNIQUE nullable, `status` enum(pending|completed|failed), `processed_at`, timestamps
- [ ] All composite uniques you'll rely on: `(subscription_id, kind, source_allocation_id)` for reversals, `(payout_id, status)` for attempts

### T3. Revenue allocation service
- [ ] `app/Services/Revenue/RevenueAllocator.php`
  - `allocate(Subscription $subscription): void` — wraps everything in `DB::transaction()` with `lockForUpdate()` on the subscription row
  - Steps:
    1. Idempotency check: if any allocation with `kind=accrual` exists for this subscription → return early
    2. Compute `instructor_pool = charged_amount × (1 − platform_cut_bps/10000)` (use `Money`)
    3. Pull all `(instructor_id, weight)` pairs from `course_instructor` where the course was active in the subscription period (default: all active courses — see §3)
    4. `Money::split($instructor_pool, $weights)` → `[shares, dust]`
    5. Insert one allocation row per instructor (accrual)
    6. If `dust > 0`, insert one allocation row with `instructor_id=NULL, kind=dust, amount_cents=dust`
  - Listens for `charge.succeeded` (event) or called directly — your call
- [ ] Unit tests: `tests/Unit/Services/Revenue/RevenueAllocatorTest.php`
  - Single instructor → all goes to them
  - Two equal weights → banker's rounding
  - Three weights with `100/3` split → banker's rounding vector (e.g. 100 cents split 33/33/34 or 34/33/33)
  - Dust row inserted when remainder is non-zero
  - Idempotent: second call returns same rows
  - Platform cut applied correctly
  - Subscription invariant: SUM(allocations) = charged_amount

### T4. Payout engine
- [ ] `app/Console/Commands/BuildPayoutBatch.php` — `php artisan ledger:build-payout-batch {--period-end=...} {--min-payout=...}`
  - For each instructor: `outstanding = SUM(accrual) − SUM(reversal) − SUM(paid_in_completed_batches)`
  - If `outstanding >= min_payout_cents`: insert a `payouts` row (status=pending) into a new `payout_batches` row
  - Idempotency: batch key = `(period_start, period_end, instructor_id)` — re-running won't create duplicates
  - Dispatches one `PayInstructorJob` per pending payout at the end
- [ ] `app/Jobs/PayInstructorJob.php`
  - `implements ShouldBeUnique`, `uniqueId = payout->id`, lock TTL 600s
  - `public function __construct(public int $payoutId)` — **never put the Eloquent model in the constructor** (stale serialization)
  - In `handle(MockPaymentProvider $provider)`:
    1. `lockForUpdate()` on the payout row
    2. If `status != pending` → return (idempotent exit)
    3. Call `$provider->sendMoney($payout->idempotency_key, $payout->amount_cents, $currency, ['payout_id' => $payout->id])`
    4. On success: payout.status = sent, payout.provider_reference, payout.sent_at = now()
    5. On `MockPaymentProviderFailedException`: payout.status = failed, payout.failed_reason, do NOT retry
    6. On `MockPaymentProviderTimeoutException`: payout.status = reconciling, dispatch `ReconcilePayoutJob($payoutId)`, with backoff
  - All wrapped in a DB transaction
- [ ] `app/Jobs/ReconcilePayoutJob.php`
  - Backoff: 10s, 30s, 2m, 5m, 30m (5 attempts)
  - Calls `$provider->status($payout->provider_reference)` if known, else `statusByIdempotencyKey('send', $payout->idempotency_key)`
  - On `succeeded` → finalize payout to `sent`
  - On `failed` → payout.status = failed
  - On still-unknown → release back to queue
  - Final attempt → payout.status = failed, mark for manual review (status field on payout)
- [ ] Test: `tests/Feature/Payouts/PayoutCommandTest.php` — running command twice never double-pays
- [ ] Test: `tests/Feature/Payouts/PayInstructorJobTest.php` — `Bus::fake()` to simulate retry; verify one provider call
- [ ] Test: `tests/Feature/Payouts/ReconcilePayoutJobTest.php` — timeout → reconciling → eventually settled

### T5. Refund handling
- [ ] `app/Models/Refund.php` + service `app/Services/Refunds/RefundSubscriptionAction.php`
- [ ] `refund(Subscription, int $amountCents, string $reason): Refund`
  - Wraps in `DB::transaction()` with `lockForUpdate()` on subscription
  - Validates: `amount <= (charged_amount − already_refunded)`
  - Calls `MockPaymentProvider` to do the actual refund (add a `refundMoney()` method if needed — or treat refund as a negative charge using `chargeMoney()` with a dedicated idempotency key namespace)
  - Writes `refunds` row
  - Pro-rates the negative across instructors proportionally to their existing allocations:
    - For each `accrual` allocation: `reversal_amount = floor(accrual.amount × refund_amount / charged_amount)`, track remainder
    - Insert a `kind=reversal` row per instructor (mirroring the original `source_allocation_id`)
    - Distribute any rounding remainder as an additional `kind=reversal` row against the highest-weight instructor (deterministic by id)
  - Sets `subscriptions.status = 'refunded'` if fully refunded
- [ ] Edge case: **refund issued after payout sent**
  - The instructor already received money they now need to "give back"
  - Ledger correctly shows negative outstanding balance
  - Mark all *future* payouts for that instructor as `clawed_back` state (we're not actually clawing back real money from a bank in this challenge — that's a separate process — but the ledger MUST reflect the negative balance)
  - Document this in ARCHITECTURE.md
- [ ] Test: `tests/Feature/Refunds/RefundTest.php` — full refund, partial refund, refund-after-payout, subscription invariant still holds

### T6. Filament v3
- [ ] `composer require filament/filament:"^3.2" -W`
- [ ] `php artisan filament:install --panels` (or skip panel and use standalone; my rec: use the admin panel — it's the standard)
- [ ] Create `app/Filament/Resources/InstructorResource.php` (read-only)
  - Table columns: name, **accrued_cents** (SUM accruals), **paid_cents** (SUM sent payouts), **outstanding_cents** (accrued − paid − reversed), last_payout_at
  - Use a custom accessor on the model OR a query scope — don't materialize
- [ ] Create `app/Filament/Resources/PayoutResource.php` (read-only)
  - Filters: instructor, batch, status, date range
  - Columns: instructor, batch, amount, status, sent_at, provider_reference
- [ ] Register an admin user (seed or first-user check) so you can demo the screen

### T7. Critical tests (the ones the rubric calls out)
- [ ] `tests/Feature/Payouts/DoubleRunTest.php` — run `BuildPayoutBatch` twice with same period → no duplicate payouts, totals match
- [ ] `tests/Feature/Payouts/RetriedJobTest.php` — simulate Laravel retrying `PayInstructorJob` → still one provider call (mock with `Mockery::spy` and assert `sendMoney` called once)
- [ ] `tests/Feature/Payouts/ProviderTimeoutTest.php` — `useDeterministicOutcomes(OUTCOME_TIMEOUT_AFTER_SUCCESS)` → eventually settles to `sent` via reconciliation
- [ ] `tests/Unit/Support/MoneyTest.php` — banker's rounding vector tests + dust accumulation
- [ ] `tests/Feature/Refunds/RefundAfterPayoutTest.php` — pay out, then refund, balances go negative correctly
- [ ] `tests/Feature/LedgerIntegrityTest.php` — for N random subscriptions, SUM(allocations) = charged_amount; SUM across all instructors + dust = charged_amount
- [ ] All existing mock provider tests should still pass (`vendor/bin/pest`)

### T8. Docs
- [ ] `README.md` — rewrite (currently Laravel boilerplate)
  - Project summary
  - Setup: `composer install`, `cp .env.example .env`, `php artisan key:generate`, `php artisan migrate --seed`
  - Run tests: `vendor/bin/pest`
  - Run demo: `php artisan ledger:build-payout-batch` etc.
  - Assumptions (platform cut 30%, etc.)
- [ ] `docs/ARCHITECTURE.md`
  - Domain model diagram (ASCII or describe in text)
  - Database design rationale (why append-only ledger, why `revenue_allocations` not running balances)
  - Revenue allocation strategy (weighted, banker's rounding, dust row)
  - Idempotency: 3 layers explained
  - Provider timeout handling: 3 outcomes, reconciliation worker
  - Refund flow + clawback limitation
  - Scaling: queue priorities, partitioning by period, batch size, indexing
  - Known limitations (no real bank clawback, no multi-currency conversion, no instructor onboarding KYC)
- [ ] `docs/AI_USAGE.md` (the challenge explicitly requires this)
  - How I used AI: clarifying trade-offs, brainstorming edge cases, suggesting test vectors
  - Workflows/prompts I relied on
  - What AI generated vs what I designed
  - Engineering decisions I personally made
  - What differentiates this from a typical AI submission
  - Trade-offs and improvements I intentionally chose

### T9. Senior bonus (discussion-only)
- [ ] `docs/SENIOR_BONUS.md` — "How would we handle a mid-term plan upgrade?"
  - Approach: close old sub early, issue prorated refund allocation, open new sub, fresh allocation
  - Why: avoid editing historical allocation rows (audit trail); the ledger stays append-only
  - Edge case: student upgrades monthly → annual 10 days in
    - Compute unused days from monthly = (30 − 10) / 30 = 66.7% of monthly fee → refund allocation
    - Charge full annual fee → new allocation
    - Net cash flow: student pays (annual − 0.667 × monthly); instructor balance reflects both events
  - Discuss: would you allow downgrades? What about plan-change fees? Proration rounding?

### T10. Video submission outline (15–20 min, 6 sections)
- [ ] 0:00–2:30 Intro + relevant background
- [ ] 2:30–7:30 Architecture walkthrough (domain model, DB, allocation, balance calc, payout)
- [ ] 7:30–14:00 Failure scenarios (double-run, retry, timeout, timeout-then-settle, refund-after-payout, rounding)
- [ ] 14:00–16:30 Testing strategy
- [ ] 16:30–18:30 AI usage + decisions
- [ ] 18:30–20:00 Future improvements (real clawback, multi-currency, partition strategy, event sourcing for audit)

---

## 3. Open decisions for T1 (decide BEFORE writing T1 code)

### 3a. Instructor storage
- **Rec: separate `instructors` table** FK to `users`
- Reason: payout destination, revenue weight, lifetime balances are instructor concerns; instructor may not even be a platform user with login
- Alternative: boolean `is_instructor` + nullable columns on `users` — simpler but pollutes the users table

### 3b. Course access model
- **Rec: subscription grants access to all active courses** for the term
- Allocation goes to every instructor with at least one active course in the subscription period
- Reason: matches the challenge wording ("A single subscription gives access to courses from many instructors")
- Alternative: explicit `enrollments` table — more accurate but adds schema + a new concept not in the brief

Tell me your calls on 3a + 3b and I'll lock them in the doc. Then start with T1 + T2 — push migrations when green and I'll review.

---

## 4. Key invariants (paste above your monitor while coding)

1. **Integer cents only** in DB. No `DECIMAL`, no `float`. Always.
2. **`SUM(allocations) = charged_amount`** for every subscription, every time. Test it with `LedgerIntegrityTest`.
3. **Provider call = one row in `payout_attempts`.** No silent retries, no orphan money.
4. **Idempotency keys are deterministic**, derived from stable IDs (e.g. `payout:{id}`), not random.
5. **Append-only ledger.** Never `UPDATE` or `DELETE` an allocation row. Reversals = new row.
6. **Every status transition is conditional + transactional.** Don't assume state; read it inside the lock.

---

## 5. Risk register (watch these)

| Risk | Mitigation |
|---|---|
| Off-by-one cent in split | Money unit tests with known vectors; integrity test on every seed |
| Retried job double-charges | `ShouldBeUnique` + provider idempotency key + `lockForUpdate` |
| Timeout leaves payout in limbo | `reconciling` status + dedicated reconcile job with backoff |
| Refund after payout | Ledger goes negative; document limitation; flag for manual review |
| Race between two batch builds | `payout_batches` unique on `(period_start, period_end)` + advisory lock |
| Rounding dust accumulates | Single dust row per subscription is fine; test sum integrity |

---

## 6. When you start coding

1. Make your T1 calls (3a, 3b above) — reply to me
2. Write T1 + T2 (config + Money + migrations + models)
3. Run `php artisan migrate:fresh` locally, push the branch, ping me
4. I'll review schema + Money before you start allocation logic

I'm here for the rest — just push and I'll respond to questions, do code review, and help you think through the failure scenarios. Good luck.
