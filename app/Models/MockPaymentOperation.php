<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MockPaymentOperation extends Model
{
    protected $fillable = [
        'provider_reference',
        'operation_type',
        'idempotency_key',
        'amount_cents',
        'currency',
        'status',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'metadata' => 'array',
        ];
    }
}
