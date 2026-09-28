<?php
/**
 * Lightweight WordPress doubles for gate tests that run outside WordPress.
 */
declare(strict_types=1);

if (!isset($GLOBALS['_wp_mutations'])) {
    $GLOBALS['_wp_mutations'] = [];
}

if (!isset($GLOBALS['_registered_routes'])) {
    $GLOBALS['_registered_routes'] = [];
}

if (!class_exists('WP_Error')) {
    class WP_Error {
        public function __construct(
            public string $code = '',
            public string $message = '',
            public mixed $data = null
        ) {}

        public function get_error_code(): string {
            return $this->code;
        }

        public function get_error_message(): string {
            return $this->message;
        }

        public function get_error_data(): mixed {
            return $this->data;
        }
    }
}

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response {
        public function __construct(public mixed $data = null, public int $status = 200) {}

        public function get_data(): mixed {
            return $this->data;
        }

        public function get_status(): int {
            return $this->status;
        }

        public function header(string $key, string $value, bool $replace = true): void {}
    }
}

if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request {
        public function __construct(
            private string $method = 'GET',
            private string $route = '',
            private array $json = [],
            private array $body = [],
            private array $query = [],
            private array $url = [],
            private array $headers = [],
            private string $raw_body = ''
        ) {}

        public function get_method(): string {
            return $this->method;
        }

        public function get_route(): string {
            return $this->route;
        }

        public function get_json_params(): array {
            return $this->json;
        }

        public function get_body_params(): array {
            return $this->body;
        }

        public function get_query_params(): array {
            return $this->query;
        }

        public function get_url_params(): array {
            return $this->url;
        }

        public function get_params(): array {
            return array_merge($this->query, $this->url, $this->body, $this->json);
        }

        public function get_body(): string {
            return $this->raw_body;
        }

        public function get_header(string $name): string {
            return (string) ($this->headers[strtolower($name)] ?? '');
        }

        public function get_param(string $key): mixed {
            return $this->json[$key] ?? $this->query[$key] ?? $this->url[$key] ?? $this->body[$key] ?? null;
        }

        public function set_header(string $name, string $value): void {
            $this->headers[strtolower($name)] = $value;
        }

        public function set_json_params(array $json): void {
            $this->json = $json;
        }

        public function set_query_params(array $query): void {
            $this->query = $query;
        }

        public function set_route(string $route): void {
            $this->route = $route;
        }

        public function set_method(string $method): void {
            $this->method = $method;
        }
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error(mixed $thing): bool {
        return $thing instanceof WP_Error;
    }
}

if (!function_exists('wp_kses_post')) {
    function wp_kses_post(string $content): string {
        return $content;
    }
}

if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field(string $value): string {
        return trim(strip_tags($value));
    }
}

if (!function_exists('wp_insert_post')) {
    function wp_insert_post(array $postarr, bool $wp_error = false, bool $fire_after_hooks = true): int {
        $GLOBALS['_wp_mutations'][] = ['wp_insert_post', $postarr];
        return 42;
    }
}

if (!function_exists('wp_update_post')) {
    function wp_update_post(array $postarr, bool $wp_error = false, bool $fire_after_hooks = true): int {
        $GLOBALS['_wp_mutations'][] = ['wp_update_post', $postarr];
        return (int) ($postarr['ID'] ?? 0);
    }
}

if (!function_exists('wp_delete_post')) {
    function wp_delete_post(int $postid, bool $force_delete = false): mixed {
        $GLOBALS['_wp_mutations'][] = ['wp_delete_post', $postid];
        return (object) ['ID' => $postid];
    }
}

if (!function_exists('get_post')) {
    function get_post(int $post = 0): mixed {
        return null;
    }
}

if (!function_exists('get_post_status')) {
    function get_post_status(int $post = 0): mixed {
        return false;
    }
}

if (!function_exists('get_theme_mod')) {
    function get_theme_mod(string $name, mixed $default = false): mixed {
        return $default;
    }
}

if (!function_exists('set_theme_mod')) {
    function set_theme_mod(string $name, mixed $value): bool {
        $GLOBALS['_wp_mutations'][] = ['set_theme_mod', $name, $value];
        return true;
    }
}

if (!function_exists('wp_update_nav_menu_item')) {
    function wp_update_nav_menu_item(int $menu_id, int $menu_item_db_id, array $menu_item_data): int {
        $GLOBALS['_wp_mutations'][] = ['wp_update_nav_menu_item', $menu_id, $menu_item_data];
        return 99;
    }
}

if (!function_exists('update_post_meta')) {
    function update_post_meta(int $post_id, string $meta_key, mixed $meta_value): int|bool {
        $GLOBALS['_wp_mutations'][] = ['update_post_meta', $post_id, $meta_key, $meta_value];
        return true;
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can(string $capability, mixed ...$args): bool {
        return false;
    }
}

if (!function_exists('is_plugin_active')) {
    function is_plugin_active(string $plugin): bool {
        return false;
    }
}

if (!function_exists('get_stylesheet')) {
    function get_stylesheet(): string {
        return '';
    }
}

if (!function_exists('get_users')) {
    function get_users(array $args = []): array {
        return [];
    }
}

if (!function_exists('wp_mail')) {
    function wp_mail(string $to, string $subject, string $message): bool {
        return true;
    }
}

if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string {
        return 'https://test.example.com/wp-admin/' . ltrim($path, '/');
    }
}

if (!function_exists('get_bloginfo')) {
    function get_bloginfo(string $show = ''): string {
        return $show === 'version' ? '6.4' : 'Test';
    }
}

if (!function_exists('get_userdata')) {
    function get_userdata(int $user_id): object {
        return (object) ['ID' => $user_id, 'user_email' => 'admin@example.com'];
    }
}

if (!function_exists('user_can')) {
    function user_can(object $user, string $capability, mixed ...$args): bool {
        return true;
    }
}

if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int {
        return 1;
    }
}

if (!function_exists('register_rest_route')) {
    function register_rest_route(string $namespace, string $route, array $args = [], bool $override = false): bool {
        $GLOBALS['_registered_routes'][] = [
            'namespace' => $namespace,
            'route' => $route,
            'args' => $args,
        ];
        return true;
    }
}
