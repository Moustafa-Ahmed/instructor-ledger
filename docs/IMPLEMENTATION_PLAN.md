# Instructor Revenue Ledger — Implementation Plan

A Laravel 11 + Pest implementation of the challenge brief: a money core for an LMS that takes subscription payments in, allocates revenue to instructors, and pays them out monthly — correctly under retries, timeouts, and partial refunds.

The plan reflects the code as of the latest round of design changes. Older design iterations are in git history.

---

## Decisions Log

Trade-offs made during design. Each is the chosen path; the rejected alternative is in italics.

- **Unified 4-type `ledger_entries`** (vs. _instructor-centric ledger + separate `payouts` + separate `platform_revenue` tables_). One row, one truth, one SUM() to answer "how much has the student paid / the platform kept / each instructor earned." _Rejected: separate tables force UNIONs and lose the single-source-of-truth for cross-domain questions._

- **Calendar-month plans** (vs. _arbitrary-day subscription terms_). "Monthly / 3-month / annual" is implemented as N sequential `Subscription` rows aligned to month boundaries, not as a single subscription with a long term. Simplifies the per-month payout cycle, the refund proration, and the test invariants.

- **Monthly payout cycle, not per-charge allocation** (vs. _write per-instructor accrual rows at charge time_). The platform's cut and the per-instructor payouts are written once at the end of each month. Allocations are derived, not stored. Real-time balance visibility is sacrificed for a much cleaner monthly invariant and a much smaller hot table.

- **Partial refunds as a negative `subscription_payment` row** (vs. _per-instructor mirror reversal rows with `source_allocation_id` chains_). A refund reduces the instructor pool naturally at the next monthly payout because the refund row is already negative in the same SUM. No clawback row, no per-instructor distribution math at refund time.

- **`period` derived from `started_at`** (vs. _stored `period_year` / `period_month` columns_ on `subscriptions` and `ledger_entries*`). The columns were pure denormalization. Month queries are `where started_at >= first_of_month AND started_at < first_of_next_month` against the `started_at` index, which is fast and avoids insert-time copy bugs.

- **Instructors merged into `users` with a `role` column** (vs. _separate `instructors` table_). Saves a join. An instructor is just a user with a different role. The `course_instructor` and `ledger_entries` FKs all point to `users`.

- **No `platform_cut_bps` snapshot on subscriptions** (vs. _per-row snapshot of the config at charge time_). The config is the single source of truth; every period's cut is computed from `config('ledger.platform_cut_bps')`. If the config ever needs to change mid-year, historical months are not recomputed — the close service's idempotency_key design is what protects them.

- **No `courses.is_active` flag** (vs. _a soft-delete-style active/inactive flag_). Every attached instructor is eligible; the schema doesn't carry course lifecycle. If a course is being retired, the attachment is removed (or, in production, would be soft-deletable via a `course_instructor.ended_at`).

- **`payout_attempts` info folded into `meta` JSON on `instructor_payout` rows** (vs. _separate `payout_attempts` table_). Attempt history is a diagnostic, not a hot query. Provider-side truth lives in `mock_payment_operations`; the `meta` JSON is the row-local summary. If per-attempt analytics become important, split it out.

- **Command-driven money flow** (vs. _HTTP API / web UI_). The challenge brief says "you do not need a full application." Artisan commands (`ledger:charge-subscription`, `ledger:refund-subscription`, `ledger:run-payouts`) are the user interface. No controllers, no form requests, no student-facing UI. The only Filament screen is the read-only ops view.

- **Service layer + thin jobs** (vs. _fat jobs with business logic inline_). Each side effect (charge, refund, close month, pay instructor, reconcile) has a service class that owns the transaction, the provider call, and the DB writes. Jobs are thin orchestrators: load the row, dispatch to the service, write the result, schedule the next step on a flag. This makes the services directly testable without queuing, and keeps the jobs readable as a single paragraph.

- **`cancel_date` lives on `subscriptions`, not on `refunds`** (vs. _refund-row placement_). The cancel date is the _input_ to the proration math, not a property of the provider-workflow row. The `Refund` model docblock already says "no financial logic of its own" — storing the cancel date there contradicted that boundary. With it on the subscription, the state transition (`status=refunded`) and the transition timestamp (`cancel_date`) commit atomically in the same `update()` call.

- **Phase 5 idempotency: precheck + unique-violation catch, no row locks** (vs. _`lockForUpdate()` on subscriptions + `sharedLock` on the join_). The two SQL hints are mutually exclusive on the same query, and the unique index on `idempotency_key` already makes the race harmless. Two layers (fast precheck + catch-and-refetch on `QueryException` 23000) covers the common case and the rare race. No row locks means a re-run or a concurrent close attempt can't block readers.

- **Empty / zero-net month → no rows written** (vs. _sentinel `platform_cut:YYYY-MM` row with `amount_cents = 0`_). The unique index is a gate, not a marker. Once a zero-amount row is written, a late refund that arrives later cannot be reflected in that month (the unique key blocks the non-zero rewrite). With nothing written, the month stays in the "not closed" state and a future close attempt after a late refund can produce real rows.

---

## Business Decisions

**Revenue Allocation:** Instructors are paid **proportionally based on the number of courses they teach**. An instructor teaching 3 courses earns 3x more than one teaching 1 course, regardless of student enrollment or course popularity. The pool for month N is `sum(subscription_payment) + sum(subscription_refund)` for that month; 30% (configurable) goes to the platform, 70% is split among the instructors who taught active courses during the month, weighted by their `course_instructor.revenue_weight` summed across courses.

---

## Phase 0 — Mock payment provider ✅ DONE

Built and tested. Don't rebuild it.

- `app/Services/Payments/MockPaymentProvider.php` — `chargeMoney()` / `sendMoney()` / `refundMoney()` / `checkStatusByIdempotencyKey()`, all idempotency-key aware
- `app/Models/MockPaymentOperation.php` — `mock_payment_operations` table
- `app/Http/Controllers/MockPaymentProviderController.php` + 3 form requests
- `database/migrations/2026_06_11_000000_create_mock_payment_operations_table.php`
- `tests/Feature/MockPaymentProviderTest.php` (9 tests) + `MockPaymentProviderApiTest.php` (8 tests)
- Outcomes: `succeeded` / `failed` / `timeout_after_success` (deterministic or random)
- Deterministic mode via `useDeterministicOutcomes([...])` — used by the rubric tests

---

## Phase 1 — Schema ✅ DONE

10 migrations. 8 models. 4 enums.

**Migrations**

- `database/migrations/2026_06_13_000000_create_plans_table.php` — `id, name, price_cents, currency, months` (the `months` is how many sequential monthly subscriptions a single plan purchase creates)
- `database/migrations/2026_06_13_000002_create_courses_table.php` — `id, title`
- `database/migrations/2026_06_13_000003_create_course_instructor_table.php` — `course_id, user_id, revenue_weight`, composite PK
- `database/migrations/2026_06_13_000004_create_subscriptions_table.php` — `user_id, plan_id, status, started_at, ends_at, charged_amount_cents, currency, provider_charge_reference (unique), charged_at`; indexes on `started_at`, `(user_id, status)`, `status`, `charged_at`
- `database/migrations/2026_06_13_000005_create_ledger_entries_table.php` — `subscription_id (nullable), user_id (nullable), type, amount_cents, idempotency_key (unique), subscription_entry_id (nullable, unique — one refund per payment), currency (nullable), meta (json)`; only explicit indexes are the two uniques
- `database/migrations/2026_06_13_000006_create_refunds_table.php` — `subscription_id, amount_cents, status, provider_refund_reference, cancel_date`; workflow tracking only, no financial logic

**Enums**

- `app/Enums/UserRole.php` — `Student` / `Instructor`
- `app/Enums/LedgerEntryType.php` — `SubscriptionPayment` / `SubscriptionRefund` / `PlatformCut` / `InstructorPayout`
- `app/Enums/SubscriptionStatus.php` — `Active` / `Refunded`
- `app/Enums/RefundStatus.php` — `Pending` / `Completed` / `Failed`

**Models**

- `app/Models/User.php` — `taughtCourses()`, `subscriptions()`, `ledgerEntries()`
- `app/Models/Plan.php`
- `app/Models/Course.php` — `instructors()` (BelongsToMany on `User`)
- `app/Models/Subscription.php` — `scopeForPeriod($year, $month)` derives the bounds from `started_at`
- `app/Models/LedgerEntry.php` — `subscriptionEntry()` (self-referential on `subscription_entry_id`)
- `app/Models/Refund.php`

**Factories + seeder** — minimal viable demo (1 plan, 3 instructors, 2 courses, 1 subscription).

**Per-month invariant (the test that proves the books balance):**

```
SUM(amount_cents) for ledger entries of (type, month) over month N
  = platform_cut(N)         — one row, user_id=null
  + SUM(instructor_payout)  — one row per instructor, user_id=instructor
  + SUM(subscription_payment + subscription_refund) for subscriptions in month N
  = 0
```

`subscription_payment` and `subscription_refund` are sign-aware (refunds already negative), so the SUM across both equals the net cash flow from students in the month.

---

## Phase 2 — Monthly payout calculator ✅ DONE

**`app/Services/Payouts/MonthlyPayoutCalculator.php`** — pure function, no DB writes.

- `calculate(int $year, int $month): PayoutDraft`
- `PayoutDraft` is a readonly DTO: `platform_cut_cents: int`, `instructor_payouts: array<int, int>` keyed by `user_id`
- Reads all `subscription_payment` + `subscription_refund` rows in the period (via `scopeForPeriod` on `Subscription`)
- `net = sum(payments) + sum(refunds)` (refunds already negative)
- `platform_cut = intdiv(net × config('ledger.platform_cut_bps'), 10000)` — uses the live config value. The `subscriptions` table has no per-subscription snapshot column. See the docblock on `MonthlyPayoutCalculator::calculate()` for the trade-off.
- `instructor_pool = net - platform_cut`
- For each instructor who taught an active course in the period, sum their `course_instructor.revenue_weight` across courses; `total_weight = sum of those`
- Per-instructor: `floor(instructor_pool × weight / total_weight)`. Distribute leftover cents one at a time, highest-remainder first, tie-break by lowest `user_id`.
- **The largest-remainder invariant is enforced by the calculator, not by the DB.** SUM of all per-instructor cents equals `instructor_pool` exactly.

**Tests: `tests/Unit/Services/Payouts/MonthlyPayoutCalculatorTest.php`** — 16 vectors, 32 assertions, all passing. Coverage:

- Single instructor (1 course) → all pool
- Two instructors equal weight → 50/50
- Proportional split by weight (1:2 ratio)
- Five equal-weight instructors sum to the exact pool
- Largest-remainder tie-break by lowest `user_id` (three equal weights, net=100 → platform=30, pool=70, split `[24, 23, 23]`, extra cent to lowest id)
- Multi-leftover distribution (1:2:1, no leftover; 2:1, one leftover)
- Refund reduces the pool
- Non-positive net (full refund) → 0 cut, 0 payouts
- Custom `config('ledger.platform_cut_bps')` is respected
- Invariant: `platform_cut + sum(instructor_payouts) === net` exactly (stressed with awkward amount 777)
- Empty month → 0 cut, empty payouts
- No active courses → platform keeps full net
- Inactive courses don't contribute to weights
- Cross-month isolation (June vs. July)
- Multi-course instructor (weights summed across courses)

---

## Phase 3 — Charge subscription service + job + command ✅ DONE

### `app/Services/Subscriptions/ChargeSubscriptionService.php`

- `charge(int $studentId, int $planId, CarbonInterface $date): Subscription`
- Returns the new (or pre-existing, on idempotent re-run) `Subscription`
- Single attempt, throws on provider failure. Retry is the job's job.
- Pre-check outside the transaction: if a `Subscription` already exists for `(user_id, started_at)` → return it.
- Inside `DB::transaction()`:
    - `lockForUpdate()` on the `(user_id, started_at)` pair — double-checked-locking to handle the race between precheck and insert.
    - `provider_charge_reference = "ch:{studentId}:{year}-{month}"` — deterministic, not random. The unique index on `provider_charge_reference` is the backstop.
    - `idempotency_key` sent to the provider is `"charge:" . $provider_charge_reference`.
    - `MockPaymentProvider::chargeMoney(...)`. On `failed` or `timeout_after_success`, the provider throws; the transaction rolls back. No rows written.
    - On `succeeded`: insert `Subscription` row, insert `LedgerEntry` of `type=subscription_payment` with `idempotency_key = "payment:subscription:{id}"`.
- Catches `QueryException` for unique-violation races; returns the existing row.

### `app/Jobs/ChargeSubscriptionJob.php`

- `public int $tries = 5;` and `public array $backoff = [1, 2, 4, 8, 16];` — Laravel-native retry policy.
- `public int $timeout = 60;` — provider call + DB writes must complete in 60s.
- Constructor: `(int $studentId, int $planId, CarbonImmutable $date)`.
- `handle(ChargeSubscriptionService $service)`: one-liner, `$service->charge(...)`.
- The retry recovers from `MockPaymentProviderTimeoutException` implicitly: the provider's `mock_payment_operations` row was written on the first attempt (its inner transaction committed before the throw). On the second attempt, the provider's `createOrReturnOperation` finds the existing row by idempotency_key and returns the prior `succeeded` result. The service then inserts the `Subscription` and `LedgerEntry` rows. No recovery code needed in the service.

### `app/Console/Commands/ChargeSubscriptionCommand.php`

- Signature: `ledger:charge-subscription {student_id} {plan_id} {date}`.
- Validates: date parses as `YYYY-MM-DD`, student exists with `role=student`, plan exists. Exit code 2 (INVALID) on bad date, 1 (FAILURE) on missing student/plan.
- `ChargeSubscriptionJob::dispatch(...)`. Prints "Charge job dispatched. It will be retried up to 5 times with exponential backoff on provider timeout."

### Tests

- `tests/Unit/Services/Subscriptions/ChargeSubscriptionServiceTest.php` — 12 vectors: success, provider `failed` and `timeout` write no rows, idempotent re-run in same month, different month = new row, race recovery on unique violation, role check, missing student/plan, deterministic `provider_charge_reference` and ledger `idempotency_key` patterns.
- `tests/Feature/Jobs/ChargeSubscriptionJobTest.php` — 6 vectors: `$tries` and `$backoff` declarations, `Bus::dispatch` shape, success on first attempt, recovery on retry after timeout (provider's idempotency_key handles it), `failed` outcome exhausts, persistent timeout exhausts. Each test rebinds a fresh `MockPaymentProvider` instance to avoid singleton state leakage between tests.
- `tests/Feature/Console/ChargeSubscriptionCommandTest.php` — 3 vectors: dispatch shape, invalid date format, missing student id.

---

## Phase 4 — Refund subscription service + command ✅ DONE

### `app/Services/Subscriptions/RefundSubscriptionService.php`

- `refund(int $subscriptionId, ?CarbonImmutable $cancelDate = null): Refund`
- `cancelDate` defaults to `now()` if null
- Validate: `cancelDate ∈ [startedAt, endsAt)` — half-open period. The subscription's `endsAt` is the first day of the next month, so a cancel date equal to `endsAt` is rejected.
- Load the `subscription_payment` `LedgerEntry` row via `firstOrFail`. The refund ledger row links back to it via `subscription_entry_id`.
- Precheck: if a `Refund` row already exists for this subscription (single refund per subscription), return it. Idempotent re-run.
- **Partial refund math**: `daysInMonth = $startedAt->daysInMonth` (Carbon's accessor — handles 28/30/31 correctly), `daysUsed = $cancelDate->day` (the cancel day is treated as "used"), `daysRemaining = max(0, daysInMonth - daysUsed)`, `refundAmount = intdiv(chargedAmount × daysRemaining, daysInMonth)`. The cancel-day-as-used convention is simpler and matches common subscription billing practice.
- **Skip the provider call when `refundAmount == 0`** (cancel on the last day of the period). The refund row is still written and the subscription is marked `refunded`, but no money moves at the provider.
- **Provider call happens outside the service's transaction** (the outbox-pattern-lite — see `ChargeSubscriptionService` for the full rationale). The provider's `mock_payment_operations` row survives a timeout; the retry finds it by `idempotency_key = "refund:subscription:{id}"`.
- `DB::transaction()`:
    - Insert `Refund` row with `status=completed`, `provider_refund_reference="re:subscription:{id}"` (no `cancel_date` — that field was moved to `subscriptions`; see note below).
    - Insert `LedgerEntry` of `type=subscription_refund`, `amount_cents = -refundAmount`, `idempotency_key = "refund:subscription:{id}:ledger"`, `subscription_entry_id` → the original `subscription_payment` row. Skipped if `refundAmount == 0` (nothing to record).
    - Update `subscription.status = refunded` and `subscription.cancel_date = $cancelDate` in the same `update()` call — atomic state transition. The two fields commit together; a `Refunded` subscription can never have a `null` `cancel_date`.

> **Note — `cancel_date` moved from `refunds` to `subscriptions`.** The cancel date is the _input_ to the proration math, not a property of the provider-workflow row. The `Refund` model docblock says it holds "no financial logic of its own" — storing the cancel date there contradicted that boundary. The `subscriptions.cancel_date` is the state-transition timestamp (the moment the subscription flipped from Active to Refunded); the `refunds` row tracks only whether the provider acknowledged the money movement. Migration: added `cancel_date` (nullable, indexed) to `subscriptions`, removed the column from `refunds`. The `Subscription` model has `cancel_date` in `$fillable` and cast to `date`. The `RefundSubscriptionCommand` re-fetches the subscription to print the cancel date.

- Catch-and-refetch on `QueryException` for unique-violation races (the `refunds.subscription_id` unique, which Laravel adds implicitly for the single-column `provider_refund_reference`).
- The `MockPaymentProvider` was extended with a `refundMoney()` method and a `TYPE_REFUND = 'refund'` constant. Same idempotency pattern as `chargeMoney` / `sendMoney`.

### `app/Console/Commands/RefundSubscriptionCommand.php`

- Signature: `ledger:refund-subscription {subscription_id} {--on=YYYY-MM-DD}`.
- `cancel_date` defaults to `now()` if `--on` omitted.
- Validates date format (`YYYY-MM-DD`). Exit code 2 (INVALID) on bad date.
- Catches `ModelNotFoundException` for missing subscription, `MockPaymentProviderFailedException` for declined, `MockPaymentProviderTimeoutException` for transient provider failure (exit 75).
- Calls the service, prints the refund id + amount + cancel date.

### Tests

- `tests/Unit/Services/Subscriptions/RefundSubscriptionServiceTest.php` — 9 vectors: partial refund proration, full-day-1 cancellation (largest refund), zero-day-30 cancellation (no money moves, refund row still written), idempotent re-run, provider-failed writes no rows, cancel before period throws, cancel after period throws, missing subscription throws, **cancel_date lives on the subscription (not on the refund row) — guards against regression**.
- `tests/Feature/Console/RefundSubscriptionCommandTest.php` — 3 vectors: successful invocation, invalid date format, unknown subscription id.

### `app/Console/Commands/RefundSubscriptionCommand.php`

- Signature: `ledger:refund-subscription {subscription_id} {--on=YYYY-MM-DD}`
- `cancel_date` defaults to `today()` if `--on` omitted
- Validates args
- Calls the service
- Prints the refund amount + the refund row id
- Exit 0 on success or idempotent re-run; non-zero on provider failure

### Tests

- `tests/Unit/Services/Subscriptions/RefundSubscriptionServiceTest.php`
    - Cancel on day 1 of a 30-day month → refund = 29/30 × charged (close to full refund, off-by-one acceptable with a clear comment)
    - Cancel on day 30 of a 30-day month → refund = 0
    - Cancel on the same day as `started_at` → refund = full amount
    - Already-refunded subscription → returns the existing Refund, no new provider call
    - Provider `failed` → no rows, exception thrown
    - `cancel_date` outside the period → validation error
    - Ledger invariant: `sum(amount_cents) where subscription_id = X AND type IN ('subscription_payment', 'subscription_refund')` equals the net paid (negative of the refund)
- `tests/Feature/Console/RefundSubscriptionCommandTest.php`
    - Successful invocation via `Artisan::call` → exit 0, expected output
    - Re-run → "already refunded" message
    - Invalid date format → validation error
    - Unknown subscription id → friendly error

---

## Phase 5 — Close monthly payout service + command + scheduler ✅ DONE

Four design decisions were settled before this phase was built. Each is in the code AND in this doc; the rationale lives here.

> **1. No application-level row locks. Idempotency gate is the unique `idempotency_key` index.**
> _Rejected:_ the original plan called for `lockForUpdate()` on subscription rows in the period + a `sharedLock` on the join. SQL locks on the same query are mutually exclusive, and the unique index already makes the race harmless — the loser catches a `QueryException` (SQLSTATE 23000) and re-fetches the winning row. Two layers (fast precheck + unique-violation catch), zero row locks. Same shape as `ChargeSubscriptionService`'s race recovery.

> **2. Empty / zero-net months write nothing.**
> _Rejected:_ a sentinel `platform_cut:YYYY-MM` row with `amount_cents = 0`. The unique index is a _gate_, not a marker — once a zero-amount row is written for a month, a late refund that arrives later cannot be reflected in that month (the unique key blocks a non-zero re-write). With no row written, an empty month stays in the "not closed" state and a future close attempt after a late refund can still produce real rows. The per-month invariant `SUM = 0` holds trivially when no rows exist.

> **3. Every `instructor_payout` row is initialised with `meta = ['status' => 'pending']`.**
> _Rejected:_ leaving `meta` null and letting Phase 6 set it on first contact. With null `meta`, every Phase 6 reader has to `?? 'pending'` — a default that lives in code instead of in data. With `meta.status = 'pending'` on insert, the row is self-describing from the moment it's created and the Phase 6 idempotency check (`in_array($meta['status'], ['sent', 'failed'], true)`) works without a null guard. Four states: `pending → reconciling → sent`, with `failed` as a terminal alternative. `pending` is the only state written by Phase 5.

> **4. Idempotency keys use the canonical `YYYY-MM` form, zero-padded.**
> _Rejected:_ PHP's `"platform_cut:{$year}-{$month}"` string interpolation — for `month=6` it produces `platform_cut:2026-6`, for `month=12` it produces `platform_cut:2026-12`. Different months, different shapes, breaks the unique index contract. The service uses `sprintf('%04d-%02d', $year, $month)` everywhere a key is built. The `LedgerEntryFactory` was updated to match.

### `app/Services/Payouts/CloseMonthlyPayoutService.php`

- `close(int $year, int $month): PayoutDraft`
- `DB::transaction()` (no row locks; the precheck + unique-violation catch handle the race):
    - Precheck: `LedgerEntry::where('idempotency_key', "platform_cut:YYYY-MM")->exists()`. If true, the month is already closed — rebuild the draft from the existing rows (including their ids) and return it.
    - Compute the draft via `MonthlyPayoutCalculator::calculate($year, $month)`.
    - If `$draft->isEmpty()` (`platform_cut_cents === 0` AND `instructorPayouts === []`), return it as-is. No rows written.
    - Otherwise: insert one `LedgerEntry` row of `type=platform_cut` (`user_id=null, subscription_id=null, amount_cents=-platform_cut_cents, currency=USD, idempotency_key="platform_cut:YYYY-MM", meta=null`) and one `LedgerEntry` row of `type=instructor_payout` per instructor (`user_id=instructor, subscription_id=null, amount_cents=-share, currency=USD, idempotency_key="payout:YYYY-MM:user:{user_id}", meta=['status'=>'pending']`).
    - The returned draft is the input draft + the row ids of the inserted rows.
- `PayoutDraft::isEmpty()` and `PayoutDraft::withLedgerEntryIds()` are the two helpers added in this phase.
- `PayoutDraft::loadExisting()` rebuilds a draft from rows already on disk (used by the precheck and the race-recovery catch). This is what makes the re-run case return the _same_ row ids and therefore dispatch the _same_ job ids.

### `app/Console/Commands/RunMonthlyPayoutsCommand.php`

- Signature: `ledger:run-payouts {--year=} {--month=}`. Defaults to previous calendar month (`CarbonImmutable::now()->subMonthNoOverflow()`).
- If either flag is passed, both are required. Invalid month (not 1–12) → exit 2 (INVALID).
- Calls `CloseMonthlyPayoutService::close()`. Empty month → prints "Month YYYY-MM had no activity. Nothing to close." and exits 0.
- Non-empty month → dispatches one `App\Jobs\PayInstructorJob($ledgerEntryId)` per `instructor_payout` row id. Prints "Month YYYY-MM closed: 1 platform_cut (X cents) + K instructor_payout(s) → K job(s) dispatched." Exit 0.
- **Phase 6 stub:** the `PayInstructorJob` class exists as a minimal `ShouldQueue + ShouldBeUnique` with `uniqueId() = "pay-instructor:{ledgerEntryId}"`. The full `handle()` (calling `PayInstructorService`) is added in Phase 6.

### Scheduler entry in `routes/console.php`

```php
Schedule::command('ledger:run-payouts')
    ->monthlyOn(1, '00:00')
    ->withoutOverlapping()
    ->onOneServer();
```

`withoutOverlapping()` is belt-and-braces: even though the close service is idempotent on a re-run, it avoids the wasted DB work of a second close attempt within the same minute. `onOneServer()` is for production deployments with multiple workers; the dev single-worker setup is unaffected.

### `Subscription::scopeForPeriod` — small cleanup

Switched from `Carbon::create()` (mutable) to `CarbonImmutable::create()` for consistency with the rest of the codebase (the refund service and the calculator both use `CarbonImmutable`). The `->copy()->addMonth()` defensive copy is no longer needed; `addMonth()` on an immutable returns a new instance.

### Tests

- `tests/Unit/Services/Payouts/CloseMonthlyPayoutServiceTest.php` — 8 vectors:
    - Successful close → 1 `platform_cut` + 1 `instructor_payout` per instructor, sums match the calculator's output
    - Re-run of the same month is a no-op (no new rows; same row ids returned)
    - Empty month (no payments) → no rows written
    - Month whose only activity is a full refund (net = 0) → no rows written
    - **Late refund can still re-close a previously-empty month** — the original close wrote nothing; a payment + refund that arrives later produces real rows on a re-run. This is the empty-month policy under test.
    - Idempotency keys follow the literal `platform_cut:2026-06` and `payout:2026-06:user:{id}` form (zero-padded; asserted against the literal, not interpolated, string — the test file is the contract)
    - A refund reduces the pool: net 1000 with a 400 refund → cut 180, pool 420
    - Recovers from a unique-violation race: a row pre-seeded with `platform_cut:2026-06` (as if written by a competing process) is honoured — close returns the pre-existing values, no double write
- `tests/Feature/Console/RunMonthlyPayoutsCommandTest.php` — 6 vectors:
    - Dispatches one `PayInstructorJob` per instructor on a successful run, asserts the dispatched `ledgerEntryId` matches the on-disk row
    - Empty month → no jobs dispatched, no rows written
    - Re-run of the same month → 0 new rows (jobs may dispatch again — that's `PayInstructorJob`'s `ShouldBeUnique` lock in Phase 6 to de-dupe)
    - Default args in July → closes June
    - `--month=13` rejected with INVALID
    - Only one of `--year` / `--month` provided → INVALID

---

## Phase 6 — Pay instructor + reconciliation services + jobs ⬜ TODO

### `app/Services/Payouts/PayInstructorService.php`

- `pay(int $ledgerEntryId): PayResult`
- `PayResult` is a DTO with `status: 'sent'|'failed'|'reconciling'` and `needsReconciliation: bool`
- `DB::transaction()`:
    - Load + `lockForUpdate()` the `instructor_payout` ledger row
    - If `meta.status` is already terminal (`sent` or `failed`) → return early with the existing status (idempotent re-run)
    - Call `MockPaymentProvider::sendMoney($idempotencyKey, abs($amount_cents), $currency)`
    - On `succeeded`: write `meta = {status: 'sent', provider_reference, sent_at: now()}`
    - On `failed`: write `meta = {status: 'failed', error: $message}` (no retry — this is a permanent failure for the platform)
    - On `timeout`: write `meta = {status: 'reconciling'}`, return `PayResult(needsReconciliation: true)`

### `app/Services/Payouts/ReconcileInstructorPayoutService.php`

- `reconcile(int $ledgerEntryId, int $attempts): void`
- `markExhausted(int $ledgerEntryId): void` — called by the job's `failed()` hook
- `DB::transaction()`:
    - Load + `lockForUpdate()` the row
    - If `meta.status != 'reconciling'` → return (already settled)
    - Call `MockPaymentProvider::checkStatusByIdempotencyKey($key)`
    - `succeeded` → `meta = {status: 'sent', provider_reference, sent_at: now()}`
    - `failed` → `meta = {status: 'failed'}`
    - `unknown` → throw `StillReconcilingException` (so the job releases with backoff)
    - If the job's attempt count is at max (5), call `markExhausted()` instead of throwing

### `app/Jobs/PayInstructorJob.php`

```php
class PayInstructorJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $uniqueFor = 600;

    public function __construct(public int $ledgerEntryId) {}

    public function uniqueId(): string
    {
        return "pay-instructor:{$this->ledgerEntryId}";
    }

    public function handle(PayInstructorService $service): void
    {
        $result = $service->pay($this->ledgerEntryId);

        if ($result->needsReconciliation) {
            ReconcileInstructorPayoutJob::dispatch($this->ledgerEntryId);
        }
    }
}
```

No DB code, no provider code in the job. Thin orchestrator.

### `app/Jobs/ReconcileInstructorPayoutJob.php`

```php
class ReconcileInstructorPayoutJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $maxExceptions = 5;
    public array $backoff = [10, 30, 120, 300, 1800];

    public function __construct(public int $ledgerEntryId) {}

    public function handle(ReconcileInstructorPayoutService $service): void
    {
        $service->reconcile($this->ledgerEntryId, $this->attempts());
    }

    public function failed(\Throwable $e): void
    {
        app(ReconcileInstructorPayoutService::class)->markExhausted($this->ledgerEntryId);
    }
}
```

### Tests

- `tests/Unit/Services/Payouts/PayInstructorServiceTest.php`
    - `succeeded` outcome → `meta.status = 'sent'`, `provider_reference` stored
    - `failed` outcome → `meta.status = 'failed'`, no retry signal
    - `timeout` outcome → `meta.status = 'reconciling'`, `PayResult::needsReconciliation = true`
    - Re-run on a `sent` row → returns the existing status, no new provider call
    - Re-run on a `failed` row → returns the existing status, no new provider call
    - Re-run on a `reconciling` row → returns `reconciling` (the reconcile job owns that state machine)
- `tests/Unit/Services/Payouts/ReconcileInstructorPayoutServiceTest.php`
    - First call, status `unknown` → throws `StillReconcilingException`
    - Subsequent call, status `succeeded` → `meta.status = 'sent'`
    - Subsequent call, status `failed` → `meta.status = 'failed'`
    - `markExhausted` writes `meta.status = 'failed', meta.reconciliation_exhausted = true`
    - Re-run on a `sent` row → no-op
- `tests/Feature/Payouts/PayInstructorJobTest.php`
    - Job handles `succeeded` → meta updated
    - Job handles `timeout` → dispatch a `ReconcileInstructorPayoutJob`
    - **Retried job never double-pays** (the `unique` lock + the service's terminal-state short-circuit)
- `tests/Feature/Payouts/ReconcileInstructorPayoutJobTest.php`
    - First attempt with `unknown` → job re-queued
    - Eventually settled to `sent` when provider returns `succeeded` on a later attempt
    - Eventually exhausted after 5 attempts → `meta.reconciliation_exhausted = true`

---

## Phase 7 — Filament (read-only ops screen) ⬜ TODO

- `composer require filament/filament:"^3.2" -W`
- `php artisan filament:install --panels`
- `app/Filament/Resources/InstructorResource.php` (read-only)
    - Table columns: `name`, `payout_destination`, **`earned_cents`** (SUM of `type=allocation` allocations, sign-aware), **`paid_cents`** (abs SUM of `type=instructor_payout`), **`outstanding_cents`** (`earned − paid`)
    - Per-row query against the ledger, no materialization
    - Negative outstanding → "balance owed back" badge
- `app/Filament/Resources/PayoutHistoryRelationManager.php` (read-only)
    - Per-instructor list of `instructor_payout` ledger rows with `meta.status`, `sent_at`, amount
- Admin user seeder

---

## Phase 8 — Rubric tests ⬜ TODO

The challenge-mandated scenarios, each in its own file. Use the deterministic outcome mode.

- `tests/Feature/Payouts/DoubleRunTest.php` — `RunMonthlyPayoutsCommand` twice for the same month → no duplicate `platform_cut` / `instructor_payout` rows; totals match between the two runs
- `tests/Feature/Payouts/RetriedJobTest.php` — `PayInstructorJob` retried via `Bus::fake()` after a crash between provider call and DB write → only one `meta.status='sent'` row, only one provider call
- `tests/Feature/Payouts/ProviderTimeoutTest.php` — `useDeterministicOutcomes([OUTCOME_TIMEOUT_AFTER_SUCCESS])` → first job times out, reconciliation finds the success, final state is `sent`
- `tests/Feature/Refunds/RefundAfterPayoutTest.php` — pay out month N, then refund a subscription in month N → the next payout run reflects the reduced pool
- `tests/Feature/LedgerIntegrityTest.php` — for N random subscriptions across multiple months: `SUM(ledger_entries.amount_cents WHERE subscription_id = X) + share_of_platform_cut + share_of_instructor_payout = 0` (each subscription's net contribution to the books is zero)
- `tests/Unit/Services/Payouts/MonthlyPayoutCalculatorTest.php` — full vector coverage (built in Phase 2)
- All existing mock provider tests still pass (`vendor/bin/pest`)

---

## Phase 9 — Docs ⬜ TODO

- `README.md` — rewrite from Laravel boilerplate
    - Project summary (1 paragraph)
    - Setup: `composer install`, `cp .env.example .env`, `php artisan key:generate`, `php artisan migrate --seed`
    - Run tests: `vendor/bin/pest`
    - Run the demo: `ledger:charge-subscription`, `ledger:refund-subscription`, `ledger:run-payouts`
    - Assumptions: platform cut 30%, monthly settlement, single currency, calendar-month plans, partial refunds
- `docs/ARCHITECTURE.md`
    - Domain model diagram (ASCII)
    - Database design rationale (unified ledger, calendar-month plans, monthly cycle)
    - Revenue allocation strategy (largest-remainder)
    - Idempotency layers (3: `provider_charge_reference` unique, `idempotency_key` unique on ledger, `subscription_entry_id` unique for refunds)
    - Provider timeout handling (3 outcomes, reconciliation worker with backoff)
    - Refund flow (partial, by day-count)
    - Service layer / thin jobs rationale
    - Scaling notes (indexes, what would change at 500k subs)
    - Known limitations (single currency, no mid-term upgrade, no engagement-based allocation)
- `docs/AI_USAGE.md` (challenge requirement)
    - How AI was used: trade-off discussions, edge-case brainstorming, test-vector suggestions, code review
    - What AI generated vs. what was designed manually
    - Engineering decisions made personally
    - What differentiates this submission
    - Trade-offs and improvements intentionally chosen
- `docs/VIDEO_OUTLINE.md` — 15–20 min walkthrough outline (6 sections per the challenge brief: intro, architecture, failure scenarios, testing, AI usage, future improvements)
