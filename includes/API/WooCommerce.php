<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;

use RJV_AGI_Bridge\Auth;
use RJV_AGI_Bridge\AI\Router;

/**
 * WooCommerce Integration
 *
 * Full store administration surface: products, orders, customers, coupons,
 * shipping zones, payment gateways, taxes, product categories/tags/attributes,
 * order notes, refunds, store settings, and webhook management.
 * All endpoints degrade gracefully when WooCommerce is absent.
 */
class WooCommerce extends Base {

    public function register_routes(): void {
        // Products
        register_rest_route($this->namespace, '/woo/products', [
            ['methods' => 'GET',  'callback' => [$this, 'list_products'],   'permission_callback' => [Auth::class, 'tier1'],
             'args' => ['per_page' => ['default' => 20], 'page' => ['default' => 1], 'status' => ['default' => 'any'], 'search' => ['default' => '']]],
            ['methods' => 'POST', 'callback' => [$this, 'create_product'],  'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/woo/products/(?P<id>\d+)', [
            ['methods' => 'GET',         'callback' => [$this, 'get_product'],    'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'PUT,PATCH',   'callback' => [$this, 'update_product'], 'permission_callback' => [Auth::class, 'tier2']],
            ['methods' => 'DELETE',      'callback' => [$this, 'delete_product'], 'permission_callback' => [Auth::class, 'tier3']],
        ]);
        register_rest_route($this->namespace, '/woo/products/(?P<id>\d+)/generate-description', [
            ['methods' => 'POST', 'callback' => [$this, 'generate_description'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/woo/products/bulk', [
            ['methods' => 'POST', 'callback' => [$this, 'bulk_products'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/woo/products/(?P<id>\d+)/variations', [
            ['methods' => 'GET', 'callback' => [$this, 'list_variations'], 'permission_callback' => [Auth::class, 'tier1']],
        ]);

        // Orders
        register_rest_route($this->namespace, '/woo/orders', [
            ['methods' => 'GET', 'callback' => [$this, 'list_orders'], 'permission_callback' => [Auth::class, 'tier1'],
             'args' => ['per_page' => ['default' => 20], 'page' => ['default' => 1], 'status' => ['default' => 'any']]],
        ]);
        register_rest_route($this->namespace, '/woo/orders/(?P<id>\d+)', [
            ['methods' => 'GET',       'callback' => [$this, 'get_order'],    'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'PUT,PATCH', 'callback' => [$this, 'update_order'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/woo/orders/(?P<id>\d+)/notes', [
            ['methods' => 'GET',  'callback' => [$this, 'list_order_notes'], 'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'POST', 'callback' => [$this, 'add_order_note'],   'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/woo/orders/(?P<id>\d+)/refunds', [
            ['methods' => 'GET',  'callback' => [$this, 'list_refunds'],   'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'POST', 'callback' => [$this, 'create_refund'],  'permission_callback' => [Auth::class, 'tier2']],
        ]);

        // Customers
        register_rest_route($this->namespace, '/woo/customers', [
            ['methods' => 'GET', 'callback' => [$this, 'list_customers'], 'permission_callback' => [Auth::class, 'tier1'],
             'args' => ['per_page' => ['default' => 20], 'page' => ['default' => 1], 'search' => ['default' => '']]],
        ]);

        // Store stats
        register_rest_route($this->namespace, '/woo/stats', [
            ['methods' => 'GET', 'callback' => [$this, 'stats'], 'permission_callback' => [Auth::class, 'tier1']],
        ]);

        // Coupons
        register_rest_route($this->namespace, '/woo/coupons', [
            ['methods' => 'GET',  'callback' => [$this, 'list_coupons'],  'permission_callback' => [Auth::class, 'tier1'],
             'args' => ['per_page' => ['default' => 20], 'page' => ['default' => 1]]],
            ['methods' => 'POST', 'callback' => [$this, 'create_coupon'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/woo/coupons/(?P<id>\d+)', [
            ['methods' => 'GET',       'callback' => [$this, 'get_coupon'],    'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'PUT,PATCH', 'callback' => [$this, 'update_coupon'], 'permission_callback' => [Auth::class, 'tier2']],
            ['methods' => 'DELETE',    'callback' => [$this, 'delete_coupon'], 'permission_callback' => [Auth::class, 'tier3']],
        ]);

        // Shipping zones & methods
        register_rest_route($this->namespace, '/woo/shipping/zones', [
            ['methods' => 'GET',  'callback' => [$this, 'list_shipping_zones'],  'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'POST', 'callback' => [$this, 'create_shipping_zone'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/woo/shipping/zones/(?P<zone_id>\d+)', [
            ['methods' => 'GET',       'callback' => [$this, 'get_shipping_zone'],    'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'PUT,PATCH', 'callback' => [$this, 'update_shipping_zone'], 'permission_callback' => [Auth::class, 'tier2']],
            ['methods' => 'DELETE',    'callback' => [$this, 'delete_shipping_zone'], 'permission_callback' => [Auth::class, 'tier3']],
        ]);
        register_rest_route($this->namespace, '/woo/shipping/zones/(?P<zone_id>\d+)/methods', [
            ['methods' => 'GET',  'callback' => [$this, 'list_shipping_methods'],  'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'POST', 'callback' => [$this, 'add_shipping_method'],    'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/woo/shipping/zones/(?P<zone_id>\d+)/methods/(?P<instance_id>\d+)', [
            ['methods' => 'PUT,PATCH', 'callback' => [$this, 'update_shipping_method'], 'permission_callback' => [Auth::class, 'tier2']],
            ['methods' => 'DELETE',    'callback' => [$this, 'delete_shipping_method'], 'permission_callback' => [Auth::class, 'tier3']],
        ]);

        // Payment gateways
        register_rest_route($this->namespace, '/woo/payment-gateways', [
            ['methods' => 'GET', 'callback' => [$this, 'list_payment_gateways'], 'permission_callback' => [Auth::class, 'tier1']],
        ]);
        register_rest_route($this->namespace, '/woo/payment-gateways/(?P<gateway_id>[a-zA-Z0-9_-]+)', [
            ['methods' => 'GET',       'callback' => [$this, 'get_payment_gateway'],    'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'PUT,PATCH', 'callback' => [$this, 'update_payment_gateway'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);

        // Taxes
        register_rest_route($this->namespace, '/woo/taxes/classes', [
            ['methods' => 'GET',  'callback' => [$this, 'list_tax_classes'],  'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'POST', 'callback' => [$this, 'create_tax_class'],  'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/woo/taxes/rates', [
            ['methods' => 'GET',  'callback' => [$this, 'list_tax_rates'],  'permission_callback' => [Auth::class, 'tier1'],
             'args' => ['class' => ['default' => ''], 'per_page' => ['default' => 50]]],
            ['methods' => 'POST', 'callback' => [$this, 'create_tax_rate'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/woo/taxes/rates/(?P<id>\d+)', [
            ['methods' => 'PUT,PATCH', 'callback' => [$this, 'update_tax_rate'], 'permission_callback' => [Auth::class, 'tier2']],
            ['methods' => 'DELETE',    'callback' => [$this, 'delete_tax_rate'], 'permission_callback' => [Auth::class, 'tier3']],
        ]);

        // Product categories, tags, attributes
        register_rest_route($this->namespace, '/woo/product-categories', [
            ['methods' => 'GET',  'callback' => [$this, 'list_product_categories'],  'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'POST', 'callback' => [$this, 'create_product_category'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/woo/product-categories/(?P<id>\d+)', [
            ['methods' => 'PUT,PATCH', 'callback' => [$this, 'update_product_category'], 'permission_callback' => [Auth::class, 'tier2']],
            ['methods' => 'DELETE',    'callback' => [$this, 'delete_product_category'], 'permission_callback' => [Auth::class, 'tier3']],
        ]);
        register_rest_route($this->namespace, '/woo/product-tags', [
            ['methods' => 'GET',  'callback' => [$this, 'list_product_tags'],  'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'POST', 'callback' => [$this, 'create_product_tag'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/woo/product-attributes', [
            ['methods' => 'GET',  'callback' => [$this, 'list_product_attributes'],  'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'POST', 'callback' => [$this, 'create_product_attribute'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/woo/product-attributes/(?P<attribute_id>\d+)/terms', [
            ['methods' => 'GET',  'callback' => [$this, 'list_attribute_terms'],  'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'POST', 'callback' => [$this, 'create_attribute_term'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);

        // Store settings
        register_rest_route($this->namespace, '/woo/settings', [
            ['methods' => 'GET',       'callback' => [$this, 'get_store_settings'],    'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'PUT,PATCH', 'callback' => [$this, 'update_store_settings'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/woo/settings/(?P<group>[a-zA-Z0-9_-]+)', [
            ['methods' => 'GET',       'callback' => [$this, 'get_settings_group'],    'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'PUT,PATCH', 'callback' => [$this, 'update_settings_group'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);

        // Webhooks
        register_rest_route($this->namespace, '/woo/webhooks', [
            ['methods' => 'GET',  'callback' => [$this, 'list_woo_webhooks'],  'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'POST', 'callback' => [$this, 'create_woo_webhook'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/woo/webhooks/(?P<id>\d+)', [
            ['methods' => 'GET',       'callback' => [$this, 'get_woo_webhook'],    'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'PUT,PATCH', 'callback' => [$this, 'update_woo_webhook'], 'permission_callback' => [Auth::class, 'tier2']],
            ['methods' => 'DELETE',    'callback' => [$this, 'delete_woo_webhook'], 'permission_callback' => [Auth::class, 'tier3']],
        ]);
    }

    // -------------------------------------------------------------------------
    // Products
    // -------------------------------------------------------------------------

    public function list_products(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }

        $args = [
            'post_type'      => 'product',
            'posts_per_page' => min((int) $r['per_page'], 100),
            'paged'          => max(1, (int) $r['page']),
            'post_status'    => sanitize_key((string) $r['status']),
        ];

        if ($s = trim((string) $r['search'])) {
            $args['s'] = $s;
        }

        $q       = new \WP_Query($args);
        $products = array_map(fn(\WP_Post $p) => $this->fmt_product($p), $q->posts);

        $this->log('woo_list_products', 'product', 0, ['count' => count($products)]);

        return $this->success([
            'products' => $products,
            'total'    => $q->found_posts,
            'pages'    => $q->max_num_pages,
        ]);
    }

    public function get_product(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }

        $post = get_post((int) $r['id']);
        if (!$post || $post->post_type !== 'product') {
            return $this->error('Product not found', 404);
        }

        return $this->success($this->fmt_product($post, true));
    }

    public function create_product(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }

        $d = (array) $r->get_json_params();

        $id = wp_insert_post([
            'post_title'   => sanitize_text_field((string) ($d['name'] ?? '')),
            'post_content' => wp_kses_post((string) ($d['description'] ?? '')),
            'post_excerpt' => sanitize_textarea_field((string) ($d['short_description'] ?? '')),
            'post_status'  => sanitize_key((string) ($d['status'] ?? 'draft')),
            'post_type'    => 'product',
        ], true);

        if (is_wp_error($id)) {
            return $this->error($id->get_error_message(), 500);
        }

        $this->apply_product_meta($id, $d);

        if (!empty($d['categories'])) {
            wp_set_object_terms($id, array_map('absint', (array) $d['categories']), 'product_cat');
        }

        if (!empty($d['tags'])) {
            wp_set_object_terms($id, array_map('sanitize_text_field', (array) $d['tags']), 'product_tag');
        }

        $this->log('woo_create_product', 'product', $id, ['name' => $d['name'] ?? ''], 2);

        return $this->success($this->fmt_product(get_post($id), true), 201);
    }

    public function update_product(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }

        $id   = (int) $r['id'];
        $post = get_post($id);

        if (!$post || $post->post_type !== 'product') {
            return $this->error('Product not found', 404);
        }

        $d = (array) $r->get_json_params();
        $u = ['ID' => $id];

        if (isset($d['name']))              $u['post_title']   = sanitize_text_field((string) $d['name']);
        if (isset($d['description']))       $u['post_content'] = wp_kses_post((string) $d['description']);
        if (isset($d['short_description'])) $u['post_excerpt'] = sanitize_textarea_field((string) $d['short_description']);
        if (isset($d['status']))            $u['post_status']  = sanitize_key((string) $d['status']);

        $res = wp_update_post($u, true);
        if (is_wp_error($res)) {
            return $this->error($res->get_error_message(), 500);
        }

        $this->apply_product_meta($id, $d);

        if (isset($d['categories'])) {
            wp_set_object_terms($id, array_map('absint', (array) $d['categories']), 'product_cat');
        }

        if (isset($d['tags'])) {
            wp_set_object_terms($id, array_map('sanitize_text_field', (array) $d['tags']), 'product_tag');
        }

        $this->log('woo_update_product', 'product', $id, ['fields' => array_keys($d)], 2);

        return $this->success($this->fmt_product(get_post($id), true));
    }

    public function delete_product(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }

        $id   = (int) $r['id'];
        $d    = (array) $r->get_json_params();
        $post = get_post($id);

        if (!$post || $post->post_type !== 'product') {
            return $this->error('Product not found', 404);
        }

        wp_delete_post($id, !empty($d['force']));
        $this->log('woo_delete_product', 'product', $id, [], 3);

        return $this->success(['deleted' => true, 'id' => $id]);
    }

    public function generate_description(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }

        $id   = (int) $r['id'];
        $post = get_post($id);

        if (!$post || $post->post_type !== 'product') {
            return $this->error('Product not found', 404);
        }

        $d     = (array) $r->get_json_params();
        $price = get_post_meta($id, '_price', true);
        $sku   = get_post_meta($id, '_sku', true);
        $cats  = wp_get_post_terms($id, 'product_cat', ['fields' => 'names']);

        $context = "Product name: {$post->post_title}";
        if ($price)            $context .= "\nPrice: {$price}";
        if ($sku)              $context .= "\nSKU: {$sku}";
        if (!empty($cats))     $context .= "\nCategories: " . implode(', ', $cats);
        if (!empty($d['notes'])) $context .= "\nAdditional notes: " . sanitize_textarea_field((string) $d['notes']);

        $type = (isset($d['type']) && $d['type'] === 'short') ? 'short' : 'full';

        $ai  = new Router();
        $res = $ai->complete(
            'You are an expert e-commerce copywriter. Write compelling, SEO-friendly product descriptions in British English.',
            "Write a " . ($type === 'short' ? '1-2 sentence short description' : 'full product description (150–300 words)') . " for:\n{$context}",
            ['provider' => $d['provider'] ?? '', 'temperature' => 0.7, 'max_tokens' => 600]
        );

        if (!empty($res['error'])) {
            return $this->error($res['error'], 500);
        }

        if (!empty($d['auto_apply'])) {
            $field = ($type === 'short') ? 'post_excerpt' : 'post_content';
            wp_update_post(['ID' => $id, $field => wp_kses_post($res['content'])]);
            $res['applied'] = true;
        }

        $this->log('woo_gen_description', 'product', $id, ['type' => $type, 'applied' => !empty($res['applied'])], 2);

        return $this->success($res);
    }

    public function bulk_products(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }

        $d      = (array) $r->get_json_params();
        $action = sanitize_key((string) ($d['action'] ?? ''));
        $ids    = array_map('absint', (array) ($d['ids'] ?? []));

        if (empty($ids) || empty($action)) {
            return $this->error('action and ids required');
        }

        $results = [];
        foreach ($ids as $id) {
            match ($action) {
                'publish' => wp_update_post(['ID' => $id, 'post_status' => 'publish']),
                'draft'   => wp_update_post(['ID' => $id, 'post_status' => 'draft']),
                'trash'   => wp_trash_post($id),
                'delete'  => wp_delete_post($id, true),
                default   => null,
            };
            $results[] = ['id' => $id, 'done' => true];
        }

        $this->log('woo_bulk_products', 'product', 0, ['action' => $action, 'count' => count($ids)], $action === 'delete' ? 3 : 2);

        return $this->success($results);
    }

    // -------------------------------------------------------------------------
    // Orders
    // -------------------------------------------------------------------------

    public function list_orders(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }

        $status = sanitize_key((string) $r['status']);
        $args   = [
            'post_type'      => 'shop_order',
            'posts_per_page' => min((int) $r['per_page'], 100),
            'paged'          => max(1, (int) $r['page']),
            'post_status'    => $status === 'any' ? array_keys(wc_get_order_statuses()) : 'wc-' . ltrim($status, 'wc-'),
        ];

        $q = new \WP_Query($args);

        $orders = array_map(function (\WP_Post $p): array {
            $order = wc_get_order($p->ID);
            return $order ? $this->fmt_order($order) : ['id' => $p->ID];
        }, $q->posts);

        $this->log('woo_list_orders', 'order', 0, ['count' => count($orders)]);

        return $this->success([
            'orders' => $orders,
            'total'  => $q->found_posts,
            'pages'  => $q->max_num_pages,
        ]);
    }

    public function get_order(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }

        $order = wc_get_order((int) $r['id']);
        if (!$order) {
            return $this->error('Order not found', 404);
        }

        return $this->success($this->fmt_order($order, true));
    }

    public function update_order(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }

        $order = wc_get_order((int) $r['id']);
        if (!$order) {
            return $this->error('Order not found', 404);
        }

        $d = (array) $r->get_json_params();

        if (!empty($d['status'])) {
            $order->update_status(sanitize_key((string) $d['status']));
        }

        if (!empty($d['note'])) {
            $order->add_order_note(sanitize_textarea_field((string) $d['note']));
        }

        $order->save();

        $this->log('woo_update_order', 'order', $order->get_id(), ['fields' => array_keys($d)], 2);

        return $this->success($this->fmt_order($order, true));
    }

    // -------------------------------------------------------------------------
    // Customers
    // -------------------------------------------------------------------------

    public function list_customers(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }

        $args = [
            'role'   => 'customer',
            'number' => min((int) $r['per_page'], 100),
            'paged'  => max(1, (int) $r['page']),
        ];

        if ($s = trim((string) $r['search'])) {
            $args['search']         = '*' . $s . '*';
            $args['search_columns'] = ['user_login', 'user_email', 'display_name'];
        }

        $query     = new \WP_User_Query($args);
        $customers = array_map(fn(\WP_User $u) => $this->fmt_customer($u), $query->get_results());

        $this->log('woo_list_customers', 'customer', 0, ['count' => count($customers)]);

        return $this->success([
            'customers' => $customers,
            'total'     => $query->get_total(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Stats
    // -------------------------------------------------------------------------

    public function stats(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }

        global $wpdb;

        $total_products = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish'"
        );

        $total_orders = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='shop_order'"
        );

        $pending_orders = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='shop_order' AND post_status='wc-pending'"
        );

        $processing_orders = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='shop_order' AND post_status='wc-processing'"
        );

        $revenue_30d = (float) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT SUM(pm.meta_value) FROM {$wpdb->posts} p
                 JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_order_total'
                 WHERE p.post_type = 'shop_order'
                   AND p.post_status IN ('wc-completed','wc-processing')
                   AND p.post_date >= %s",
                gmdate('Y-m-d', strtotime('-30 days'))
            )
        );

        $out_of_stock = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_stock_status'
             WHERE p.post_type = 'product' AND p.post_status = 'publish' AND pm.meta_value = 'outofstock'"
        );

        $this->log('woo_stats', 'store', 0, []);

        return $this->success([
            'total_products'    => $total_products,
            'total_orders'      => $total_orders,
            'pending_orders'    => $pending_orders,
            'processing_orders' => $processing_orders,
            'revenue_30d'       => round($revenue_30d, 2),
            'out_of_stock'      => $out_of_stock,
            'currency'          => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '',
        ]);
    }

    // -------------------------------------------------------------------------
    // Formatters
    // -------------------------------------------------------------------------

    private function fmt_product(\WP_Post $p, bool $full = false): array {
        $data = [
            'id'                => $p->ID,
            'name'              => $p->post_title,
            'status'            => $p->post_status,
            'slug'              => $p->post_name,
            'price'             => get_post_meta($p->ID, '_price', true),
            'regular_price'     => get_post_meta($p->ID, '_regular_price', true),
            'sale_price'        => get_post_meta($p->ID, '_sale_price', true),
            'sku'               => get_post_meta($p->ID, '_sku', true),
            'stock_status'      => get_post_meta($p->ID, '_stock_status', true),
            'stock_quantity'    => get_post_meta($p->ID, '_stock', true),
            'manage_stock'      => (bool) get_post_meta($p->ID, '_manage_stock', true),
            'categories'        => wp_get_post_terms($p->ID, 'product_cat', ['fields' => 'names']),
            'tags'              => wp_get_post_terms($p->ID, 'product_tag', ['fields' => 'names']),
            'permalink'         => get_permalink($p->ID),
            'modified'          => $p->post_modified_gmt,
        ];

        if ($full) {
            $data['description']       = $p->post_content;
            $data['short_description'] = $p->post_excerpt;
            $data['featured_image']    = get_the_post_thumbnail_url($p->ID, 'full') ?: null;
            $data['created']           = $p->post_date_gmt;
        }

        return $data;
    }

    private function fmt_order(\WC_Order $order, bool $full = false): array {
        $data = [
            'id'            => $order->get_id(),
            'status'        => $order->get_status(),
            'total'         => $order->get_total(),
            'currency'      => $order->get_currency(),
            'customer_id'   => $order->get_customer_id(),
            'customer_email'=> $order->get_billing_email(),
            'created'       => $order->get_date_created()?->format('c'),
            'modified'      => $order->get_date_modified()?->format('c'),
            'item_count'    => $order->get_item_count(),
        ];

        if ($full) {
            $items = [];
            foreach ($order->get_items() as $item) {
                /** @var \WC_Order_Item_Product $item */
                $items[] = [
                    'product_id' => $item->get_product_id(),
                    'name'       => $item->get_name(),
                    'quantity'   => $item->get_quantity(),
                    'total'      => $item->get_total(),
                ];
            }
            $data['items']           = $items;
            $data['billing_address'] = $order->get_formatted_billing_address();
            $data['payment_method']  = $order->get_payment_method_title();
        }

        return $data;
    }

    private function fmt_customer(\WP_User $u): array {
        return [
            'id'           => $u->ID,
            'email'        => $u->user_email,
            'display_name' => $u->display_name,
            'registered'   => $u->user_registered,
            'orders_count' => (int) wc_get_customer_order_count($u->ID),
            'total_spent'  => (float) wc_get_customer_total_spent($u->ID),
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function apply_product_meta(int $id, array $d): void {
        $meta_map = [
            'regular_price' => '_regular_price',
            'sale_price'    => '_sale_price',
            'price'         => '_price',
            'sku'           => '_sku',
            'stock_status'  => '_stock_status',
            'stock'         => '_stock',
            'manage_stock'  => '_manage_stock',
            'weight'        => '_weight',
            'length'        => '_length',
            'width'         => '_width',
            'height'        => '_height',
        ];

        foreach ($meta_map as $key => $meta_key) {
            if (!array_key_exists($key, $d)) {
                continue;
            }
            $value = in_array($key, ['regular_price', 'sale_price', 'price', 'weight', 'length', 'width', 'height'], true)
                ? (string) floatval($d[$key])
                : sanitize_text_field((string) $d[$key]);
            update_post_meta($id, $meta_key, $value);
        }

        // Keep _price in sync with regular price when not on sale
        if (isset($d['regular_price']) && !isset($d['sale_price'])) {
            $sale = get_post_meta($id, '_sale_price', true);
            if (empty($sale)) {
                update_post_meta($id, '_price', (string) floatval($d['regular_price']));
            }
        }
    }

    private function is_woo_active(): bool {
        return class_exists('WooCommerce') || function_exists('WC');
    }

    // =========================================================================
    // Product variations
    // =========================================================================

    public function list_variations(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }
        $product_id = (int) $r['id'];
        $variations = get_posts([
            'post_parent' => $product_id,
            'post_type'   => 'product_variation',
            'post_status' => 'publish',
            'numberposts' => 200,
        ]);

        return $this->success([
            'product_id' => $product_id,
            'variations' => array_map(function (\WP_Post $v): array {
                return [
                    'id'            => $v->ID,
                    'sku'           => get_post_meta($v->ID, '_sku', true),
                    'regular_price' => get_post_meta($v->ID, '_regular_price', true),
                    'sale_price'    => get_post_meta($v->ID, '_sale_price', true),
                    'stock_status'  => get_post_meta($v->ID, '_stock_status', true),
                    'stock_qty'     => get_post_meta($v->ID, '_stock', true),
                    'attributes'    => $this->get_variation_attributes($v->ID),
                ];
            }, $variations),
        ]);
    }

    private function get_variation_attributes(int $id): array {
        $meta  = get_post_meta($id);
        $attrs = [];
        foreach ($meta as $key => $values) {
            if (str_starts_with($key, 'attribute_')) {
                $attrs[str_replace('attribute_', '', $key)] = $values[0] ?? '';
            }
        }
        return $attrs;
    }

    // =========================================================================
    // Order notes
    // =========================================================================

    public function list_order_notes(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }
        $order_id = (int) $r['id'];
        $order    = wc_get_order($order_id);
        if (!$order) {
            return $this->error('Order not found', 404);
        }

        $notes = wc_get_order_notes(['order_id' => $order_id]);

        return $this->success([
            'order_id' => $order_id,
            'notes'    => array_map(function ($note): array {
                return [
                    'id'            => (int) $note->comment_ID,
                    'note'          => $note->comment_content,
                    'added_by'      => $note->comment_author,
                    'date'          => $note->comment_date_gmt,
                    'customer_note' => (bool) get_comment_meta($note->comment_ID, 'is_customer_note', true),
                ];
            }, $notes),
        ]);
    }

    public function add_order_note(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }
        $order_id = (int) $r['id'];
        $order    = wc_get_order($order_id);
        if (!$order) {
            return $this->error('Order not found', 404);
        }

        $d             = (array) $r->get_json_params();
        $note          = sanitize_textarea_field((string) ($d['note'] ?? ''));
        $customer_note = (bool) ($d['customer_note'] ?? false);

        if (empty($note)) {
            return $this->error('note is required');
        }

        $note_id = $order->add_order_note($note, $customer_note ? 1 : 0);
        $this->log('woo_add_order_note', 'order', $order_id, ['customer_note' => $customer_note], 2);

        return $this->success(['note_id' => $note_id, 'order_id' => $order_id], 201);
    }

    // =========================================================================
    // Order refunds
    // =========================================================================

    public function list_refunds(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }
        $order_id = (int) $r['id'];
        $order    = wc_get_order($order_id);
        if (!$order) {
            return $this->error('Order not found', 404);
        }

        $refunds = $order->get_refunds();

        return $this->success([
            'order_id' => $order_id,
            'refunds'  => array_map(function (\WC_Order_Refund $refund): array {
                return [
                    'id'     => $refund->get_id(),
                    'amount' => $refund->get_amount(),
                    'reason' => $refund->get_reason(),
                    'date'   => $refund->get_date_created()?->format('c'),
                ];
            }, $refunds),
        ]);
    }

    public function create_refund(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }
        $order_id = (int) $r['id'];
        $order    = wc_get_order($order_id);
        if (!$order) {
            return $this->error('Order not found', 404);
        }

        $d      = (array) $r->get_json_params();
        $amount = (float) ($d['amount'] ?? 0);
        $reason = sanitize_text_field((string) ($d['reason'] ?? ''));

        if ($amount <= 0) {
            return $this->error('amount must be positive');
        }

        $refund = wc_create_refund([
            'order_id' => $order_id,
            'amount'   => $amount,
            'reason'   => $reason,
        ]);

        if (is_wp_error($refund)) {
            return $this->error($refund->get_error_message(), 500);
        }

        $this->log('woo_create_refund', 'order', $order_id, ['amount' => $amount, 'reason' => $reason], 2);

        return $this->success(['refund_id' => $refund->get_id(), 'amount' => $amount], 201);
    }

    // =========================================================================
    // Coupons
    // =========================================================================

    public function list_coupons(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }
        $pp   = min((int) ($r['per_page'] ?? 20), 100);
        $page = max(1, (int) ($r['page'] ?? 1));

        $args = [
            'post_type'      => 'shop_coupon',
            'post_status'    => 'publish',
            'posts_per_page' => $pp,
            'paged'          => $page,
        ];

        $query    = new \WP_Query($args);
        $coupons  = [];

        foreach ($query->posts as $post) {
            $coupons[] = $this->fmt_coupon_post($post);
        }

        return $this->success([
            'coupons'  => $coupons,
            'total'    => (int) $query->found_posts,
            'page'     => $page,
            'per_page' => $pp,
        ]);
    }

    public function get_coupon(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }
        $coupon = new \WC_Coupon((int) $r['id']);
        if (!$coupon->get_id()) {
            return $this->error('Coupon not found', 404);
        }
        return $this->success($this->fmt_coupon($coupon));
    }

    public function create_coupon(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }
        $d    = (array) $r->get_json_params();
        $code = sanitize_text_field((string) ($d['code'] ?? ''));
        if (empty($code)) {
            return $this->error('code is required');
        }

        $coupon = new \WC_Coupon();
        $coupon->set_code($code);
        $this->apply_coupon_fields($coupon, $d);
        $id = $coupon->save();

        $this->log('woo_create_coupon', 'coupon', $id, ['code' => $code], 2);
        return $this->success($this->fmt_coupon($coupon), 201);
    }

    public function update_coupon(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }
        $coupon = new \WC_Coupon((int) $r['id']);
        if (!$coupon->get_id()) {
            return $this->error('Coupon not found', 404);
        }
        $d = (array) $r->get_json_params();
        $this->apply_coupon_fields($coupon, $d);
        $coupon->save();

        $this->log('woo_update_coupon', 'coupon', $coupon->get_id(), [], 2);
        return $this->success($this->fmt_coupon($coupon));
    }

    public function delete_coupon(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }
        $coupon = new \WC_Coupon((int) $r['id']);
        if (!$coupon->get_id()) {
            return $this->error('Coupon not found', 404);
        }
        $coupon->delete(true);
        $this->log('woo_delete_coupon', 'coupon', (int) $r['id'], [], 3);
        return $this->success(['deleted' => true, 'id' => (int) $r['id']]);
    }

    private function fmt_coupon_post(\WP_Post $p): array {
        return $this->fmt_coupon(new \WC_Coupon($p->ID));
    }

    private function fmt_coupon(\WC_Coupon $c): array {
        return [
            'id'                 => $c->get_id(),
            'code'               => $c->get_code(),
            'discount_type'      => $c->get_discount_type(),
            'amount'             => $c->get_amount(),
            'free_shipping'      => $c->get_free_shipping(),
            'expiry_date'        => $c->get_date_expires()?->format('c'),
            'usage_limit'        => $c->get_usage_limit(),
            'usage_count'        => $c->get_usage_count(),
            'minimum_amount'     => $c->get_minimum_amount(),
            'maximum_amount'     => $c->get_maximum_amount(),
            'individual_use'     => $c->get_individual_use(),
        ];
    }

    private function apply_coupon_fields(\WC_Coupon $coupon, array $d): void {
        if (isset($d['discount_type']))  $coupon->set_discount_type(sanitize_key($d['discount_type']));
        if (isset($d['amount']))         $coupon->set_amount((string) floatval($d['amount']));
        if (isset($d['free_shipping']))  $coupon->set_free_shipping((bool) $d['free_shipping']);
        if (isset($d['usage_limit']))    $coupon->set_usage_limit((int) $d['usage_limit']);
        if (isset($d['minimum_amount'])) $coupon->set_minimum_amount((string) floatval($d['minimum_amount']));
        if (isset($d['maximum_amount'])) $coupon->set_maximum_amount((string) floatval($d['maximum_amount']));
        if (isset($d['individual_use'])) $coupon->set_individual_use((bool) $d['individual_use']);
        if (!empty($d['expiry_date']))   $coupon->set_date_expires(strtotime((string) $d['expiry_date']));
    }

    // =========================================================================
    // Shipping zones & methods
    // =========================================================================

    public function list_shipping_zones(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }
        $data_store = \WC_Data_Store::load('shipping-zone');
        $raw_zones  = $data_store->get_zones();
        $zones      = array_map([$this, 'fmt_shipping_zone'], $raw_zones);

        return $this->success(['zones' => $zones]);
    }

    public function get_shipping_zone(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }
        $zone = new \WC_Shipping_Zone((int) $r['zone_id']);
        if (!$zone->get_id()) {
            return $this->error('Shipping zone not found', 404);
        }
        return $this->success(array_merge($this->fmt_shipping_zone($zone), [
            'locations' => $zone->get_zone_locations(),
        ]));
    }

    public function create_shipping_zone(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }
        $d    = (array) $r->get_json_params();
        $name = sanitize_text_field((string) ($d['name'] ?? ''));
        if (empty($name)) {
            return $this->error('name is required');
        }

        $zone = new \WC_Shipping_Zone();
        $zone->set_zone_name($name);
        if (!empty($d['order'])) $zone->set_zone_order((int) $d['order']);
        $zone->save();

        if (!empty($d['locations']) && is_array($d['locations'])) {
            $zone->set_locations($d['locations']);
            $zone->save();
        }

        $this->log('woo_create_shipping_zone', 'shipping', $zone->get_id(), ['name' => $name], 2);
        return $this->success($this->fmt_shipping_zone($zone), 201);
    }

    public function update_shipping_zone(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }
        $zone = new \WC_Shipping_Zone((int) $r['zone_id']);
        if (!$zone->get_id()) {
            return $this->error('Shipping zone not found', 404);
        }
        $d = (array) $r->get_json_params();
        if (isset($d['name']))      $zone->set_zone_name(sanitize_text_field($d['name']));
        if (isset($d['order']))     $zone->set_zone_order((int) $d['order']);
        if (isset($d['locations'])) $zone->set_locations((array) $d['locations']);
        $zone->save();

        $this->log('woo_update_shipping_zone', 'shipping', $zone->get_id(), [], 2);
        return $this->success($this->fmt_shipping_zone($zone));
    }

    public function delete_shipping_zone(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }
        $zone = new \WC_Shipping_Zone((int) $r['zone_id']);
        if (!$zone->get_id()) {
            return $this->error('Shipping zone not found', 404);
        }
        $zone->delete();
        $this->log('woo_delete_shipping_zone', 'shipping', (int) $r['zone_id'], [], 3);
        return $this->success(['deleted' => true, 'zone_id' => (int) $r['zone_id']]);
    }

    public function list_shipping_methods(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }
        $zone = new \WC_Shipping_Zone((int) $r['zone_id']);
        if (!$zone->get_id()) {
            return $this->error('Shipping zone not found', 404);
        }
        $methods = $zone->get_shipping_methods();
        return $this->success([
            'zone_id' => (int) $r['zone_id'],
            'methods' => array_map([$this, 'fmt_shipping_method'], $methods),
        ]);
    }

    public function add_shipping_method(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }
        $zone = new \WC_Shipping_Zone((int) $r['zone_id']);
        if (!$zone->get_id()) {
            return $this->error('Shipping zone not found', 404);
        }
        $d       = (array) $r->get_json_params();
        $type    = sanitize_key((string) ($d['method_id'] ?? ''));
        if (empty($type)) {
            return $this->error('method_id is required (e.g. flat_rate, free_shipping, local_pickup)');
        }

        $instance_id = $zone->add_shipping_method($type);
        if (!$instance_id) {
            return $this->error('Could not add shipping method', 500);
        }

        // Apply settings if provided
        if (!empty($d['settings']) && is_array($d['settings'])) {
            $methods = $zone->get_shipping_methods();
            foreach ($methods as $method) {
                if ($method->get_instance_id() === $instance_id) {
                    $method->set_post_data($d['settings']);
                    $method->process_admin_options();
                    break;
                }
            }
        }

        $this->log('woo_add_shipping_method', 'shipping', $instance_id, ['zone_id' => $zone->get_id(), 'type' => $type], 2);
        return $this->success(['instance_id' => $instance_id, 'method_id' => $type], 201);
    }

    public function update_shipping_method(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }
        $zone        = new \WC_Shipping_Zone((int) $r['zone_id']);
        $instance_id = (int) $r['instance_id'];
        $d           = (array) $r->get_json_params();

        $methods = $zone->get_shipping_methods();
        foreach ($methods as $method) {
            if ($method->get_instance_id() === $instance_id) {
                if (isset($d['enabled'])) {
                    $method->instance_settings['enabled'] = (bool) $d['enabled'] ? 'yes' : 'no';
                }
                if (!empty($d['settings']) && is_array($d['settings'])) {
                    $method->set_post_data($d['settings']);
                    $method->process_admin_options();
                }
                $method->instance_settings = $method->instance_settings;
                update_option($method->get_instance_option_key(), $method->instance_settings);
                $this->log('woo_update_shipping_method', 'shipping', $instance_id, ['zone_id' => $zone->get_id()], 2);
                return $this->success($this->fmt_shipping_method($method));
            }
        }
        return $this->error('Shipping method instance not found', 404);
    }

    public function delete_shipping_method(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }
        $zone        = new \WC_Shipping_Zone((int) $r['zone_id']);
        $instance_id = (int) $r['instance_id'];
        $zone->delete_shipping_method($instance_id);
        $this->log('woo_delete_shipping_method', 'shipping', $instance_id, ['zone_id' => $zone->get_id()], 3);
        return $this->success(['deleted' => true, 'instance_id' => $instance_id]);
    }

    private function fmt_shipping_zone(\WC_Shipping_Zone $zone): array {
        return [
            'id'    => $zone->get_id(),
            'name'  => $zone->get_zone_name(),
            'order' => $zone->get_zone_order(),
        ];
    }

    private function fmt_shipping_method(\WC_Shipping_Method $m): array {
        return [
            'instance_id' => $m->get_instance_id(),
            'method_id'   => $m->id,
            'title'       => $m->get_title(),
            'enabled'     => $m->is_enabled(),
            'settings'    => $m->instance_settings ?? [],
        ];
    }

    // =========================================================================
    // Payment gateways
    // =========================================================================

    public function list_payment_gateways(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }
        $gateways = WC()->payment_gateways()->payment_gateways();
        return $this->success([
            'gateways' => array_values(array_map([$this, 'fmt_gateway'], $gateways)),
        ]);
    }

    public function get_payment_gateway(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }
        $id      = sanitize_key((string) $r['gateway_id']);
        $gateways= WC()->payment_gateways()->payment_gateways();
        if (!isset($gateways[$id])) {
            return $this->error('Payment gateway not found', 404);
        }
        $gw   = $gateways[$id];
        $data = $this->fmt_gateway($gw);
        $data['form_fields'] = array_keys($gw->form_fields ?? []);
        $data['settings']    = $gw->settings ?? [];
        return $this->success($data);
    }

    public function update_payment_gateway(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }
        $id      = sanitize_key((string) $r['gateway_id']);
        $gateways= WC()->payment_gateways()->payment_gateways();
        if (!isset($gateways[$id])) {
            return $this->error('Payment gateway not found', 404);
        }

        $gw = $gateways[$id];
        $d  = (array) $r->get_json_params();

        // Toggle enabled/disabled
        if (isset($d['enabled'])) {
            $gw->settings['enabled'] = (bool) $d['enabled'] ? 'yes' : 'no';
        }

        // Apply individual settings fields
        if (!empty($d['settings']) && is_array($d['settings'])) {
            foreach ($d['settings'] as $k => $v) {
                $gw->settings[sanitize_key($k)] = sanitize_text_field((string) $v);
            }
        }

        update_option($gw->get_option_key(), apply_filters('woocommerce_settings_api_sanitized_fields_' . $gw->id, $gw->settings));
        $this->log('woo_update_payment_gateway', 'gateway', 0, ['gateway' => $id], 2);

        return $this->success($this->fmt_gateway($gw));
    }

    private function fmt_gateway(\WC_Payment_Gateway $gw): array {
        return [
            'id'          => $gw->id,
            'title'       => $gw->get_title(),
            'description' => $gw->get_description(),
            'enabled'     => $gw->is_available(),
            'order'       => (int) $gw->order,
        ];
    }

    // =========================================================================
    // Tax classes & rates
    // =========================================================================

    public function list_tax_classes(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }
        $classes = \WC_Tax::get_tax_classes();
        array_unshift($classes, 'Standard Rate');
        return $this->success(['tax_classes' => $classes]);
    }

    public function create_tax_class(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }
        $d    = (array) $r->get_json_params();
        $name = sanitize_text_field((string) ($d['name'] ?? ''));
        if (empty($name)) {
            return $this->error('name is required');
        }

        $result = \WC_Tax::create_tax_class($name);
        if (is_wp_error($result)) {
            return $this->error($result->get_error_message(), 400);
        }
        $this->log('woo_create_tax_class', 'tax', 0, ['name' => $name], 2);
        return $this->success($result, 201);
    }

    public function list_tax_rates(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }
        $class   = sanitize_key((string) ($r['class'] ?? ''));
        $pp      = min((int) ($r['per_page'] ?? 50), 200);
        $rates   = \WC_Tax::get_rates_for_tax_class($class);
        return $this->success(['rates' => array_values($rates), 'total' => count($rates)]);
    }

    public function create_tax_rate(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }
        $d = (array) $r->get_json_params();

        $rate_id = \WC_Tax::_insert_tax_rate([
            'tax_rate_country'  => strtoupper(sanitize_text_field((string) ($d['country'] ?? ''))),
            'tax_rate_state'    => sanitize_text_field((string) ($d['state'] ?? '')),
            'tax_rate'          => number_format((float) ($d['rate'] ?? 0), 4, '.', ''),
            'tax_rate_name'     => sanitize_text_field((string) ($d['name'] ?? 'Tax')),
            'tax_rate_priority' => (int) ($d['priority'] ?? 1),
            'tax_rate_compound' => (int) (bool) ($d['compound'] ?? false),
            'tax_rate_shipping' => (int) (bool) ($d['shipping'] ?? true),
            'tax_rate_order'    => (int) ($d['order'] ?? 0),
            'tax_rate_class'    => sanitize_key((string) ($d['class'] ?? '')),
        ]);

        $this->log('woo_create_tax_rate', 'tax', $rate_id, [], 2);
        return $this->success(['rate_id' => $rate_id], 201);
    }

    public function update_tax_rate(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }
        $rate_id = (int) $r['id'];
        $d       = (array) $r->get_json_params();

        $update = [];
        if (isset($d['rate']))     $update['tax_rate']          = number_format((float) $d['rate'], 4, '.', '');
        if (isset($d['name']))     $update['tax_rate_name']     = sanitize_text_field($d['name']);
        if (isset($d['country']))  $update['tax_rate_country']  = strtoupper(sanitize_text_field($d['country']));
        if (isset($d['shipping'])) $update['tax_rate_shipping'] = (int) (bool) $d['shipping'];

        \WC_Tax::_update_tax_rate($rate_id, $update);
        $this->log('woo_update_tax_rate', 'tax', $rate_id, [], 2);
        return $this->success(['updated' => true, 'rate_id' => $rate_id]);
    }

    public function delete_tax_rate(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }
        $rate_id = (int) $r['id'];
        \WC_Tax::_delete_tax_rate($rate_id);
        $this->log('woo_delete_tax_rate', 'tax', $rate_id, [], 3);
        return $this->success(['deleted' => true, 'rate_id' => $rate_id]);
    }

    // =========================================================================
    // Product categories, tags, attributes
    // =========================================================================

    public function list_product_categories(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }
        $terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 500]);
        if (is_wp_error($terms)) {
            return $this->error($terms->get_error_message(), 500);
        }
        return $this->success(['categories' => array_map([$this, 'fmt_term'], $terms)]);
    }

    public function create_product_category(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }
        $d    = (array) $r->get_json_params();
        $name = sanitize_text_field((string) ($d['name'] ?? ''));
        if (empty($name)) {
            return $this->error('name is required');
        }

        $args = ['description' => sanitize_textarea_field((string) ($d['description'] ?? ''))];
        if (!empty($d['parent'])) $args['parent'] = (int) $d['parent'];
        if (!empty($d['slug']))   $args['slug']   = sanitize_title($d['slug']);

        $result = wp_insert_term($name, 'product_cat', $args);
        if (is_wp_error($result)) {
            return $this->error($result->get_error_message(), 400);
        }

        $this->log('woo_create_product_category', 'taxonomy', $result['term_id'], ['name' => $name], 2);
        return $this->success(['id' => $result['term_id'], 'name' => $name], 201);
    }

    public function update_product_category(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }
        $id   = (int) $r['id'];
        $d    = (array) $r->get_json_params();
        $args = [];
        if (isset($d['name']))        $args['name']        = sanitize_text_field($d['name']);
        if (isset($d['description'])) $args['description'] = sanitize_textarea_field($d['description']);
        if (isset($d['parent']))      $args['parent']      = (int) $d['parent'];

        $result = wp_update_term($id, 'product_cat', $args);
        if (is_wp_error($result)) {
            return $this->error($result->get_error_message(), 400);
        }
        $this->log('woo_update_product_category', 'taxonomy', $id, [], 2);
        return $this->success(['updated' => true, 'id' => $id]);
    }

    public function delete_product_category(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }
        $result = wp_delete_term((int) $r['id'], 'product_cat');
        if (is_wp_error($result)) {
            return $this->error($result->get_error_message(), 400);
        }
        $this->log('woo_delete_product_category', 'taxonomy', (int) $r['id'], [], 3);
        return $this->success(['deleted' => true]);
    }

    public function list_product_tags(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }
        $terms = get_terms(['taxonomy' => 'product_tag', 'hide_empty' => false, 'number' => 500]);
        if (is_wp_error($terms)) {
            return $this->error($terms->get_error_message(), 500);
        }
        return $this->success(['tags' => array_map([$this, 'fmt_term'], $terms)]);
    }

    public function create_product_tag(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }
        $d    = (array) $r->get_json_params();
        $name = sanitize_text_field((string) ($d['name'] ?? ''));
        if (empty($name)) {
            return $this->error('name is required');
        }
        $result = wp_insert_term($name, 'product_tag');
        if (is_wp_error($result)) {
            return $this->error($result->get_error_message(), 400);
        }
        $this->log('woo_create_product_tag', 'taxonomy', $result['term_id'], ['name' => $name], 2);
        return $this->success(['id' => $result['term_id'], 'name' => $name], 201);
    }

    public function list_product_attributes(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }
        $attributes = wc_get_attribute_taxonomies();
        return $this->success([
            'attributes' => array_map(function ($attr): array {
                return [
                    'id'       => (int) $attr->attribute_id,
                    'name'     => $attr->attribute_label,
                    'slug'     => $attr->attribute_name,
                    'type'     => $attr->attribute_type,
                    'orderby'  => $attr->attribute_orderby,
                    'has_archives' => (bool) $attr->attribute_public,
                ];
            }, $attributes),
        ]);
    }

    public function create_product_attribute(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }
        $d    = (array) $r->get_json_params();
        $name = sanitize_text_field((string) ($d['name'] ?? ''));
        $slug = sanitize_title((string) ($d['slug'] ?? $name));
        if (empty($name)) {
            return $this->error('name is required');
        }

        $result = wc_create_attribute([
            'name'         => $name,
            'slug'         => $slug,
            'type'         => sanitize_key((string) ($d['type'] ?? 'select')),
            'order_by'     => sanitize_key((string) ($d['orderby'] ?? 'menu_order')),
            'has_archives' => (bool) ($d['has_archives'] ?? false),
        ]);

        if (is_wp_error($result)) {
            return $this->error($result->get_error_message(), 400);
        }
        $this->log('woo_create_product_attribute', 'taxonomy', $result, ['name' => $name], 2);
        return $this->success(['attribute_id' => $result, 'name' => $name], 201);
    }

    public function list_attribute_terms(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }
        $attribute_id = (int) $r['attribute_id'];
        $attribute    = wc_get_attribute($attribute_id);
        if (!$attribute) {
            return $this->error('Attribute not found', 404);
        }
        $taxonomy = wc_attribute_taxonomy_name($attribute->slug);
        $terms    = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
        if (is_wp_error($terms)) {
            return $this->error($terms->get_error_message(), 500);
        }
        return $this->success(['attribute_id' => $attribute_id, 'terms' => array_map([$this, 'fmt_term'], $terms)]);
    }

    public function create_attribute_term(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }
        $attribute_id = (int) $r['attribute_id'];
        $attribute    = wc_get_attribute($attribute_id);
        if (!$attribute) {
            return $this->error('Attribute not found', 404);
        }
        $taxonomy = wc_attribute_taxonomy_name($attribute->slug);
        $d        = (array) $r->get_json_params();
        $name     = sanitize_text_field((string) ($d['name'] ?? ''));
        if (empty($name)) {
            return $this->error('name is required');
        }
        $result = wp_insert_term($name, $taxonomy);
        if (is_wp_error($result)) {
            return $this->error($result->get_error_message(), 400);
        }
        $this->log('woo_create_attribute_term', 'taxonomy', $result['term_id'], ['name' => $name], 2);
        return $this->success(['id' => $result['term_id'], 'name' => $name], 201);
    }

    private function fmt_term(\WP_Term $t): array {
        return ['id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'count' => $t->count];
    }

    // =========================================================================
    // Store settings
    // =========================================================================

    public function get_store_settings(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }
        return $this->success([
            'general' => [
                'store_address'      => get_option('woocommerce_store_address', ''),
                'store_city'         => get_option('woocommerce_store_city', ''),
                'store_postcode'     => get_option('woocommerce_store_postcode', ''),
                'store_country'      => get_option('woocommerce_default_country', ''),
                'currency'           => get_option('woocommerce_currency', 'GBP'),
                'currency_position'  => get_option('woocommerce_currency_pos', 'left'),
                'thousand_separator' => get_option('woocommerce_price_thousand_sep', ','),
                'decimal_separator'  => get_option('woocommerce_price_decimal_sep', '.'),
                'decimals'           => get_option('woocommerce_price_num_decimals', 2),
            ],
            'catalog' => [
                'shop_page_id'        => get_option('woocommerce_shop_page_id', ''),
                'products_per_page'   => get_option('woocommerce_catalog_columns', 4),
                'enable_reviews'      => get_option('woocommerce_enable_reviews', 'yes'),
                'review_rating_req'   => get_option('woocommerce_review_rating_required', 'no'),
            ],
            'inventory' => [
                'manage_stock'        => get_option('woocommerce_manage_stock', 'yes'),
                'hold_stock_minutes'  => get_option('woocommerce_hold_stock_minutes', 60),
                'low_stock_threshold' => get_option('woocommerce_notify_low_stock_amount', 2),
                'out_of_stock_threshold' => get_option('woocommerce_notify_no_stock_amount', 0),
                'out_of_stock_visibility' => get_option('woocommerce_hide_out_of_stock_items', 'no'),
            ],
            'tax' => [
                'calc_taxes'          => get_option('woocommerce_calc_taxes', 'no'),
                'prices_include_tax'  => get_option('woocommerce_prices_include_tax', 'no'),
                'tax_based_on'        => get_option('woocommerce_tax_based_on', 'shipping'),
                'tax_display_shop'    => get_option('woocommerce_tax_display_shop', 'excl'),
                'tax_display_cart'    => get_option('woocommerce_tax_display_cart', 'excl'),
            ],
            'shipping' => [
                'ship_to_countries'   => get_option('woocommerce_ship_to_countries', ''),
                'default_country'     => get_option('woocommerce_default_country', ''),
                'calc_shipping'       => get_option('woocommerce_calc_shipping', 'yes'),
            ],
            'checkout' => [
                'checkout_page_id'    => get_option('woocommerce_checkout_page_id', ''),
                'cart_page_id'        => get_option('woocommerce_cart_page_id', ''),
                'terms_page_id'       => get_option('woocommerce_terms_page_id', ''),
                'force_ssl_checkout'  => get_option('woocommerce_force_ssl_checkout', 'no'),
                'guest_checkout'      => get_option('woocommerce_enable_guest_checkout', 'yes'),
                'login_checkout'      => get_option('woocommerce_enable_checkout_login_reminder', 'yes'),
            ],
            'email' => [
                'email_from_name'     => get_option('woocommerce_email_from_name', get_bloginfo('name')),
                'email_from_address'  => get_option('woocommerce_email_from_address', get_option('admin_email')),
                'email_header_image'  => get_option('woocommerce_email_header_image', ''),
                'email_footer_text'   => get_option('woocommerce_email_footer_text', ''),
            ],
        ]);
    }

    public function update_store_settings(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }
        $d = (array) $r->get_json_params();

        $option_map = [
            'currency'               => 'woocommerce_currency',
            'currency_position'      => 'woocommerce_currency_pos',
            'thousand_separator'     => 'woocommerce_price_thousand_sep',
            'decimal_separator'      => 'woocommerce_price_decimal_sep',
            'decimals'               => 'woocommerce_price_num_decimals',
            'store_address'          => 'woocommerce_store_address',
            'store_city'             => 'woocommerce_store_city',
            'store_postcode'         => 'woocommerce_store_postcode',
            'store_country'          => 'woocommerce_default_country',
            'calc_taxes'             => 'woocommerce_calc_taxes',
            'prices_include_tax'     => 'woocommerce_prices_include_tax',
            'manage_stock'           => 'woocommerce_manage_stock',
            'low_stock_threshold'    => 'woocommerce_notify_low_stock_amount',
            'out_of_stock_threshold' => 'woocommerce_notify_no_stock_amount',
            'email_from_name'        => 'woocommerce_email_from_name',
            'email_from_address'     => 'woocommerce_email_from_address',
            'email_footer_text'      => 'woocommerce_email_footer_text',
            'force_ssl_checkout'     => 'woocommerce_force_ssl_checkout',
            'guest_checkout'         => 'woocommerce_enable_guest_checkout',
        ];

        $updated = [];
        foreach ($option_map as $key => $option) {
            if (array_key_exists($key, $d)) {
                $value = in_array($key, ['decimals', 'low_stock_threshold', 'out_of_stock_threshold'], true)
                    ? (string) (int) $d[$key]
                    : sanitize_text_field((string) $d[$key]);
                update_option($option, $value);
                $updated[] = $key;
            }
        }

        $this->log('woo_update_store_settings', 'store', 0, ['updated' => $updated], 2);
        return $this->success(['updated' => $updated]);
    }

    public function get_settings_group(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }
        $group    = sanitize_key((string) $r['group']);
        $settings = \WC_Admin_Settings::get_settings_pages();
        foreach ($settings as $page) {
            if (method_exists($page, 'get_id') && $page->get_id() === $group) {
                ob_start();
                $settings_data = [];
                $fields = method_exists($page, 'get_settings') ? $page->get_settings() : [];
                foreach ($fields as $field) {
                    if (empty($field['id'])) continue;
                    $settings_data[$field['id']] = [
                        'title'   => $field['title'] ?? '',
                        'type'    => $field['type'] ?? '',
                        'value'   => get_option($field['id'], $field['default'] ?? ''),
                        'default' => $field['default'] ?? '',
                    ];
                }
                ob_end_clean();
                return $this->success(['group' => $group, 'settings' => $settings_data]);
            }
        }
        return $this->error('Settings group not found', 404);
    }

    public function update_settings_group(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }
        $group    = sanitize_key((string) $r['group']);
        $d        = (array) $r->get_json_params();
        $settings = $d['settings'] ?? [];
        if (!is_array($settings)) {
            return $this->error('settings must be an object');
        }

        $updated = [];
        foreach ($settings as $option_id => $value) {
            $option_id = sanitize_key((string) $option_id);
            if (!str_starts_with($option_id, 'woocommerce_')) {
                $option_id = 'woocommerce_' . $option_id;
            }
            update_option($option_id, sanitize_text_field((string) $value));
            $updated[] = $option_id;
        }

        $this->log('woo_update_settings_group', 'store', 0, ['group' => $group, 'updated' => $updated], 2);
        return $this->success(['group' => $group, 'updated' => $updated]);
    }

    // =========================================================================
    // WooCommerce Webhooks
    // =========================================================================

    public function list_woo_webhooks(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }
        $data_store = \WC_Data_Store::load('webhook');
        $ids        = $data_store->get_webhooks_ids();
        $webhooks   = [];
        foreach ($ids as $id) {
            $wh         = new \WC_Webhook($id);
            $webhooks[] = $this->fmt_woo_webhook($wh);
        }
        return $this->success(['webhooks' => $webhooks]);
    }

    public function get_woo_webhook(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }
        $wh = new \WC_Webhook((int) $r['id']);
        if (!$wh->get_id()) {
            return $this->error('Webhook not found', 404);
        }
        $data                = $this->fmt_woo_webhook($wh);
        $data['secret']      = substr($wh->get_secret(), 0, 4) . '****';
        $data['delivery_url']= $wh->get_delivery_url();
        return $this->success($data);
    }

    public function create_woo_webhook(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }
        $d   = (array) $r->get_json_params();
        $url = esc_url_raw((string) ($d['delivery_url'] ?? ''));
        if (empty($url)) {
            return $this->error('delivery_url is required');
        }

        $wh = new \WC_Webhook();
        $wh->set_name(sanitize_text_field((string) ($d['name'] ?? 'AGI Webhook')));
        $wh->set_topic(sanitize_text_field((string) ($d['topic'] ?? 'order.created')));
        $wh->set_delivery_url($url);
        $wh->set_secret(sanitize_text_field((string) ($d['secret'] ?? wp_generate_password(40, false))));
        $wh->set_status(sanitize_key((string) ($d['status'] ?? 'active')));
        $id = $wh->save();

        $this->log('woo_create_webhook', 'webhook', $id, ['topic' => $wh->get_topic()], 2);
        return $this->success($this->fmt_woo_webhook($wh), 201);
    }

    public function update_woo_webhook(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }
        $wh = new \WC_Webhook((int) $r['id']);
        if (!$wh->get_id()) {
            return $this->error('Webhook not found', 404);
        }
        $d = (array) $r->get_json_params();
        if (isset($d['name']))         $wh->set_name(sanitize_text_field($d['name']));
        if (isset($d['topic']))        $wh->set_topic(sanitize_text_field($d['topic']));
        if (!empty($d['delivery_url'])) $wh->set_delivery_url(esc_url_raw($d['delivery_url']));
        if (isset($d['status']))       $wh->set_status(sanitize_key($d['status']));
        $wh->save();
        $this->log('woo_update_webhook', 'webhook', $wh->get_id(), [], 2);
        return $this->success($this->fmt_woo_webhook($wh));
    }

    public function delete_woo_webhook(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_woo_active()) {
            return $this->error('WooCommerce is not active', 503);
        }
        $wh = new \WC_Webhook((int) $r['id']);
        if (!$wh->get_id()) {
            return $this->error('Webhook not found', 404);
        }
        $wh->delete(true);
        $this->log('woo_delete_webhook', 'webhook', (int) $r['id'], [], 3);
        return $this->success(['deleted' => true, 'id' => (int) $r['id']]);
    }

    private function fmt_woo_webhook(\WC_Webhook $wh): array {
        return [
            'id'            => $wh->get_id(),
            'name'          => $wh->get_name(),
            'topic'         => $wh->get_topic(),
            'resource'      => $wh->get_resource(),
            'event'         => $wh->get_event(),
            'status'        => $wh->get_status(),
            'delivery_url'  => $wh->get_delivery_url(),
            'failure_count' => $wh->get_failure_count(),
        ];
    }
}
