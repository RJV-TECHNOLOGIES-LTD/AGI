<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;

use RJV_AGI_Bridge\Integrations\CloudflareAPI;
use RJV_AGI_Bridge\Hosting\TunnelManager;
use RJV_AGI_Bridge\AuditLog;

/**
 * Cloudflare Manager REST Controller
 *
 * Provides full programmatic access to Cloudflare for WordPress sites,
 * covering zone management, DNS, SSL, speed, tunnels and domain registration.
 *
 * Routes (all require manage_options capability):
 *
 *   Account & Zones
 *     GET  /cloudflare/accounts                  – List Cloudflare accounts
 *     GET  /cloudflare/zones                     – List zones (domains)
 *     POST /cloudflare/zones                     – Add a domain to Cloudflare
 *     GET  /cloudflare/zones/{id}                – Get zone detail
 *     DELETE /cloudflare/zones/{id}              – Remove zone
 *     GET  /cloudflare/zones/{id}/nameservers    – Get assigned nameservers
 *     POST /cloudflare/zones/{id}/purge-cache    – Purge all cache
 *
 *   DNS
 *     GET  /cloudflare/zones/{id}/dns            – List DNS records
 *     POST /cloudflare/zones/{id}/dns            – Create DNS record
 *     PATCH /cloudflare/zones/{id}/dns/{rid}     – Update DNS record
 *     DELETE /cloudflare/zones/{id}/dns/{rid}    – Delete DNS record
 *
 *   SSL & Security
 *     GET  /cloudflare/zones/{id}/ssl            – Get SSL mode
 *     POST /cloudflare/zones/{id}/ssl            – Set SSL mode
 *     POST /cloudflare/zones/{id}/always-https   – Toggle always-HTTPS
 *
 *   Speed & Performance
 *     POST /cloudflare/zones/{id}/speed          – Apply speed settings
 *
 *   Page Rules
 *     GET  /cloudflare/zones/{id}/page-rules     – List page rules
 *     POST /cloudflare/zones/{id}/page-rules     – Create page rule
 *     DELETE /cloudflare/zones/{id}/page-rules/{rid} – Delete page rule
 *
 *   Tunnels
 *     GET  /cloudflare/tunnels                   – List tunnels
 *     POST /cloudflare/tunnels                   – Create named tunnel
 *     GET  /cloudflare/tunnels/{tid}/token       – Get tunnel token
 *     POST /cloudflare/tunnels/{tid}/configure   – Configure route (hostname→service)
 *     DELETE /cloudflare/tunnels/{tid}           – Delete tunnel
 *
 *   Domain Registration
 *     GET  /cloudflare/registrar/check           – Check domain availability
 *     GET  /cloudflare/registrar/domains         – List registered domains
 *     POST /cloudflare/registrar/register        – Register a domain
 *
 *   Wizard
 *     POST /cloudflare/setup                     – Full WordPress Cloudflare setup in one call
 */
class CloudflareManager extends Base {

    public function register_routes(): void {
        $ns = $this->namespace;

        // ---- Account & Zones ----
        register_rest_route($ns, '/cloudflare/accounts', [
            'methods'             => 'GET',
            'callback'            => [$this, 'list_accounts'],
            'permission_callback' => [$this, 'can_manage'],
        ]);

        register_rest_route($ns, '/cloudflare/zones', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'list_zones'],
                'permission_callback' => [$this, 'can_manage'],
                'args'                => [
                    'name'     => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                    'status'   => ['type' => 'string', 'enum' => ['active', 'pending', 'initializing', 'moved', 'deleted', 'deactivated']],
                    'page'     => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
                    'per_page' => ['type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100],
                ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'create_zone'],
                'permission_callback' => [$this, 'can_manage'],
                'args'                => [
                    'name'        => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                    'account_id'  => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                    'jump_start'  => ['type' => 'boolean', 'default' => true],
                    'type'        => ['type' => 'string', 'enum' => ['full', 'partial'], 'default' => 'full'],
                ],
            ],
        ]);

        register_rest_route($ns, '/cloudflare/zones/(?P<zone_id>[a-z0-9]+)', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_zone'],
                'permission_callback' => [$this, 'can_manage'],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [$this, 'delete_zone'],
                'permission_callback' => [$this, 'can_manage'],
            ],
        ]);

        register_rest_route($ns, '/cloudflare/zones/(?P<zone_id>[a-z0-9]+)/nameservers', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_nameservers'],
            'permission_callback' => [$this, 'can_manage'],
        ]);

        register_rest_route($ns, '/cloudflare/zones/(?P<zone_id>[a-z0-9]+)/purge-cache', [
            'methods'             => 'POST',
            'callback'            => [$this, 'purge_cache'],
            'permission_callback' => [$this, 'can_manage'],
            'args'                => [
                'urls' => ['type' => 'array', 'items' => ['type' => 'string'], 'default' => []],
            ],
        ]);

        // ---- DNS ----
        register_rest_route($ns, '/cloudflare/zones/(?P<zone_id>[a-z0-9]+)/dns', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'list_dns'],
                'permission_callback' => [$this, 'can_manage'],
                'args'                => [
                    'type' => ['type' => 'string'],
                    'name' => ['type' => 'string'],
                ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'create_dns'],
                'permission_callback' => [$this, 'can_manage'],
                'args'                => [
                    'type'     => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                    'name'     => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                    'content'  => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                    'ttl'      => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
                    'proxied'  => ['type' => 'boolean', 'default' => false],
                    'priority' => ['type' => 'integer', 'default' => 10, 'minimum' => 0],
                ],
            ],
        ]);

        register_rest_route($ns, '/cloudflare/zones/(?P<zone_id>[a-z0-9]+)/dns/(?P<record_id>[a-z0-9]+)', [
            [
                'methods'             => 'PATCH',
                'callback'            => [$this, 'update_dns'],
                'permission_callback' => [$this, 'can_manage'],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [$this, 'delete_dns'],
                'permission_callback' => [$this, 'can_manage'],
            ],
        ]);

        // ---- SSL ----
        register_rest_route($ns, '/cloudflare/zones/(?P<zone_id>[a-z0-9]+)/ssl', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_ssl'],
                'permission_callback' => [$this, 'can_manage'],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'set_ssl'],
                'permission_callback' => [$this, 'can_manage'],
                'args'                => [
                    'mode' => ['type' => 'string', 'required' => true, 'enum' => ['off', 'flexible', 'full', 'strict']],
                ],
            ],
        ]);

        register_rest_route($ns, '/cloudflare/zones/(?P<zone_id>[a-z0-9]+)/always-https', [
            'methods'             => 'POST',
            'callback'            => [$this, 'toggle_always_https'],
            'permission_callback' => [$this, 'can_manage'],
            'args'                => [
                'enable' => ['type' => 'boolean', 'default' => true],
            ],
        ]);

        // ---- Speed ----
        register_rest_route($ns, '/cloudflare/zones/(?P<zone_id>[a-z0-9]+)/speed', [
            'methods'             => 'POST',
            'callback'            => [$this, 'apply_speed_settings'],
            'permission_callback' => [$this, 'can_manage'],
            'args'                => [
                'minify'            => ['type' => 'boolean', 'default' => true],
                'brotli'            => ['type' => 'boolean', 'default' => true],
                'rocket_loader'     => ['type' => 'string', 'enum' => ['on', 'off', 'manual'], 'default' => 'on'],
                'polish'            => ['type' => 'string', 'enum' => ['off', 'lossless', 'lossy'], 'default' => 'lossless'],
                'browser_cache_ttl' => ['type' => 'integer', 'default' => 14400],
            ],
        ]);

        // ---- Page Rules ----
        register_rest_route($ns, '/cloudflare/zones/(?P<zone_id>[a-z0-9]+)/page-rules', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'list_page_rules'],
                'permission_callback' => [$this, 'can_manage'],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'create_page_rule'],
                'permission_callback' => [$this, 'can_manage'],
                'args'                => [
                    'url_pattern' => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                    'actions'     => ['type' => 'array', 'required' => true],
                    'priority'    => ['type' => 'integer', 'default' => 1],
                    'status'      => ['type' => 'string', 'enum' => ['active', 'disabled'], 'default' => 'active'],
                ],
            ],
        ]);

        register_rest_route($ns, '/cloudflare/zones/(?P<zone_id>[a-z0-9]+)/page-rules/(?P<rule_id>[a-z0-9]+)', [
            'methods'             => 'DELETE',
            'callback'            => [$this, 'delete_page_rule'],
            'permission_callback' => [$this, 'can_manage'],
        ]);

        // ---- Tunnels ----
        register_rest_route($ns, '/cloudflare/tunnels', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'list_tunnels'],
                'permission_callback' => [$this, 'can_manage'],
                'args'                => ['account_id' => ['type' => 'string']],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'create_tunnel'],
                'permission_callback' => [$this, 'can_manage'],
                'args'                => [
                    'name'       => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                    'secret'     => ['type' => 'string', 'required' => true],
                    'account_id' => ['type' => 'string'],
                ],
            ],
        ]);

        register_rest_route($ns, '/cloudflare/tunnels/(?P<tunnel_id>[a-z0-9\-]+)/token', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_tunnel_token'],
            'permission_callback' => [$this, 'can_manage'],
            'args'                => ['account_id' => ['type' => 'string']],
        ]);

        register_rest_route($ns, '/cloudflare/tunnels/(?P<tunnel_id>[a-z0-9\-]+)/configure', [
            'methods'             => 'POST',
            'callback'            => [$this, 'configure_tunnel'],
            'permission_callback' => [$this, 'can_manage'],
            'args'                => [
                'hostname'   => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'service'    => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'account_id' => ['type' => 'string'],
            ],
        ]);

        register_rest_route($ns, '/cloudflare/tunnels/(?P<tunnel_id>[a-z0-9\-]+)', [
            'methods'             => 'DELETE',
            'callback'            => [$this, 'delete_tunnel'],
            'permission_callback' => [$this, 'can_manage'],
            'args'                => ['account_id' => ['type' => 'string']],
        ]);

        // ---- Registrar ----
        register_rest_route($ns, '/cloudflare/registrar/check', [
            'methods'             => 'GET',
            'callback'            => [$this, 'check_domain'],
            'permission_callback' => [$this, 'can_manage'],
            'args'                => [
                'domain'     => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'account_id' => ['type' => 'string'],
            ],
        ]);

        register_rest_route($ns, '/cloudflare/registrar/domains', [
            'methods'             => 'GET',
            'callback'            => [$this, 'list_registered_domains'],
            'permission_callback' => [$this, 'can_manage'],
            'args'                => ['account_id' => ['type' => 'string']],
        ]);

        register_rest_route($ns, '/cloudflare/registrar/register', [
            'methods'             => 'POST',
            'callback'            => [$this, 'register_domain'],
            'permission_callback' => [$this, 'can_manage'],
            'args'                => [
                'domain'              => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'account_id'          => ['type' => 'string'],
                'years'               => ['type' => 'integer', 'default' => 1, 'minimum' => 1, 'maximum' => 10],
                'auto_renew'          => ['type' => 'boolean', 'default' => true],
                'privacy_protection'  => ['type' => 'boolean', 'default' => true],
            ],
        ]);

        // ---- Full WordPress Setup Wizard ----
        register_rest_route($ns, '/cloudflare/setup', [
            'methods'             => 'POST',
            'callback'            => [$this, 'setup_wordpress'],
            'permission_callback' => [$this, 'can_manage'],
            'args'                => [
                'zone_id'       => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'server_ip'     => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => ''],
            ],
        ]);
    }

    // =========================================================================
    // Handlers
    // =========================================================================

    public function list_accounts(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        return $this->cf_call(fn(CloudflareAPI $cf) => $cf->list_accounts());
    }

    public function list_zones(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        $filters = array_filter([
            'name'     => $req->get_param('name'),
            'status'   => $req->get_param('status'),
            'page'     => $req->get_param('page'),
            'per_page' => $req->get_param('per_page'),
        ]);
        return $this->cf_call(fn(CloudflareAPI $cf) => $cf->list_zones($filters));
    }

    public function create_zone(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        $name       = (string) $req->get_param('name');
        $account_id = (string) ($req->get_param('account_id') ?? '');
        $jump_start = (bool)   $req->get_param('jump_start');
        $type       = (string) $req->get_param('type');

        return $this->cf_call(
            fn(CloudflareAPI $cf) => $cf->create_zone($name, $account_id, $jump_start, $type),
            'zone_created',
            ['name' => $name]
        );
    }

    public function get_zone(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        return $this->cf_call(fn(CloudflareAPI $cf) => $cf->get_zone($req['zone_id']));
    }

    public function delete_zone(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        return $this->cf_call(
            fn(CloudflareAPI $cf) => $cf->delete_zone($req['zone_id']),
            'zone_deleted',
            ['zone_id' => $req['zone_id']]
        );
    }

    public function get_nameservers(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        return $this->cf_call(fn(CloudflareAPI $cf) => $cf->get_nameservers($req['zone_id']));
    }

    public function purge_cache(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        $urls = (array) $req->get_param('urls');
        return $this->cf_call(function (CloudflareAPI $cf) use ($req, $urls) {
            return empty($urls)
                ? $cf->purge_cache($req['zone_id'])
                : $cf->purge_urls($req['zone_id'], $urls);
        }, 'cache_purged', ['zone_id' => $req['zone_id']]);
    }

    public function list_dns(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        $filters = array_filter([
            'type' => $req->get_param('type'),
            'name' => $req->get_param('name'),
        ]);
        return $this->cf_call(fn(CloudflareAPI $cf) => $cf->list_dns_records($req['zone_id'], $filters));
    }

    public function create_dns(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        return $this->cf_call(
            fn(CloudflareAPI $cf) => $cf->create_dns_record(
                $req['zone_id'],
                (string) $req->get_param('type'),
                (string) $req->get_param('name'),
                (string) $req->get_param('content'),
                (int)    $req->get_param('ttl'),
                (bool)   $req->get_param('proxied'),
                (int)    $req->get_param('priority')
            ),
            'dns_record_created',
            ['zone_id' => $req['zone_id'], 'type' => $req->get_param('type'), 'name' => $req->get_param('name')]
        );
    }

    public function update_dns(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        $updates = array_filter($req->get_params(), fn($v) => $v !== null);
        unset($updates['zone_id'], $updates['record_id']);
        return $this->cf_call(
            fn(CloudflareAPI $cf) => $cf->update_dns_record($req['zone_id'], $req['record_id'], $updates),
            'dns_record_updated'
        );
    }

    public function delete_dns(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        return $this->cf_call(
            fn(CloudflareAPI $cf) => $cf->delete_dns_record($req['zone_id'], $req['record_id']),
            'dns_record_deleted'
        );
    }

    public function get_ssl(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        return $this->cf_call(fn(CloudflareAPI $cf) => $cf->get_ssl_settings($req['zone_id']));
    }

    public function set_ssl(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        $mode = (string) $req->get_param('mode');
        return $this->cf_call(
            fn(CloudflareAPI $cf) => $cf->set_ssl_mode($req['zone_id'], $mode),
            'ssl_mode_set',
            ['zone_id' => $req['zone_id'], 'mode' => $mode]
        );
    }

    public function toggle_always_https(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        $enable = (bool) $req->get_param('enable');
        return $this->cf_call(
            fn(CloudflareAPI $cf) => $cf->enable_always_https($req['zone_id'], $enable),
            'always_https_toggled'
        );
    }

    public function apply_speed_settings(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        $zone_id = $req['zone_id'];
        return $this->cf_call(function (CloudflareAPI $cf) use ($req, $zone_id) {
            $results = [];
            $results['minify']         = $cf->set_minification($zone_id, (bool) $req->get_param('minify'));
            $results['brotli']         = $cf->set_brotli($zone_id, (bool) $req->get_param('brotli'));
            $results['rocket_loader']  = $cf->set_rocket_loader($zone_id, (string) $req->get_param('rocket_loader'));
            $results['polish']         = $cf->set_polish($zone_id, (string) $req->get_param('polish'));
            $results['browser_cache']  = $cf->set_browser_cache_ttl($zone_id, (int) $req->get_param('browser_cache_ttl'));
            return ['success' => true, 'results' => $results];
        }, 'speed_settings_applied');
    }

    public function list_page_rules(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        return $this->cf_call(fn(CloudflareAPI $cf) => $cf->list_page_rules($req['zone_id']));
    }

    public function create_page_rule(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        return $this->cf_call(
            fn(CloudflareAPI $cf) => $cf->create_page_rule(
                $req['zone_id'],
                (string) $req->get_param('url_pattern'),
                (array)  $req->get_param('actions'),
                (int)    $req->get_param('priority'),
                (string) $req->get_param('status')
            ),
            'page_rule_created'
        );
    }

    public function delete_page_rule(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        return $this->cf_call(
            fn(CloudflareAPI $cf) => $cf->delete_page_rule($req['zone_id'], $req['rule_id']),
            'page_rule_deleted'
        );
    }

    public function list_tunnels(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        $account_id = (string) ($req->get_param('account_id') ?? '');
        return $this->cf_call(fn(CloudflareAPI $cf) => $cf->list_tunnels($account_id));
    }

    public function create_tunnel(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        return $this->cf_call(
            fn(CloudflareAPI $cf) => $cf->create_tunnel(
                (string) $req->get_param('name'),
                (string) $req->get_param('secret'),
                (string) ($req->get_param('account_id') ?? '')
            ),
            'tunnel_created'
        );
    }

    public function get_tunnel_token(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        return $this->cf_call(
            fn(CloudflareAPI $cf) => $cf->get_tunnel_token($req['tunnel_id'], (string) ($req->get_param('account_id') ?? ''))
        );
    }

    public function configure_tunnel(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        return $this->cf_call(
            fn(CloudflareAPI $cf) => $cf->configure_tunnel_route(
                $req['tunnel_id'],
                (string) $req->get_param('hostname'),
                (string) $req->get_param('service'),
                (string) ($req->get_param('account_id') ?? '')
            ),
            'tunnel_route_configured'
        );
    }

    public function delete_tunnel(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        return $this->cf_call(
            fn(CloudflareAPI $cf) => $cf->delete_tunnel($req['tunnel_id'], (string) ($req->get_param('account_id') ?? '')),
            'tunnel_deleted'
        );
    }

    public function check_domain(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        return $this->cf_call(
            fn(CloudflareAPI $cf) => $cf->check_domain_availability(
                (string) $req->get_param('domain'),
                (string) ($req->get_param('account_id') ?? '')
            )
        );
    }

    public function list_registered_domains(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        return $this->cf_call(
            fn(CloudflareAPI $cf) => $cf->list_registered_domains((string) ($req->get_param('account_id') ?? ''))
        );
    }

    public function register_domain(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        $options = [
            'years'              => (int)  $req->get_param('years'),
            'auto_renew'         => (bool) $req->get_param('auto_renew'),
            'privacy_protection' => (bool) $req->get_param('privacy_protection'),
        ];
        return $this->cf_call(
            fn(CloudflareAPI $cf) => $cf->register_domain(
                (string) $req->get_param('domain'),
                $options,
                (string) ($req->get_param('account_id') ?? '')
            ),
            'domain_registered',
            ['domain' => $req->get_param('domain')]
        );
    }

    public function setup_wordpress(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        $zone_id   = (string) $req->get_param('zone_id');
        $server_ip = (string) $req->get_param('server_ip');

        return $this->cf_call(
            fn(CloudflareAPI $cf) => $cf->setup_wordpress($zone_id, $server_ip),
            'cloudflare_wordpress_setup',
            ['zone_id' => $zone_id]
        );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** Build a CloudflareAPI instance from stored options. */
    private function build_client(): CloudflareAPI {
        $token      = (string) get_option('rjv_agi_cloudflare_token', '');
        $email      = (string) get_option('rjv_agi_cloudflare_email', '');
        $api_key    = (string) get_option('rjv_agi_cloudflare_api_key', '');
        $account_id = (string) get_option('rjv_agi_cloudflare_account_id', '');

        return new CloudflareAPI($token, $email, $api_key, $account_id ?: null);
    }

    /**
     * Execute a Cloudflare API call, normalize the response, optionally log.
     *
     * @param  callable    $call       Receives CloudflareAPI, returns result array.
     * @param  string|null $log_action AuditLog action name (null = skip logging).
     * @param  array       $log_ctx    Extra audit context.
     */
    private function cf_call(callable $call, ?string $log_action = null, array $log_ctx = []): \WP_REST_Response|\WP_Error {
        $cf     = $this->build_client();
        $result = $call($cf);

        if ($log_action !== null) {
            AuditLog::log($log_action, 'cloudflare', 0, $log_ctx, 2);
        }

        if (!($result['success'] ?? false)) {
            $err = $result['error'] ?? (implode('; ', $result['errors'] ?? []) ?: 'Cloudflare API error');
            return $this->error($err, 502);
        }

        return $this->success($result['data'] ?? $result, 200, [
            'errors' => $result['errors'] ?? [],
            'meta'   => $result['meta'] ?? [],
        ]);
    }

    public function can_manage(): bool {
        return current_user_can('manage_options');
    }
}
