<?php

declare(strict_types=1);

use App\Http\Controllers\MockPaymentProviderController;
use Illuminate\Support\Facades\Route;

Route::prefix('mock-provider')->group(function () {
    Route::post('charges', [MockPaymentProviderController::class, 'charge']);
    Route::post('sends', [MockPaymentProviderController::class, 'send']);
    Route::get('operations/{provider_reference}/status', [MockPaymentProviderController::class, 'status']);
    Route::get('operations/{operation_type}/{idempotency_key}/status', [MockPaymentProviderController::class, 'statusByIdempotencyKey']);
});
