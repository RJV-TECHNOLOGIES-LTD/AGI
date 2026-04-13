<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\Security;

use RJV_AGI_Bridge\AuditLog;

/**
 * Role-Based Access Control
 *
 * Maps WordPress roles and custom roles to AGI capabilities.
 * Ensures no user performs actions beyond defined permissions.
 * AGI itself operates within defined boundaries.
 */
final class AccessControl {
    private static ?self $instance = null;
    private array $role_capabilities = [];
    private array $custom_roles = [];

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        $this->load_role_mappings();
    }

    /**
     * Load role to capability mappings
     */
    private function load_role_mappings(): void {
        // Default WordPress role mappings
        $this->role_capabilities = [
            'administrator' => [
                'agi_full_access' => true,
                'agi_content_manage' => true,
                'agi_content_delete' => true,
                'agi_media_manage' => true,
                'agi_users_manage' => true,
                'agi_settings_manage' => true,
                'agi_themes_manage' => true,
                'agi_plugins_manage' => true,
                'agi_database_access' => true,
                'agi_files_manage' => true,
                'agi_security_manage' => true,
                'agi_agents_manage' => true,
                'agi_approvals_manage' => true,
                'agi_ai_access' => true,
            ],
            'editor' => [
                'agi_content_manage' => true,
                'agi_content_delete' => false,
                'agi_media_manage' => true,
                'agi_ai_access' => true,
                'agi_seo_manage' => true,
            ],
            'author' => [
                'agi_content_manage' => 'own',
                'agi_media_manage' => 'own',
                'agi_ai_access' => true,
            ],
            'contributor' => [
                'agi_content_manage' => 'own_draft',
                'agi_ai_access' => 'limited',
            ],
            'subscriber' => [
                'agi_ai_access' => 'limited',
            ],
        ];

        // Load custom role mappings from options
        $custom = get_option('rjv_agi_custom_role_mappings', []);
        if (is_array($custom)) {
            $this->role_capabilities = array_merge($this->role_capabilities, $custom);
        }
    }

    /**
     * Check if current user can perform action
     */
    public function can(string $capability, ?int $user_id = null, ?int $resource_id = null): bool {
        $user = $user_id ? get_userdata($user_id) : wp_get_current_user();
        if (!$user || !$user->exists()) {
            return false;
        }

        // Check each user role
        foreach ($user->roles as $role) {
            $result = $this->check_role_capability($role, $capability, $user->ID, $resource_id);
            if ($result === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check capability for a specific role
     */
    private function check_role_capability(string $role, string $capability, int $user_id, ?int $resource_id): bool {
        $caps = $this->role_capabilities[$role] ?? [];

        // Check for full access
        if (!empty($caps['agi_full_access'])) {
            return true;
        }

        $value = $caps[$capability] ?? false;

        if ($value === true) {
            return true;
        }

        if ($value === 'own' && $resource_id) {
            // Check if user owns the resource
            $post = get_post($resource_id);
            return $post && (int) $post->post_author === $user_id;
        }

        if ($value === 'own_draft' && $resource_id) {
            $post = get_post($resource_id);
            return $post && (int) $post->post_author === $user_id && $post->post_status === 'draft';
        }

        if ($value === 'limited') {
            // Limited access - check specific sub-capabilities
            return $this->check_limited_capability($capability, $user_id);
        }

        return false;
    }

    /**
     * Check limited capability access
     */
    private function check_limited_capability(string $capability, int $user_id): bool {
        // Limited AI access - check daily limits
        if ($capability === 'agi_ai_access') {
            $daily_limit = (int) get_option('rjv_agi_limited_ai_daily', 10);
            $today = gmdate('Y-m-d');
            $usage_key = "rjv_agi_ai_usage_{$user_id}_{$today}";
            $usage = (int) get_transient($usage_key);
            return $usage < $daily_limit;
        }

        return false;
    }

    /**
     * Increment usage counter
     */
    public function increment_usage(string $capability, int $user_id): void {
        if ($capability === 'agi_ai_access') {
            $today = gmdate('Y-m-d');
            $usage_key = "rjv_agi_ai_usage_{$user_id}_{$today}";
            $usage = (int) get_transient($usage_key);
            set_transient($usage_key, $usage + 1, DAY_IN_SECONDS);
        }
    }

    /**
     * Get user capabilities
     */
    public function get_user_capabilities(?int $user_id = null): array {
        $user = $user_id ? get_userdata($user_id) : wp_get_current_user();
        if (!$user || !$user->exists()) {
            return [];
        }

        $capabilities = [];
        foreach ($user->roles as $role) {
            $role_caps = $this->role_capabilities[$role] ?? [];
            foreach ($role_caps as $cap => $value) {
                if (!isset($capabilities[$cap]) || $value === true) {
                    $capabilities[$cap] = $value;
                }
            }
        }

        return $capabilities;
    }

    /**
     * Add custom role mapping
     */
    public function add_role_mapping(string $role, array $capabilities): void {
        $custom = get_option('rjv_agi_custom_role_mappings', []);
        $custom[$role] = $capabilities;
        update_option('rjv_agi_custom_role_mappings', $custom);
        $this->role_capabilities[$role] = $capabilities;

        AuditLog::log('role_mapping_added', 'access_control', 0, [
            'role' => $role,
            'capabilities' => array_keys($capabilities),
        ], 3);
    }

    /**
     * Create custom AGI role
     */
    public function create_custom_role(string $role_name, string $display_name, array $capabilities): void {
        // Create WordPress role
        add_role($role_name, $display_name, []);

        // Map to AGI capabilities
        $this->add_role_mapping($role_name, $capabilities);
        $this->custom_roles[] = $role_name;

        AuditLog::log('custom_role_created', 'access_control', 0, [
            'role' => $role_name,
            'display_name' => $display_name,
        ], 3);
    }

    /**
     * Validate action against user permissions
     */
    public function validate_action(string $action, array $context = []): array {
        $user_id = $context['user_id'] ?? get_current_user_id();
        $resource_id = $context['resource_id'] ?? null;

        // Map action to required capability
        $required = $this->map_action_to_capability($action);
        if (!$required) {
            return ['allowed' => true]; // Unknown actions allowed by default
        }

        if (!$this->can($required, $user_id, $resource_id)) {
            AuditLog::log('access_denied', 'access_control', 0, [
                'action' => $action,
                'user_id' => $user_id,
                'required_capability' => $required,
            ], 1, 'error');

            return [
                'allowed' => false,
                'error' => 'Permission denied',
                'required_capability' => $required,
            ];
        }

        return ['allowed' => true];
    }

    /**
     * Map action to required capability
     */
    private function map_action_to_capability(string $action): ?string {
        $mapping = [
            // Content actions
            'create_post' => 'agi_content_manage',
            'update_post' => 'agi_content_manage',
            'delete_post' => 'agi_content_delete',
            'create_page' => 'agi_content_manage',
            'update_page' => 'agi_content_manage',
            'delete_page' => 'agi_content_delete',
            'bulk_posts' => 'agi_content_manage',

            // Media actions
            'upload_media' => 'agi_media_manage',
            'sideload_media' => 'agi_media_manage',
            'delete_media' => 'agi_media_manage',

            // User actions
            'list_users' => 'agi_users_manage',
            'update_user' => 'agi_users_manage',

            // Settings actions
            'update_options' => 'agi_settings_manage',
            'set_theme_mod' => 'agi_settings_manage',

            // Theme/Plugin actions
            'activate_theme' => 'agi_themes_manage',
            'toggle_plugin' => 'agi_plugins_manage',

            // Database actions
            'db_query' => 'agi_database_access',
            'db_optimize' => 'agi_database_access',

            // File actions
            'file_read' => 'agi_files_manage',
            'file_write' => 'agi_files_manage',

            // AI actions
            'ai_complete' => 'agi_ai_access',
            'ai_generate' => 'agi_ai_access',
            'ai_rewrite' => 'agi_ai_access',

            // Security actions
            'security_scan' => 'agi_security_manage',
            'integrity_check' => 'agi_security_manage',

            // Agent actions
            'deploy_agent' => 'agi_agents_manage',
            'stop_agent' => 'agi_agents_manage',

            // Approval actions
            'approve_action' => 'agi_approvals_manage',
            'reject_action' => 'agi_approvals_manage',
        ];

        return $mapping[$action] ?? null;
    }

    /**
     * Get all defined capabilities
     */
    public function get_all_capabilities(): array {
        return [
            'agi_full_access' => 'Full AGI access',
            'agi_content_manage' => 'Manage content (create/edit)',
            'agi_content_delete' => 'Delete content',
            'agi_media_manage' => 'Manage media',
            'agi_users_manage' => 'Manage users',
            'agi_settings_manage' => 'Manage settings',
            'agi_themes_manage' => 'Manage themes',
            'agi_plugins_manage' => 'Manage plugins',
            'agi_database_access' => 'Database access',
            'agi_files_manage' => 'Manage files',
            'agi_security_manage' => 'Security management',
            'agi_agents_manage' => 'Manage agents',
            'agi_approvals_manage' => 'Manage approvals',
            'agi_ai_access' => 'AI access',
            'agi_seo_manage' => 'SEO management',
        ];
    }

    /**
     * Check AGI boundaries
     */
    public function check_agi_boundary(string $action, array $context = []): bool {
        // AGI cannot bypass approval workflows
        if ($action === 'bypass_approval') {
            return false;
        }

        // AGI cannot modify its own access controls
        if (in_array($action, ['modify_access_control', 'grant_capability', 'remove_capability'], true)) {
            return false;
        }

        // AGI cannot create new admin users
        if ($action === 'create_admin_user') {
            return false;
        }

        return true;
    }
}
