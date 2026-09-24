<?php
namespace App\Support;

use App\Models\Tenant\Chatbot;

/**
 * Which WordPress content types a chatbot's index is allowed to contain —
 * read and written from both the WordPress plugin's own settings page and
 * this chatbot's customer-portal SyncSettings page (App\Filament\Customer\
 * Pages\SyncSettings). The portal is authoritative: the plugin fetches the
 * current value before every sync run (GET .../sync-settings) and pushes its
 * own local changes up (PUT .../sync-settings) so the portal always reflects
 * the latest choice regardless of which side an admin actually edited it
 * from — the same "server is the single source of truth, the plugin is a
 * client of it" shape WidgetDefaults/WidgetSettings already established for
 * appearance/text settings.
 *
 * Defaults match what "sync everything" used to mean in practice before this
 * setting existed: products and pages, but never blog posts (see
 * class-haman-page-sync.php's docblock in the WordPress plugin for the
 * incident this was built to fix).
 */
class SyncSettings
{
    // Only one product type exists in this system (WooCommerce, via
    // class-haman-product-sync.php's wc_get_products() call) — "products"
    // and "WooCommerce products" are the same setting, not two.
    public const DEFAULTS = [
        'sync_products'         => true,
        'sync_pages'            => true,
        'sync_posts'            => false,
        'excluded_category_ids' => [],
        'excluded_page_ids'     => [],
    ];

    /** Always the full shape, defaults filled in for anything the stored row is missing. */
    public static function merge(Chatbot $chatbot): array
    {
        return array_merge(self::DEFAULTS, $chatbot->sync_settings ?? []);
    }

    /** Whether a document already in the index is still allowed to be there under the CURRENT settings. */
    public static function allows(array $settings, string $sourceType, ?int $categoryId = null, ?int $pageId = null): bool
    {
        $settings = array_merge(self::DEFAULTS, $settings);

        switch ($sourceType) {
            case 'woocommerce_product':
            case 'product_attachment':
                return (bool) $settings['sync_products'];
            case 'wordpress_page':
                if ($pageId !== null && in_array($pageId, $settings['excluded_page_ids'], true)) return false;
                return (bool) $settings['sync_pages'];
            case 'wordpress_post':
                if ($categoryId !== null && in_array($categoryId, $settings['excluded_category_ids'], true)) return false;
                return (bool) $settings['sync_posts'];
            default:
                // faq and anything else this setting doesn't govern.
                return true;
        }
    }
}
