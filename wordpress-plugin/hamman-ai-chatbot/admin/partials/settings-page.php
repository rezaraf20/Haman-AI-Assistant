<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap">
<h1>🤖 Hamman AI Chatbot — شرکت هامان فناوران پیشرو</h1>
<?php if(isset($_GET['saved'])): ?><div class="notice notice-success"><p>✅ Settings saved.</p></div><?php endif; ?>
<?php if(isset($_GET['synced'])): ?><div class="notice notice-success"><p>✅ Sync completed.</p></div><?php endif; ?>

<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
<input type="hidden" name="action" value="hamman_save_settings">
<?php wp_nonce_field('hamman_save_settings'); ?>
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
<?php submit_button('Save Settings'); ?>
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
