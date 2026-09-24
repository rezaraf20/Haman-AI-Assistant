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
use App\Http\Controllers\Api\V1\HealthController;

Route::prefix('v1')->group(function () {

    // ── Auth (Public) ──────────────────────────────────
    Route::prefix('auth')->group(function () {
        // Registration creates a tenant and a whole Postgres schema; login
        // was guessable at full speed. Both were unthrottled until the
        // abuse audit — see AppServiceProvider for how each is keyed.
        Route::post('register', [AuthController::class, 'register'])->middleware('throttle:register');
        Route::post('login',    [AuthController::class, 'login'])->middleware('throttle:login');
    });

    // Public, unauthenticated — the WordPress plugin's settings page
    // polls this (before necessarily having a valid API key entered) to
    // show an "update available" notice.
    Route::get('wp-plugin/latest-version', [HealthController::class, 'wpPluginVersion'])
        ->middleware('throttle:public-read');

    // ── Chat Widget (Public, but domain- and rate-limited) ─
    Route::prefix('chat')->group(function () {
        Route::post('session', [ChatController::class, 'createSession'])
            ->middleware(['maintenance', 'chatbot.domain', 'throttle:chat-session']);
        Route::post('message', [ChatController::class, 'sendMessage'])
            ->middleware(['maintenance', 'chatbot.domain', 'throttle:chat-message']);
        Route::get('history/{sessionId}', [ChatController::class, 'history'])
            ->middleware(['maintenance', 'chatbot.domain', 'throttle:chat-message']);
        Route::get('conversation/{conversationId}/messages', [ChatController::class, 'conversationMessages'])
            ->middleware(['maintenance', 'chatbot.domain', 'throttle:chat-message']);
        Route::post('feedback', [ChatController::class, 'submitFeedback'])
            ->middleware(['maintenance', 'throttle:chat-message']);
        Route::post('cart-event', [ChatController::class, 'cartEvent'])
            ->middleware(['maintenance', 'throttle:chat-message']);
        // create_payment_link (doc-04) — the one endpoint in this group
        // that creates real money-adjacent state, so it gets the same
        // origin check as session/message rather than cart-event/feedback's
        // lighter posture; its own internal security checks (enabled,
        // amount cap, per-conversation/per-IP-per-day limits) are on top
        // of this, not instead of it.
        Route::post('payment-link', [ChatController::class, 'createPaymentLink'])
            ->middleware(['maintenance', 'chatbot.domain', 'throttle:chat-message']);
        // get_order_status (doc-04) — request-code spends the merchant's
        // money and verify exposes a customer's orders, so both get the
        // same origin check as session/message. Their own caps (per
        // contact/hour, per chatbot/day, per IP/day, 5-minute code, 3
        // attempts) are on top of this, not instead of it.
        Route::post('order-status/request-code', [ChatController::class, 'requestOrderStatusCode'])
            ->middleware(['maintenance', 'chatbot.domain', 'throttle:chat-message']);
        Route::post('order-status/verify', [ChatController::class, 'verifyOrderStatusCode'])
            ->middleware(['maintenance', 'chatbot.domain', 'throttle:chat-message']);
    });

    // ── Plugin API (API Key) ───────────────────────────
    // Every sync call embeds text at the platform's expense, and a valid
    // key could make them as fast as it liked.
    Route::middleware(['auth.apikey', 'tenant.schema', 'throttle:plugin-api'])->group(function () {
        Route::prefix('sync')->group(function () {
            Route::post('products', [SyncController::class, 'syncProducts']);
            Route::post('pages',    [SyncController::class, 'syncPages']);
            Route::post('faqs',     [SyncController::class, 'syncFaqs']);
            Route::post('webhook',  [SyncController::class, 'handleWebhook'])->middleware('webhook.verify');
            Route::get('status/{jobId}', [SyncController::class, 'status']);
            // "Clear and reindex" — wipes this chatbot's synced documents
            // (never manually-entered FAQs) so a changed sync_settings
            // scope doesn't leave stale, now-excluded content sitting in
            // the index; the plugin follows this with its own normal full
            // sync to rebuild only what's currently allowed.
            Route::post('clear', [SyncController::class, 'clearIndex']);
        });
        Route::get('chatbots',       [ChatbotController::class, 'index']);
        Route::get('chatbots/{id}',  [ChatbotController::class, 'show']);
        Route::put('chatbots/{id}/widget-settings', [ChatbotController::class, 'updateWidgetSettings']);
        Route::get('chatbots/{id}/sync-settings', [ChatbotController::class, 'syncSettings']);
        Route::put('chatbots/{id}/sync-settings', [ChatbotController::class, 'updateSyncSettings']);
        Route::get('tenant/webhook-secret', [TenantController::class, 'webhookSecret']);
        Route::post('tenant/webhook-secret/regenerate', [TenantController::class, 'regenerateWebhookSecret']);
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

        // NOT apiResource. It generates GET chatbots and GET chatbots/{chatbot},
        // which collide with the API-key routes above — and because this group
        // is registered second, its `GET chatbots` silently overwrote the
        // plugin's one in the route lookup. Every WordPress plugin install got
        // 401 from its own "test connection" button as a result, since
        // Haman_Api_Client::get_chatbots() calls exactly that endpoint.
        //
        // So: only the verbs this group actually needs, spelled out, and the
        // two GETs deliberately left to the API-key group. Param named {id}
        // to match the routes above — two routes matching the same path with
        // differently-named parameters is the other half of the same trap.
        Route::post('chatbots',        [ChatbotController::class, 'store']);
        Route::put('chatbots/{id}',    [ChatbotController::class, 'update']);
        Route::delete('chatbots/{id}', [ChatbotController::class, 'destroy']);
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
