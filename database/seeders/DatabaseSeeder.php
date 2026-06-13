<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\SubscriptionStatus;
use App\Models\Course;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $monthly = Plan::create([
            'name' => 'Monthly',
            'price_cents' => 1999,
            'currency' => 'USD',
            'interval_days' => 30,
        ]);

        $alice = User::factory()->instructor()->create(['name' => 'Alice']);
        $bob = User::factory()->instructor()->create(['name' => 'Bob']);
        $carol = User::factory()->instructor()->create(['name' => 'Carol']);

        $laravel = Course::factory()->create(['title' => 'Laravel Fundamentals']);
        $design = Course::factory()->create(['title' => 'API Design']);

        foreach ([$alice, $bob, $carol] as $instructor) {
            $laravel->instructors()->attach($instructor->id, ['revenue_weight' => 1]);
            $design->instructors()->attach($instructor->id, ['revenue_weight' => 1]);
        }

        $student = User::factory()->create();
        $startedAt = Carbon::now()->subDays(30);

        Subscription::create([
            'user_id' => $student->id,
            'plan_id' => $monthly->id,
            'status' => SubscriptionStatus::Active,
            'started_at' => $startedAt,
            'ends_at' => $startedAt->copy()->addDays($monthly->interval_days),
            'charged_amount_cents' => $monthly->price_cents,
            'currency' => $monthly->currency,
            'provider_charge_reference' => 'ch_'.Str::random(24),
            'charged_at' => $startedAt,
        ]);
    }
}
