<?php
namespace App\Services;

/**
 * get_order_status (doc-04) — the two live-query calls behind the OTP
 * order-status flow, deliberately kept as two distinct actions rather than
 * one call with a flag.
 *
 * hasOrders() runs BEFORE a single SMS is paid for and is structurally
 * incapable of returning order content: the plugin answers it with a
 * boolean and a count, nothing more. fetchOrders() runs only AFTER the
 * customer has proved control of the contact, and the plugin sanitises its
 * own response at source — a shipping address or payment reference never
 * crosses the wire at all, rather than being fetched here and dropped
 * later.
 */
class OrderStatusService
{
    public function __construct(private LiveQueryClient $live) {}

    /**
     * "Is it even worth sending an SMS to this contact?" — the single most
     * important control in this feature: without it, anyone could make the
     * merchant pay to text an arbitrary number.
     *
     * Returns null when the store could not be reached at all. Callers MUST
     * distinguish that from false ("no orders"): treating an unreachable
     * store as "no orders" is the safe direction (no SMS), but the customer
     * deserves a different message.
     */
    public function hasOrders(string $domain, string $secret, string $contact, string $contactType): ?bool
    {
        $result = $this->live->call($domain, $secret, 'contact_has_orders', [
            'contact'      => $contact,
            'contact_type' => $contactType,
        ]);
        if ($result === null || !array_key_exists('found', $result)) return null;
        return (bool) $result['found'];
    }

    /**
     * The sanitised order list — status, tracking code and item names only.
     * Never reached before a verified OTP for this exact contact.
     */
    public function fetchOrders(string $domain, string $secret, string $contact, string $contactType): ?array
    {
        $result = $this->live->call($domain, $secret, 'get_orders_for_contact', [
            'contact'      => $contact,
            'contact_type' => $contactType,
        ]);
        if ($result === null || !isset($result['orders']) || !is_array($result['orders'])) return null;

        // Belt and braces: the plugin already limits what it sends, but this
        // endpoint's whole promise is that address/payment data never
        // reaches the customer's browser, so the shape is pinned here too
        // rather than trusting whatever a (possibly older) plugin returns.
        return array_map(fn ($o) => [
            'number'       => (string) ($o['number'] ?? ''),
            'status'       => (string) ($o['status'] ?? ''),
            'date_created' => $o['date_created'] ?? null,
            'tracking'     => $o['tracking'] ?? null,
            'items'        => array_map(fn ($i) => [
                'name'     => (string) ($i['name'] ?? ''),
                'quantity' => (int) ($i['quantity'] ?? 1),
            ], is_array($o['items'] ?? null) ? $o['items'] : []),
        ], $result['orders']);
    }
}
