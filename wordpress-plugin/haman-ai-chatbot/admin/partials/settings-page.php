<?php if (!defined('ABSPATH')) exit;
/**
 * Haman AI settings page. WordPress's own structure (.wrap, nav-tab-wrapper,
 * form-table, notices) so it reads as a WordPress settings screen, with the
 * brand palette applied only inside .haman-admin (admin/css/haman-admin.css).
 * Every string is translatable ('haman-ai-chatbot'), so the page follows
 * the admin's language and direction.
 */
$log = get_option(Haman_Admin::LOG_OPTION, []);
if (!is_array($log)) $log = [];

$field_mapping = get_option('haman_field_mapping', []);
if (!is_array($field_mapping)) $field_mapping = [];
$authenticity_field_labels = [
    'authenticity_status'  => __('Authenticity status', 'haman-ai-chatbot'),
    'brand'                => __('Brand name', 'haman-ai-chatbot'),
    'official_distributor' => __('Official distributor', 'haman-ai-chatbot'),
    'warranty_period'      => __('Warranty period', 'haman-ai-chatbot'),
    'country_of_origin'    => __('Country of origin', 'haman-ai-chatbot'),
];

$hm_tabs = [
    'connection' => __('Connection', 'haman-ai-chatbot'),
    'sync'       => __('Sync', 'haman-ai-chatbot'),
    'appearance' => __('Appearance & texts', 'haman-ai-chatbot'),
    'advanced'   => __('Advanced', 'haman-ai-chatbot'),
];

$hm_last_sync = (int) get_option('haman_last_full_sync', 0);
$hm_last_sync_text = $hm_last_sync ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $hm_last_sync) : __('Never', 'haman-ai-chatbot');

// rtrim($url, '/api/v1') would be wrong — rtrim's second argument is a
// character mask, not a suffix — hence the regex. /portal/widget-settings/{id}
// is the portal page where these settings are actually edited (the chatbot
// id is a route segment there, not a query parameter).
$hm_portal_base = preg_replace('#/api/v1/?$#', '', get_option('haman_api_url', HAMAN_API_BASE));
$hm_chatbot_id  = get_option('haman_chatbot_id', '');
$hm_widget_url  = $hm_portal_base . '/portal/widget-settings/' . rawurlencode($hm_chatbot_id);
$hm_knowledge_url = $hm_portal_base . '/portal/knowledge';
?>
<div class="wrap haman-admin">
<h1 class="haman-admin__title">
    <span class="dashicons dashicons-format-chat" aria-hidden="true"></span>
    <?php esc_html_e('Haman AI Chatbot', 'haman-ai-chatbot'); ?>
</h1>
<hr class="wp-header-end">

<?php if (isset($_GET['saved'])): ?>
    <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'haman-ai-chatbot'); ?></p></div>
<?php endif; ?>
<?php if (isset($_GET['synced'])): ?>
    <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Sync finished.', 'haman-ai-chatbot'); ?></p></div>
<?php endif; ?>

<?php /* At-a-glance status. Connection and documents are filled in by
         haman-admin.js from the status AJAX call, so the page itself never
         waits on the Haman API to render. */ ?>
<section class="haman-status" id="hm-status" aria-labelledby="hm-status-heading" data-overall="checking">
    <div class="haman-status__head">
        <h2 id="hm-status-heading" class="haman-status__title"><?php esc_html_e('Status', 'haman-ai-chatbot'); ?></h2>
        <p class="haman-status__summary" id="hm-status-summary" role="status" aria-live="polite"><?php esc_html_e('Checking…', 'haman-ai-chatbot'); ?></p>
        <button type="button" class="button button-small haman-status__recheck" id="hm-status-recheck"><?php esc_html_e('Re-check', 'haman-ai-chatbot'); ?></button>
    </div>
    <ul class="haman-status__grid">
        <li class="haman-status__item">
            <span class="haman-status__label"><?php esc_html_e('Connection', 'haman-ai-chatbot'); ?></span>
            <span class="haman-badge" id="hm-status-connection" data-tone="neutral"><?php esc_html_e('Checking…', 'haman-ai-chatbot'); ?></span>
            <span class="haman-status__detail" id="hm-status-connection-detail"></span>
        </li>
        <li class="haman-status__item">
            <span class="haman-status__label"><?php esc_html_e('Last full sync', 'haman-ai-chatbot'); ?></span>
            <span class="haman-status__value" id="hm-status-last-sync"><?php echo esc_html($hm_last_sync_text); ?></span>
        </li>
        <li class="haman-status__item">
            <span class="haman-status__label"><?php esc_html_e('Indexed documents', 'haman-ai-chatbot'); ?></span>
            <span class="haman-status__value" id="hm-status-documents"><?php esc_html_e('Checking…', 'haman-ai-chatbot'); ?></span>
        </li>
        <li class="haman-status__item">
            <span class="haman-status__label"><?php esc_html_e('Widget on this site', 'haman-ai-chatbot'); ?></span>
            <?php
            $hm_widget_on = get_option('haman_enabled', '1') === '1';
            $hm_configured = !empty(get_option('haman_api_key')) && !empty($hm_chatbot_id);
            if ($hm_widget_on && $hm_configured): ?>
                <span class="haman-badge" id="hm-status-widget" data-tone="success"><?php esc_html_e('Active', 'haman-ai-chatbot'); ?></span>
            <?php elseif ($hm_widget_on): ?>
                <span class="haman-badge" id="hm-status-widget" data-tone="warning"><?php esc_html_e('Not shown — not connected', 'haman-ai-chatbot'); ?></span>
            <?php else: ?>
                <span class="haman-badge" id="hm-status-widget" data-tone="neutral"><?php esc_html_e('Turned off', 'haman-ai-chatbot'); ?></span>
            <?php endif; ?>
        </li>
    </ul>
</section>

<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e('Settings sections', 'haman-ai-chatbot'); ?>">
    <?php foreach ($hm_tabs as $id => $label): ?>
        <a href="<?php echo esc_url(add_query_arg(['page' => 'haman-ai-chatbot', 'tab' => $id], admin_url('admin.php'))); ?>" class="nav-tab hm-tab-link" data-tab="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?></a>
    <?php endforeach; ?>
</nav>

<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="haman-admin__form">
<input type="hidden" name="action" value="haman_save_settings">
<input type="hidden" name="haman_active_tab" id="haman_active_tab_input" value="connection">
<?php wp_nonce_field('haman_save_settings'); ?>

<div class="hm-tab-panel haman-panel" data-tab-panel="connection">
    <p class="haman-panel__intro"><?php esc_html_e('Enter the API key and Chatbot ID from your Haman panel, save, then test the connection.', 'haman-ai-chatbot'); ?></p>
    <table class="form-table" role="presentation">
    <tr>
        <th scope="row"><label for="hm-api-key"><?php esc_html_e('API key', 'haman-ai-chatbot'); ?></label></th>
        <td>
            <div class="haman-secret">
                <input type="password" id="hm-api-key" name="haman_api_key" value="<?php echo esc_attr(get_option('haman_api_key', '')); ?>" class="regular-text code" autocomplete="off" spellcheck="false" dir="ltr">
                <button type="button" class="button" id="hm-api-key-toggle" aria-controls="hm-api-key" aria-pressed="false"><?php esc_html_e('Show', 'haman-ai-chatbot'); ?></button>
                <button type="button" class="button" id="hm-api-key-copy"><?php esc_html_e('Copy', 'haman-ai-chatbot'); ?></button>
                <span class="haman-inline-note" id="hm-api-key-copy-result" role="status" aria-live="polite"></span>
            </div>
            <p class="description"><?php esc_html_e('From your Haman panel → API keys. It starts with hfp_.', 'haman-ai-chatbot'); ?></p>
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="hm-chatbot-id"><?php esc_html_e('Chatbot ID', 'haman-ai-chatbot'); ?></label></th>
        <td><input type="text" id="hm-chatbot-id" name="haman_chatbot_id" value="<?php echo esc_attr($hm_chatbot_id); ?>" class="regular-text code" placeholder="00000000-0000-0000-0000-000000000000" dir="ltr"></td>
    </tr>
    <tr>
        <th scope="row"><label for="hm-api-url"><?php esc_html_e('API URL', 'haman-ai-chatbot'); ?></label></th>
        <td>
            <input type="url" id="hm-api-url" name="haman_api_url" value="<?php echo esc_attr(get_option('haman_api_url', HAMAN_API_BASE)); ?>" class="regular-text code" dir="ltr">
            <p class="description"><?php esc_html_e('Leave as it is unless Haman support asks you to change it.', 'haman-ai-chatbot'); ?></p>
        </td>
    </tr>
    <tr>
        <th scope="row"><?php esc_html_e('Chat widget', 'haman-ai-chatbot'); ?></th>
        <td>
            <label><input type="checkbox" name="haman_enabled" value="1" <?php checked(get_option('haman_enabled', '1'), '1'); ?>> <?php esc_html_e('Show the chat widget on this site', 'haman-ai-chatbot'); ?></label>
        </td>
    </tr>
    <tr>
        <th scope="row"><?php esc_html_e('Test connection', 'haman-ai-chatbot'); ?></th>
        <td>
            <button type="button" id="hm-test-connection" class="button"><?php esc_html_e('Test connection', 'haman-ai-chatbot'); ?></button>
            <span class="spinner" id="hm-test-connection-spinner"></span>
            <div id="hm-test-connection-result" class="haman-result" role="status" aria-live="polite" hidden></div>
            <p class="description"><?php esc_html_e('Uses the saved key and URL — save first if you just changed them. A successful test shows the name of the connected chatbot.', 'haman-ai-chatbot'); ?></p>
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="hm-webhook-secret-field"><?php esc_html_e('Webhook secret', 'haman-ai-chatbot'); ?></label></th>
        <td>
            <div class="haman-secret">
                <input type="password" id="hm-webhook-secret-field" value="••••••••••••" class="regular-text code" readonly dir="ltr">
                <button type="button" id="hm-webhook-secret-show" class="button"><?php esc_html_e('Show', 'haman-ai-chatbot'); ?></button>
                <button type="button" id="hm-webhook-secret-regenerate" class="button"><?php esc_html_e('Regenerate', 'haman-ai-chatbot'); ?></button>
                <span class="haman-inline-note" id="hm-webhook-secret-result" role="status" aria-live="polite"></span>
            </div>
            <p class="description"><?php esc_html_e('Kept on the Haman server, never stored on this site, so it is always the current value.', 'haman-ai-chatbot'); ?></p>
        </td>
    </tr>
    </table>
</div>

<div class="hm-tab-panel haman-panel" data-tab-panel="sync">
    <h2 class="haman-panel__heading"><?php esc_html_e('Sync status', 'haman-ai-chatbot'); ?></h2>
    <div class="haman-sync-status">
        <p><?php esc_html_e('Last full sync:', 'haman-ai-chatbot'); ?> <strong id="hm-sync-last"><?php echo esc_html($hm_last_sync_text); ?></strong></p>
        <div id="hm-knowledge" class="haman-knowledge" aria-live="polite"><p class="description"><?php esc_html_e('Loading…', 'haman-ai-chatbot'); ?></p></div>
        <div class="haman-sync-run">
            <button type="button" class="button button-secondary" id="hm-run-sync"><?php esc_html_e('Run full sync now', 'haman-ai-chatbot'); ?></button>
            <span class="spinner" id="hm-run-sync-spinner"></span>
            <div class="haman-progress" id="hm-sync-progress" hidden>
                <progress id="hm-sync-progress-bar" max="100" value="0"></progress>
                <span id="hm-sync-progress-text" role="status" aria-live="polite"></span>
            </div>
            <div id="hm-sync-result" class="haman-result" role="status" aria-live="polite" hidden></div>
        </div>
        <p class="description"><a href="<?php echo esc_url($hm_knowledge_url); ?>" target="_blank" rel="noopener"><?php esc_html_e('See the full breakdown in your Haman panel', 'haman-ai-chatbot'); ?></a></p>
    </div>

    <h2 class="haman-panel__heading"><?php esc_html_e('What to sync', 'haman-ai-chatbot'); ?></h2>
    <table class="form-table" role="presentation">
    <tr><th scope="row"><?php esc_html_e('Content', 'haman-ai-chatbot'); ?></th><td>
        <label><input type="checkbox" name="haman_sync_products" value="1" <?php checked(get_option('haman_sync_products', '1'), '1'); ?>> <?php esc_html_e('WooCommerce products', 'haman-ai-chatbot'); ?></label>
        <?php
        // Every public post type this site really has — read from WordPress
        // (get_post_types), not a hand-written list — with its own label and
        // published count. See Haman_Sync_Settings.
        $hm_types   = Haman_Sync_Settings::available_post_types();
        $hm_enabled = Haman_Page_Sync::enabled_post_types();
        $hm_fields  = Haman_Sync_Settings::custom_fields();
        ?>
        <div class="haman-table-wrap">
        <table class="widefat striped haman-post-types">
            <thead><tr>
                <td class="check-column"><span class="screen-reader-text"><?php esc_html_e('Sync', 'haman-ai-chatbot'); ?></span></td>
                <th scope="col"><?php esc_html_e('Content type', 'haman-ai-chatbot'); ?></th>
                <th scope="col"><?php esc_html_e('Slug', 'haman-ai-chatbot'); ?></th>
                <th scope="col" class="num"><?php esc_html_e('Published', 'haman-ai-chatbot'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($hm_types as $slug => $info): ?>
                <tr>
                    <th scope="row" class="check-column">
                        <?php if ($info['separate']): ?>
                            <input type="checkbox" disabled aria-label="<?php esc_attr_e('Managed separately', 'haman-ai-chatbot'); ?>">
                        <?php else: ?>
                            <input type="checkbox" class="hm-post-type-toggle" id="hm-pt-<?php echo esc_attr($slug); ?>" name="haman_sync_post_types[]" value="<?php echo esc_attr($slug); ?>" data-type="<?php echo esc_attr($slug); ?>" <?php checked(in_array($slug, $hm_enabled, true)); ?>>
                        <?php endif; ?>
                    </th>
                    <td>
                        <label for="hm-pt-<?php echo esc_attr($slug); ?>"><strong><?php echo esc_html($info['label']); ?></strong></label>
                        <?php if ($info['separate']): ?>
                            <br><span class="description"><?php esc_html_e('Synced separately, by the WooCommerce products option above.', 'haman-ai-chatbot'); ?></span>
                        <?php elseif ($slug === 'post'): ?>
                            <br><span class="description"><?php esc_html_e('Off by default — a blog post is rarely what a customer is asking about.', 'haman-ai-chatbot'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><code><?php echo esc_html($slug); ?></code></td>
                    <td class="num"><?php echo esc_html(number_format_i18n($info['count'])); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <p><label><input type="checkbox" name="haman_sync_pdfs" value="1" <?php checked(get_option('haman_sync_pdfs', '1'), '1'); ?>> <?php esc_html_e('PDF files attached to products (datasheets)', 'haman-ai-chatbot'); ?></label></p>
    </td></tr>
    <tr><th scope="row"><?php esc_html_e('Custom fields', 'haman-ai-chatbot'); ?></th><td>
        <p class="description haman-measure"><?php esc_html_e('No custom field is sent unless you pick it. For each selected content type, the fields that actually exist on this site are listed with a real sample value; tick the ones the bot should know. Private fields (starting with _) are never shown or sent.', 'haman-ai-chatbot'); ?></p>
        <?php foreach ($hm_types as $slug => $info): if ($info['separate']) continue; ?>
            <?php $hm_keys = Haman_Sync_Settings::discover_meta_keys($slug); $hm_picked = $hm_fields[$slug] ?? []; ?>
            <fieldset class="hm-custom-fields haman-fieldset" data-type="<?php echo esc_attr($slug); ?>" <?php echo in_array($slug, $hm_enabled, true) ? '' : 'hidden'; ?>>
                <legend><?php echo esc_html($info['label']); ?></legend>
                <?php if (empty($hm_keys)): ?>
                    <p class="description"><?php esc_html_e('This type has no public custom fields.', 'haman-ai-chatbot'); ?></p>
                <?php else: ?>
                    <div class="haman-table-wrap">
                    <table class="widefat striped">
                        <thead><tr>
                            <td class="check-column"><span class="screen-reader-text"><?php esc_html_e('Send', 'haman-ai-chatbot'); ?></span></td>
                            <th scope="col"><?php esc_html_e('Key', 'haman-ai-chatbot'); ?></th>
                            <th scope="col"><?php esc_html_e('Sample value', 'haman-ai-chatbot'); ?></th>
                            <th scope="col" class="num"><?php esc_html_e('Items', 'haman-ai-chatbot'); ?></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($hm_keys as $k): ?>
                            <tr>
                                <th scope="row" class="check-column">
                                    <?php if ($k['sendable']): ?>
                                        <input type="checkbox" class="hm-field-toggle" name="haman_sync_custom_fields[<?php echo esc_attr($slug); ?>][]" value="<?php echo esc_attr($k['key']); ?>" aria-label="<?php echo esc_attr($k['key']); ?>" <?php checked(in_array($k['key'], $hm_picked, true)); ?>>
                                    <?php else: ?>
                                        <input type="checkbox" disabled aria-label="<?php esc_attr_e('Not plain text', 'haman-ai-chatbot'); ?>">
                                    <?php endif; ?>
                                </th>
                                <td><code><?php echo esc_html($k['key']); ?></code></td>
                                <td><?php if ($k['sendable']): echo esc_html(mb_strimwidth($k['sample'], 0, 80, '…')); else: ?><em><?php esc_html_e('Structured data — cannot be sent', 'haman-ai-chatbot'); ?></em><?php endif; ?></td>
                                <td class="num"><?php echo esc_html(number_format_i18n($k['items'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    <p class="hm-field-summary description"></p>
                <?php endif; ?>
            </fieldset>
        <?php endforeach; ?>
    </td></tr>
    <tr><th scope="row"><?php esc_html_e('Leave out', 'haman-ai-chatbot'); ?></th><td>
        <p>
            <label for="hm-excluded-cats"><?php esc_html_e('Category IDs to leave out (posts)', 'haman-ai-chatbot'); ?></label><br>
            <input type="text" id="hm-excluded-cats" name="haman_sync_excluded_category_ids" value="<?php echo esc_attr(get_option('haman_sync_excluded_category_ids', '')); ?>" class="regular-text" placeholder="4, 9, 12" dir="ltr">
        </p>
        <p class="description"><?php esc_html_e('Posts in these categories are never synced, even when posts are on.', 'haman-ai-chatbot'); ?></p>
        <p>
            <label for="hm-excluded-pages"><?php esc_html_e('Page IDs to leave out', 'haman-ai-chatbot'); ?></label><br>
            <input type="text" id="hm-excluded-pages" name="haman_sync_excluded_page_ids" value="<?php echo esc_attr(get_option('haman_sync_excluded_page_ids', '')); ?>" class="regular-text" placeholder="2, 15" dir="ltr">
        </p>
    </td></tr>
    </table>

    <h2 class="haman-panel__heading"><?php esc_html_e('Authenticity field mapping', 'haman-ai-chatbot'); ?></h2>
    <p class="description haman-measure"><?php esc_html_e('“Is this genuine?” is the question shoppers ask most. The bot only repeats exactly what you recorded in these fields — it never judges on its own. If your store keeps any of them in a custom field or product attribute, say which one here.', 'haman-ai-chatbot'); ?></p>
    <table class="form-table" role="presentation">
        <?php foreach (Haman_Product_Sync::AUTHENTICITY_FIELDS as $field): $conf = $field_mapping[$field] ?? []; ?>
        <tr>
            <th scope="row"><label for="hm-map-<?php echo esc_attr($field); ?>"><?php echo esc_html($authenticity_field_labels[$field]); ?></label></th>
            <td class="haman-mapping">
                <select id="hm-map-<?php echo esc_attr($field); ?>" name="haman_field_map_<?php echo esc_attr($field); ?>_type">
                    <option value="" <?php selected(empty($conf['type'])); ?>><?php esc_html_e('— Not mapped —', 'haman-ai-chatbot'); ?></option>
                    <option value="meta" <?php selected($conf['type'] ?? '', 'meta'); ?>><?php esc_html_e('Custom field (meta key)', 'haman-ai-chatbot'); ?></option>
                    <option value="attribute" <?php selected($conf['type'] ?? '', 'attribute'); ?>><?php esc_html_e('Product attribute', 'haman-ai-chatbot'); ?></option>
                </select>
                <input type="text" name="haman_field_map_<?php echo esc_attr($field); ?>_key" value="<?php echo esc_attr($conf['key'] ?? ''); ?>" class="regular-text" placeholder="_is_genuine / pa_brand" dir="ltr" aria-label="<?php echo esc_attr(sprintf(__('Key for %s', 'haman-ai-chatbot'), $authenticity_field_labels[$field])); ?>">
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>

<div class="hm-tab-panel haman-panel" data-tab-panel="appearance">
    <div class="notice notice-info inline haman-managed">
        <p><strong><?php esc_html_e('These settings are managed in your Haman panel.', 'haman-ai-chatbot'); ?></strong>
        <?php esc_html_e('They are shown here read-only and cannot be changed on this page — they are kept on Haman so they stay the same on every site the chatbot runs on.', 'haman-ai-chatbot'); ?></p>
        <p><a href="<?php echo esc_url($hm_widget_url); ?>" target="_blank" rel="noopener" class="button button-primary"><?php esc_html_e('Edit appearance and texts in the Haman panel', 'haman-ai-chatbot'); ?><span class="screen-reader-text"> <?php esc_html_e('(opens in a new tab)', 'haman-ai-chatbot'); ?></span></a></p>
    </div>
    <div id="hm-widget-settings-display" class="haman-readonly" aria-live="polite"><p class="description"><?php esc_html_e('Loading…', 'haman-ai-chatbot'); ?></p></div>
</div>

<div class="hm-tab-panel haman-panel" data-tab-panel="advanced">
    <h2 class="haman-panel__heading"><?php esc_html_e('Message limits', 'haman-ai-chatbot'); ?></h2>
    <p class="description"><?php esc_html_e('These apply on this site only, per visitor IP address.', 'haman-ai-chatbot'); ?></p>
    <table class="form-table" role="presentation">
    <tr>
        <th scope="row"><label for="hm-rate-max"><?php esc_html_e('Messages per visitor', 'haman-ai-chatbot'); ?></label></th>
        <td><input type="number" min="1" id="hm-rate-max" name="haman_rate_limit_max_messages" value="<?php echo esc_attr(get_option('haman_rate_limit_max_messages', '50')); ?>" class="small-text"> <?php esc_html_e('messages', 'haman-ai-chatbot'); ?></td>
    </tr>
    <tr>
        <th scope="row"><label for="hm-rate-block"><?php esc_html_e('Block for', 'haman-ai-chatbot'); ?></label></th>
        <td><input type="number" min="1" id="hm-rate-block" name="haman_rate_limit_block_minutes" value="<?php echo esc_attr(get_option('haman_rate_limit_block_minutes', '15')); ?>" class="small-text"> <?php esc_html_e('minutes', 'haman-ai-chatbot'); ?></td>
    </tr>
    <tr>
        <th scope="row"><?php esc_html_e('On uninstall', 'haman-ai-chatbot'); ?></th>
        <td>
            <label><input type="checkbox" name="haman_delete_data_on_uninstall" value="1" <?php checked(get_option('haman_delete_data_on_uninstall', '0'), '1'); ?>> <?php esc_html_e('Delete this plugin’s settings from WordPress', 'haman-ai-chatbot'); ?></label>
            <p class="description"><?php esc_html_e('Only WordPress-side settings are removed; nothing on the Haman server is affected.', 'haman-ai-chatbot'); ?></p>
        </td>
    </tr>
    </table>
</div>

<?php
// Outside every .hm-tab-panel on purpose: initTabs() hides every panel but
// the active one, so a button inside a panel was only visible on that one
// tab. The form saves fields from several tabs; Save must be on all of them.
submit_button(__('Save settings', 'haman-ai-chatbot'));
?>
</form>

<?php /* No-JavaScript fallback for the manual sync (the button above runs it step by step over AJAX). */ ?>
<noscript>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="haman_manual_sync">
        <?php wp_nonce_field('haman_manual_sync'); ?>
        <?php submit_button(__('Run full sync now', 'haman-ai-chatbot'), 'secondary'); ?>
    </form>
</noscript>

<div class="hm-tab-panel haman-panel haman-panel--after" data-tab-panel="sync">
    <h2 class="haman-panel__heading"><?php esc_html_e('Clear and reindex', 'haman-ai-chatbot'); ?></h2>
    <p class="description haman-measure"><?php esc_html_e('Deletes everything currently synced and rebuilds it with the settings above — for when you changed what to sync and old content is still in the index. FAQs you entered by hand are not affected.', 'haman-ai-chatbot'); ?></p>
    <p><button type="button" id="hm-clear-reindex" class="button"><?php esc_html_e('Clear and reindex', 'haman-ai-chatbot'); ?></button>
    <span class="haman-inline-note" id="hm-clear-reindex-result" role="status" aria-live="polite"></span></p>

    <?php $r = get_transient('haman_sync_results'); if ($r && empty($r['error'])): ?>
        <h3><?php esc_html_e('Last sync results', 'haman-ai-chatbot'); ?></h3>
        <div class="haman-table-wrap">
        <table class="widefat striped haman-results" id="hm-sync-results">
        <thead><tr>
            <th scope="col"><?php esc_html_e('Type', 'haman-ai-chatbot'); ?></th>
            <th scope="col" class="num"><?php esc_html_e('New', 'haman-ai-chatbot'); ?></th>
            <th scope="col" class="num"><?php esc_html_e('Updated', 'haman-ai-chatbot'); ?></th>
            <th scope="col" class="num"><?php esc_html_e('Unchanged', 'haman-ai-chatbot'); ?></th>
            <th scope="col" class="num"><?php esc_html_e('Deleted', 'haman-ai-chatbot'); ?></th>
            <th scope="col" class="num"><?php esc_html_e('Failed', 'haman-ai-chatbot'); ?></th>
            <th scope="col" class="num"><?php esc_html_e('Over limit', 'haman-ai-chatbot'); ?></th>
        </tr></thead>
        <tbody>
        <?php
        $labels = ['products' => __('Products', 'haman-ai-chatbot'), 'pages' => __('Pages and site content', 'haman-ai-chatbot'), 'faqs' => __('FAQs', 'haman-ai-chatbot')];
        foreach ($labels as $key => $label):
            if (empty($r[$key]) || !is_array($r[$key])) continue;
            $row = $r[$key];
        ?>
            <tr>
                <td><?php echo esc_html($label); ?></td>
                <?php foreach (['new', 'updated', 'skipped', 'deleted', 'failed', 'over_limit'] as $col): ?>
                    <td class="num"><?php echo esc_html(number_format_i18n((int) ($row[$col] ?? 0))); ?></td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
        </table>
        </div>
        <?php $hm_over = array_sum(array_map(fn ($row) => is_array($row) ? (int) ($row['over_limit'] ?? 0) : 0, $r)); if ($hm_over > 0): ?>
            <div class="notice notice-warning inline"><p><?php echo esc_html(sprintf(
                /* translators: %s: number of documents */
                __('%s documents were not indexed because your plan’s document limit was reached. The bot does not answer from them; upgrading, or turning off types you don’t need, lets the next sync add them.', 'haman-ai-chatbot'),
                number_format_i18n($hm_over)
            )); ?></p></div>
        <?php endif; ?>
    <?php elseif ($r && !empty($r['error'])): ?>
        <div class="notice notice-error inline"><p><?php echo esc_html($r['error']); ?></p></div>
    <?php endif; ?>
</div>

<div class="hm-tab-panel haman-panel haman-panel--after" data-tab-panel="appearance">
    <h2 class="haman-panel__heading"><?php esc_html_e('Move old local settings to Haman (once)', 'haman-ai-chatbot'); ?></h2>
    <p class="description haman-measure"><?php esc_html_e('Only for sites that set these texts on this WordPress page with an older version of the plugin: sends them to Haman once, so they appear and can be edited in your Haman panel.', 'haman-ai-chatbot'); ?></p>
    <p><button type="button" id="hm-migrate-to-server" class="button"><?php esc_html_e('Move to Haman', 'haman-ai-chatbot'); ?></button>
    <span class="haman-inline-note" id="hm-migrate-result" role="status" aria-live="polite"></span></p>
</div>

<div class="hm-tab-panel haman-panel haman-panel--after" data-tab-panel="advanced">
    <h2 class="haman-panel__heading"><?php esc_html_e('Cache', 'haman-ai-chatbot'); ?></h2>
    <p><button type="button" id="hm-clear-cache" class="button"><?php esc_html_e('Clear cache', 'haman-ai-chatbot'); ?></button>
    <span class="haman-inline-note" id="hm-clear-cache-result" role="status" aria-live="polite"></span></p>

    <h2 class="haman-panel__heading"><?php esc_html_e('Plugin version', 'haman-ai-chatbot'); ?></h2>
    <p><?php esc_html_e('Installed version:', 'haman-ai-chatbot'); ?> <strong dir="ltr"><?php echo esc_html(HAMAN_VERSION); ?></strong></p>
    <p id="hm-version-check-result" class="haman-inline-note" role="status" aria-live="polite"></p>

    <h2 class="haman-panel__heading"><?php esc_html_e('Recent API log', 'haman-ai-chatbot'); ?></h2>
    <?php if (empty($log)): ?>
        <p class="description"><?php esc_html_e('Nothing logged yet.', 'haman-ai-chatbot'); ?></p>
    <?php else: ?>
        <div class="haman-log" dir="ltr" tabindex="0" aria-label="<?php esc_attr_e('Recent API log', 'haman-ai-chatbot'); ?>">
        <?php foreach ($log as $entry): ?>
            <div class="haman-log__entry">
                <span class="haman-log__time"><?php echo esc_html(date_i18n('Y-m-d H:i:s', $entry['time'] ?? time())); ?></span>
                <span class="haman-log__message"><?php echo esc_html($entry['message'] ?? ''); ?></span>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

</div>
