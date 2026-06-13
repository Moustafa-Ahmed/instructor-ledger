<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'price_cents',
        'currency',
        'months',
    ];

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'months' => 'integer',
        ];
    }
}
