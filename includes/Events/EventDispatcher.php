<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\Events;

use RJV_AGI_Bridge\AuditLog;
use RJV_AGI_Bridge\Bridge\PlatformConnector;

/**
 * Event Streaming System
 *
 * Listens to all relevant WordPress events and streams them to the AGI platform
 * in real time. Enables the AGI to react, adapt, and execute follow-up actions.
 */
final class EventDispatcher {
    private static ?self $instance = null;
    private array $listeners = [];
    private array $event_queue = [];
    private bool $streaming_enabled = false;
    private int $batch_size = 10;
    private int $flush_interval = 5; // seconds

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        $this->streaming_enabled = get_option('rjv_agi_event_streaming', '0') === '1';
        $this->register_wordpress_hooks();
        add_action('shutdown', [$this, 'flush_queue']);
    }

    /**
     * Register WordPress event hooks
     */
    private function register_wordpress_hooks(): void {
        // Content events
        add_action('save_post', [$this, 'on_post_save'], 10, 3);
        add_action('before_delete_post', [$this, 'on_post_delete'], 10, 2);
        add_action('transition_post_status', [$this, 'on_status_change'], 10, 3);
        add_action('updated_post_meta', [$this, 'on_meta_update'], 10, 4);

        // User events
        add_action('wp_login', [$this, 'on_user_login'], 10, 2);
        add_action('wp_logout', [$this, 'on_user_logout'], 10, 1);
        add_action('user_register', [$this, 'on_user_register']);
        add_action('profile_update', [$this, 'on_profile_update'], 10, 3);

        // Comment events
        add_action('comment_post', [$this, 'on_comment_post'], 10, 3);
        add_action('wp_set_comment_status', [$this, 'on_comment_status'], 10, 2);
        add_action('spam_comment', [$this, 'on_comment_spam']);
        add_action('delete_comment', [$this, 'on_comment_delete'], 10, 2);

        // Theme/Plugin events
        add_action('switch_theme', [$this, 'on_theme_switch'], 10, 3);
        add_action('activated_plugin', [$this, 'on_plugin_activate'], 10, 2);
        add_action('deactivated_plugin', [$this, 'on_plugin_deactivate'], 10, 2);

        // Option events
        add_action('updated_option', [$this, 'on_option_update'], 10, 3);

        // Media events
        add_action('add_attachment', [$this, 'on_media_upload']);
        add_action('delete_attachment', [$this, 'on_media_delete'], 10, 2);

        // Error events
        add_action('wp_error_added', [$this, 'on_wp_error'], 10, 4);

        // Performance events
        add_action('wp_footer', [$this, 'capture_performance_metrics'], 9999);
    }

    /**
     * Dispatch an event
     */
    public function dispatch(string $event_type, array $data, int $priority = 5): void {
        $event = [
            'type' => $event_type,
            'timestamp' => gmdate('c'),
            'data' => $data,
            'priority' => $priority,
            'site_url' => get_site_url(),
        ];

        // Notify local listeners
        $this->notify_listeners($event_type, $event);

        // Queue for remote streaming
        if ($this->streaming_enabled) {
            $this->event_queue[] = $event;

            if (count($this->event_queue) >= $this->batch_size) {
                $this->flush_queue();
            }
        }

        // Always log high-priority events
        if ($priority >= 7) {
            AuditLog::log("event_{$event_type}", 'event', 0, $data, 1);
        }
    }

    /**
     * Subscribe to events
     */
    public function subscribe(string $event_type, callable $callback, int $priority = 10): void {
        if (!isset($this->listeners[$event_type])) {
            $this->listeners[$event_type] = [];
        }
        $this->listeners[$event_type][] = [
            'callback' => $callback,
            'priority' => $priority,
        ];

        // Sort by priority
        usort($this->listeners[$event_type], fn($a, $b) => $b['priority'] <=> $a['priority']);
    }

    /**
     * Unsubscribe from events
     */
    public function unsubscribe(string $event_type, callable $callback): void {
        if (!isset($this->listeners[$event_type])) {
            return;
        }
        $this->listeners[$event_type] = array_filter(
            $this->listeners[$event_type],
            fn($l) => $l['callback'] !== $callback
        );
    }

    /**
     * Notify local listeners
     */
    private function notify_listeners(string $event_type, array $event): void {
        // Notify specific listeners
        if (isset($this->listeners[$event_type])) {
            foreach ($this->listeners[$event_type] as $listener) {
                try {
                    ($listener['callback'])($event);
                } catch (\Throwable $e) {
                    AuditLog::log('event_listener_error', 'event', 0, [
                        'event_type' => $event_type,
                        'error' => $e->getMessage(),
                    ], 1, 'error');
                }
            }
        }

        // Notify wildcard listeners
        if (isset($this->listeners['*'])) {
            foreach ($this->listeners['*'] as $listener) {
                try {
                    ($listener['callback'])($event);
                } catch (\Throwable $e) {
                    // Silent fail for wildcard listeners
                }
            }
        }
    }

    /**
     * Flush event queue to platform
     */
    public function flush_queue(): void {
        if (empty($this->event_queue) || !$this->streaming_enabled) {
            return;
        }

        $events = $this->event_queue;
        $this->event_queue = [];

        $connector = PlatformConnector::instance();
        if (!$connector->is_configured()) {
            return;
        }

        foreach ($events as $event) {
            $connector->report_event($event['type'], $event['data']);
        }
    }

    // WordPress Event Handlers

    public function on_post_save(int $post_id, \WP_Post $post, bool $update): void {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        $this->dispatch($update ? 'content.updated' : 'content.created', [
            'post_id' => $post_id,
            'post_type' => $post->post_type,
            'post_status' => $post->post_status,
            'post_title' => $post->post_title,
            'author' => (int) $post->post_author,
        ], 6);
    }

    public function on_post_delete(int $post_id, \WP_Post $post): void {
        $this->dispatch('content.deleted', [
            'post_id' => $post_id,
            'post_type' => $post->post_type,
            'post_title' => $post->post_title,
        ], 7);
    }

    public function on_status_change(string $new_status, string $old_status, \WP_Post $post): void {
        if ($new_status === $old_status) {
            return;
        }

        $priority = 5;
        if ($new_status === 'publish') {
            $priority = 7;
        } elseif ($new_status === 'trash') {
            $priority = 6;
        }

        $this->dispatch('content.status_changed', [
            'post_id' => $post->ID,
            'post_type' => $post->post_type,
            'old_status' => $old_status,
            'new_status' => $new_status,
        ], $priority);
    }

    public function on_meta_update(int $meta_id, int $object_id, string $meta_key, $meta_value): void {
        // Skip internal meta keys
        if (str_starts_with($meta_key, '_edit_') || str_starts_with($meta_key, '_wp_')) {
            return;
        }

        $this->dispatch('content.meta_updated', [
            'object_id' => $object_id,
            'meta_key' => $meta_key,
        ], 3);
    }

    public function on_user_login(string $user_login, \WP_User $user): void {
        $this->dispatch('user.login', [
            'user_id' => $user->ID,
            'user_login' => $user_login,
            'roles' => $user->roles,
        ], 5);
    }

    public function on_user_logout(int $user_id = 0): void {
        // WP 5.5+ passes the user ID directly; fall back to get_current_user_id()
        // only when the hook was fired without the argument (edge-case compatibility).
        if ($user_id === 0) {
            $user_id = get_current_user_id();
        }
        $this->dispatch('user.logout', [
            'user_id' => $user_id,
        ], 3);
    }

    public function on_user_register(int $user_id): void {
        $user = get_userdata($user_id);
        $this->dispatch('user.registered', [
            'user_id' => $user_id,
            'user_login' => $user ? $user->user_login : '',
        ], 6);
    }

    public function on_profile_update(int $user_id, \WP_User $old_data, array $new_data): void {
        $this->dispatch('user.updated', [
            'user_id' => $user_id,
        ], 4);
    }

    public function on_comment_post(int $comment_id, $approved, array $data): void {
        $this->dispatch('comment.created', [
            'comment_id' => $comment_id,
            'post_id' => $data['comment_post_ID'] ?? 0,
            'approved' => $approved,
        ], 5);
    }

    public function on_comment_status(int $comment_id, string $status): void {
        $this->dispatch('comment.status_changed', [
            'comment_id' => $comment_id,
            'status' => $status,
        ], 4);
    }

    public function on_comment_spam(int $comment_id): void {
        $this->dispatch('comment.spam', [
            'comment_id' => $comment_id,
        ], 5);
    }

    public function on_comment_delete(int $comment_id, \WP_Comment $comment): void {
        $this->dispatch('comment.deleted', [
            'comment_id' => $comment_id,
            'post_id' => (int) $comment->comment_post_ID,
        ], 5);
    }

    public function on_theme_switch(string $new_name, \WP_Theme $new_theme, ?\WP_Theme $old_theme): void {
        $this->dispatch('theme.switched', [
            'new_theme' => $new_theme->get_stylesheet(),
            'old_theme' => $old_theme ? $old_theme->get_stylesheet() : null,
        ], 8);
    }

    public function on_plugin_activate(string $plugin, bool $network_wide): void {
        $this->dispatch('plugin.activated', [
            'plugin' => $plugin,
            'network_wide' => $network_wide,
        ], 7);
    }

    public function on_plugin_deactivate(string $plugin, bool $network_deactivating): void {
        $this->dispatch('plugin.deactivated', [
            'plugin' => $plugin,
            'network_wide' => $network_deactivating,
        ], 7);
    }

    public function on_option_update(string $option, $old_value, $value): void {
        // Skip transients and internal options
        if (str_starts_with($option, '_transient') || str_starts_with($option, '_site_transient')) {
            return;
        }

        // Only track specific important options
        $tracked_options = [
            'blogname', 'blogdescription', 'siteurl', 'home',
            'users_can_register', 'default_role', 'permalink_structure',
            'active_plugins', 'template', 'stylesheet',
        ];

        if (!in_array($option, $tracked_options, true) && !str_starts_with($option, 'rjv_agi_')) {
            return;
        }

        $this->dispatch('option.updated', [
            'option' => $option,
        ], 6);
    }

    public function on_media_upload(int $attachment_id): void {
        $attachment = get_post($attachment_id);
        $this->dispatch('media.uploaded', [
            'attachment_id' => $attachment_id,
            'mime_type' => $attachment ? $attachment->post_mime_type : '',
            'url' => wp_get_attachment_url($attachment_id),
        ], 4);
    }

    public function on_media_delete(int $attachment_id, \WP_Post $attachment): void {
        $this->dispatch('media.deleted', [
            'attachment_id' => $attachment_id,
            'mime_type' => $attachment->post_mime_type,
        ], 5);
    }

    public function on_wp_error(string $code, string $message, $data, \WP_Error $error): void {
        $this->dispatch('error.occurred', [
            'code' => $code,
            'message' => $message,
        ], 8);
    }

    public function capture_performance_metrics(): void {
        if (!is_admin()) {
            global $wpdb;

            $metrics = [
                'queries' => $wpdb->num_queries,
                'memory_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
            ];

            if (defined('SAVEQUERIES') && SAVEQUERIES && !empty($wpdb->queries)) {
                $slow_queries = array_filter($wpdb->queries, fn($q) => $q[1] > 0.05);
                if (!empty($slow_queries)) {
                    $metrics['slow_queries'] = count($slow_queries);
                    $this->dispatch('performance.slow_queries', $metrics, 6);
                }
            }
        }
    }

    /**
     * Get pending events in queue
     */
    public function get_queue_size(): int {
        return count($this->event_queue);
    }

    /**
     * Enable/disable streaming
     */
    public function set_streaming(bool $enabled): void {
        $this->streaming_enabled = $enabled;
        update_option('rjv_agi_event_streaming', $enabled ? '1' : '0');
    }
}
