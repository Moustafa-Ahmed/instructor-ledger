<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('mock_payment_operations', function (Blueprint $table) {
            $table->id();
            $table->string('provider_reference')->unique();
            $table->string('operation_type', 16);
            $table->string('idempotency_key');
            $table->unsignedBigInteger('amount_cents');
            $table->char('currency', 3);
            $table->string('status', 32);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['operation_type', 'idempotency_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mock_payment_operations');
    }
};
