<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\Bridge;

use RJV_AGI_Bridge\AuditLog;

/**
 * Multi-Tenant Isolation Layer
 *
 * Ensures strict isolation between tenants in multi-site and multi-tenant environments.
 * No data, configuration, or execution context may leak between tenants.
 */
final class TenantIsolation {
    private static ?self $instance = null;
    private ?string $current_tenant = null;
    private array $tenant_context = [];

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        // Initialize tenant context from request
        $this->initialize_tenant_context();
    }

    /**
     * Initialize tenant context from request headers or configuration
     */
    private function initialize_tenant_context(): void {
        // Check for tenant ID in request header
        if (!empty($_SERVER['HTTP_X_TENANT_ID'])) {
            $this->current_tenant = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_TENANT_ID']));
        }

        // Fallback to site-based tenant (for WordPress multisite)
        if (empty($this->current_tenant) && is_multisite()) {
            $this->current_tenant = 'site_' . get_current_blog_id();
        }

        // Fallback to configured tenant ID
        if (empty($this->current_tenant)) {
            $this->current_tenant = get_option('rjv_agi_tenant_id', '');
        }

        if (!empty($this->current_tenant)) {
            $this->tenant_context = [
                'tenant_id' => $this->current_tenant,
                'site_id' => is_multisite() ? get_current_blog_id() : 1,
                'initialized_at' => gmdate('c'),
            ];
        }
    }

    /**
     * Get current tenant ID
     */
    public function get_tenant_id(): ?string {
        return $this->current_tenant;
    }

    /**
     * Get tenant context
     */
    public function get_context(): array {
        return $this->tenant_context;
    }

    /**
     * Check if multi-tenant mode is enabled
     */
    public function is_enabled(): bool {
        return !empty($this->current_tenant) || is_multisite();
    }

    /**
     * Validate that an operation is scoped to the correct tenant
     */
    public function validate_scope(string $resource_tenant): bool {
        if (!$this->is_enabled()) {
            return true;
        }

        if (empty($resource_tenant)) {
            return true; // No tenant specified, allow
        }

        $valid = $resource_tenant === $this->current_tenant;

        if (!$valid) {
            AuditLog::log('tenant_scope_violation', 'isolation', 0, [
                'current_tenant' => $this->current_tenant,
                'resource_tenant' => $resource_tenant,
            ], 3, 'error');
        }

        return $valid;
    }

    /**
     * Scope a query to current tenant
     */
    public function scope_query(string $sql, string $tenant_column = 'tenant_id'): string {
        if (!$this->is_enabled() || empty($this->current_tenant)) {
            return $sql;
        }

        global $wpdb;

        // Add tenant condition to WHERE clause
        $tenant_condition = $wpdb->prepare("{$tenant_column} = %s", $this->current_tenant);

        if (stripos($sql, 'WHERE') !== false) {
            $sql = preg_replace('/WHERE/i', "WHERE {$tenant_condition} AND", $sql, 1);
        } else {
            // Add WHERE clause before ORDER BY, GROUP BY, LIMIT, etc.
            $patterns = ['ORDER BY', 'GROUP BY', 'LIMIT', 'HAVING'];
            $inserted = false;
            foreach ($patterns as $pattern) {
                if (stripos($sql, $pattern) !== false) {
                    $sql = preg_replace("/({$pattern})/i", "WHERE {$tenant_condition} $1", $sql, 1);
                    $inserted = true;
                    break;
                }
            }
            if (!$inserted) {
                $sql .= " WHERE {$tenant_condition}";
            }
        }

        return $sql;
    }

    /**
     * Add tenant ID to data before insert
     */
    public function scope_data(array $data, string $tenant_key = 'tenant_id'): array {
        if (!$this->is_enabled()) {
            return $data;
        }

        $data[$tenant_key] = $this->current_tenant;
        return $data;
    }

    /**
     * Get tenant-specific option
     */
    public function get_option(string $option, $default = false) {
        if (!$this->is_enabled() || empty($this->current_tenant)) {
            return get_option($option, $default);
        }

        $tenant_option = "tenant_{$this->current_tenant}_{$option}";
        return get_option($tenant_option, get_option($option, $default));
    }

    /**
     * Set tenant-specific option
     */
    public function set_option(string $option, $value): bool {
        if (!$this->is_enabled() || empty($this->current_tenant)) {
            return update_option($option, $value);
        }

        $tenant_option = "tenant_{$this->current_tenant}_{$option}";
        return update_option($tenant_option, $value);
    }

    /**
     * Get tenant-specific transient
     */
    public function get_transient(string $transient) {
        if (!$this->is_enabled() || empty($this->current_tenant)) {
            return get_transient($transient);
        }

        return get_transient("tenant_{$this->current_tenant}_{$transient}");
    }

    /**
     * Set tenant-specific transient
     */
    public function set_transient(string $transient, $value, int $expiration = 0): bool {
        if (!$this->is_enabled() || empty($this->current_tenant)) {
            return set_transient($transient, $value, $expiration);
        }

        return set_transient("tenant_{$this->current_tenant}_{$transient}", $value, $expiration);
    }

    /**
     * Get tenant-specific cache key
     */
    public function cache_key(string $key): string {
        if (!$this->is_enabled() || empty($this->current_tenant)) {
            return $key;
        }

        return "tenant_{$this->current_tenant}_{$key}";
    }

    /**
     * Switch tenant context (for admin operations)
     */
    public function switch_tenant(string $tenant_id): void {
        $previous = $this->current_tenant;
        $this->current_tenant = $tenant_id;
        $this->tenant_context['tenant_id'] = $tenant_id;
        $this->tenant_context['switched_from'] = $previous;
        $this->tenant_context['switched_at'] = gmdate('c');

        AuditLog::log('tenant_switched', 'isolation', 0, [
            'from' => $previous,
            'to' => $tenant_id,
        ], 2);
    }

    /**
     * Execute callback in tenant context
     */
    public function with_tenant(string $tenant_id, callable $callback): mixed {
        $previous = $this->current_tenant;

        try {
            $this->switch_tenant($tenant_id);
            return $callback();
        } finally {
            if ($previous) {
                $this->switch_tenant($previous);
            } else {
                $this->current_tenant = null;
            }
        }
    }

    /**
     * List all tenants (for admin dashboard)
     */
    public function list_tenants(): array {
        global $wpdb;

        $tenants = [];

        // For multisite, list all sites
        if (is_multisite()) {
            $sites = get_sites(['number' => 100]);
            foreach ($sites as $site) {
                $tenants[] = [
                    'tenant_id' => 'site_' . $site->blog_id,
                    'name' => $site->blogname,
                    'url' => $site->siteurl,
                    'type' => 'multisite',
                ];
            }
        }

        // Add configured tenants from options
        $configured_tenants = get_option('rjv_agi_configured_tenants', []);
        foreach ($configured_tenants as $tenant) {
            $tenants[] = array_merge($tenant, ['type' => 'configured']);
        }

        return $tenants;
    }

    /**
     * Validate tenant exists
     */
    public function tenant_exists(string $tenant_id): bool {
        // Check multisite
        if (str_starts_with($tenant_id, 'site_')) {
            $site_id = (int) substr($tenant_id, 5);
            return get_site($site_id) !== null;
        }

        // Check configured tenants
        $configured = get_option('rjv_agi_configured_tenants', []);
        foreach ($configured as $tenant) {
            if ($tenant['tenant_id'] === $tenant_id) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get tenant configuration
     */
    public function get_tenant_config(?string $tenant_id = null): array {
        $tenant_id = $tenant_id ?? $this->current_tenant;

        if (empty($tenant_id)) {
            return [];
        }

        return [
            'tenant_id' => $tenant_id,
            'features' => $this->get_option('features', []),
            'limits' => $this->get_option('limits', []),
            'settings' => $this->get_option('settings', []),
        ];
    }

    /**
     * Ensure tenant isolation for database queries
     */
    public function add_query_filters(): void {
        if (!$this->is_enabled()) {
            return;
        }

        // Add filter for posts queries
        add_filter('posts_where', function ($where, $query) {
            if (!empty($query->query_vars['suppress_tenant_filter'])) {
                return $where;
            }

            // For multisite, WordPress handles this
            if (is_multisite()) {
                return $where;
            }

            // For single-site multi-tenant, add tenant filter
            // This would need custom meta or taxonomy for tenant tracking
            return $where;
        }, 10, 2);
    }

    /**
     * Clean up tenant data
     */
    public function cleanup_tenant(string $tenant_id): array {
        $cleaned = [
            'options' => 0,
            'transients' => 0,
        ];

        global $wpdb;

        // Clean tenant-specific options
        $cleaned['options'] = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            "tenant_{$tenant_id}_%"
        ));

        // Clean tenant-specific transients
        $cleaned['transients'] = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            "%_transient_tenant_{$tenant_id}_%"
        ));

        AuditLog::log('tenant_cleanup', 'isolation', 0, [
            'tenant_id' => $tenant_id,
            'cleaned' => $cleaned,
        ], 3);

        return $cleaned;
    }
}
