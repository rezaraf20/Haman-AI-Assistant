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
     * default_enabled: the compiled-in fallback used to seed the
     * tools.default_enabled.<name> setting (SettingsRegistry) the first time
     * it's read — the real, admin-editable value always wins once set; see
     * defaultEnabledNames(). get_order_status starts off because it exposes
     * a real customer's order to anyone in the conversation until buyer
     * identity is actually verified (see OrderStatusTest's OTP flow) — a
     * merchant turns it on once, deliberately, from the panel.
     * A tool absent from here cannot be switched on from the panel at all,
     * so adding one to the Python registry without declaring it here fails
     * closed rather than appearing unlabelled.
     */
    public const CATALOGUE = [
        'search_products'          => ['group' => 'catalogue', 'cost' => 'none', 'default_enabled' => true],
        'recommend_products'       => ['group' => 'catalogue', 'cost' => 'none', 'default_enabled' => true],
        'compare_products'         => ['group' => 'catalogue', 'cost' => 'none', 'default_enabled' => true],
        'get_product_variants'     => ['group' => 'catalogue', 'cost' => 'none', 'default_enabled' => true],

        'get_product_availability' => ['group' => 'live', 'cost' => 'live_query', 'default_enabled' => true],

        'build_cart_url'           => ['group' => 'cart', 'cost' => 'none', 'default_enabled' => true],
        'add_to_cart'              => ['group' => 'cart', 'cost' => 'live_query', 'default_enabled' => true],

        'create_payment_link'      => ['group' => 'orders', 'cost' => 'money', 'default_enabled' => true],
        // Off by default: answering "where is my order" requires the buyer's
        // identity to actually be verified first (the OTP flow in
        // OrderStatusTest), not just that the plan and chatbot allow it.
        'get_order_status'         => ['group' => 'orders', 'cost' => 'sms', 'default_enabled' => false],
    ];

    /** @return string[] */
    public static function names(): array
    {
        return array_keys(self::CATALOGUE);
    }

    /**
     * The tools switched on for a chatbot that has never had enabled_tools
     * set (database NULL) — read from the admin-editable
     * tools.default_enabled.<name> setting, not this class's own
     * CATALOGUE constant directly, so the platform owner can change the
     * default without a deploy (see SettingsRegistry). CATALOGUE's
     * default_enabled only seeds that setting's own declared default.
     *
     * @return string[]
     */
    public static function defaultEnabledNames(): array
    {
        return array_values(array_filter(
            self::names(),
            fn (string $name) => \App\Support\Settings::get("tools.default_enabled.{$name}"),
        ));
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
     * The bilingual, editable display name for a tool — "افزودن به سبد
     * خرید" for an admin or a visitor, never the technical slug
     * add_to_cart. These already exist as lang/{fa,en}/chatbot.php's
     * tool_<name> keys (WidgetSettings's own tool checkboxes have used them
     * since the tool gate shipped) — this is the one place every OTHER
     * consumer (the public pricing page's comparison table included) should
     * read them from, rather than re-deriving a label from the slug.
     */
    public static function label(string $name): string
    {
        return __("chatbot.tool_{$name}");
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
