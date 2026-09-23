<?php if (!defined('ABSPATH')) exit;
$log = get_option(Haman_Admin::LOG_OPTION, []);
if (!is_array($log)) $log = [];

$field_mapping = get_option('haman_field_mapping', []);
if (!is_array($field_mapping)) $field_mapping = [];
$authenticity_field_labels = [
    'authenticity_status'  => 'وضعیت اصالت / Authenticity status',
    'brand'                => 'نام برند / Brand name',
    'official_distributor' => 'نمایندگی رسمی / Official distributor',
    'warranty_period'      => 'مدت گارانتی / Warranty period',
    'country_of_origin'    => 'کشور مبدأ / Country of origin',
];
?>
<div class="wrap hm-settings-wrap">
<h1>🤖 Haman AI Chatbot — شرکت هامان فناوران پیشرو</h1>
<p id="hm-version-check-result" class="hm-version-check"></p>
<?php if(isset($_GET['saved'])): ?><div class="notice notice-success"><p>✅ تنظیمات ذخیره شد. / Settings saved.</p></div><?php endif; ?>
<?php if(isset($_GET['synced'])): ?><div class="notice notice-success"><p>✅ همگام‌سازی انجام شد. / Sync completed.</p></div><?php endif; ?>

<h2 class="nav-tab-wrapper">
    <a href="#" class="nav-tab hm-tab-link" data-tab="connection">اتصال <span class="hm-tab-en">Connection</span></a>
    <a href="#" class="nav-tab hm-tab-link" data-tab="sync">همگام‌سازی <span class="hm-tab-en">Sync</span></a>
    <a href="#" class="nav-tab hm-tab-link" data-tab="appearance">ظاهر و متن‌ها <span class="hm-tab-en">Appearance &amp; Texts</span></a>
    <a href="#" class="nav-tab hm-tab-link" data-tab="advanced">پیشرفته <span class="hm-tab-en">Advanced</span></a>
</h2>

<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
<input type="hidden" name="action" value="haman_save_settings">
<input type="hidden" name="haman_active_tab" id="haman_active_tab_input" value="connection">
<?php wp_nonce_field('haman_save_settings'); ?>

<div class="hm-tab-panel" data-tab-panel="connection">
    <p class="description hm-tab-desc">
        کلید API و شناسه چت‌بات را از داشبورد هامان دریافت و اینجا وارد کنید، سپس اتصال را تست کنید.<br>
        <em>Enter the API key and chatbot ID from your Haman dashboard, then test the connection.</em>
    </p>
    <table class="form-table">
    <tr><th>API Key</th><td>
        <input type="password" name="haman_api_key" value="<?php echo esc_attr(get_option('haman_api_key','')); ?>" class="regular-text">
        <p class="description">از داشبورد هامان (با hfp_ شروع می‌شود) / From Haman dashboard (starts with hfp_)</p>
    </td></tr>
    <tr><th>Chatbot ID</th><td>
        <input type="text" name="haman_chatbot_id" value="<?php echo esc_attr(get_option('haman_chatbot_id','')); ?>" class="regular-text" placeholder="UUID">
    </td></tr>
    <tr><th>API URL</th><td>
        <input type="url" name="haman_api_url" value="<?php echo esc_attr(get_option('haman_api_url',HAMAN_API_BASE)); ?>" class="regular-text">
    </td></tr>
    <tr><th>فعال‌سازی ویجت / Enable Widget</th><td>
        <label><input type="checkbox" name="haman_enabled" value="1" <?php checked(get_option('haman_enabled','1'),'1'); ?>> نمایش چت‌بات در سایت / Show chatbot on frontend</label>
    </td></tr>
    <tr><th>تست اتصال / Test Connection</th><td>
        <button type="button" id="hm-test-connection" class="button">تست اتصال / Test Connection</button>
        <span id="hm-test-connection-result"></span>
        <p class="description">با استفاده از کلید و آدرسی که در حال حاضر ذخیره شده — اگر همین الان تغییرشان دادید، اول تنظیمات را ذخیره کنید. / Uses the currently *saved* key/URL — save first if you just changed them. یک تست موفق نام واقعی چت‌بات متصل‌شده را نشان می‌دهد. / A successful test shows the real name of the connected chatbot.</p>
    </td></tr>
    <tr><th>Webhook Secret</th><td>
        <input type="password" id="hm-webhook-secret-field" value="••••••••••••" class="regular-text" readonly>
        <button type="button" id="hm-webhook-secret-show" class="button">نمایش / Show</button>
        <button type="button" id="hm-webhook-secret-regenerate" class="button">تولید مجدد / Regenerate</button>
        <span id="hm-webhook-secret-result"></span>
        <p class="description">این مقدار روی سرور نگهداری می‌شود، نه محلی — همیشه واقعی است. / This is stored on the server, not locally — always the real, current value.</p>
    </td></tr>
    </table>
</div>

<div class="hm-tab-panel" data-tab-panel="sync">
    <p class="description hm-tab-desc">
        مشخص کنید چه محتوایی همگام‌سازی شود، و همگام‌سازی را به‌صورت دستی اجرا کنید.<br>
        <em>Choose what content gets synced, and trigger a manual sync.</em>
    </p>
    <table class="form-table">
    <tr><th>چه چیزی همگام‌سازی شود / What to sync</th><td>
        <label><input type="checkbox" name="haman_sync_products" value="1" <?php checked(get_option('haman_sync_products','1'),'1'); ?>> محصولات (WooCommerce) / Products</label><br>
        <label><input type="checkbox" name="haman_sync_pages" value="1" <?php checked(get_option('haman_sync_pages','1'),'1'); ?>> صفحات و نوشته‌ها / Pages &amp; posts</label><br>
        <label><input type="checkbox" name="haman_sync_pdfs" value="1" <?php checked(get_option('haman_sync_pdfs','1'),'1'); ?>> فایل‌های PDF ضمیمه‌ی محصول (دیتاشیت) / PDF attachments (datasheets)</label>
    </td></tr>
    </table>

    <h3>نگاشت فیلدهای اصالت کالا / Authenticity Field Mapping</h3>
    <p class="description">
        «این اصل است؟» پرتکرارترین سوال مشتریان در هر دو مصاحبه بود. بات فقط همین فیلدها را — دقیقاً همان‌طور که فروشنده ثبت کرده — نقل می‌کند؛ هرگز خودش قضاوت نمی‌کند. اگر فروشگاه شما یک فیلد سفارشی یا ویژگی برای هرکدام دارد، اینجا مشخص کنید کدام کلید به کدام معنا نگاشت شود.<br>
        <em>"Is this genuine?" was the most common customer question in both interviews this feature was built from. The bot only ever repeats exactly what the seller recorded in these fields — it never judges on its own. If your store has a custom field or attribute for each, map its key here.</em>
    </p>
    <table class="form-table hm-field-mapping-table">
        <?php foreach ( Haman_Product_Sync::AUTHENTICITY_FIELDS as $field ): $conf = $field_mapping[ $field ] ?? []; ?>
        <tr>
            <th><?php echo esc_html( $authenticity_field_labels[ $field ] ); ?></th>
            <td>
                <select name="haman_field_map_<?php echo esc_attr( $field ); ?>_type">
                    <option value="" <?php selected( empty( $conf['type'] ) ); ?>>— هیچ‌کدام / None —</option>
                    <option value="meta" <?php selected( $conf['type'] ?? '', 'meta' ); ?>>فیلد سفارشی (meta key) / Custom field</option>
                    <option value="attribute" <?php selected( $conf['type'] ?? '', 'attribute' ); ?>>ویژگی محصول / Product attribute</option>
                </select>
                <input type="text" name="haman_field_map_<?php echo esc_attr( $field ); ?>_key"
                       value="<?php echo esc_attr( $conf['key'] ?? '' ); ?>" class="regular-text"
                       placeholder="مثلاً / e.g. _is_genuine یا pa_brand">
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>

<div class="hm-tab-panel" data-tab-panel="advanced">
    <p class="description hm-tab-desc">
        محدودیت پیام، پاک کردن کش، لاگ، و حذف داده هنگام حذف افزونه.<br>
        <em>Rate limiting, cache clearing, logs, and uninstall data-deletion setting.</em>
    </p>

    <h3>محدودیت‌های ارسال پیام / Rate Limits</h3>
    <p class="description">این محدودیت‌ها محلی‌اند (روی همین سایت) و بر روی IP کاربر ثبت می‌شوند. / These are local to this site and apply per visitor IP.</p>
    <table class="form-table">
    <tr><th>چند پیام برای هر IP</th><td>
        <input type="number" min="1" name="haman_rate_limit_max_messages" value="<?php echo esc_attr(get_option('haman_rate_limit_max_messages','50')); ?>" class="small-text"> پیام
    </td></tr>
    <tr><th>مدت زمان بلاک برای هر IP</th><td>
        <input type="number" min="1" name="haman_rate_limit_block_minutes" value="<?php echo esc_attr(get_option('haman_rate_limit_block_minutes','15')); ?>" class="small-text"> دقیقه
    </td></tr>
    <tr><th>حذف اطلاعات هنگام حذف افزونه / Delete Data on Uninstall</th><td>
        <label><input type="checkbox" name="haman_delete_data_on_uninstall" value="1" <?php checked(get_option('haman_delete_data_on_uninstall','0'),'1'); ?>>
            همه‌ی تنظیمات محلی این افزونه را هنگام حذف پاک کن / Delete all of this plugin's local settings when it's uninstalled
        </label>
        <p class="description">فقط تنظیمات وردپرس را پاک می‌کند؛ داده‌های سمت سرور هامان تحت تأثیر قرار نمی‌گیرند. / Only clears WordPress-side settings — data on the Haman server is unaffected.</p>
    </td></tr>
    </table>

    <?php submit_button('ذخیره تنظیمات / Save Settings'); ?>
</div>
</form>

<div class="hm-tab-panel" data-tab-panel="sync">
    <hr>
    <h3>وضعیت همگام‌سازی / Sync Status</h3>
    <p>آخرین همگام‌سازی کامل / Last full sync: <strong><?php echo get_option('haman_last_full_sync') ? esc_html(date_i18n('Y-m-d H:i',get_option('haman_last_full_sync'))) : 'هرگز / Never'; ?></strong></p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <input type="hidden" name="action" value="haman_manual_sync">
    <?php wp_nonce_field('haman_manual_sync'); ?>
    <?php submit_button('اجرای همگام‌سازی کامل / Run Full Sync Now','secondary'); ?>
    </form>
    <?php $r = get_transient('haman_sync_results'); if($r): ?>
        <table class="widefat" style="max-width:700px">
        <thead><tr><th>نوع / Type</th><th>جدید / New</th><th>به‌روزشده / Updated</th><th>بدون تغییر / Skipped</th><th>حذف‌شده / Deleted</th><th>ناموفق / Failed</th></tr></thead>
        <tbody>
        <?php
        $labels = ['products' => 'محصولات / Products', 'pages' => 'صفحات / Pages', 'faqs' => 'سوالات متداول / FAQs'];
        foreach ($labels as $key => $label):
            if (empty($r[$key])) continue;
            $row = $r[$key];
        ?>
            <tr>
                <td><?php echo esc_html($label); ?></td>
                <td><?php echo esc_html($row['new'] ?? 0); ?></td>
                <td><?php echo esc_html($row['updated'] ?? 0); ?></td>
                <td><?php echo esc_html($row['skipped'] ?? 0); ?></td>
                <td><?php echo esc_html($row['deleted'] ?? 0); ?></td>
                <td><?php echo esc_html($row['failed'] ?? 0); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        </table>
        <?php if (!empty($r['error'])): ?>
            <div class="notice notice-error inline"><p><?php echo esc_html($r['error']); ?></p></div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<div class="hm-tab-panel" data-tab-panel="appearance">
    <p class="description hm-tab-desc">
        این فیلدها فقط نمایشی هستند و از سرور خوانده می‌شوند — برای تغییر، از پنل مشتری هامان‌تک استفاده کنید. این تنظیمات آنجا نگهداری می‌شوند تا در همه‌ی سایت‌های شما یکسان بمانند.<br>
        <em>These fields are read-only, fetched from the server — to edit them, use the HamanTech customer portal. They're kept there so they stay consistent across all your sites.</em>
    </p>
    <?php
        // rtrim($url, '/api/v1') would be wrong here — rtrim's second
        // argument is a character mask (strips any of /,a,p,i,v,1 from the
        // end), not a literal suffix — hence the explicit regex.
        $portal_base = preg_replace('#/api/v1/?$#', '', get_option('haman_api_url', HAMAN_API_BASE));
        // Not just "/portal": that lands on the bare dashboard, which has
        // no path from there to this chatbot's own appearance/text fields
        // — a merchant who clicked this button had nowhere to actually
        // make the edit they came here for. /portal/widget-settings is the
        // real page (WidgetSettings.php); it takes the chatbot as a plain
        // ?chatbot= query parameter, not a route segment — the same way
        // MyChatbots.php's own "row action" already links to it, so this
        // mirrors a link that is already known to work rather than a
        // freshly guessed URL shape.
        $chatbot_id = get_option('haman_chatbot_id', '');
        $portal_url = $portal_base . '/portal/widget-settings?chatbot=' . rawurlencode($chatbot_id);
    ?>
    <p>
        <a href="<?php echo esc_url($portal_url); ?>" target="_blank" rel="noopener" class="button button-primary">
            ✏️ ویرایش در پنل هامان‌تک / Edit in the HamanTech portal
        </a>
    </p>
    <div id="hm-widget-settings-display">در حال بارگذاری... / Loading...</div>

    <hr>
    <h3>انتقال تنظیمات محلی به سرور (یک‌بار) / Migrate Local Settings to Server (once)</h3>
    <p class="description">
        اگر قبلاً این مقادیر را در همین صفحه‌ی وردپرس تنظیم کرده بودید (نسخه‌های قدیمی‌تر افزونه)، با این دکمه یک‌بار آن‌ها را به سرور بفرستید تا در پنل هامان‌تک هم قابل مشاهده و ویرایش باشند.<br>
        <em>If you'd previously set these values on this WordPress page (older plugin versions), use this to push them to the server once so they're visible and editable in the HamanTech portal too.</em>
    </p>
    <button type="button" id="hm-migrate-to-server" class="button">انتقال به سرور / Migrate to Server</button>
    <span id="hm-migrate-result"></span>
</div>

<div class="hm-tab-panel" data-tab-panel="advanced">
    <hr>
    <h3>پاک کردن کش / Clear Cache</h3>
    <button type="button" id="hm-clear-cache" class="button">پاک کردن کش / Clear Cache</button>
    <span id="hm-clear-cache-result"></span>

    <hr>
    <h3>نسخه‌ی افزونه / Plugin Version</h3>
    <p>نسخه‌ی فعلی / Current version: <strong><?php echo esc_html(HAMAN_VERSION); ?></strong></p>

    <hr>
    <h3>لاگ اخیر ارتباط با API / Recent API Log</h3>
    <?php if (empty($log)): ?>
        <p class="description">هنوز لاگی ثبت نشده / No log entries yet.</p>
    <?php else: ?>
        <div class="hm-log-box">
        <?php foreach ($log as $entry): ?>
            <div class="hm-log-entry">
                <span class="hm-log-time"><?php echo esc_html(date_i18n('Y-m-d H:i:s', $entry['time'] ?? time())); ?></span>
                <span class="hm-log-message"><?php echo esc_html($entry['message'] ?? ''); ?></span>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

</div>
