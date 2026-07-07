<?php

use App\Http\Controllers\Api\V1\AvailabilityController;
use App\Http\Controllers\Api\V1\BusinessController;
use App\Http\Controllers\Api\V1\ReservationController;
use App\Http\Controllers\Api\V1\ServiceController;
use App\Http\Controllers\Api\V1\TenantController;
use App\Http\Controllers\Api\V1\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('tenants', [TenantController::class, 'store']);

    Route::middleware('tenant')->group(function (): void {
        Route::apiResource('businesses', BusinessController::class)->only(['index', 'store', 'show', 'update']);
        Route::apiResource('services', ServiceController::class)->only(['index', 'store', 'show', 'update']);

        Route::get('availability', [AvailabilityController::class, 'index']);
        Route::post('reservations', [ReservationController::class, 'store']);
        Route::post('reservations/{reservation}/cancel', [ReservationController::class, 'cancel']);
        Route::post('reservations/{reservation}/reschedule', [ReservationController::class, 'reschedule']);
    });

    Route::get('webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify']);
    Route::post('webhooks/whatsapp', [WhatsAppWebhookController::class, 'handle']);
});
