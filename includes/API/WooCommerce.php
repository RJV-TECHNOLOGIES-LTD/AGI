<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;

use RJV_AGI_Bridge\Auth;
use RJV_AGI_Bridge\AI\Router;

/**
 * WooCommerce Integration
 *
 * Exposes products, orders, customers, and store statistics to the AGI
 * orchestrator. All operations are gated behind WooCommerce availability
 * checks so the endpoints degrade gracefully when the plugin is absent.
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

        // Orders
        register_rest_route($this->namespace, '/woo/orders', [
            ['methods' => 'GET', 'callback' => [$this, 'list_orders'], 'permission_callback' => [Auth::class, 'tier1'],
             'args' => ['per_page' => ['default' => 20], 'page' => ['default' => 1], 'status' => ['default' => 'any']]],
        ]);
        register_rest_route($this->namespace, '/woo/orders/(?P<id>\d+)', [
            ['methods' => 'GET',       'callback' => [$this, 'get_order'],    'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'PUT,PATCH', 'callback' => [$this, 'update_order'], 'permission_callback' => [Auth::class, 'tier2']],
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
}
