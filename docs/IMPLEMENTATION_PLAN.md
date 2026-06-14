# Instructor Revenue Ledger — Implementation Plan

A Laravel 11 + Pest implementation of the challenge brief: a money core for an LMS that takes subscription payments in, allocates revenue to instructors, and pays them out monthly — correctly under retries, timeouts, and partial refunds.

The plan reflects the code as of the latest round of design changes. Older design iterations are in git history.

---

## Decisions Log

Trade-offs made during design. Each is the chosen path; the rejected alternative is in italics.

- **Unified 4-type `ledger_entries`** (vs. *instructor-centric ledger + separate `payouts` + separate `platform_revenue` tables*). One row, one truth, one SUM() to answer "how much has the student paid / the platform kept / each instructor earned." *Rejected: separate tables force UNIONs and lose the single-source-of-truth for cross-domain questions.*

- **Calendar-month plans** (vs. *arbitrary-day subscription terms*). "Monthly / 3-month / annual" is implemented as N sequential `Subscription` rows aligned to month boundaries, not as a single subscription with a long term. Simplifies the per-month payout cycle, the refund proration, and the test invariants.

- **Monthly payout cycle, not per-charge allocation** (vs. *write per-instructor accrual rows at charge time*). The platform's cut and the per-instructor payouts are written once at the end of each month. Allocations are derived, not stored. Real-time balance visibility is sacrificed for a much cleaner monthly invariant and a much smaller hot table.

- **Partial refunds as a negative `subscription_payment` row** (vs. *per-instructor mirror reversal rows with `source_allocation_id` chains*). A refund reduces the instructor pool naturally at the next monthly payout because the refund row is already negative in the same SUM. No clawback row, no per-instructor distribution math at refund time.

- **`period` derived from `started_at`** (vs. *stored `period_year` / `period_month` columns* on `subscriptions` and `ledger_entries*`). The columns were pure denormalization. Month queries are `where started_at >= first_of_month AND started_at < first_of_next_month` against the `started_at` index, which is fast and avoids insert-time copy bugs.

- **Instructors merged into `users` with a `role` column** (vs. *separate `instructors` table*). Saves a join. An instructor is just a user with a different role. The `course_instructor` and `ledger_entries` FKs all point to `users`.

- **No `platform_cut_bps` snapshot on subscriptions** (vs. *per-row snapshot of the config at charge time*). The config is the single source of truth; every period's cut is computed from `config('ledger.platform_cut_bps')`. If the config ever needs to change mid-year, historical months are not recomputed — the close service's idempotency_key design is what protects them.

- **No `courses.is_active` flag** (vs. *a soft-delete-style active/inactive flag*). Every attached instructor is eligible; the schema doesn't carry course lifecycle. If a course is being retired, the attachment is removed (or, in production, would be soft-deletable via a `course_instructor.ended_at`).

- **`payout_attempts` info folded into `meta` JSON on `instructor_payout` rows** (vs. *separate `payout_attempts` table*). Attempt history is a diagnostic, not a hot query. Provider-side truth lives in `mock_payment_operations`; the `meta` JSON is the row-local summary. If per-attempt analytics become important, split it out.

- **Command-driven money flow** (vs. *HTTP API / web UI*). The challenge brief says "you do not need a full application." Artisan commands (`ledger:charge-subscription`, `ledger:refund-subscription`, `ledger:run-payouts`) are the user interface. No controllers, no form requests, no student-facing UI. The only Filament screen is the read-only ops view.

- **Service layer + thin jobs** (vs. *fat jobs with business logic inline*). Each side effect (charge, refund, close month, pay instructor, reconcile) has a service class that owns the transaction, the provider call, and the DB writes. Jobs are thin orchestrators: load the row, dispatch to the service, write the result, schedule the next step on a flag. This makes the services directly testable without queuing, and keeps the jobs readable as a single paragraph.

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

## Phase 4 — Refund subscription service + command ⬜ TODO

### `app/Services/Subscriptions/RefundSubscriptionService.php`

- `refund(int $subscriptionId, ?CarbonInterface $cancelDate = null): Refund`
- `cancelDate` defaults to `today()` if null
- `DB::transaction()`:
  - `lockForUpdate()` on the subscription row
  - Validate: subscription exists, `status=active`, `cancel_date ∈ [started_at, ends_at]`
  - If a `subscription_refund` `LedgerEntry` already exists for this subscription (the `unique(subscription_entry_id)` index catches it) → return the existing `Refund` (idempotent re-run)
  - Compute partial refund by day-count:
    - `days_remaining = $subscription->ends_at->diffInDays($cancelDate)` — calendar-day diff using the `Carbon` `diffInDays` semantics
    - `days_in_month = $subscription->ends_at->day` (last day of the month)
    - `refund_amount = intdiv(charged_amount × days_remaining, days_in_month)` — integer floor; round-half-up if you want, but floor is safer for the platform
  - Call `MockPaymentProvider::refundMoney("refund:subscription:{id}", $refund_amount, $subscription->currency)`
  - On `succeeded`:
    - Insert `Refund` row with `status=completed`, `provider_refund_reference`, `cancel_date`
    - Insert `LedgerEntry` row of `type=subscription_refund` with `subscription_entry_id` → original `subscription_payment` row, `amount_cents = -refund_amount`, `idempotency_key = "refund:subscription:{id}"`
    - Update `subscription.status = refunded`
  - On `failed`: throw
- Idempotent re-run returns the existing `Refund`

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

## Phase 5 — Close monthly payout service + command + scheduler ⬜ TODO

### `app/Services/Payouts/CloseMonthlyPayoutService.php`

- `close(int $year, int $month): PayoutDraft`
- `DB::transaction()`:
  - `lockForUpdate()` on all `Subscription` rows in the period (via `scopeForPeriod` + a `sharedLock` on the join to `ledger_entries`)
  - Check `LedgerEntry::where('idempotency_key', "platform_cut:{year}-{month}")->exists()` — if yes, the month is already closed; return the existing draft (idempotent re-run)
  - Call `MonthlyPayoutCalculator::calculate($year, $month)` to get the draft
  - Insert `LedgerEntry` row of `type=platform_cut`: `user_id=null, subscription_id=null, amount_cents=-platform_cut_cents, currency=USD, idempotency_key="platform_cut:{year}-{month}"`
  - Insert one `LedgerEntry` row of `type=instructor_payout` per instructor: `user_id=instructor, subscription_id=null, amount_cents=-share, currency=USD, idempotency_key="payout:{year}-{month}:user:{user_id}"`
  - Capture the row ids of the created `instructor_payout` rows into the returned draft
- **Does not dispatch jobs.** The command dispatches them after the transaction commits.
- Idempotent re-run: returns the existing draft (constructed from already-existing rows) without inserting

### `app/Console/Commands/RunMonthlyPayoutsCommand.php`

- Signature: `ledger:run-payouts {--year=} {--month=}`. Defaults to previous calendar month.
- Validates year/month are valid integers
- Calls `CloseMonthlyPayoutService::close()`
- After the transaction commits: dispatches one `PayInstructorJob` per `instructor_payout` row id
- Prints "month N closed: 1 platform_cut + K instructor_payouts dispatched" or "month N already closed, no-op"
- Exit 0 always

### Scheduler entry in `routes/console.php`

```php
use Illuminate\Support\Facades\Schedule;
Schedule::command('ledger:run-payouts')
    ->monthlyOn(1, '00:00')
    ->withoutOverlapping()
    ->onOneServer();
```

The `withoutOverlapping()` prevents two cron invocations from racing on the same month. `onOneServer()` for production deployments with multiple workers.

### Tests

- `tests/Unit/Services/Payouts/CloseMonthlyPayoutServiceTest.php`
  - Successful close → 1 platform_cut + N instructor_payout rows, sums match the calculator's output
  - **Re-run with same month → no new rows** (idempotent — the unique on `idempotency_key` enforces it)
  - Empty month (no payments) → no rows written (or 1 platform_cut with amount=0 + 0 instructor_payouts; decide and document)
  - Idempotency keys follow the exact pattern: `platform_cut:2026-06`, `payout:2026-06:user:{id}`
- `tests/Feature/Console/RunMonthlyPayoutsCommandTest.php`
  - Successful run with `Bus::fake()` → 1 platform_cut row + K jobs dispatched
  - Re-run → 0 new rows, 0 new jobs
  - `--year=2026 --month=6` flag works
  - Default args (no flags, ran in July) → closes June
  - Refunds in the period reduce the pool

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
