<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LedgerEntryType;
use App\Enums\UserRole;
use App\Services\Payouts\InstructorBalanceService;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'payout_destination',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    public function taughtCourses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'course_instructor')
            ->withPivot('revenue_weight')
            ->withTimestamps();
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function payoutLedgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class)
            ->where('type', LedgerEntryType::InstructorPayout->value);
    }

    /**
     * @return array{earned_cents: int, paid_cents: int, outstanding_cents: int}
     */
    public function payoutBalance(): array
    {
        // The relation must be eager-loaded by the caller. The fallback makes
        // a missing eager-load "work" at the cost of a per-user query.
        if (! $this->relationLoaded('payoutLedgerEntries')) {
            return app(InstructorBalanceService::class)
                ->balanceFor((int) $this->id);
        }

        $earned = 0;
        $paid = 0;
        $inFlight = 0;

        foreach ($this->payoutLedgerEntries as $row) {
            $abs = (int) abs($row->amount_cents);
            $earned += $abs;

            $status = (string) (($row->meta ?? [])['status'] ?? 'pending');

            if ($status === 'sent') {
                $paid += $abs;
            } elseif ($status === 'reconciling' || $status === 'failed') {
                $inFlight += $abs;
            }
        }

        return [
            'earned_cents' => $earned,
            'paid_cents' => $paid,
            'outstanding_cents' => $earned - $paid + $inFlight,
        ];
    }
}
