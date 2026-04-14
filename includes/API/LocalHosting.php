<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;

use RJV_AGI_Bridge\Hosting\TunnelManager;
use RJV_AGI_Bridge\AI\Router as AIRouter;
use RJV_AGI_Bridge\AuditLog;

/**
 * Local Hosting REST Controller
 *
 * Exposes endpoints to manage zero-cost public hosting for WordPress sites
 * running on a developer's local machine via Cloudflare Tunnel.
 *
 * Routes:
 *   GET  /rjv-agi/v1/hosting/status           – Tunnel status + system info
 *   POST /rjv-agi/v1/hosting/start            – Start quick (no-account) tunnel
 *   POST /rjv-agi/v1/hosting/start-named      – Start authenticated named tunnel
 *   POST /rjv-agi/v1/hosting/stop             – Stop running tunnel
 *   POST /rjv-agi/v1/hosting/apply-url        – Apply tunnel URL to WP options
 *   POST /rjv-agi/v1/hosting/revert-url       – Revert WP URLs to original
 *   POST /rjv-agi/v1/hosting/download-binary  – Download cloudflared binary
 *   GET  /rjv-agi/v1/hosting/log              – Read tunnel process log
 *   POST /rjv-agi/v1/hosting/wizard           – AI-guided onboarding wizard step
 */
class LocalHosting extends Base {

    private TunnelManager $tunnel;

    public function __construct() {
        $this->tunnel = new TunnelManager();
    }

    public function register_routes(): void {
        $ns = $this->namespace;

        register_rest_route($ns, '/hosting/status', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_status'],
            'permission_callback' => [$this, 'admin_only'],
        ]);

        register_rest_route($ns, '/hosting/start', [
            'methods'             => 'POST',
            'callback'            => [$this, 'start_quick'],
            'permission_callback' => [$this, 'admin_only'],
            'args'                => [
                'port' => ['type' => 'integer', 'default' => 80, 'minimum' => 1, 'maximum' => 65535],
            ],
        ]);

        register_rest_route($ns, '/hosting/start-named', [
            'methods'             => 'POST',
            'callback'            => [$this, 'start_named'],
            'permission_callback' => [$this, 'admin_only'],
            'args'                => [
                'token'    => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'hostname' => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'port'     => ['type' => 'integer', 'default' => 80, 'minimum' => 1, 'maximum' => 65535],
            ],
        ]);

        register_rest_route($ns, '/hosting/stop', [
            'methods'             => 'POST',
            'callback'            => [$this, 'stop_tunnel'],
            'permission_callback' => [$this, 'admin_only'],
        ]);

        register_rest_route($ns, '/hosting/apply-url', [
            'methods'             => 'POST',
            'callback'            => [$this, 'apply_url'],
            'permission_callback' => [$this, 'admin_only'],
            'args'                => [
                'url' => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'esc_url_raw'],
            ],
        ]);

        register_rest_route($ns, '/hosting/revert-url', [
            'methods'             => 'POST',
            'callback'            => [$this, 'revert_url'],
            'permission_callback' => [$this, 'admin_only'],
        ]);

        register_rest_route($ns, '/hosting/download-binary', [
            'methods'             => 'POST',
            'callback'            => [$this, 'download_binary'],
            'permission_callback' => [$this, 'admin_only'],
        ]);

        register_rest_route($ns, '/hosting/log', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_log'],
            'permission_callback' => [$this, 'admin_only'],
            'args'                => [
                'lines' => ['type' => 'integer', 'default' => 100, 'minimum' => 1, 'maximum' => 2000],
            ],
        ]);

        register_rest_route($ns, '/hosting/wizard', [
            'methods'             => 'POST',
            'callback'            => [$this, 'wizard_step'],
            'permission_callback' => [$this, 'admin_only'],
            'args'                => [
                'step'    => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_key'],
                'context' => ['type' => 'object', 'default' => []],
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // Handlers
    // -------------------------------------------------------------------------

    public function get_status(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        $status   = $this->tunnel->status();
        $sys_info = $this->system_info();

        return $this->success(array_merge($status, ['system' => $sys_info]));
    }

    public function start_quick(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        $port   = (int) $req->get_param('port');
        $result = $this->tunnel->start_quick($port);

        if (!$result['success']) {
            return $this->error($result['error'] ?? 'Failed to start tunnel', 500);
        }

        return $this->success($result, 200, ['message' => 'Quick tunnel started']);
    }

    public function start_named(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        $token    = (string) $req->get_param('token');
        $hostname = (string) $req->get_param('hostname');
        $port     = (int) $req->get_param('port');

        $result = $this->tunnel->start_named($token, $hostname, $port);

        if (!$result['success']) {
            return $this->error($result['error'] ?? 'Failed to start named tunnel', 500);
        }

        return $this->success($result, 200, ['message' => 'Named tunnel started']);
    }

    public function stop_tunnel(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        $result = $this->tunnel->stop();
        return $this->success($result, 200, ['message' => 'Tunnel stopped']);
    }

    public function apply_url(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        $url    = (string) $req->get_param('url');
        $result = $this->tunnel->apply_to_wordpress($url);
        return $this->success($result, 200, ['message' => 'WordPress URLs updated']);
    }

    public function revert_url(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        $result = $this->tunnel->revert_wordpress_urls();
        return $this->success($result, 200, ['message' => 'WordPress URLs reverted']);
    }

    public function download_binary(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        $result = $this->tunnel->download_binary();
        if (!$result['success']) {
            return $this->error($result['error'] ?? 'Download failed', 500);
        }
        return $this->success($result, 200, ['message' => 'cloudflared binary downloaded']);
    }

    public function get_log(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        $lines  = (int) $req->get_param('lines');
        $result = $this->tunnel->read_log($lines);
        return $this->success($result);
    }

    /**
     * AI-guided wizard step handler.
     *
     * Step names:
     *   "intro"       – Explain local hosting + what will happen.
     *   "check_env"   – Check system prerequisites.
     *   "download"    – Download cloudflared binary.
     *   "start"       – Start quick tunnel.
     *   "configure"   – Walk through named-tunnel Cloudflare setup.
     *   "apply"       – Apply tunnel URL to WordPress.
     *   "complete"    – Final summary.
     */
    public function wizard_step(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        $step    = (string) $req->get_param('step');
        $context = (array)  $req->get_param('context');

        switch ($step) {
            case 'intro':
                return $this->wizard_intro($context);
            case 'check_env':
                return $this->wizard_check_env($context);
            case 'download':
                return $this->success($this->tunnel->download_binary());
            case 'start':
                $port   = (int) ($context['port'] ?? 80);
                return $this->success($this->tunnel->start_quick($port));
            case 'configure':
                return $this->wizard_configure($context);
            case 'apply':
                $url = (string) ($context['url'] ?? '');
                if (empty($url)) {
                    $status = $this->tunnel->status();
                    $url    = $status['url'] ?? '';
                }
                if (empty($url)) {
                    return $this->error('No tunnel URL available to apply', 400);
                }
                return $this->success($this->tunnel->apply_to_wordpress($url));
            case 'complete':
                return $this->wizard_complete($context);
            default:
                return $this->error("Unknown wizard step: {$step}", 400);
        }
    }

    // -------------------------------------------------------------------------
    // Wizard step implementations
    // -------------------------------------------------------------------------

    private function wizard_intro(array $context): \WP_REST_Response {
        return $this->success([
            'step'        => 'intro',
            'title'       => 'Free Public Hosting via Cloudflare Tunnel',
            'description' => 'This wizard will set up a Cloudflare Tunnel so your WordPress site, running on your laptop or desktop, is accessible on the public internet — at zero hosting cost. No paid server needed.',
            'what_happens' => [
                '1. We download the free cloudflared binary from Cloudflare.',
                '2. We start a tunnel from your machine to Cloudflare\'s network.',
                '3. Your site gets a public HTTPS URL (e.g. https://xxxx.trycloudflare.com).',
                '4. (Optional) Connect a real domain via Cloudflare for a permanent URL.',
                '5. (Optional) Register a domain via Cloudflare Registrar if you need one.',
            ],
            'requirements' => [
                'PHP exec/proc_open must be available',
                'Outbound HTTPS (port 443) must be open',
                'WordPress must be reachable on localhost',
            ],
            'next_step' => 'check_env',
        ]);
    }

    private function wizard_check_env(array $context): \WP_REST_Response {
        $checks = [];

        // proc_open availability
        $proc_open = function_exists('proc_open') && !in_array('proc_open', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true);
        $checks['proc_open'] = [
            'ok'   => $proc_open,
            'note' => $proc_open ? 'Available' : 'proc_open is disabled; tunnel cannot be started from PHP. Run cloudflared manually.',
        ];

        // Writable upload dir
        $upload  = wp_upload_dir();
        $writable = is_writable((string) $upload['basedir']);
        $checks['upload_writable'] = [
            'ok'   => $writable,
            'note' => $writable ? 'Upload directory is writable' : 'Upload directory is not writable: ' . $upload['basedir'],
        ];

        // Check if cloudflared already exists
        $status  = $this->tunnel->status();
        $checks['binary_present'] = [
            'ok'   => $status['binary_present'],
            'note' => $status['binary_present'] ? 'cloudflared binary already downloaded at ' . $status['binary_path'] : 'cloudflared binary not yet downloaded',
        ];

        // OS detection
        $checks['os'] = [
            'ok'   => true,
            'note' => 'OS: ' . PHP_OS_FAMILY . ' / ' . php_uname('m'),
        ];

        $all_ok = !in_array(false, array_column($checks, 'ok'), true);

        return $this->success([
            'step'      => 'check_env',
            'all_ok'    => $all_ok,
            'checks'    => $checks,
            'next_step' => $all_ok ? ($status['binary_present'] ? 'start' : 'download') : 'intro',
        ]);
    }

    private function wizard_configure(array $context): \WP_REST_Response {
        return $this->success([
            'step'        => 'configure',
            'title'       => 'Set Up a Permanent Custom Domain (optional)',
            'instructions' => [
                'To use a permanent custom domain instead of a random trycloudflare.com URL:',
                '',
                '1. Create a free Cloudflare account at https://dash.cloudflare.com/sign-up',
                '2. Add your domain to Cloudflare (or register one via Cloudflare Registrar)',
                '3. In the Cloudflare dashboard → Zero Trust → Tunnels → Create a tunnel',
                '4. Copy the tunnel token shown after creation',
                '5. Return here and call POST /hosting/start-named with your token and hostname',
                '',
                'Alternatively, use the /cloudflare/* endpoints in this plugin to automate',
                'zone creation, DNS, and tunnel setup via the Cloudflare API.',
            ],
            'next_step' => 'apply',
        ]);
    }

    private function wizard_complete(array $context): \WP_REST_Response {
        $status  = $this->tunnel->status();

        return $this->success([
            'step'        => 'complete',
            'title'       => 'Your site is now publicly accessible!',
            'tunnel_url'  => $status['url'],
            'tunnel_mode' => $status['mode'],
            'running'     => $status['running'],
            'next_steps'  => [
                'Share your public URL with visitors or collaborators.',
                'Set up Google Analytics via /external-platforms/google/setup.',
                'Set up Microsoft Clarity via /external-platforms/microsoft/setup.',
                'Add a permanent domain via the /cloudflare/setup endpoint.',
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // Permission / helpers
    // -------------------------------------------------------------------------

    public function admin_only(): bool {
        return current_user_can('manage_options');
    }

    private function system_info(): array {
        return [
            'php_version'   => PHP_VERSION,
            'os_family'     => PHP_OS_FAMILY,
            'os_arch'       => php_uname('m'),
            'wp_version'    => get_bloginfo('version'),
            'server_addr'   => $_SERVER['SERVER_ADDR'] ?? 'unknown',
            'https'         => is_ssl(),
            'site_url'      => get_option('siteurl'),
            'home'          => get_option('home'),
        ];
    }
}
