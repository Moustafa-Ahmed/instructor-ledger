<?php

declare(strict_types=1);

use App\Enums\PlanInterval;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('interval')->default(PlanInterval::Monthly->value);
            $table->unsignedSmallInteger('interval_count')->default(1);
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3)->default('USD');
            $table->unsignedSmallInteger('duration_days');
            $table->timestamps();

            $table->index(['interval', 'interval_count']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
