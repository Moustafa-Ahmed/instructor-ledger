<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')
                ->nullable()
                ->constrained('subscriptions')
                ->nullOnDelete();
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('type');
            $table->integer('amount_cents');
            $table->string('idempotency_key')->unique();
            $table->foreignId('subscription_entry_id')
                ->nullable()
                ->constrained('ledger_entries')
                ->restrictOnDelete();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique('subscription_entry_id', 'ledger_entries_subscription_entry_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
