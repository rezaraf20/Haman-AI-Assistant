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
        <label><input type="checkbox" name="haman_sync_products" value="1" <?php checked(get_option('haman_sync_products','1'),'1'); ?>> محصولات (WooCommerce) / Products</label>
        <?php
        // Every public post type this site really has — read from WordPress
        // (get_post_types), not a hand-written list — with its own label and
        // published count. See Haman_Sync_Settings.
        $hm_types   = Haman_Sync_Settings::available_post_types();
        $hm_enabled = Haman_Page_Sync::enabled_post_types();
        $hm_fields  = Haman_Sync_Settings::custom_fields();
        ?>
        <table class="widefat striped hm-post-types" style="max-width:700px;margin-top:12px">
            <thead><tr><th style="width:40px"></th><th>نوع محتوا / Content type</th><th>شناسه / Slug</th><th>منتشرشده / Published</th></tr></thead>
            <tbody>
            <?php foreach ($hm_types as $slug => $info): ?>
                <tr>
                    <td>
                        <?php if ($info['separate']): ?>
                            <input type="checkbox" disabled title="جداگانه مدیریت می‌شود / Managed separately">
                        <?php else: ?>
                            <input type="checkbox" class="hm-post-type-toggle" id="hm-pt-<?php echo esc_attr($slug); ?>" name="haman_sync_post_types[]" value="<?php echo esc_attr($slug); ?>" data-type="<?php echo esc_attr($slug); ?>" <?php checked(in_array($slug, $hm_enabled, true)); ?>>
                        <?php endif; ?>
                    </td>
                    <td>
                        <label for="hm-pt-<?php echo esc_attr($slug); ?>"><strong><?php echo esc_html($info['label']); ?></strong></label>
                        <?php if ($info['separate']): ?>
                            <br><span class="description">جداگانه با گزینه‌ی «محصولات» بالا همگام می‌شود / Synced separately, by the Products option above</span>
                        <?php elseif ($slug === 'post'): ?>
                            <br><span class="description">پیش‌فرض خاموش — نوشته‌ی وبلاگ به‌ندرت جواب سوال مشتری است / Off by default — a blog post is rarely what a customer is asking about</span>
                        <?php endif; ?>
                    </td>
                    <td><code><?php echo esc_html($slug); ?></code></td>
                    <td><?php echo esc_html(number_format_i18n($info['count'])); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <br>
        <label><input type="checkbox" name="haman_sync_pdfs" value="1" <?php checked(get_option('haman_sync_pdfs','1'),'1'); ?>> فایل‌های PDF ضمیمه‌ی محصول (دیتاشیت) / PDF attachments (datasheets)</label>
    </td></tr>
    <tr><th>فیلدهای سفارشی / Custom fields</th><td>
        <p class="description" style="max-width:700px">
            هیچ فیلد سفارشی‌ای بدون انتخاب شما فرستاده نمی‌شود. برای هر نوع محتوای انتخاب‌شده، فیلدهایی که واقعاً در این سایت وجود دارند با یک مقدار نمونه نشان داده شده‌اند؛ هر کدام را که می‌خواهید بات بداند علامت بزنید. فیلدهای خصوصی (که با _ شروع می‌شوند) هرگز نمایش داده یا فرستاده نمی‌شوند.<br>
            <em>No custom field is sent unless you pick it. For each selected content type, the fields that actually exist on this site are listed with a real sample value; tick the ones the bot should know. Private fields (starting with _) are never shown or sent.</em>
        </p>
        <?php foreach ($hm_types as $slug => $info): if ($info['separate']) continue; ?>
            <?php $hm_keys = Haman_Sync_Settings::discover_meta_keys($slug); $hm_picked = $hm_fields[$slug] ?? []; ?>
            <fieldset class="hm-custom-fields" data-type="<?php echo esc_attr($slug); ?>" style="max-width:700px;margin:12px 0;<?php echo in_array($slug, $hm_enabled, true) ? '' : 'display:none'; ?>">
                <legend><strong><?php echo esc_html($info['label']); ?></strong></legend>
                <?php if (empty($hm_keys)): ?>
                    <p class="description">این نوع فیلد سفارشی عمومی ندارد. / This type has no public custom fields.</p>
                <?php else: ?>
                    <table class="widefat striped">
                        <thead><tr><th style="width:40px">ارسال / Send</th><th>کلید / Key</th><th>نمونه‌ی مقدار / Sample value</th><th>موارد / Items</th></tr></thead>
                        <tbody>
                        <?php foreach ($hm_keys as $k): ?>
                            <tr>
                                <td>
                                    <?php if ($k['sendable']): ?>
                                        <input type="checkbox" class="hm-field-toggle" name="haman_sync_custom_fields[<?php echo esc_attr($slug); ?>][]" value="<?php echo esc_attr($k['key']); ?>" <?php checked(in_array($k['key'], $hm_picked, true)); ?>>
                                    <?php else: ?>
                                        <input type="checkbox" disabled title="مقدار متنی نیست / Not plain text">
                                    <?php endif; ?>
                                </td>
                                <td><code><?php echo esc_html($k['key']); ?></code></td>
                                <td><?php echo $k['sendable'] ? esc_html(mb_strimwidth($k['sample'], 0, 80, '…')) : '<em>داده‌ی ساختاریافته — قابل ارسال نیست / structured data — not sendable</em>'; ?></td>
                                <td><?php echo esc_html(number_format_i18n($k['items'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="hm-field-summary description" data-none="هیچ فیلدی فرستاده نمی‌شود / No fields will be sent" data-prefix="این فیلدها فرستاده می‌شوند / These fields will be sent: "></p>
                <?php endif; ?>
            </fieldset>
        <?php endforeach; ?>
    </td></tr>
    <tr><th>استثنا کردن محتوای خاص / Exclude specific content</th><td>
        <label>شناسه‌ی دسته‌های مستثنا (نوشته‌ها) / Excluded category IDs (posts):<br>
            <input type="text" name="haman_sync_excluded_category_ids" value="<?php echo esc_attr(get_option('haman_sync_excluded_category_ids','')); ?>" class="regular-text" placeholder="مثلاً / e.g. 4, 9, 12"></label>
        <p class="description">نوشته‌های این دسته‌ها هیچ‌وقت همگام نمی‌شوند، حتی اگر «نوشته‌های وبلاگ» روشن باشد. / Posts in these categories are never synced, even when Blog posts is on.</p>
        <label>شناسه‌ی برگه‌های مستثنا / Excluded page IDs:<br>
            <input type="text" name="haman_sync_excluded_page_ids" value="<?php echo esc_attr(get_option('haman_sync_excluded_page_ids','')); ?>" class="regular-text" placeholder="مثلاً / e.g. 2, 15"></label>
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
</div>

<?php
// Deliberately OUTSIDE every .hm-tab-panel div, even though it's visually
// last in the form: initTabs() (haman-admin.js) toggles each panel's
// display by matching data-tab-panel to the active tab, so anything
// rendered *inside* one panel is invisible on every other tab. This one
// button saves fields from three different panels above (connection,
// sync, advanced) -- it was previously nested inside the advanced panel's
// closing </div>, which meant Save was only ever visible while the
// Advanced tab happened to be active, and never on Connection or Sync,
// despite both having real fields submitted by this exact form.
submit_button('ذخیره تنظیمات / Save Settings');
?>
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

    <button type="button" id="hm-clear-reindex" class="button">پاک کردن و ایندکس دوباره / Clear and reindex</button>
    <p class="description">
        هرچه الان همگام شده (محصولات، صفحات، نوشته‌های وبلاگ) را پاک می‌کند و با تنظیمات بالا از نو می‌سازد — برای وقتی که تنظیمات «چه چیزی همگام‌سازی شود» را تغییر داده‌اید و محتوای قدیمی هنوز در ایندکس مانده است. سوالات متداولی که دستی وارد کرده‌اید دست‌نخورده می‌مانند.<br>
        <em>Deletes everything currently synced (products, pages, blog posts) and rebuilds it using the settings above — for when you've changed what to sync and old content is still stuck in the index. Manually-entered FAQs are not affected.</em>
    </p>
    <span id="hm-clear-reindex-result"></span>
    <?php $r = get_transient('haman_sync_results'); if($r): ?>
        <table class="widefat" style="max-width:700px">
        <thead><tr><th>نوع / Type</th><th>جدید / New</th><th>به‌روزشده / Updated</th><th>بدون تغییر / Skipped</th><th>حذف‌شده / Deleted</th><th>ناموفق / Failed</th><th>بیش از سقف / Over limit</th></tr></thead>
        <tbody>
        <?php
        $labels = ['products' => 'محصولات / Products', 'pages' => 'صفحات و محتوای سایت / Pages & site content', 'faqs' => 'سوالات متداول / FAQs'];
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
                <td><?php echo esc_html($row['over_limit'] ?? 0); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        </table>
        <?php foreach ($labels as $key => $label):
            if (empty($r[$key]['note'])) continue;
        ?>
            <div class="notice notice-info inline"><p><?php echo esc_html($r[$key]['note']); ?></p></div>
        <?php endforeach; ?>
        <?php $hm_over = array_sum(array_map(fn($row) => is_array($row) ? (int) ($row['over_limit'] ?? 0) : 0, $r)); if ($hm_over > 0): ?>
            <div class="notice notice-warning inline"><p>
                <?php echo esc_html(sprintf('%d سند ایندکس نشد چون به سقف اسناد پلن رسیده‌اید. بات از این‌ها جواب نمی‌دهد؛ با ارتقای پلن یا خاموش کردن نوع‌هایی که لازم ندارید، در همگام‌سازی بعدی اضافه می‌شوند.', $hm_over)); ?><br>
                <em><?php echo esc_html(sprintf('%d documents were not indexed because your plan\'s document limit was reached. The bot does not answer from them; upgrading, or turning off types you don\'t need, lets the next sync add them.', $hm_over)); ?></em>
            </p></div>
        <?php endif; ?>
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
        // make the edit they came here for. /portal/widget-settings/{id}
        // is the real page (WidgetSettings.php) — the chatbot id is a
        // route SEGMENT, not a ?chatbot= query parameter: an earlier
        // version of this fix used a query string, matching what
        // MyChatbots.php's own row action appeared to do, and both turned
        // out to 500 for the same reason (WidgetSettings had no {chatbot}
        // route parameter declared at all, so nothing --the row action
        // included-- could ever have reached it with a query string).
        // Fixed at the source in WidgetSettings.php; this now matches its
        // real, working URL shape.
        $chatbot_id = get_option('haman_chatbot_id', '');
        $portal_url = $portal_base . '/portal/widget-settings/' . rawurlencode($chatbot_id);
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
