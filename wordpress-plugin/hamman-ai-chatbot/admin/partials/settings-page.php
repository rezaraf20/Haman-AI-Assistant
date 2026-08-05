<?php if (!defined('ABSPATH')) exit;
$qq = get_option('hamman_quick_questions', []);
if (!is_array($qq)) $qq = [];
?>
<div class="wrap">
<h1>🤖 Hamman AI Chatbot — شرکت هامان فناوران پیشرو</h1>
<?php if(isset($_GET['saved'])): ?><div class="notice notice-success"><p>✅ تنظیمات ذخیره شد.</p></div><?php endif; ?>
<?php if(isset($_GET['sync_warning'])): ?><div class="notice notice-warning"><p>⚠️ تنظیمات محلی ذخیره شد اما ارسال به سرور هامان با خطا مواجه شد: <?php echo esc_html(get_transient('hamman_settings_push_error')); ?></p></div><?php endif; ?>
<?php if(isset($_GET['synced'])): ?><div class="notice notice-success"><p>✅ Sync completed.</p></div><?php endif; ?>

<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
<input type="hidden" name="action" value="hamman_save_settings">
<?php wp_nonce_field('hamman_save_settings'); ?>

<h2>اتصال به هامان</h2>
<table class="form-table">
<tr><th>API Key</th><td>
    <input type="password" name="hamman_api_key" value="<?php echo esc_attr(get_option('hamman_api_key','')); ?>" class="regular-text">
    <p class="description">From Hamman dashboard (starts with hfp_)</p>
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
<tr><th>Enable Widget</th><td>
    <label><input type="checkbox" name="hamman_enabled" value="1" <?php checked(get_option('hamman_enabled','1'),'1'); ?>> Show chatbot on frontend</label>
</td></tr>
</table>

<hr>
<h2>سیستم پاسخ‌دهی خودکار هوش مصنوعی</h2>
<table class="form-table">
<tr><th>فعال کردن پاسخ‌دهی خودکار</th><td>
    <label><input type="checkbox" name="hamman_auto_reply_enabled" value="1" <?php checked(get_option('hamman_auto_reply_enabled','1'),'1'); ?>> هوش مصنوعی به‌صورت خودکار به پیام‌های کاربران پاسخ بدهد</label>
</td></tr>
<tr><th>نام هوش مصنوعی</th><td>
    <input type="text" name="hamman_ai_name" value="<?php echo esc_attr(get_option('hamman_ai_name','AI BOT')); ?>" class="regular-text" placeholder="AI BOT">
    <p class="description">با چه نامی گفتگو انجام شود؟ برای مثال: دستیار سایت (پیش‌فرض: AI BOT)</p>
</td></tr>
<tr><th>عنوان ابتدای گفتگو</th><td>
    <input type="text" name="hamman_chat_title" value="<?php echo esc_attr(get_option('hamman_chat_title','پشتیبانی آنلاین')); ?>" class="regular-text" placeholder="پشتیبانی آنلاین">
    <p class="description">عنوانی که بالای صفحه گفتگو نمایش داده می‌شود</p>
</td></tr>
<tr><th>متن پیش‌فرض در صفحه گفتگو</th><td>
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
</table>

<hr>
<h2>محدودیت‌های ارسال پیام</h2>
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

<hr>
<h2>سوالات آماده</h2>
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
<p><button type="button" id="hm-qq-add" class="button">+ افزودن سوال</button></p>
<script>
(function(){
    document.getElementById('hm-qq-add').addEventListener('click', function(){
        var tbody = document.querySelector('#hm-qq-table tbody');
        var tr = document.createElement('tr');
        tr.innerHTML = '<td><input type="text" name="hamman_qq_question[]" class="regular-text" style="width:100%"></td>'
                     + '<td><input type="text" name="hamman_qq_answer[]" class="regular-text" style="width:100%"></td>'
                     + '<td><button type="button" class="button hm-qq-remove">حذف</button></td>';
        tbody.appendChild(tr);
    });
    document.querySelector('#hm-qq-table').addEventListener('click', function(e){
        if (e.target.classList.contains('hm-qq-remove')) {
            var rows = document.querySelectorAll('#hm-qq-table tbody tr');
            if (rows.length > 1) e.target.closest('tr').remove();
            else e.target.closest('tr').querySelectorAll('input').forEach(function(i){ i.value=''; });
        }
    });
})();
</script>

<?php submit_button('ذخیره تنظیمات'); ?>
</form>

<hr>
<h2>Data Sync</h2>
<p>Last sync: <?php echo get_option('hamman_last_full_sync') ? date('Y-m-d H:i',get_option('hamman_last_full_sync')) : 'Never'; ?></p>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
<input type="hidden" name="action" value="hamman_manual_sync">
<?php wp_nonce_field('hamman_manual_sync'); ?>
<?php submit_button('Run Full Sync Now','secondary'); ?>
</form>
<?php $r = get_transient('hamman_sync_results'); if($r): ?>
<div class="notice notice-info"><pre><?php echo esc_html(json_encode($r,JSON_PRETTY_PRINT)); ?></pre></div>
<?php endif; ?>
</div>
