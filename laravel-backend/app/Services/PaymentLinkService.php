<?php
namespace App\Services;

/**
 * create_payment_link (doc-04) — Laravel calls the WordPress plugin's
 * live-query endpoint DIRECTLY here, unlike every other live-query tool
 * (which is only ever called from Python's product_tools.py): this is
 * pure order-creation/security-enforcement logic, not something that
 * needs the AI service involved at all, and Laravel already holds the
 * tenant's webhook_secret directly (Tenant::getWebhookSecret()), not via
 * the public-schema join product_tools.py needs to reach the same value.
 *
 * Same HMAC scheme as every other live-query caller
 * (X-Haman-Signature: sha256=hex(hmac_sha256(raw_body, secret))) — see
 * Haman_Live_Query_Handler::verify_signature() on the plugin side.
 */
class PaymentLinkService
{
    public function __construct(private LiveQueryClient $live) {}

    /** Read-only — computes live price/name/total for a proposed order
     * without creating anything. Returns null on ANY failure (unreachable
     * site, invalid signature setup, out of stock, etc.) — the caller
     * must treat null as "can't confirm this order right now", never
     * invent a total of its own. */
    public function previewOrder(string $domain, string $secret, array $items): ?array
    {
        return $this->live->call($domain, $secret, 'preview_order', ['items' => $items]);
    }

    /** The one call in this whole class that actually creates a real
     * WooCommerce order. Only ever reached from ChatController::
     * createPaymentLink() AFTER every security check (enabled, cap
     * configured, per-conversation/per-IP limits, amount within cap
     * against a freshly-previewed total) has already passed. */
    public function createDraftOrder(string $domain, string $secret, array $items, ?array $customer): ?array
    {
        $payload = ['items' => $items];
        if (!empty($customer)) $payload['customer'] = $customer;
        return $this->live->call($domain, $secret, 'create_draft_order', $payload);
    }
}
