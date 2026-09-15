<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Reverse-direction endpoint: the AI service's tool-calling path (see
 * python-ai-service/app/services/tools/product_tools.py) calls INTO this
 * site to read LIVE WooCommerce data — stock/price/variants/search straight
 * from this site's own database, not the last-synced snapshot the regular
 * indexed content is built from. Authenticated with the same HMAC scheme
 * (X-Hamman-Signature, sha256=hex(hmac_sha256(body, webhook_secret)))
 * already used for this plugin's existing outbound sync webhooks — no new
 * credential for the customer to manage, and this site already trusts that
 * secret.
 *
 * A short, dedicated rate limit protects this specific site's database
 * from being hammered — separate from any other limiter in this plugin,
 * since this is the one endpoint that runs real live DB/product queries
 * per call.
 */
class Hamman_Live_Query_Handler {
    const RATE_LIMIT_MAX_PER_MINUTE = 20;
    const MAX_SEARCH_RESULTS        = 10;
    const MAX_VARIANTS_RETURNED     = 50;
    const MAX_RECOMMEND_RESULTS     = 3;
    const MAX_COMPARE_PRODUCTS      = 5;
    const MAX_ORDER_ITEMS           = 10;
    const MAX_ITEM_QUANTITY         = 20;
    const MAX_ORDERS_RETURNED       = 5;
    // A full sync re-embeds the whole catalogue, so this is per-site and
    // measured in hours, not the per-minute bucket live-query uses.
    const SYNC_MIN_INTERVAL_HOURS   = 1;

    public function register_routes(): void {
        register_rest_route( 'hamman/v1', '/live-query', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle' ],
            // Auth is HMAC-based below, not WP's native REST permission
            // system — same posture as class-hamman-webhook-handler.php.
            'permission_callback' => '__return_true',
        ] );

        // Sync has only ever been push: the plugin decides when to send,
        // and nobody on the platform side could ask for one. That left
        // support unable to do anything about a customer whose catalogue
        // had gone stale, even though refreshing it is part of the role.
        // Same HMAC auth as live-query — no new credential to manage.
        register_rest_route( 'hamman/v1', '/trigger-sync', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_trigger_sync' ],
            'permission_callback' => '__return_true',
        ] );
    }

    /**
     * Runs a full sync on demand and reports what the site pushed.
     *
     * Deliberately synchronous: the caller is a human waiting on a button,
     * and a fire-and-forget background job would give them nothing to look
     * at. A separate, much tighter rate limit than live-query's, because
     * one call here re-embeds the whole catalogue at the platform's expense.
     */
    public function handle_trigger_sync( WP_REST_Request $req ): WP_REST_Response {
        if ( ! $this->verify_signature( $req ) ) {
            return new WP_REST_Response( [ 'error' => 'Invalid signature' ], 403 );
        }

        $version = defined( 'HAMMAN_VERSION' ) ? HAMMAN_VERSION : '';

        if ( ! $this->check_sync_rate_limit() ) {
            return new WP_REST_Response( [
                'error'             => 'sync_rate_limited',
                'plugin_version'    => $version,
                'retry_after_hours' => self::SYNC_MIN_INTERVAL_HOURS,
            ], 429 );
        }

        if ( ! class_exists( 'Hamman_Sync_Manager' ) ) {
            return new WP_REST_Response( [ 'error' => 'sync_unavailable', 'plugin_version' => $version ], 503 );
        }

        $started = time();
        $results = ( new Hamman_Sync_Manager() )->run_full_sync();

        if ( isset( $results['error'] ) ) {
            // "Not configured" — the plugin is installed but has no API key
            // or chatbot id yet, which is a different problem from a failure.
            return new WP_REST_Response( [
                'error'          => 'not_configured',
                'detail'         => $results['error'],
                'plugin_version' => $version,
            ], 409 );
        }

        set_transient( 'hamman_last_manual_sync', $started, DAY_IN_SECONDS );

        return new WP_REST_Response( [
            'ok'              => true,
            'plugin_version'  => $version,
            'woocommerce'     => class_exists( 'WooCommerce' ),
            'duration_seconds' => time() - $started,
            'pushed'          => $results,
        ], 200 );
    }

    /** One manual sync per site per interval, whoever asks for it. */
    private function check_sync_rate_limit(): bool {
        $last = (int) get_transient( 'hamman_manual_sync_guard' );
        if ( $last ) return false;
        set_transient( 'hamman_manual_sync_guard', time(), self::SYNC_MIN_INTERVAL_HOURS * HOUR_IN_SECONDS );
        return true;
    }

    public function handle( WP_REST_Request $req ): WP_REST_Response {
        if ( ! $this->verify_signature( $req ) ) {
            return new WP_REST_Response( [ 'error' => 'Invalid signature' ], 403 );
        }
        if ( ! $this->check_rate_limit() ) {
            return new WP_REST_Response( [ 'error' => 'Rate limit exceeded' ], 429 );
        }
        if ( ! class_exists( 'WooCommerce' ) ) {
            return new WP_REST_Response( [ 'error' => 'WooCommerce not active' ], 503 );
        }

        $body   = $req->get_json_params() ?: [];
        $action = $body['action'] ?? '';

        switch ( $action ) {
            case 'get_availability':
                return $this->get_availability( $body );
            case 'get_variants':
                return $this->get_variants( $body );
            case 'search_products':
                return $this->search_products( $body );
            case 'recommend_products':
                return $this->recommend_products( $body );
            case 'compare_products':
                return $this->compare_products( $body );
            case 'preview_order':
                return $this->preview_order( $body );
            case 'create_draft_order':
                return $this->create_draft_order( $body );
            case 'contact_has_orders':
                return $this->contact_has_orders( $body );
            case 'get_orders_for_contact':
                return $this->get_orders_for_contact( $body );
            default:
                return new WP_REST_Response( [ 'error' => 'Unknown action' ], 400 );
        }
    }

    private function verify_signature( WP_REST_Request $req ): bool {
        $sig = $req->get_header( 'X-Hamman-Signature' );
        if ( empty( $sig ) ) return false;
        $secret = $this->get_secret();
        if ( empty( $secret ) ) return false;
        $expected = 'sha256=' . hash_hmac( 'sha256', $req->get_body(), $secret );
        return hash_equals( $expected, (string) $sig );
    }

    /** Same cached-from-server secret Hamman_Api_Client::send_webhook()
     * already uses for the outbound direction — never stored as a
     * permanent local option (see class-hamman-admin.php's Connection tab
     * docblock), just cached an hour at a time. */
    private function get_secret(): string {
        $cached = get_transient( 'hamman_webhook_secret_cache' );
        if ( false !== $cached ) return (string) $cached;
        $secret = ( new Hamman_Api_Client() )->get_webhook_secret();
        if ( is_wp_error( $secret ) ) return '';
        set_transient( 'hamman_webhook_secret_cache', $secret, HOUR_IN_SECONDS );
        return $secret;
    }

    private function check_rate_limit(): bool {
        $key   = 'hamman_toolcall_rl_' . gmdate( 'YmdHi' ); // fixed 1-minute bucket
        $count = (int) get_transient( $key );
        if ( $count >= self::RATE_LIMIT_MAX_PER_MINUTE ) return false;
        set_transient( $key, $count + 1, 70 );
        return true;
    }

    /**
     * SECURITY: $sku is checked against a strict allowlist regex and
     * $product_id is always cast with absint() before either touches
     * anything else — never trusted, never concatenated into raw SQL or a
     * shell context. Every WooCommerce call below goes through WC's own
     * parameterized data store / WP_Query, not raw SQL built in this
     * plugin.
     */
    private function resolve_product( array $body ) {
        if ( ! empty( $body['product_id'] ) ) {
            $id = absint( $body['product_id'] );
            if ( ! $id ) return new WP_Error( 'invalid_product_id', 'Invalid product_id' );
            return wc_get_product( $id ) ?: null;
        }
        if ( ! empty( $body['sku'] ) ) {
            $sku = (string) $body['sku'];
            if ( ! preg_match( '/^[A-Za-z0-9\-_. ]{1,64}$/', $sku ) ) {
                return new WP_Error( 'invalid_sku', 'Invalid sku' );
            }
            $id = wc_get_product_id_by_sku( $sku );
            return $id ? wc_get_product( $id ) : null;
        }
        return new WP_Error( 'missing_identifier', 'sku or product_id required' );
    }

    /** get_product_availability: stock, current price, sale price, publish
     * status — by SKU or numeric product_id, whichever the model supplied. */
    private function get_availability( array $body ): WP_REST_Response {
        $product = $this->resolve_product( $body );
        if ( is_wp_error( $product ) ) {
            return new WP_REST_Response( [ 'error' => $product->get_error_message() ], 400 );
        }
        if ( ! $product ) {
            return new WP_REST_Response( [ 'found' => false ], 200 );
        }

        return new WP_REST_Response( [
            'found'          => true,
            'product_id'     => $product->get_id(),
            'name'           => $product->get_name(),
            'sku'            => $product->get_sku(),
            // publish/draft/pending/private — lets the bot avoid describing
            // a product that isn't actually live on the site as available.
            'status'         => $product->get_status(),
            'stock_status'   => $product->get_stock_status(),
            'stock_quantity' => $product->get_stock_quantity(),
            'price'          => '' !== $product->get_price() ? (float) $product->get_price() : null,
            'regular_price'  => '' !== $product->get_regular_price() ? (float) $product->get_regular_price() : null,
            'sale_price'     => '' !== $product->get_sale_price() ? (float) $product->get_sale_price() : null,
            'on_sale'        => $product->is_on_sale(),
            'is_variable'    => $product->is_type( 'variable' ),
            'currency'       => get_woocommerce_currency(),
        ], 200 );
    }

    /** get_product_variants: every variation of a variable product (size,
     * color, or any other variable attribute), each with its OWN stock and
     * price — critical for a cosmetics shop (volume/shade) or parts store
     * (package size), where the parent product alone doesn't say enough. */
    private function get_variants( array $body ): WP_REST_Response {
        $id = absint( $body['product_id'] ?? 0 );
        if ( ! $id ) {
            return new WP_REST_Response( [ 'error' => 'Invalid product_id' ], 400 );
        }
        $product = wc_get_product( $id );
        if ( ! $product ) {
            return new WP_REST_Response( [ 'found' => false ], 200 );
        }
        if ( ! $product->is_type( 'variable' ) ) {
            return new WP_REST_Response( [ 'found' => true, 'is_variable' => false, 'product_id' => $id, 'name' => $product->get_name() ], 200 );
        }

        $variants = [];
        foreach ( $product->get_children() as $variation_id ) {
            if ( count( $variants ) >= self::MAX_VARIANTS_RETURNED ) break;
            $variation = wc_get_product( $variation_id );
            if ( ! $variation ) continue;
            $variants[] = [
                'variation_id'   => $variation_id,
                // e.g. ['attribute_pa_size' => 'M', 'attribute_pa_color' => 'Red']
                'attributes'     => $variation->get_variation_attributes(),
                'sku'            => $variation->get_sku(),
                'stock_status'   => $variation->get_stock_status(),
                'stock_quantity' => $variation->get_stock_quantity(),
                'price'          => '' !== $variation->get_price() ? (float) $variation->get_price() : null,
                'regular_price'  => '' !== $variation->get_regular_price() ? (float) $variation->get_regular_price() : null,
                'sale_price'     => '' !== $variation->get_sale_price() ? (float) $variation->get_sale_price() : null,
            ];
        }

        return new WP_REST_Response( [
            'found'       => true,
            'is_variable' => true,
            'product_id'  => $id,
            'name'        => $product->get_name(),
            'currency'    => get_woocommerce_currency(),
            'variants'    => $variants,
        ], 200 );
    }

    /**
     * search_products: STRUCTURED live catalog search — category, price
     * range, brand, in-stock-only — deliberately not a semantic/similarity
     * search (that's what the regular vector retrieval pipeline is for).
     * Every filter goes through wc_get_products()'s own WC_Product_Query
     * builder (WP_Query + parameterized meta_query/tax_query under the
     * hood), never raw SQL assembled in this plugin.
     */
    private function search_products( array $body ): WP_REST_Response {
        $args = [
            'status' => 'publish',
            'limit'  => min( self::MAX_SEARCH_RESULTS, max( 1, absint( $body['limit'] ?? 8 ) ) ),
            'return' => 'objects',
        ];

        if ( ! empty( $body['query'] ) ) {
            $q = sanitize_text_field( (string) $body['query'] );
            $args['s'] = mb_substr( $q, 0, 200 );
        }
        if ( ! empty( $body['category'] ) ) {
            $args['category'] = [ sanitize_title( (string) $body['category'] ) ];
        }
        if ( isset( $body['price_min'] ) && is_numeric( $body['price_min'] ) ) {
            $args['min_price'] = (float) $body['price_min'];
        }
        if ( isset( $body['price_max'] ) && is_numeric( $body['price_max'] ) ) {
            $args['max_price'] = (float) $body['price_max'];
        }
        if ( ! empty( $body['in_stock_only'] ) ) {
            $args['stock_status'] = 'instock';
        }
        if ( ! empty( $body['brand'] ) ) {
            $tax = $this->resolve_brand_taxonomy();
            // Silently ignored (not an error) if this store has no brand
            // taxonomy at all — same "unknown filter degrades gracefully"
            // posture as the rest of this integration, since not every
            // WooCommerce store has a brand plugin installed.
            if ( $tax ) {
                $args['tax_query'] = [ [
                    'taxonomy' => $tax,
                    'field'    => 'name',
                    'terms'    => sanitize_text_field( (string) $body['brand'] ),
                ] ];
            }
        }

        $products = wc_get_products( $args );
        $results  = [];
        foreach ( $products as $p ) {
            $results[] = [
                'product_id'   => $p->get_id(),
                'name'         => $p->get_name(),
                'sku'          => $p->get_sku(),
                'price'        => '' !== $p->get_price() ? (float) $p->get_price() : null,
                'on_sale'      => $p->is_on_sale(),
                'stock_status' => $p->get_stock_status(),
                'is_variable'  => $p->is_type( 'variable' ),
            ];
        }

        return new WP_REST_Response( [
            'count'    => count( $results ),
            'currency' => get_woocommerce_currency(),
            'results'  => $results,
        ], 200 );
    }

    /** Not every WooCommerce store has a brand taxonomy — there's no core
     * one. Checks the handful of real plugins that add one, in the order
     * they're actually seen in the wild, rather than assuming a specific
     * brand plugin is installed. */
    private function resolve_brand_taxonomy(): ?string {
        foreach ( [ 'product_brand', 'pwb-brand', 'pa_brand' ] as $tax ) {
            if ( taxonomy_exists( $tax ) ) return $tax;
        }
        return null;
    }

    /**
     * recommend_products: candidates for "what's good for X?" — from doc-04's
     * product-compare item and a real shop interview ("what product is right
     * for me?" was one of a cosmetics store's three most common questions).
     * ALWAYS in-stock only, hardcoded here rather than left as a
     * model-controlled flag — the acceptance criterion is that an
     * out-of-stock item is never recommended, so this is enforced at the
     * query level, not merely by prompting the model to behave. $need is
     * passed straight into WC's own 's' (search) query arg, which WP_Query
     * parameterizes internally; never built into raw SQL here.
     */
    private function recommend_products( array $body ): WP_REST_Response {
        $need = isset( $body['need'] ) ? sanitize_text_field( (string) $body['need'] ) : '';
        if ( '' === $need ) {
            return new WP_REST_Response( [ 'error' => 'need is required' ], 400 );
        }

        $args = [
            'status'       => 'publish',
            'stock_status' => 'instock',
            'limit'        => self::MAX_RECOMMEND_RESULTS,
            'return'       => 'objects',
            's'            => mb_substr( $need, 0, 300 ),
        ];
        if ( ! empty( $body['category'] ) ) {
            $args['category'] = [ sanitize_title( (string) $body['category'] ) ];
        }
        if ( isset( $body['price_max'] ) && is_numeric( $body['price_max'] ) ) {
            $args['max_price'] = (float) $body['price_max'];
        }
        if ( isset( $body['price_min'] ) && is_numeric( $body['price_min'] ) ) {
            $args['min_price'] = (float) $body['price_min'];
        }

        $products = wc_get_products( $args );
        $results  = [];
        foreach ( $products as $p ) {
            $results[] = [
                'product_id'        => $p->get_id(),
                'name'              => $p->get_name(),
                'price'             => '' !== $p->get_price() ? (float) $p->get_price() : null,
                'on_sale'           => $p->is_on_sale(),
                // Always 'instock' by construction (see stock_status above)
                // — still returned so the caller never has to assume it.
                'stock_status'      => $p->get_stock_status(),
                'short_description' => $this->plain_short_description( $p ),
                'image'             => $this->product_image_url( $p ),
            ];
        }

        return new WP_REST_Response( [
            'currency' => get_woocommerce_currency(),
            'products' => $results,
        ], 200 );
    }

    /**
     * compare_products: a feature-by-feature table for 2-5 products. Returns
     * each product's OWN real WooCommerce attributes only — never invents or
     * infers a value for a product that doesn't have one; the caller (see
     * product_tools.compare_products on the Python side) builds the union of
     * attribute names across all returned products and leaves a product's
     * cell blank wherever it has no matching key, rather than guessing.
     */
    private function compare_products( array $body ): WP_REST_Response {
        $raw_ids = is_array( $body['product_ids'] ?? null ) ? $body['product_ids'] : [];
        $ids     = array_values( array_filter( array_unique( array_map( 'absint', $raw_ids ) ) ) );
        $ids     = array_slice( $ids, 0, self::MAX_COMPARE_PRODUCTS );
        if ( count( $ids ) < 2 ) {
            return new WP_REST_Response( [ 'error' => 'At least 2 valid product_ids are required' ], 400 );
        }

        $products = [];
        foreach ( $ids as $id ) {
            $p = wc_get_product( $id );
            if ( ! $p ) {
                $products[] = [ 'product_id' => $id, 'found' => false ];
                continue;
            }
            $products[] = [
                'product_id'   => $p->get_id(),
                'found'        => true,
                'name'         => $p->get_name(),
                'price'        => '' !== $p->get_price() ? (float) $p->get_price() : null,
                'stock_status' => $p->get_stock_status(),
                'image'        => $this->product_image_url( $p ),
                'attributes'   => $this->product_attributes_map( $p ),
            ];
        }

        return new WP_REST_Response( [
            'currency' => get_woocommerce_currency(),
            'products' => $products,
        ], 200 );
    }

    /** Label => value for every attribute this SPECIFIC product actually
     * has (both global/taxonomy attributes like pa_color and local/custom
     * ones) — omits anything empty rather than returning a blank value, so
     * a missing key unambiguously means "this product doesn't have this
     * attribute", not "it has an empty one". */
    private function product_attributes_map( WC_Product $product ): array {
        $map = [];
        foreach ( $product->get_attributes() as $attribute ) {
            $label = wc_attribute_label( $attribute->get_name(), $product );
            if ( $attribute->is_taxonomy() ) {
                $terms = wc_get_product_terms( $product->get_id(), $attribute->get_name(), [ 'fields' => 'names' ] );
                $value = implode( ', ', $terms );
            } else {
                $value = implode( ', ', $attribute->get_options() );
            }
            if ( '' !== trim( (string) $value ) ) {
                $map[ $label ] = $value;
            }
        }
        return $map;
    }

    private function product_image_url( WC_Product $product ): ?string {
        $image_id = $product->get_image_id();
        if ( ! $image_id ) return null;
        $url = wp_get_attachment_image_url( $image_id, 'medium' );
        return $url ?: null;
    }

    /** Plain, tag-stripped, whitespace-collapsed short description — the
     * raw material the model grounds its one-sentence "why this fits"
     * recommendation in, never invented beyond what this text says. */
    private function plain_short_description( WC_Product $product ): string {
        $text = $product->get_short_description() ?: $product->get_description();
        $text = wp_strip_all_tags( (string) $text );
        $text = trim( preg_replace( '/\s+/', ' ', $text ) );
        return mb_substr( $text, 0, 220 );
    }

    /**
     * create_payment_link (doc-04) — shared item resolution/pricing for
     * BOTH preview_order (read-only) and create_draft_order (the one
     * write action in this whole handler), so a preview can never promise
     * something the confirm step doesn't actually deliver: the exact same
     * stock/purchasability/price checks run again, fresh, at order-
     * creation time — nothing computed by the preview call is ever
     * trusted or reused. Returns WP_Error on the FIRST invalid item
     * (never partially prices/creates an order for a mix of valid and
     * invalid items) or an array of resolved rows.
     *
     * @return array|WP_Error
     */
    private function resolve_order_items( array $raw_items ) {
        if ( empty( $raw_items ) || ! is_array( $raw_items ) ) {
            return new WP_Error( 'no_items', 'No items given' );
        }
        if ( count( $raw_items ) > self::MAX_ORDER_ITEMS ) {
            return new WP_Error( 'too_many_items', 'Too many items' );
        }

        $resolved = [];
        foreach ( $raw_items as $raw ) {
            if ( ! is_array( $raw ) ) return new WP_Error( 'invalid_item', 'Invalid item' );
            $product_id = absint( $raw['product_id'] ?? 0 );
            if ( ! $product_id ) return new WP_Error( 'invalid_item', 'Invalid product_id' );
            $variation_id = ! empty( $raw['variation_id'] ) ? absint( $raw['variation_id'] ) : 0;
            $quantity = max( 1, min( self::MAX_ITEM_QUANTITY, absint( $raw['quantity'] ?? 1 ) ) );

            $target = $variation_id ?: $product_id;
            $product = wc_get_product( $target );
            if ( ! $product ) return new WP_Error( 'product_not_found', "Product {$target} not found" );
            if ( $variation_id && (int) $product->get_parent_id() !== $product_id ) {
                return new WP_Error( 'variation_mismatch', 'variation_id does not belong to the given product_id' );
            }
            if ( ! $product->is_purchasable() ) {
                return new WP_Error( 'not_purchasable', "{$product->get_name()} is not purchasable" );
            }
            if ( ! $product->is_in_stock() ) {
                return new WP_Error( 'out_of_stock', "{$product->get_name()} is out of stock" );
            }
            if ( $product->managing_stock() && $product->get_stock_quantity() !== null && $product->get_stock_quantity() < $quantity ) {
                return new WP_Error( 'insufficient_stock', "Not enough stock for {$product->get_name()}" );
            }

            $price = (float) $product->get_price();
            $resolved[] = [
                'product_id'   => $product_id,
                'variation_id' => $variation_id ?: null,
                'quantity'     => $quantity,
                'name'         => $product->get_name(),
                'price'        => $price,
                'line_total'   => $price * $quantity,
                'wc_product'   => $product,
            ];
        }
        return $resolved;
    }

    /** Read-only preview — computes live price/name/total for a proposed
     * order WITHOUT creating anything. This is what create_payment_link
     * (the model-callable tool) actually calls; the widget renders this
     * as the order summary + total the customer must see and explicitly
     * confirm before anything real is created (see rag_service's
     * _payment_link_rule() and hamman-widget.js's
     * renderPaymentLinkPreview()). */
    private function preview_order( array $body ): WP_REST_Response {
        $resolved = $this->resolve_order_items( $body['items'] ?? [] );
        if ( is_wp_error( $resolved ) ) {
            return new WP_REST_Response( [ 'error' => $resolved->get_error_message() ], 400 );
        }

        $items_out = array_map( function ( $r ) {
            return [
                'product_id'   => $r['product_id'],
                'variation_id' => $r['variation_id'],
                'quantity'     => $r['quantity'],
                'name'         => $r['name'],
                'price'        => $r['price'],
                'line_total'   => $r['line_total'],
            ];
        }, $resolved );

        return new WP_REST_Response( [
            'items'    => $items_out,
            'total'    => array_sum( array_column( $resolved, 'line_total' ) ),
            'currency' => get_woocommerce_currency(),
        ], 200 );
    }

    /**
     * create_draft_order — the ONE write action in this entire handler.
     * Only ever reached via Laravel's ChatController::createPaymentLink(),
     * itself only ever reached after a real customer click on a rendered
     * "Confirm & Pay" button (see the task's own security rules) AND after
     * Laravel's own per-conversation/per-IP/per-day and per-chatbot max-
     * amount checks already passed — this endpoint re-validates
     * stock/price itself regardless, never trusting that anything checked
     * upstream still holds true.
     *
     * Creates a real WooCommerce order in 'pending' status (WooCommerce's
     * own native "awaiting payment" status — nothing custom invented
     * here) and returns order_key/order_pay_url exactly once, in this one
     * response. This plugin never logs either value (see verify_signature()
     * et al. — nothing here writes $body or this response to any log) and
     * never persists them itself; Laravel is told explicitly to store only
     * the numeric order_id, never the key or the URL.
     */
    private function create_draft_order( array $body ): WP_REST_Response {
        $resolved = $this->resolve_order_items( $body['items'] ?? [] );
        if ( is_wp_error( $resolved ) ) {
            return new WP_REST_Response( [ 'error' => $resolved->get_error_message() ], 400 );
        }

        $order = wc_create_order();
        if ( is_wp_error( $order ) ) {
            return new WP_REST_Response( [ 'error' => 'Could not create order' ], 500 );
        }

        foreach ( $resolved as $r ) {
            $order->add_product( $r['wc_product'], $r['quantity'] );
        }

        $customer = is_array( $body['customer'] ?? null ) ? $body['customer'] : [];
        if ( ! empty( $customer['name'] ) ) {
            $parts = preg_split( '/\s+/', trim( sanitize_text_field( (string) $customer['name'] ) ), 2 );
            $order->set_billing_first_name( $parts[0] ?? '' );
            $order->set_billing_last_name( $parts[1] ?? '' );
        }
        if ( ! empty( $customer['phone'] ) ) {
            $order->set_billing_phone( sanitize_text_field( (string) $customer['phone'] ) );
        }
        if ( ! empty( $customer['email'] ) && is_email( (string) $customer['email'] ) ) {
            $order->set_billing_email( sanitize_email( (string) $customer['email'] ) );
        }

        // Flags this order as ours — the auto-cancel cron
        // (cancel_stale_draft_orders(), Hamman_Sync_Manager) and the
        // order-status-changed webhook both key off this meta, never off
        // guessing from order content, so a merchant's own manually-
        // created pending orders are never touched by either.
        $order->update_meta_data( '_hamman_created', 1 );
        $order->update_meta_data( '_hamman_chatbot_id', sanitize_text_field( (string) get_option( 'hamman_chatbot_id', '' ) ) );
        $order->set_status( 'pending' );
        $order->calculate_totals();
        $order->save();

        return new WP_REST_Response( [
            'order_id'      => $order->get_id(),
            'key'           => $order->get_order_key(),
            'order_pay_url' => $order->get_checkout_payment_url(),
            'total'         => (float) $order->get_total(),
            'currency'      => $order->get_currency(),
        ], 200 );
    }

    /**
     * Iranian mobile numbers reach WooCommerce in whatever shape the
     * customer typed them at checkout (09..., +989..., 00989..., 9...),
     * so an exact-match lookup on one spelling would silently miss a real
     * customer's real order. Reduce to the trailing 10 digits (9XXXXXXXXX)
     * and let the caller fan out over the spellings that produces.
     */
    private function phone_variants( string $phone ): array {
        $digits = preg_replace( '/\D+/', '', $phone );
        if ( strlen( $digits ) < 10 ) return [];
        $core = substr( $digits, -10 ); // 9XXXXXXXXX
        if ( $core[0] !== '9' ) return [];
        return [ '0' . $core, $core, '+98' . $core, '0098' . $core, '98' . $core ];
    }

    /**
     * Every order matching a phone/email, newest first, capped. Returns
     * WC_Order objects - callers decide what (if anything) may leave this
     * site. Deliberately queries only billing_phone/billing_email: a
     * customer proving control of a contact proves nothing about orders
     * placed under a different one.
     */
    private function find_orders_for_contact( array $body ): array {
        $type    = ( $body['contact_type'] ?? '' ) === 'email' ? 'email' : 'phone';
        $contact = sanitize_text_field( (string) ( $body['contact'] ?? '' ) );
        if ( $contact === '' ) return [];

        $found = [];
        if ( 'email' === $type ) {
            if ( ! is_email( $contact ) ) return [];
            $found = wc_get_orders( [
                'billing_email' => $contact,
                'limit'         => self::MAX_ORDERS_RETURNED,
                'orderby'       => 'date',
                'order'         => 'DESC',
                'status'        => array_keys( wc_get_order_statuses() ),
            ] );
        } else {
            foreach ( $this->phone_variants( $contact ) as $variant ) {
                $batch = wc_get_orders( [
                    'billing_phone' => $variant,
                    'limit'         => self::MAX_ORDERS_RETURNED,
                    'orderby'       => 'date',
                    'order'         => 'DESC',
                    'status'        => array_keys( wc_get_order_statuses() ),
                ] );
                foreach ( $batch as $order ) {
                    $found[ $order->get_id() ] = $order;
                }
                if ( count( $found ) >= self::MAX_ORDERS_RETURNED ) break;
            }
            $found = array_values( $found );
        }

        usort( $found, function ( $a, $b ) {
            return $b->get_date_created()->getTimestamp() <=> $a->get_date_created()->getTimestamp();
        } );

        return array_slice( $found, 0, self::MAX_ORDERS_RETURNED );
    }

    /**
     * The anti-abuse gate, and the whole reason this is a separate action
     * from get_orders_for_contact(): the server asks "is it even worth
     * sending an SMS to this number?" BEFORE spending the merchant's money,
     * and this response is structurally incapable of leaking order content
     * - it is a boolean and a count, nothing else, no matter what the
     * caller asks for.
     */
    private function contact_has_orders( array $body ): WP_REST_Response {
        $orders = $this->find_orders_for_contact( $body );
        return new WP_REST_Response( [
            'found' => ! empty( $orders ),
            'count' => count( $orders ),
        ], 200 );
    }

    /**
     * Called only after the server has verified the customer actually
     * controls this contact (OTP). Sanitising happens HERE, at the source,
     * so a shipping address or a payment reference never crosses the wire
     * at all rather than being fetched and then dropped somewhere later:
     * status, tracking number and item names are the whole contract.
     */
    private function get_orders_for_contact( array $body ): WP_REST_Response {
        $out = [];
        foreach ( $this->find_orders_for_contact( $body ) as $order ) {
            $items = [];
            foreach ( $order->get_items() as $item ) {
                $items[] = [
                    'name'     => $item->get_name(),
                    'quantity' => (int) $item->get_quantity(),
                ];
            }
            $out[] = [
                'number'       => $order->get_order_number(),
                'status'       => $order->get_status(),
                'date_created' => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d' ) : null,
                'tracking'     => $this->order_tracking_code( $order ),
                'items'        => $items,
            ];
        }
        return new WP_REST_Response( [ 'orders' => $out ], 200 );
    }

    /**
     * WooCommerce core has no tracking-number field, so this reads the meta
     * keys the common shipping/tracking plugins actually write, and returns
     * null rather than inventing anything when none of them is present.
     */
    private function order_tracking_code( WC_Order $order ): ?string {
        foreach ( [ '_tracking_number', '_wc_shipment_tracking_number', '_hamman_tracking', 'tracking_code' ] as $key ) {
            $value = $order->get_meta( $key );
            if ( is_string( $value ) && $value !== '' ) {
                return sanitize_text_field( $value );
            }
        }
        // WooCommerce Shipment Tracking stores an array of tracking items.
        $items = $order->get_meta( '_wc_shipment_tracking_items' );
        if ( is_array( $items ) && ! empty( $items[0]['tracking_number'] ) ) {
            return sanitize_text_field( (string) $items[0]['tracking_number'] );
        }
        return null;
    }
}
