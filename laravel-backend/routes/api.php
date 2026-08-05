<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ChatController;
use App\Http\Controllers\Api\V1\ChatbotController;
use App\Http\Controllers\Api\V1\SyncController;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\TenantController;
use App\Http\Controllers\Api\V1\FaqController;
use App\Http\Controllers\Api\V1\DocumentController;

Route::prefix('v1')->group(function () {

    // ── Auth (Public) ──────────────────────────────────
    Route::prefix('auth')->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login',    [AuthController::class, 'login']);
    });

    // ── Chat Widget (Public) ───────────────────────────
    Route::prefix('chat')->group(function () {
        Route::post('session',              [ChatController::class, 'createSession']);
        Route::post('message',             [ChatController::class, 'sendMessage']);
        Route::get('history/{sessionId}',  [ChatController::class, 'history']);
        Route::post('feedback',            [ChatController::class, 'submitFeedback']);
    });

    // ── Plugin API (API Key) ───────────────────────────
    Route::middleware(['auth.apikey', 'tenant.schema'])->group(function () {
        Route::prefix('sync')->group(function () {
            Route::post('products', [SyncController::class, 'syncProducts']);
            Route::post('pages',    [SyncController::class, 'syncPages']);
            Route::post('faqs',     [SyncController::class, 'syncFaqs']);
            Route::post('webhook',  [SyncController::class, 'handleWebhook'])->middleware('webhook.verify');
            Route::get('status/{jobId}', [SyncController::class, 'status']);
        });
        Route::get('chatbots',       [ChatbotController::class, 'index']);
        Route::get('chatbots/{id}',  [ChatbotController::class, 'show']);
        Route::put('chatbots/{id}/widget-settings', [ChatbotController::class, 'updateWidgetSettings']);
    });

    // ── Dashboard (Sanctum) ────────────────────────────
    Route::middleware(['auth:sanctum', 'tenant.schema'])->group(function () {
        Route::get('auth/me',      [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);

        Route::get('tenant',       [TenantController::class, 'show']);
        Route::patch('tenant',     [TenantController::class, 'update']);
        Route::get('tenant/usage', [TenantController::class, 'usage']);
        Route::post('tenant/apikeys',        [TenantController::class, 'createApiKey']);
        Route::get('tenant/apikeys',         [TenantController::class, 'apiKeys']);
        Route::delete('tenant/apikeys/{id}', [TenantController::class, 'revokeApiKey']);

        Route::apiResource('chatbots', ChatbotController::class);
        Route::post('chatbots/{id}/domains',          [ChatbotController::class, 'addDomain']);
        Route::delete('chatbots/{id}/domains/{domain}', [ChatbotController::class, 'removeDomain']);
        Route::get('chatbots/{id}/documents',         [ChatbotController::class, 'documents']);
        Route::post('chatbots/{id}/documents',        [DocumentController::class, 'store']);
        Route::get('chatbots/{id}/products',          [ChatbotController::class, 'products']);
        Route::get('chatbots/{id}/stats',             [ChatbotController::class, 'stats']);

        Route::get('chatbots/{id}/faqs',            [FaqController::class, 'index']);
        Route::post('chatbots/{id}/faqs',           [FaqController::class, 'store']);
        Route::put('chatbots/{id}/faqs/{faqId}',    [FaqController::class, 'update']);
        Route::delete('chatbots/{id}/faqs/{faqId}', [FaqController::class, 'destroy']);

        Route::prefix('analytics')->group(function () {
            Route::get('dashboard',     [AnalyticsController::class, 'dashboard']);
            Route::get('conversations', [AnalyticsController::class, 'conversations']);
            Route::get('tokens',        [AnalyticsController::class, 'tokenUsage']);
        });
    });
});
