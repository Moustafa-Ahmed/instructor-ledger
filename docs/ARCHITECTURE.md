# Architecture — Instructor Revenue Ledger

The architecture for this money system rests on a few structural choices, each
recorded here with the rejected alternative. The full Phase 9 walkthrough
(domain model diagram, scaling notes, etc.) is in [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md);
this file is the decisions log and the source of truth for the _why_.

---

## Design decisions (chronological)

### 1. Unified 4-type `ledger_entries`

One table, four row types, one `SUM()` answers "how much has the student paid /
the platform kept / each instructor earned." The four types:

| type                   | sign of `amount_cents` | `user_id`  | `subscription_id` | written by                  |
| ---------------------- | ---------------------- | ---------- | ----------------- | --------------------------- |
| `subscription_payment` | positive               | student    | set               | `ChargeSubscriptionService` |
| `subscription_refund`  | negative               | student    | set               | `RefundSubscriptionService` |
| `platform_cut`         | negative               | null       | null              | `CloseMonthlyPayoutService` |
| `instructor_payout`    | negative               | instructor | null              | `CloseMonthlyPayoutService` |

_Rejected:_ separate `payouts` and `platform_revenue` tables plus an
instructor-centric ledger. Cross-domain questions ("did the books balance?")
become `UNION`s across three tables; the invariant `SUM = 0` for a closed
month stops being a single query.

The per-month invariant:

```
SUM(amount_cents) for ledger entries in month N
  = platform_cut(N)            — one row, user_id=null
  + SUM(instructor_payout(N))  — one row per instructor, user_id=instructor
  + SUM(subscription_payment + subscription_refund) for subs in month N
  = 0
```

`subscription_payment` and `subscription_refund` are sign-aware (refunds are
already negative), so `SUM` across both equals the net cash flow from students
in the month.

### 2. `cancel_date` lives on `subscriptions`, not on `refunds`

The cancel date is the _input_ to the proration math, not a property of the
provider-workflow row. The `Refund` model docblock says it holds "no financial
logic of its own" — storing the cancel date there contradicted that boundary.
The `subscriptions.cancel_date` is the state-transition timestamp (the moment
the subscription flipped from Active to Refunded); the `refunds` row tracks
only whether the provider acknowledged the money movement.

The two are set in the same `update()` call inside `DB::transaction()`:

```php
$subscription->update([
    'status' => SubscriptionStatus::Refunded,
    'cancel_date' => $cancelDate->toDateString(),
]);
```

A `Refunded` subscription can never have a `null` `cancel_date`; the state
transition and its timestamp commit atomically.

### 3. Phase 5 idempotency — precheck + unique-violation catch, no row locks

_Rejected:_ the original plan called for `lockForUpdate()` on subscription
rows in the period + a `sharedLock` on the join. SQL locks on the same query
are mutually exclusive, and the unique index on `ledger_entries.idempotency_key`
already makes the race harmless — the loser catches a `QueryException`
(SQLSTATE 23000) and re-fetches the winning row.

Two layers, zero row locks:

```php
if ($existing = $this->loadExisting($year, $month)) {
    return $existing;  // fast precheck
}

try {
    return DB::transaction(fn () => $this->writeRows($draft, $year, $month));
} catch (QueryException $e) {
    if ($this->isUniqueViolation($e)) {
        return $this->loadExisting($year, $month);  // race recovery
    }
    throw $e;
}
```

Same shape as `ChargeSubscriptionService`'s race recovery. The precheck
makes the common case fast; the unique-violation catch makes the race safe.

### 4. Empty / zero-net month → no rows written

_Rejected:_ a sentinel `platform_cut:YYYY-MM` row with `amount_cents = 0`.

The unique index is a _gate_, not a marker. Once a zero-amount row is written
for a month, a late refund that arrives later cannot be reflected in that
month — the unique key blocks a non-zero re-write. With no row written, the
month stays in the "not closed" state and a future close attempt after a
late refund can produce real rows. The per-month invariant `SUM = 0` holds
trivially when no rows exist; the ops query "did month N close?" becomes
`LedgerEntry::where('idempotency_key', 'platform_cut:2026-06')->exists()`.

### 5. `meta = ['status' => 'pending']` on every `instructor_payout` row

_Rejected:_ leaving `meta` null and letting Phase 6 set it on first contact.

With null `meta`, every Phase 6 reader has to `?? 'pending'` — a default
that lives in code instead of in data. With `meta.status = 'pending'` on
insert, the row is self-describing from the moment it's created and the
Phase 6 idempotency check works without a null guard:

```php
if (in_array($entry->meta['status'], ['sent', 'failed'], true)) {
    return early;  // already settled, idempotent re-run
}
// otherwise: pending | reconciling
```

Four states: `pending → reconciling → sent`, with `failed` as a terminal
alternative. `pending` is the only state written by Phase 5. The full
state machine (with the `reconciliation_exhausted` terminator and the
split ownership between `pay()` and `reconcile()`) is in Phase 6
below (decision 8).

### 6. Idempotency keys are canonical `YYYY-MM`, zero-padded

_Rejected:_ PHP's `"platform_cut:{$year}-{$month}"` string interpolation —
for `month=6` it produces `platform_cut:2026-6`, for `month=12` it produces
`platform_cut:2026-12`. Different months, different shapes, breaks the unique
index contract. The service uses `sprintf('%04d-%02d', $year, $month)`
everywhere a key is built. The `LedgerEntryFactory` matches.

### 7. Phase 6: `lockForUpdate()` on the ledger row — the only place it's correct

The close service (Phase 5) didn't lock anything — the unique `idempotency_key`
index made the race harmless for _insert_ operations. Phase 6 is a different
problem: it's a _read-modify-write on a single row_
(`read meta.status` → `decide` → `write meta.status`). Two concurrent `pay()`
calls on the same row would race on the `meta` write. The precheck on
`meta.status` is the fast path; the row lock makes the read-modify-write
atomic.

```php
DB::transaction(function () use ($ledgerEntryId) {
    $entry = LedgerEntry::query()
        ->where('id', $ledgerEntryId)
        ->where('type', LedgerEntryType::InstructorPayout->value)
        ->lockForUpdate()
        ->firstOrFail();

    $current = $this->metaStatus($entry);
    // ... read-modify-write on meta.status ...
});
```

The `where('type', ...)` scope is the safety belt: a `lockForUpdate()` on the
wrong row type (e.g. a `platform_cut` row) throws `ModelNotFoundException`
before the lock can take effect.

### 8. Phase 6: split state machine ownership, not shared

The state machine on `meta.status` for `instructor_payout` rows:

```
  pending ──pay()──► sent          (provider said "succeeded")
                ├──► failed        (provider said "failed" — permanent)
                └──► reconciling   (provider timed out after a real
                                    success; the reconcile worker
                                    owns the resolution)

  reconciling ──reconcile()──► sent | failed
                       └──► failed + reconciliation_exhausted
                            (5 attempts without a final status)
```

`PayInstructorService` owns `pending → {sent, failed, reconciling}`.
`ReconcileInstructorPayoutService` owns `reconciling → {sent, failed}` plus
the `reconciliation_exhausted` terminator. The two services'
short-circuits on each other's states prevent clobbering:

- `pay()` on a `reconciling` row returns `reconciling` without calling the
  provider or dispatching another reconcile worker. A second reconcile
  worker would race the first for who marks the row `sent` first.
- `reconcile()` on a `sent` or `failed` row returns immediately. The
  reconcile worker does **not** overwrite a terminal status — that's the
  job of `pay()` (which is the only one that called the provider).
- `reconcile()` on a `pending` row returns immediately. The reconcile
  worker only operates on `reconciling` rows; `pending` is `pay()`'s
  concern.

The "exhaustion" path is the only state transition that touches a
non-`reconciling` row, and only via `markExhausted()` — the out-of-band
hook called by the job's `failed()`. Even that has a `if ($status === 'sent') { return; }` guard, so an exhausted reconcile can never clobber a `sent` row.

---

## Domain model

```
User (role: student | instructor)
  ├─ Subscription
  │    └─ LedgerEntry (subscription_payment, subscription_refund)
  └─ LedgerEntry (instructor_payout)

Course
  └─ course_instructor (revenue_weight) → User (instructor)

LedgerEntry (cross-cutting)
  └─ types: subscription_payment | subscription_refund | platform_cut | instructor_payout

Refund (workflow tracking only)
  └─ subscription_id → Subscription
       (cancel_date is on the subscription, not here)
```

---

## Idempotency layers (four)

1. **Provider side** — `mock_payment_operations` rows are keyed by
   `(operation_type, idempotency_key)`. A retry of the same operation
   returns the prior result.
2. **Subscription side** — `subscriptions` has a unique
   `provider_charge_reference`; `refunds` has a unique
   `provider_refund_reference`. Provider-side dedupe is backstopped by DB
   uniqueness.
3. **Ledger side** — `ledger_entries.idempotency_key` is unique. The
   per-charge, per-refund, and per-close keys all live here. The
   `subscription_entry_id` is unique, so a payment can be partially
   refunded at most once.
4. **Job side** — `PayInstructorJob` is `ShouldBeUnique` with
   `uniqueId() = "pay-instructor:{ledgerEntryId}"` and `uniqueFor = 600`
   seconds. Two workers can't both grab the same `ledgerEntryId` from
   the queue. The unique lock is the cross-process gate that
   complements the row-level `lockForUpdate()` inside
   `PayInstructorService`. The close service doesn't need this layer —
   the unique `idempotency_key` is enough for an insert; Phase 6's
   read-modify-write needs the additional cross-process dedup.

Four layers means a failure in one (provider timeout, app crash mid-write,
race between two processes, two workers picking up the same job) is
caught by another.

---

## What's in this file vs. `IMPLEMENTATION_PLAN.md`

- This file: _the design decisions and the why_. The architectural shape
  (domain model, idempotency layers, decision log).
- `IMPLEMENTATION_PLAN.md`: the _phased build_ (what was built when, in
  what order, with which tests). The decisions log at the top of that file
  is the in-order chronological version of the same notes.

The full Phase 9 architecture write-up — the failure-mode walkthroughs,
scaling notes for 500k subscriptions, what would change in production —
still needs to be drafted. The decisions in this file are the foundation;
the walkthroughs in the plan are the build history; the Phase 9 deliverable
will be the operations manual.
