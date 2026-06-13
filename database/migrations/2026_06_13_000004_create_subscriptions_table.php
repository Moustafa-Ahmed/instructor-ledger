<?php

declare(strict_types=1);

use App\Enums\SubscriptionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('plan_id')->constrained('plans')->restrictOnDelete();
            $table->string('status')->default(SubscriptionStatus::Active->value);
            $table->timestamp('started_at');
            $table->timestamp('ends_at');
            $table->unsignedInteger('charged_amount_cents');
            $table->string('currency', 3)->default('USD');
            $table->unsignedSmallInteger('platform_cut_bps');
            $table->string('provider_charge_reference')->unique();
            $table->timestamp('charged_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('status');
            $table->index('started_at');
            $table->index('charged_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
