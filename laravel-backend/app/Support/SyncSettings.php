<?php
namespace App\Support;

use App\Models\Tenant\Chatbot;

/**
 * Which WordPress content a chatbot's index is allowed to contain — read and
 * written from both the WordPress plugin's own settings page and this
 * chatbot's customer-portal SyncSettings page (App\Filament\Customer\Pages\
 * SyncSettings). The portal is authoritative: the plugin fetches the current
 * value before every full sync and when its sync tab opens (GET
 * .../sync-settings), and pushes its own local changes up (PUT
 * .../sync-settings), the same "server is the source of truth, the plugin
 * is a client of it" shape WidgetDefaults/WidgetSettings use.
 *
 * Shape (version 2):
 *
 *   sync_products         bool    WooCommerce products (+ their PDFs) — their
 *                                 own sync path, never listed in post_types
 *   post_types            list    every other post type to sync, by slug:
 *                                 'page', 'post', and any custom type
 *                                 ('portfolio', 'service', …)
 *   custom_fields         map     post type → meta keys the site owner chose,
 *                                 from a preview, to send. Absent = none. A
 *                                 key starting with "_" is never allowed.
 *   available_post_types  map     slug → {label, count}, as last reported by
 *                                 the plugin. Informational only (the portal
 *                                 can't list a site's types otherwise).
 *   excluded_category_ids list    posts in these categories are never synced
 *   excluded_page_ids     list    these pages are never synced
 *
 * Version 1 stored two fixed flags (sync_pages, sync_posts) instead of
 * post_types. normalize() reads either, so an old row, or an old plugin
 * still PUTting the flags, keeps meaning exactly what it meant; the
 * 2026_11_07 migration rewrites stored rows to version 2 once.
 *
 * Defaults match what "sync everything" used to mean before this setting
 * existed: products and pages, never blog posts (see class-haman-page-
 * sync.php in the plugin for the incident behind that).
 */
class SyncSettings
{
    public const VERSION = 2;

    /** Never valid in post_types: WooCommerce has its own path, the rest aren't content. */
    public const NON_CONTENT_TYPES = ['product', 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face', 'elementor_library'];

    public const DEFAULTS = [
        'version'               => self::VERSION,
        'sync_products'         => true,
        'post_types'            => ['page'],
        'custom_fields'         => [],
        'available_post_types'  => [],
        // WooCommerce product attributes (colour, size, material …) the
        // bot may read. null = what WooCommerce itself shows on the product
        // page (is_visible) — the plugin applies that default; a list = the
        // owner's own choice of attribute keys (pa_color, a custom name …).
        'product_attributes'    => null,
        // As the plugin last reported them: key => {label, count, visible}.
        'available_product_attributes' => [],
        'excluded_category_ids' => [],
        'excluded_page_ids'     => [],
    ];

    /** Always the full version-2 shape, defaults filled in for anything the stored row is missing. */
    public static function merge(Chatbot $chatbot): array
    {
        return self::normalize($chatbot->sync_settings ?? []);
    }

    /**
     * Version-2 shape from anything stored or sent: a version-1 row, a
     * partial update, or garbage. Version-1 flags are only consulted when
     * post_types itself is absent, so a new-style value always wins.
     */
    public static function normalize(array $raw): array
    {
        $out = self::DEFAULTS;

        if (array_key_exists('sync_products', $raw)) $out['sync_products'] = (bool) $raw['sync_products'];

        if (array_key_exists('post_types', $raw) && is_array($raw['post_types'])) {
            $out['post_types'] = self::cleanPostTypes($raw['post_types']);
        } elseif (array_key_exists('sync_pages', $raw) || array_key_exists('sync_posts', $raw)) {
            $types = [];
            if ((bool) ($raw['sync_pages'] ?? true)) $types[] = 'page';
            if ((bool) ($raw['sync_posts'] ?? false)) $types[] = 'post';
            $out['post_types'] = $types;
        }

        if (isset($raw['custom_fields']) && is_array($raw['custom_fields'])) {
            $out['custom_fields'] = self::cleanCustomFields($raw['custom_fields']);
        }

        if (isset($raw['available_post_types']) && is_array($raw['available_post_types'])) {
            foreach ($raw['available_post_types'] as $slug => $info) {
                $slug = self::cleanSlug((string) $slug);
                if ($slug === null || !is_array($info)) continue;
                $out['available_post_types'][$slug] = [
                    'label' => mb_substr((string) ($info['label'] ?? $slug), 0, 100),
                    'count' => max(0, (int) ($info['count'] ?? 0)),
                ];
            }
        }

        if (array_key_exists('product_attributes', $raw)) {
            $out['product_attributes'] = is_array($raw['product_attributes'])
                ? array_values(array_unique(array_filter(array_map(fn ($k) => self::cleanAttributeKey((string) $k), $raw['product_attributes']))))
                : null;
        }

        if (isset($raw['available_product_attributes']) && is_array($raw['available_product_attributes'])) {
            foreach (array_slice($raw['available_product_attributes'], 0, 100, true) as $key => $info) {
                $key = self::cleanAttributeKey((string) $key);
                if ($key === null || !is_array($info)) continue;
                $out['available_product_attributes'][$key] = [
                    'label'   => mb_substr((string) ($info['label'] ?? $key), 0, 100),
                    'count'   => max(0, (int) ($info['count'] ?? 0)),
                    'visible' => (bool) ($info['visible'] ?? true),
                    'samples' => array_values(array_slice(array_map(fn ($v) => mb_substr((string) $v, 0, 60), (array) ($info['samples'] ?? [])), 0, 6)),
                ];
            }
        }

        foreach (['excluded_category_ids', 'excluded_page_ids'] as $key) {
            if (isset($raw[$key]) && is_array($raw[$key])) {
                $out[$key] = array_values(array_unique(array_filter(array_map('intval', $raw[$key]), fn ($id) => $id > 0)));
            }
        }

        return $out;
    }

    /**
     * The API's view: version 2 plus the two version-1 flags derived from it,
     * so a plugin older than 2.6.0 that reads them still sees the truth.
     */
    public static function forApi(array $settings): array
    {
        $settings = self::normalize($settings);
        return $settings + [
            'sync_pages' => in_array('page', $settings['post_types'], true),
            'sync_posts' => in_array('post', $settings['post_types'], true),
        ];
    }

    /** Whether a document is allowed in the index under these settings. $postType is required for wordpress_cpt. */
    public static function allows(array $settings, string $sourceType, ?int $categoryId = null, ?int $pageId = null, ?string $postType = null): bool
    {
        $settings = self::normalize($settings);

        switch ($sourceType) {
            case 'woocommerce_product':
            case 'product_attachment':
                return $settings['sync_products'];
            case 'wordpress_page':
                if ($pageId !== null && in_array($pageId, $settings['excluded_page_ids'], true)) return false;
                return in_array('page', $settings['post_types'], true);
            case 'wordpress_post':
                if ($categoryId !== null && in_array($categoryId, $settings['excluded_category_ids'], true)) return false;
                return in_array('post', $settings['post_types'], true);
            case 'wordpress_cpt':
                return $postType !== null && in_array($postType, $settings['post_types'], true);
            default:
                // faq and anything else this setting doesn't govern.
                return true;
        }
    }

    /** A WooCommerce attribute key: pa_color, or a custom attribute's sanitized name (may be URL-encoded Persian). */
    public static function cleanAttributeKey(string $key): ?string
    {
        $key = trim($key);
        return ($key !== '' && mb_strlen($key) <= 200 && !preg_match('/[\s<>"\x27]/u', $key)) ? $key : null;
    }

    /**
     * The attributes of one product the bot may read: all of them while the
     * owner has not chosen (the plugin already sent only the ones WooCommerce
     * shows on the product page), otherwise only the chosen keys — checked
     * here too, so an older or misconfigured plugin cannot widen it.
     */
    public static function allowedProductAttributes(array $settings, array $attributes): array
    {
        $chosen = self::normalize($settings)['product_attributes'];
        if ($chosen === null) return $attributes;
        return array_values(array_filter($attributes, fn ($a) => is_array($a) && in_array((string) ($a['name'] ?? ''), $chosen, true)));
    }

    /** The meta keys allowed for one post type — what the owner picked, minus anything private. */
    public static function allowedCustomFields(array $settings, string $postType): array
    {
        return self::normalize($settings)['custom_fields'][$postType] ?? [];
    }

    /** Post types that were enabled in $before and are not in $after. */
    public static function removedPostTypes(array $before, array $after): array
    {
        return array_values(array_diff(self::normalize($before)['post_types'], self::normalize($after)['post_types']));
    }

    /** The document source_type a WordPress post of this type is stored under. */
    public static function sourceTypeFor(string $postType): string
    {
        return match ($postType) {
            'page'  => 'wordpress_page',
            'post'  => 'wordpress_post',
            default => 'wordpress_cpt',
        };
    }

    private static function cleanSlug(string $slug): ?string
    {
        // WordPress post type keys: lowercase alphanumerics, dashes and
        // underscores, at most 20 characters (register_post_type()).
        $slug = strtolower(trim($slug));
        return preg_match('/^[a-z0-9_-]{1,20}$/', $slug) ? $slug : null;
    }

    private static function cleanPostTypes(array $types): array
    {
        $out = [];
        foreach ($types as $t) {
            $slug = self::cleanSlug((string) $t);
            if ($slug !== null && !in_array($slug, self::NON_CONTENT_TYPES, true)) $out[] = $slug;
        }
        return array_values(array_unique($out));
    }

    private static function cleanCustomFields(array $map): array
    {
        $out = [];
        foreach ($map as $postType => $keys) {
            $postType = self::cleanSlug((string) $postType);
            if ($postType === null || !is_array($keys)) continue;
            $clean = [];
            foreach ($keys as $key) {
                $key = trim((string) $key);
                // Underscore-prefixed meta is WordPress's own convention for
                // "private / not for display" (and where plugins keep
                // internal notes, wholesale prices, customer data). Refused
                // here too, not only in the plugin's picker.
                if ($key === '' || str_starts_with($key, '_') || mb_strlen($key) > 191) continue;
                $clean[] = $key;
            }
            if ($clean) $out[$postType] = array_values(array_unique($clean));
        }
        return $out;
    }
}
