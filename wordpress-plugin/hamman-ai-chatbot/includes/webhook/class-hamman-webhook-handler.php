<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Hamman_Webhook_Handler {
    public function register_routes(): void {
        register_rest_route('hamman/v1','/sync',[
            'methods'             => 'POST',
            'callback'            => [$this,'handle'],
            'permission_callback' => '__return_true',
        ]);
    }
    public function handle( WP_REST_Request $req ): WP_REST_Response {
        $sig    = $req->get_header('X-Hamman-Signature');
        $secret = get_option('hamman_webhook_secret','');
        if (!empty($secret)) {
            $expected = 'sha256='.hash_hmac('sha256',$req->get_body(),$secret);
            if (!hash_equals($expected,(string)$sig)) return new WP_REST_Response(['error'=>'Invalid signature'],403);
        }
        return new WP_REST_Response(['status'=>'received'],200);
    }
}
