<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Hamman_Admin {
    public function add_menu_page(): void {
        add_menu_page('Hamman AI','Hamman AI','manage_options','hamman-ai-chatbot',[$this,'render'],'dashicons-format-chat',30);
    }
    public function enqueue_assets( string $hook ): void {
        if (strpos($hook,'hamman-ai-chatbot')===false) return;
        wp_enqueue_style('hamman-admin',HAMMAN_PLUGIN_URL.'admin/css/hamman-admin.css',[],HAMMAN_VERSION);
    }
    public function render(): void {
        if (!current_user_can('manage_options')) wp_die('No permission');
        include HAMMAN_PLUGIN_DIR.'admin/partials/settings-page.php';
    }
    public function save_settings(): void {
        if (!current_user_can('manage_options')) wp_die('No permission');
        check_admin_referer('hamman_save_settings');
        update_option('hamman_api_key',       sanitize_text_field($_POST['hamman_api_key']??''));
        update_option('hamman_chatbot_id',    sanitize_text_field($_POST['hamman_chatbot_id']??''));
        update_option('hamman_webhook_secret',sanitize_text_field($_POST['hamman_webhook_secret']??''));
        update_option('hamman_api_url',       esc_url_raw($_POST['hamman_api_url']??HAMMAN_API_BASE));
        update_option('hamman_enabled',       isset($_POST['hamman_enabled'])?'1':'0');
        wp_redirect(admin_url('admin.php?page=hamman-ai-chatbot&saved=1'));
        exit;
    }
    public function manual_sync(): void {
        if (!current_user_can('manage_options')) wp_die('No permission');
        check_admin_referer('hamman_manual_sync');
        $results = (new Hamman_Sync_Manager())->run_full_sync();
        set_transient('hamman_sync_results',$results,60);
        wp_redirect(admin_url('admin.php?page=hamman-ai-chatbot&synced=1'));
        exit;
    }
}
