<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge;

final class Plugin {
    private static ?self $instance = null;
    private Admin\Dashboard $dashboard;
    public static function instance(): self {
        return self::$instance ??= new self();
    }
    private function __construct() {
        $files = ['Installer','Settings','Auth','AuditLog','AI/Provider','AI/OpenAI','AI/Anthropic','AI/Router',
            'API/Base','API/Posts','API/Pages','API/Media','API/Users','API/Options','API/Themes','API/Plugins',
            'API/Menus','API/Widgets','API/SEO','API/Comments','API/Taxonomies','API/SiteHealth','API/ContentGen',
            'API/Database','API/FileSystem','API/Cron','Admin/Dashboard'];
        foreach ($files as $f) { $p = RJV_AGI_PLUGIN_DIR."includes/{$f}.php"; if(file_exists($p)) require_once $p; }
        Installer::maybe_upgrade();
        $this->dashboard = new Admin\Dashboard();
        add_action('rest_api_init', [$this, 'register_routes']);
        add_action('admin_menu', [$this->dashboard, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this->dashboard, 'enqueue_assets']);
        add_filter('rest_pre_dispatch', [$this, 'rate_limit'], 10, 3);
        if (!wp_next_scheduled('rjv_agi_log_cleanup')) {
            wp_schedule_event(time(), 'daily', 'rjv_agi_log_cleanup');
        }
        add_action('rjv_agi_log_cleanup', [AuditLog::class, 'cleanup']);
    }
    public function register_routes(): void {
        $ctrls = [new API\Posts(),new API\Pages(),new API\Media(),new API\Users(),new API\Options(),
            new API\Themes(),new API\Plugins(),new API\Menus(),new API\Widgets(),new API\SEO(),
            new API\Comments(),new API\Taxonomies(),new API\SiteHealth(),new API\ContentGen(),
            new API\Database(),new API\FileSystem(),new API\Cron()];
        foreach ($ctrls as $c) $c->register_routes();
    }
    public function rate_limit($result, $server, $request) {
        $route = $request->get_route();
        if (strpos($route, '/rjv-agi/v1/') !== 0) return $result;
        $key = $request->get_header('X-RJV-AGI-Key');
        if (empty($key)) return $result;
        $tk = 'rjv_rl_' . hash('sha256', $key);
        $count = (int) get_transient($tk);
        $limit = (int) get_option('rjv_agi_rate_limit', 600);
        if ($count >= $limit) return new \WP_Error('rate_limit', 'Rate limit exceeded', ['status'=>429]);
        set_transient($tk, $count + 1, MINUTE_IN_SECONDS);
        return $result;
    }
}
