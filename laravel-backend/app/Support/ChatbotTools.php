<?php
namespace App\Support;

/**
 * The tools a chatbot can be allowed to use.
 *
 * Mirrors the Python registry (python-ai-service/app/services/tools/), which
 * gates every tool on the chatbot's own enabled_tools list. That gate has
 * always worked; what was missing was any way to put a name INTO that list,
 * so every chatbot in production sat at [] and no tool had ever run. Which is
 * why compare_products never logged a compared_pair event: not a bug in the
 * logging, just a tool that was never reachable.
 *
 * `cost` is what switching it on can spend, and it is shown to the merchant
 * next to the switch. They are the ones paying, so they are the ones who
 * decide — and they should not have to find out from an invoice.
 */
class ChatbotTools
{
    /**
     * cost: none | live_query | sms | money
     * A tool absent from here cannot be switched on from the panel at all,
     * so adding one to the Python registry without declaring it here fails
     * closed rather than appearing unlabelled.
     */
    public const CATALOGUE = [
        'search_products'          => ['group' => 'catalogue', 'cost' => 'none'],
        'recommend_products'       => ['group' => 'catalogue', 'cost' => 'none'],
        'compare_products'         => ['group' => 'catalogue', 'cost' => 'none'],
        'get_product_variants'     => ['group' => 'catalogue', 'cost' => 'none'],

        'get_product_availability' => ['group' => 'live', 'cost' => 'live_query'],

        'build_cart_url'           => ['group' => 'cart', 'cost' => 'none'],
        'add_to_cart'              => ['group' => 'cart', 'cost' => 'live_query'],

        'create_payment_link'      => ['group' => 'orders', 'cost' => 'money'],
        'get_order_status'         => ['group' => 'orders', 'cost' => 'sms'],
    ];

    /** @return string[] */
    public static function names(): array
    {
        return array_keys(self::CATALOGUE);
    }

    /** @return array<string, string[]> group => tool names */
    public static function grouped(): array
    {
        $groups = [];
        foreach (self::CATALOGUE as $name => $definition) {
            $groups[$definition['group']][] = $name;
        }
        return $groups;
    }

    public static function cost(string $name): string
    {
        return self::CATALOGUE[$name]['cost'] ?? 'none';
    }

    /**
     * Drops anything not in the catalogue.
     *
     * The Python side already ignores unknown names, but storing one would
     * leave a value in the database that nothing can ever turn off from the
     * panel, because the panel only renders known tools.
     */
    public static function sanitise(array $names): array
    {
        return array_values(array_intersect(self::names(), array_filter($names)));
    }
}
