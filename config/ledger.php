<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Platform revenue cut
    |--------------------------------------------------------------------------
    |
    | Expressed in basis points (1 bp = 0.01%). 3000 bps = 30%.
    | The platform keeps this share of every subscription charge. The
    | remainder is the instructor pool that gets split between instructors.
    |
    | Change with care: changing this affects how every new subscription is
    | allocated, but historical allocations are never recomputed.
    |
    */

    'platform_cut_bps' => env('LEDGER_PLATFORM_CUT_BPS', 3000),

    /*
    |--------------------------------------------------------------------------
    | Minimum payout threshold
    |--------------------------------------------------------------------------
    |
    | Instructors will not receive a payout until their outstanding balance
    | reaches this many cents. Keeps the payout queue from being flooded
    | with sub-cent sub-dollar payouts.
    |
    */

    'min_payout_cents' => env('LEDGER_MIN_PAYOUT_CENTS', 1000),

    /*
    |--------------------------------------------------------------------------
    | Default payout currency
    |--------------------------------------------------------------------------
    |
    | All money in the ledger is stored as integer cents in a single currency
    | for this challenge. Multi-currency is out of scope; this constant exists
    | so the call sites don't sprinkle 'USD' around.
    |
    */

    'currency' => env('LEDGER_CURRENCY', 'USD'),

    /*
    |--------------------------------------------------------------------------
    | Idempotency key namespaces
    |--------------------------------------------------------------------------
    |
    | Deterministic prefixes for idempotency keys sent to the payment
    | provider. Keeping these centralized makes it easy to grep for
    | "what calls the provider" and ensures we never collide keys across
    | operation types.
    |
    */

    'idempotency' => [
        'charge' => 'charge:',
        'send' => 'send:',
        'refund' => 'refund:',
    ],

];
