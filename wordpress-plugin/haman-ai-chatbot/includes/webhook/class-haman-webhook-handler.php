<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Haman_Webhook_Handler {
    public function register_routes(): void {
        // Both namespaces, same handler -- see Haman_Legacy.
        foreach ( [ 'haman/v1', Haman_Legacy::REST_NAMESPACE ] as $ns ) {
            register_rest_route($ns,'/sync',[
                'methods'             => 'POST',
                'callback'            => [$this,'handle'],
                'permission_callback' => '__return_true',
            ]);
        }
    }
    public function handle( WP_REST_Request $req ): WP_REST_Response {
        $sig    = $req->get_header('X-Haman-Signature')
                  ?: $req->get_header(Haman_Legacy::SIGNATURE_HEADER);
        $secret = get_option('haman_webhook_secret','');
        if (!empty($secret)) {
            $expected = 'sha256='.hash_hmac('sha256',$req->get_body(),$secret);
            if (!hash_equals($expected,(string)$sig)) return new WP_REST_Response(['error'=>'Invalid signature'],403);
        }
        return new WP_REST_Response(['status'=>'received'],200);
    }
}
