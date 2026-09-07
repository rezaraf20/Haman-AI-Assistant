<?php if (!defined('ABSPATH')) exit;
$qq = get_option('hamman_quick_questions', []);
if (!is_array($qq)) $qq = [];
$log = get_option(Hamman_Admin::LOG_OPTION, []);
if (!is_array($log)) $log = [];
?>
<div class="wrap hm-settings-wrap">
<h1>🤖 Hamman AI Chatbot — شرکت هامان فناوران پیشرو</h1>
<?php if(isset($_GET['saved'])): ?><div class="notice notice-success"><p>✅ تنظیمات ذخیره شد. / Settings saved.</p></div><?php endif; ?>
<?php if(isset($_GET['sync_warning'])): ?><div class="notice notice-warning"><p>⚠️ تنظیمات محلی ذخیره شد اما ارسال به سرور هامان با خطا مواجه شد: <?php echo esc_html(get_transient('hamman_settings_push_error')); ?></p></div><?php endif; ?>
<?php if(isset($_GET['synced'])): ?><div class="notice notice-success"><p>✅ همگام‌سازی انجام شد. / Sync completed.</p></div><?php endif; ?>

<h2 class="nav-tab-wrapper">
    <a href="#" class="nav-tab hm-tab-link" data-tab="connection">اتصال <span class="hm-tab-en">Connection</span></a>
    <a href="#" class="nav-tab hm-tab-link" data-tab="appearance">ظاهر <span class="hm-tab-en">Appearance</span></a>
    <a href="#" class="nav-tab hm-tab-link" data-tab="texts">متن‌ها <span class="hm-tab-en">Texts</span></a>
    <a href="#" class="nav-tab hm-tab-link" data-tab="sync">همگام‌سازی <span class="hm-tab-en">Sync</span></a>
    <a href="#" class="nav-tab hm-tab-link" data-tab="advanced">پیشرفته <span class="hm-tab-en">Advanced</span></a>
</h2>

<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
<input type="hidden" name="action" value="hamman_save_settings">
<input type="hidden" name="hamman_active_tab" id="hamman_active_tab_input" value="connection">
<?php wp_nonce_field('hamman_save_settings'); ?>

<div class="hm-tab-panel" data-tab-panel="connection">
    <p class="description hm-tab-desc">
        کلید API و شناسه چت‌بات را از داشبورد هامان دریافت و اینجا وارد کنید، سپس اتصال را تست کنید.<br>
        <em>Enter the API key and chatbot ID from your Hamman dashboard, then test the connection.</em>
    </p>
    <table class="form-table">
    <tr><th>API Key</th><td>
        <input type="password" name="hamman_api_key" value="<?php echo esc_attr(get_option('hamman_api_key','')); ?>" class="regular-text">
        <p class="description">از داشبورد هامان (با hfp_ شروع می‌شود) / From Hamman dashboard (starts with hfp_)</p>
    </td></tr>
    <tr><th>Chatbot ID</th><td>
        <input type="text" name="hamman_chatbot_id" value="<?php echo esc_attr(get_option('hamman_chatbot_id','')); ?>" class="regular-text" placeholder="UUID">
    </td></tr>
    <tr><th>Webhook Secret</th><td>
        <input type="password" name="hamman_webhook_secret" value="<?php echo esc_attr(get_option('hamman_webhook_secret','')); ?>" class="regular-text">
    </td></tr>
    <tr><th>API URL</th><td>
        <input type="url" name="hamman_api_url" value="<?php echo esc_attr(get_option('hamman_api_url',HAMMAN_API_BASE)); ?>" class="regular-text">
    </td></tr>
    <tr><th>فعال‌سازی ویجت / Enable Widget</th><td>
        <label><input type="checkbox" name="hamman_enabled" value="1" <?php checked(get_option('hamman_enabled','1'),'1'); ?>> نمایش چت‌بات در سایت / Show chatbot on frontend</label>
    </td></tr>
    <tr><th>تست اتصال / Test Connection</th><td>
        <button type="button" id="hm-test-connection" class="button" data-label-ok="متصل شد / Connected" data-label-fail="ناموفق / Failed">تست اتصال / Test Connection</button>
        <span id="hm-test-connection-result"></span>
        <p class="description">با استفاده از کلید و آدرسی که در حال حاضر ذخیره شده — اگر همین الان تغییرشان دادید، اول تنظیمات را ذخیره کنید. / Uses the currently *saved* key/URL — save first if you just changed them.</p>
    </td></tr>
    </table>
</div>

<div class="hm-tab-panel" data-tab-panel="appearance">
    <p class="description hm-tab-desc">
        رنگ، موقعیت روی صفحه، و آواتار چت‌بات را شخصی‌سازی کنید.<br>
        <em>Customize the widget's color, on-page position, and avatar.</em>
    </p>
    <table class="form-table">
    <tr><th>رنگ اصلی / Primary Color</th><td>
        <input type="text" name="hamman_primary_color" value="<?php echo esc_attr(get_option('hamman_primary_color','#1B3A6B')); ?>" class="hm-color-field" placeholder="#1B3A6B">
    </td></tr>
    <tr><th>موقعیت / Position</th><td>
        <?php $pos = get_option('hamman_widget_position','bottom-right'); ?>
        <label><input type="radio" name="hamman_widget_position" value="bottom-right" <?php checked($pos,'bottom-right'); ?>> پایین راست / Bottom Right</label><br>
        <label><input type="radio" name="hamman_widget_position" value="bottom-left" <?php checked($pos,'bottom-left'); ?>> پایین چپ / Bottom Left</label>
    </td></tr>
    <tr><th>متن دکمه / Button / AI Name</th><td>
        <input type="text" name="hamman_ai_name" value="<?php echo esc_attr(get_option('hamman_ai_name','AI BOT')); ?>" class="regular-text" placeholder="AI BOT">
        <p class="description">نامی که کنار دکمه و در گفتگو نمایش داده می‌شود / The name shown by the button and in the chat</p>
    </td></tr>
    <tr><th>آواتار / Avatar</th><td>
        <input type="url" name="hamman_avatar_url" value="<?php echo esc_attr(get_option('hamman_avatar_url','')); ?>" class="regular-text" placeholder="https://.../avatar.png">
        <p class="description">آدرس یک تصویر مربعی — اگر خالی بماند، آیکون پیش‌فرض 💬 نمایش داده می‌شود / URL of a square image — leave empty to keep the default 💬 icon</p>
    </td></tr>
    </table>
</div>

<div class="hm-tab-panel" data-tab-panel="texts">
    <p class="description hm-tab-desc">
        پیام خوش‌آمد، سوالات آماده، متن‌های ثبت لید، و پیام خطا را اینجا تنظیم کنید.<br>
        <em>Set the welcome message, quick questions, lead-capture texts, and error message here.</em>
    </p>
    <table class="form-table">
    <tr><th>فعال کردن پاسخ‌دهی خودکار</th><td>
        <label><input type="checkbox" name="hamman_auto_reply_enabled" value="1" <?php checked(get_option('hamman_auto_reply_enabled','1'),'1'); ?>> هوش مصنوعی به‌صورت خودکار به پیام‌های کاربران پاسخ بدهد</label>
    </td></tr>
    <tr><th>عنوان ابتدای گفتگو</th><td>
        <input type="text" name="hamman_chat_title" value="<?php echo esc_attr(get_option('hamman_chat_title','پشتیبانی آنلاین')); ?>" class="regular-text" placeholder="پشتیبانی آنلاین">
    </td></tr>
    <tr><th>پیام خوش‌آمد</th><td>
        <textarea name="hamman_welcome_text" rows="3" class="large-text" placeholder="سلام! چطور می‌توانم کمکتان کنم؟"><?php echo esc_textarea(get_option('hamman_welcome_text','')); ?></textarea>
        <p class="description">متنی که هنگام باز شدن گفتگو، قبل از هر پیامی نمایش داده می‌شود</p>
    </td></tr>
    <tr><th>متن اینپوت ورودی</th><td>
        <input type="text" name="hamman_input_placeholder" value="<?php echo esc_attr(get_option('hamman_input_placeholder','پیام خود را بنویسید...')); ?>" class="regular-text" placeholder="پیام خود را بنویسید...">
    </td></tr>
    <tr><th>دستور العمل و قوانین سیستم (system instruction)</th><td>
        <textarea name="hamman_system_instruction" rows="5" class="large-text" placeholder="مثلاً: لحن دوستانه و مودبانه داشته باش..."><?php echo esc_textarea(get_option('hamman_system_instruction','')); ?></textarea>
        <p class="description">این قوانین همراه با اطلاعات سایت برای هوش مصنوعی ارسال می‌شود</p>
    </td></tr>
    <tr><th>پیام خطا / Error Message</th><td>
        <textarea name="hamman_fallback_response" rows="2" class="large-text" placeholder="پیام هنگامی که هوش مصنوعی نتواند پاسخ دهد"><?php echo esc_textarea(get_option('hamman_fallback_response','')); ?></textarea>
        <p class="description">وقتی چیزی در اطلاعات موجود نیست یا خطای فنی رخ می‌دهد نمایش داده می‌شود — خالی بگذارید تا پیام پیش‌فرض دوزبانه استفاده شود / Shown when nothing relevant is found or a technical error occurs — leave empty to use the built-in bilingual default</p>
    </td></tr>
    </table>

    <hr>
    <h3>ثبت لید / Lead Capture</h3>
    <p class="description">وقتی بات نتواند پاسخ دهد، از کاربر شماره تماس یا ایمیل می‌خواهد. / When the bot can't answer, it asks the visitor for a phone number or email.</p>
    <table class="form-table">
    <tr><th>فعال‌سازی / Enable</th><td>
        <label><input type="checkbox" name="hamman_lead_capture_enabled" value="1" <?php checked(get_option('hamman_lead_capture_enabled','0'),'1'); ?>> فعال / Enabled</label>
    </td></tr>
    <tr><th>متن درخواست شماره</th><td>
        <textarea name="hamman_lead_capture_prompt" rows="2" class="large-text" placeholder="متأسفانه پاسخ دقیقی برای این سوال ندارم. می‌توانید شماره تماس یا ایمیل خود را بگذارید؟"><?php echo esc_textarea(get_option('hamman_lead_capture_prompt','')); ?></textarea>
    </td></tr>
    <tr><th>متن تشکر</th><td>
        <textarea name="hamman_lead_capture_thanks" rows="2" class="large-text" placeholder="ممنون! به‌زودی با شما تماس می‌گیریم."><?php echo esc_textarea(get_option('hamman_lead_capture_thanks','')); ?></textarea>
    </td></tr>
    <tr><th>متن نامعتبر</th><td>
        <textarea name="hamman_lead_capture_invalid" rows="2" class="large-text" placeholder="این یک شماره تماس یا ایمیل معتبر به نظر نمی‌رسد. لطفاً دوباره تلاش کنید."><?php echo esc_textarea(get_option('hamman_lead_capture_invalid','')); ?></textarea>
    </td></tr>
    </table>

    <hr>
    <h3>سوالات آماده / Quick Questions</h3>
    <p class="description">سوالاتی که به کاربر پیشنهاد می‌شود؛ با کلیک روی هرکدام، همان جواب از پیش نوشته‌شده نمایش داده می‌شود (بدون تماس با هوش مصنوعی).</p>
    <table class="widefat" id="hm-qq-table" style="max-width:900px">
    <thead><tr><th style="width:35%">سوال</th><th>جواب</th><th style="width:60px"></th></tr></thead>
    <tbody>
    <?php if (empty($qq)): $qq = [['question'=>'','answer'=>'']]; endif; ?>
    <?php foreach ($qq as $row): ?>
    <tr>
        <td><input type="text" name="hamman_qq_question[]" value="<?php echo esc_attr($row['question'] ?? ''); ?>" class="regular-text" style="width:100%"></td>
        <td><input type="text" name="hamman_qq_answer[]" value="<?php echo esc_attr($row['answer'] ?? ''); ?>" class="regular-text" style="width:100%"></td>
        <td><button type="button" class="button hm-qq-remove">حذف</button></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    </table>
    <p><button type="button" id="hm-qq-add" class="button" data-remove-label="حذف">+ افزودن سوال</button></p>

    <hr>
    <h3>محدودیت‌های ارسال پیام / Rate Limits</h3>
    <p class="description">این محدودیت‌ها بر روی IP کاربر ثبت می‌شود.</p>
    <table class="form-table">
    <tr><th>چند پیام برای هر IP</th><td>
        <input type="number" min="1" name="hamman_rate_limit_max_messages" value="<?php echo esc_attr(get_option('hamman_rate_limit_max_messages','50')); ?>" class="small-text"> پیام
    </td></tr>
    <tr><th>مدت زمان بلاک برای هر IP</th><td>
        <input type="number" min="1" name="hamman_rate_limit_block_minutes" value="<?php echo esc_attr(get_option('hamman_rate_limit_block_minutes','15')); ?>" class="small-text"> دقیقه
        <p class="description">بعد از رسیدن به سقف پیام مجاز، کاربر تا این مدت زمان بلاک می‌شود</p>
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
        <label><input type="checkbox" name="hamman_sync_products" value="1" <?php checked(get_option('hamman_sync_products','1'),'1'); ?>> محصولات (WooCommerce) / Products</label><br>
        <label><input type="checkbox" name="hamman_sync_pages" value="1" <?php checked(get_option('hamman_sync_pages','1'),'1'); ?>> صفحات و نوشته‌ها / Pages &amp; posts</label><br>
        <label><input type="checkbox" name="hamman_sync_pdfs" value="1" <?php checked(get_option('hamman_sync_pdfs','1'),'1'); ?>> فایل‌های PDF ضمیمه‌ی محصول (دیتاشیت) / PDF attachments (datasheets)</label>
    </td></tr>
    </table>
</div>
</form>

<div class="hm-tab-panel" data-tab-panel="sync">
    <hr>
    <h3>وضعیت همگام‌سازی / Sync Status</h3>
    <p>آخرین همگام‌سازی کامل / Last full sync: <strong><?php echo get_option('hamman_last_full_sync') ? esc_html(date_i18n('Y-m-d H:i',get_option('hamman_last_full_sync'))) : 'هرگز / Never'; ?></strong></p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <input type="hidden" name="action" value="hamman_manual_sync">
    <?php wp_nonce_field('hamman_manual_sync'); ?>
    <?php submit_button('اجرای همگام‌سازی کامل / Run Full Sync Now','secondary'); ?>
    </form>
    <?php $r = get_transient('hamman_sync_results'); if($r): ?>
    <div class="notice notice-info"><p><strong>نتیجه آخرین همگام‌سازی / Last sync result:</strong></p><pre><?php echo esc_html(json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)); ?></pre></div>
    <?php endif; ?>
</div>

<div class="hm-tab-panel" data-tab-panel="advanced">
    <p class="description hm-tab-desc">
        لاگ، پاک کردن کش، و تنظیمات حذف اطلاعات هنگام حذف افزونه.<br>
        <em>Debug log, cache clearing, and uninstall data-deletion setting.</em>
    </p>

    <h3>پاک کردن کش / Clear Cache</h3>
    <button type="button" id="hm-clear-cache" class="button" data-label-ok="پاک شد / Cleared" data-label-fail="ناموفق / Failed">پاک کردن کش / Clear Cache</button>
    <span id="hm-clear-cache-result"></span>

    <hr>
    <h3>حذف اطلاعات هنگام حذف افزونه / Delete Data on Uninstall</h3>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <input type="hidden" name="action" value="hamman_save_settings">
    <input type="hidden" name="hamman_active_tab" value="advanced">
    <?php wp_nonce_field('hamman_save_settings'); ?>
    <?php
        // Every field from the other tabs must also be resubmitted here so
        // this standalone save doesn't blank them out — simplest correct
        // option given this checkbox alone needs its own small form outside
        // the main one above (it's rendered after that form already closed).
        foreach ([
            'hamman_api_key','hamman_chatbot_id','hamman_webhook_secret','hamman_api_url','hamman_enabled',
            'hamman_primary_color','hamman_widget_position','hamman_avatar_url',
            'hamman_auto_reply_enabled','hamman_ai_name','hamman_chat_title','hamman_welcome_text',
            'hamman_input_placeholder','hamman_system_instruction','hamman_fallback_response',
            'hamman_rate_limit_max_messages','hamman_rate_limit_block_minutes',
            'hamman_lead_capture_enabled','hamman_lead_capture_prompt','hamman_lead_capture_thanks','hamman_lead_capture_invalid',
            'hamman_sync_products','hamman_sync_pages','hamman_sync_pdfs',
        ] as $opt) {
            $val = get_option($opt, '');
            if ($val === '1' || $val === true) {
                echo '<input type="hidden" name="'.esc_attr($opt).'" value="1">';
            } elseif (!is_array($val) && $val !== '0' && $val !== false) {
                echo '<input type="hidden" name="'.esc_attr($opt).'" value="'.esc_attr($val).'">';
            }
        }
        foreach ($qq as $i => $row) {
            echo '<input type="hidden" name="hamman_qq_question[]" value="'.esc_attr($row['question']??'').'">';
            echo '<input type="hidden" name="hamman_qq_answer[]" value="'.esc_attr($row['answer']??'').'">';
        }
    ?>
    <label><input type="checkbox" name="hamman_delete_data_on_uninstall" value="1" <?php checked(get_option('hamman_delete_data_on_uninstall','0'),'1'); ?>>
        همه تنظیمات این افزونه را هنگام حذف، پاک کن / Delete all of this plugin's settings when it's uninstalled
    </label>
    <p class="description">این گزینه فقط تنظیمات محلی وردپرس را پاک می‌کند؛ داده‌های سمت سرور هامان (مکالمات، لیدها و...) تحت تأثیر قرار نمی‌گیرند. / This only clears local WordPress settings — data on the Hamman server (conversations, leads, etc.) is unaffected.</p>
    <?php submit_button('ذخیره / Save'); ?>
    </form>

    <hr>
    <h3>لاگ / Debug Log</h3>
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
