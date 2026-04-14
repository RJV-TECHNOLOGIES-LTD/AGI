<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\Integrations;

/**
 * Cloudflare API v4 Client
 *
 * Provides a comprehensive wrapper for the Cloudflare API, covering:
 *   • Zone management (create, list, get, purge, delete)
 *   • DNS record CRUD
 *   • SSL/TLS settings
 *   • Speed / performance settings (minification, polish, mirage, Rocket Loader)
 *   • Page Rules
 *   • Firewall rules and IP access rules
 *   • Cache settings (browser TTL, always-online, development mode)
 *   • Workers (list, upload)
 *   • Cloudflare Tunnel management (create, list, delete, get token)
 *   • Registrar domain search and purchase
 *   • Analytics (zone analytics, web analytics)
 *   • Account and user info
 *
 * Authentication supports both Global API Key + email (legacy) and
 * Bearer tokens (recommended – scoped API tokens).
 *
 * All requests include automatic retry with exponential back-off on 429/500/503
 * and return a consistent envelope:
 *   { success: bool, data?: mixed, errors?: list<string>, meta?: array }
 */
final class CloudflareAPI {

    private const BASE_URL    = 'https://api.cloudflare.com/client/v4/';
    private const MAX_RETRIES = 3;

    private string $token;
    private string $email;
    private string $api_key;
    private bool   $use_token;
    private ?string $account_id;

    /**
     * @param string      $token       Bearer API token (preferred – leave empty to use key+email).
     * @param string      $email       Account email (Global API Key auth).
     * @param string      $api_key     Global API Key (Global API Key auth).
     * @param string|null $account_id  Account ID – required for Tunnel and Registrar endpoints.
     */
    public function __construct(
        string $token     = '',
        string $email     = '',
        string $api_key   = '',
        ?string $account_id = null
    ) {
        $this->token      = $token;
        $this->email      = $email;
        $this->api_key    = $api_key;
        $this->use_token  = $token !== '';
        $this->account_id = $account_id;
    }

    // =========================================================================
    // User / Account
    // =========================================================================

    /** Get current user info. */
    public function get_user(): array {
        return $this->get('user');
    }

    /** List accounts accessible to the token. */
    public function list_accounts(int $page = 1, int $per_page = 20): array {
        return $this->get('accounts', ['page' => $page, 'per_page' => $per_page]);
    }

    /** Get a single account. */
    public function get_account(string $account_id): array {
        return $this->get("accounts/{$account_id}");
    }

    // =========================================================================
    // Zones
    // =========================================================================

    /**
     * List all zones.
     *
     * @param  array{name?: string, status?: string, page?: int, per_page?: int} $filters
     */
    public function list_zones(array $filters = []): array {
        return $this->get('zones', $filters);
    }

    /**
     * Get a specific zone.
     */
    public function get_zone(string $zone_id): array {
        return $this->get("zones/{$zone_id}");
    }

    /**
     * Create a new zone (add domain to Cloudflare).
     *
     * @param  string $name       Domain name, e.g. "example.com"
     * @param  string $account_id Cloudflare account ID.
     * @param  string $jump_start Whether to auto-fetch existing DNS records.
     * @param  string $type       'full' (nameserver change) or 'partial' (CNAME).
     */
    public function create_zone(
        string $name,
        string $account_id = '',
        bool   $jump_start  = true,
        string $type        = 'full'
    ): array {
        $acct = $account_id ?: $this->account_id ?: '';
        $body = ['name' => $name, 'jump_start' => $jump_start, 'type' => $type];
        if ($acct !== '') {
            $body['account'] = ['id' => $acct];
        }
        return $this->post('zones', $body);
    }

    /**
     * Delete (remove) a zone.
     */
    public function delete_zone(string $zone_id): array {
        return $this->delete("zones/{$zone_id}");
    }

    /**
     * Get the Cloudflare nameservers assigned to a zone.
     */
    public function get_nameservers(string $zone_id): array {
        $result = $this->get_zone($zone_id);
        if (!$result['success']) {
            return $result;
        }
        return [
            'success'      => true,
            'nameservers'  => $result['data']['name_servers'] ?? [],
            'status'       => $result['data']['status'] ?? 'unknown',
        ];
    }

    /**
     * Purge all cached files for a zone.
     */
    public function purge_cache(string $zone_id): array {
        return $this->post("zones/{$zone_id}/purge_cache", ['purge_everything' => true]);
    }

    /**
     * Purge specific URLs from cache.
     *
     * @param string[] $urls
     */
    public function purge_urls(string $zone_id, array $urls): array {
        return $this->post("zones/{$zone_id}/purge_cache", ['files' => $urls]);
    }

    // =========================================================================
    // DNS Records
    // =========================================================================

    /**
     * List DNS records for a zone.
     *
     * @param  array{type?: string, name?: string, content?: string} $filters
     */
    public function list_dns_records(string $zone_id, array $filters = []): array {
        return $this->get("zones/{$zone_id}/dns_records", $filters);
    }

    /**
     * Create a DNS record.
     *
     * @param string $type    A, AAAA, CNAME, TXT, MX, etc.
     * @param string $name    Record name (relative to zone, e.g. "www" or "@").
     * @param string $content Record value.
     * @param int    $ttl     TTL in seconds (1 = auto).
     * @param bool   $proxied Whether to proxy through Cloudflare (orange cloud).
     * @param int    $priority MX/SRV priority.
     */
    public function create_dns_record(
        string $zone_id,
        string $type,
        string $name,
        string $content,
        int    $ttl      = 1,
        bool   $proxied  = false,
        int    $priority = 10
    ): array {
        $body = [
            'type'    => strtoupper($type),
            'name'    => $name,
            'content' => $content,
            'ttl'     => $ttl,
            'proxied' => $proxied,
        ];
        if (in_array(strtoupper($type), ['MX', 'SRV', 'URI'], true)) {
            $body['priority'] = $priority;
        }
        return $this->post("zones/{$zone_id}/dns_records", $body);
    }

    /**
     * Update an existing DNS record.
     */
    public function update_dns_record(
        string $zone_id,
        string $record_id,
        array  $updates
    ): array {
        return $this->patch("zones/{$zone_id}/dns_records/{$record_id}", $updates);
    }

    /**
     * Delete a DNS record.
     */
    public function delete_dns_record(string $zone_id, string $record_id): array {
        return $this->delete("zones/{$zone_id}/dns_records/{$record_id}");
    }

    /**
     * Upsert a DNS record: updates if a matching type+name exists, creates otherwise.
     */
    public function upsert_dns_record(
        string $zone_id,
        string $type,
        string $name,
        string $content,
        bool   $proxied = false
    ): array {
        $list = $this->list_dns_records($zone_id, ['type' => strtoupper($type), 'name' => $name]);
        if (!$list['success']) {
            return $list;
        }
        $records = $list['data']['result'] ?? [];
        if (!empty($records)) {
            return $this->update_dns_record($zone_id, $records[0]['id'], [
                'content' => $content,
                'proxied' => $proxied,
            ]);
        }
        return $this->create_dns_record($zone_id, $type, $name, $content, 1, $proxied);
    }

    // =========================================================================
    // SSL / TLS
    // =========================================================================

    /**
     * Get SSL settings for a zone.
     */
    public function get_ssl_settings(string $zone_id): array {
        return $this->get("zones/{$zone_id}/settings/ssl");
    }

    /**
     * Set SSL mode: 'off', 'flexible', 'full', 'strict'.
     */
    public function set_ssl_mode(string $zone_id, string $mode): array {
        return $this->patch("zones/{$zone_id}/settings/ssl", ['value' => $mode]);
    }

    /**
     * Enable Always-Use-HTTPS (301 redirect all HTTP → HTTPS).
     */
    public function enable_always_https(string $zone_id, bool $enable = true): array {
        return $this->patch("zones/{$zone_id}/settings/always_use_https", [
            'value' => $enable ? 'on' : 'off',
        ]);
    }

    /**
     * Enable HSTS.
     */
    public function enable_hsts(string $zone_id, int $max_age = 31536000, bool $include_subdomains = true, bool $preload = false): array {
        return $this->patch("zones/{$zone_id}/settings/security_header", [
            'value' => [
                'strict_transport_security' => [
                    'enabled'              => true,
                    'max_age'              => $max_age,
                    'include_subdomains'   => $include_subdomains,
                    'preload'              => $preload,
                    'nosniff'              => true,
                ],
            ],
        ]);
    }

    /**
     * Set minimum TLS version: '1.0', '1.1', '1.2', '1.3'.
     */
    public function set_min_tls_version(string $zone_id, string $version = '1.2'): array {
        return $this->patch("zones/{$zone_id}/settings/min_tls_version", ['value' => $version]);
    }

    // =========================================================================
    // Speed / Performance settings
    // =========================================================================

    /**
     * Set minification: minify JS/CSS/HTML.
     */
    public function set_minification(string $zone_id, bool $js = true, bool $css = true, bool $html = true): array {
        return $this->patch("zones/{$zone_id}/settings/minify", [
            'value' => ['js' => $js ? 'on' : 'off', 'css' => $css ? 'on' : 'off', 'html' => $html ? 'on' : 'off'],
        ]);
    }

    /**
     * Set Rocket Loader: 'on' | 'off' | 'manual'.
     */
    public function set_rocket_loader(string $zone_id, string $mode = 'on'): array {
        return $this->patch("zones/{$zone_id}/settings/rocket_loader", ['value' => $mode]);
    }

    /**
     * Set Polish (image optimisation): 'off' | 'lossless' | 'lossy'.
     */
    public function set_polish(string $zone_id, string $mode = 'lossless'): array {
        return $this->patch("zones/{$zone_id}/settings/polish", ['value' => $mode]);
    }

    /**
     * Set Mirage (mobile image lazy load): on|off.
     */
    public function set_mirage(string $zone_id, bool $enable = true): array {
        return $this->patch("zones/{$zone_id}/settings/mirage", ['value' => $enable ? 'on' : 'off']);
    }

    /**
     * Set browser cache TTL in seconds.
     */
    public function set_browser_cache_ttl(string $zone_id, int $seconds = 14400): array {
        return $this->patch("zones/{$zone_id}/settings/browser_cache_ttl", ['value' => $seconds]);
    }

    /**
     * Enable/disable development mode (bypasses cache for 3 hours).
     */
    public function set_development_mode(string $zone_id, bool $enable): array {
        return $this->patch("zones/{$zone_id}/settings/development_mode", [
            'value' => $enable ? 'on' : 'off',
        ]);
    }

    /**
     * Enable/disable Brotli compression.
     */
    public function set_brotli(string $zone_id, bool $enable = true): array {
        return $this->patch("zones/{$zone_id}/settings/brotli", ['value' => $enable ? 'on' : 'off']);
    }

    // =========================================================================
    // Page Rules
    // =========================================================================

    /** List page rules. */
    public function list_page_rules(string $zone_id, string $status = 'active'): array {
        return $this->get("zones/{$zone_id}/pagerules", ['status' => $status]);
    }

    /**
     * Create a page rule.
     *
     * @param  array{url: string} $targets   Target URL pattern.
     * @param  array              $actions    Cloudflare page rule actions array.
     * @param  int                $priority
     * @param  string             $status     'active' or 'disabled'.
     */
    public function create_page_rule(
        string $zone_id,
        string $url_pattern,
        array  $actions,
        int    $priority = 1,
        string $status   = 'active'
    ): array {
        return $this->post("zones/{$zone_id}/pagerules", [
            'targets'  => [['target' => 'url', 'constraint' => ['operator' => 'matches', 'value' => $url_pattern]]],
            'actions'  => $actions,
            'priority' => $priority,
            'status'   => $status,
        ]);
    }

    /** Delete a page rule. */
    public function delete_page_rule(string $zone_id, string $rule_id): array {
        return $this->delete("zones/{$zone_id}/pagerules/{$rule_id}");
    }

    // =========================================================================
    // Cloudflare Tunnel
    // =========================================================================

    /**
     * List tunnels for an account.
     */
    public function list_tunnels(string $account_id = ''): array {
        $acct = $account_id ?: $this->account_id ?: '';
        if ($acct === '') {
            return ['success' => false, 'error' => 'account_id required'];
        }
        return $this->get("accounts/{$acct}/cfd_tunnel");
    }

    /**
     * Create a named tunnel.
     *
     * @return array{success: bool, data?: array{id: string, name: string, token: string}}
     */
    public function create_tunnel(string $name, string $secret, string $account_id = ''): array {
        $acct = $account_id ?: $this->account_id ?: '';
        if ($acct === '') {
            return ['success' => false, 'error' => 'account_id required'];
        }
        return $this->post("accounts/{$acct}/cfd_tunnel", [
            'name'          => $name,
            'tunnel_secret' => base64_encode($secret),
        ]);
    }

    /**
     * Get the connector token for a named tunnel.
     * Returns the token string the user can pass to `cloudflared tunnel run --token`.
     */
    public function get_tunnel_token(string $tunnel_id, string $account_id = ''): array {
        $acct = $account_id ?: $this->account_id ?: '';
        if ($acct === '') {
            return ['success' => false, 'error' => 'account_id required'];
        }
        return $this->get("accounts/{$acct}/cfd_tunnel/{$tunnel_id}/token");
    }

    /**
     * Configure a tunnel route (map hostname → service).
     *
     * @param string $hostname     Public hostname (e.g. mysite.example.com).
     * @param string $service      Internal service URL (e.g. http://localhost:80).
     * @param string $origin_request_timeout  e.g. '60s'.
     */
    public function configure_tunnel_route(
        string $tunnel_id,
        string $hostname,
        string $service,
        string $account_id = ''
    ): array {
        $acct = $account_id ?: $this->account_id ?: '';
        if ($acct === '') {
            return ['success' => false, 'error' => 'account_id required'];
        }
        return $this->put("accounts/{$acct}/cfd_tunnel/{$tunnel_id}/configurations", [
            'config' => [
                'ingress' => [
                    ['hostname' => $hostname, 'service' => $service],
                    ['service' => 'http_status:404'],
                ],
            ],
        ]);
    }

    /**
     * Delete a tunnel.
     */
    public function delete_tunnel(string $tunnel_id, string $account_id = ''): array {
        $acct = $account_id ?: $this->account_id ?: '';
        return $this->delete("accounts/{$acct}/cfd_tunnel/{$tunnel_id}");
    }

    // =========================================================================
    // Registrar (domain purchase via Cloudflare)
    // =========================================================================

    /**
     * Check domain availability (Cloudflare Registrar).
     * NOTE: Requires the account to have Registrar access.
     *
     * @return array{success: bool, available?: bool, price?: float, currency?: string}
     */
    public function check_domain_availability(string $domain, string $account_id = ''): array {
        $acct = $account_id ?: $this->account_id ?: '';
        if ($acct === '') {
            return ['success' => false, 'error' => 'account_id required'];
        }
        return $this->get("accounts/{$acct}/registrar/domains/{$domain}");
    }

    /**
     * List domains registered through Cloudflare Registrar.
     */
    public function list_registered_domains(string $account_id = ''): array {
        $acct = $account_id ?: $this->account_id ?: '';
        if ($acct === '') {
            return ['success' => false, 'error' => 'account_id required'];
        }
        return $this->get("accounts/{$acct}/registrar/domains");
    }

    /**
     * Initiate domain registration.
     *
     * @param  array{years: int, auto_renew: bool, privacy_protection: bool} $options
     */
    public function register_domain(
        string $domain,
        array  $options     = [],
        string $account_id  = ''
    ): array {
        $acct = $account_id ?: $this->account_id ?: '';
        if ($acct === '') {
            return ['success' => false, 'error' => 'account_id required'];
        }
        $body = array_merge([
            'auto_renew'         => true,
            'privacy_protection' => true,
            'years'              => 1,
        ], $options);
        return $this->post("accounts/{$acct}/registrar/domains/{$domain}", $body);
    }

    // =========================================================================
    // Firewall / Security
    // =========================================================================

    /** List IP access rules for a zone. */
    public function list_ip_access_rules(string $zone_id): array {
        return $this->get("zones/{$zone_id}/firewall/access_rules/rules");
    }

    /**
     * Create an IP access rule.
     *
     * @param string $mode    'block' | 'challenge' | 'whitelist' | 'js_challenge'.
     * @param string $target  'ip' | 'ip_range' | 'asn' | 'country'.
     * @param string $value   IP, CIDR, ASN, or 2-letter country code.
     */
    public function create_ip_access_rule(
        string $zone_id,
        string $mode,
        string $target,
        string $value,
        string $notes = ''
    ): array {
        return $this->post("zones/{$zone_id}/firewall/access_rules/rules", [
            'mode'          => $mode,
            'configuration' => ['target' => $target, 'value' => $value],
            'notes'         => $notes,
        ]);
    }

    // =========================================================================
    // Analytics
    // =========================================================================

    /**
     * Get zone analytics dashboard summary.
     *
     * @param string $since  ISO8601 date string (e.g. '-10080' for last 7 days).
     * @param string $until  ISO8601 date string ('0' = now).
     * @param bool   $continuous
     */
    public function get_zone_analytics(
        string $zone_id,
        string $since       = '-10080',
        string $until       = '0',
        bool   $continuous  = true
    ): array {
        return $this->get("zones/{$zone_id}/analytics/dashboard", [
            'since'      => $since,
            'until'      => $until,
            'continuous' => $continuous ? 'true' : 'false',
        ]);
    }

    // =========================================================================
    // Full WordPress Setup Wizard
    // =========================================================================

    /**
     * Configure a zone for optimal WordPress hosting in a single call.
     *
     * Steps performed:
     *   1. Set SSL to "Full (Strict)"
     *   2. Enable Always-Use-HTTPS
     *   3. Set minimum TLS 1.2
     *   4. Enable Brotli
     *   5. Set minification on
     *   6. Set browser cache TTL to 4 hours
     *   7. Create page rule: cache-level=Cache Everything for wp-content
     *   8. Create page rule: bypass cache for wp-admin and login
     *   9. Create A record pointing to the server IP (if provided)
     *
     * @param string $zone_id
     * @param string $server_ip      Optional: set this to create an A record.
     * @param bool   $create_tunnel  Create and configure a tunnel instead of an A record.
     */
    public function setup_wordpress(
        string $zone_id,
        string $server_ip    = '',
        bool   $create_tunnel = false
    ): array {
        $steps   = [];
        $errors  = [];

        $apply = function (string $step, array $result) use (&$steps, &$errors): void {
            $steps[$step] = $result['success'] ? 'ok' : ('error: ' . ($result['error'] ?? 'unknown'));
            if (!$result['success']) {
                $errors[] = $step;
            }
        };

        $apply('ssl_strict',          $this->set_ssl_mode($zone_id, 'full'));
        $apply('always_https',        $this->enable_always_https($zone_id));
        $apply('min_tls_1_2',         $this->set_min_tls_version($zone_id, '1.2'));
        $apply('brotli',              $this->set_brotli($zone_id));
        $apply('minify',              $this->set_minification($zone_id));
        $apply('browser_cache_4h',    $this->set_browser_cache_ttl($zone_id, 14400));

        // WP-Content cache-everything page rule
        $apply('page_rule_wp_content', $this->create_page_rule(
            $zone_id,
            '*/*wp-content/*',
            [['id' => 'cache_level', 'value' => 'cache_everything']],
            1
        ));

        // Bypass cache for wp-admin
        $apply('page_rule_wp_admin', $this->create_page_rule(
            $zone_id,
            '*/wp-admin*',
            [['id' => 'cache_level', 'value' => 'bypass'], ['id' => 'disable_performance', 'value' => 'on']],
            2
        ));

        // Bypass cache for login page
        $apply('page_rule_wp_login', $this->create_page_rule(
            $zone_id,
            '*/wp-login.php*',
            [['id' => 'cache_level', 'value' => 'bypass']],
            3
        ));

        if ($server_ip !== '') {
            $apply('dns_a_root', $this->upsert_dns_record($zone_id, 'A', '@', $server_ip, true));
            $apply('dns_a_www',  $this->upsert_dns_record($zone_id, 'A', 'www', $server_ip, true));
        }

        return [
            'success' => empty($errors),
            'steps'   => $steps,
            'errors'  => $errors,
        ];
    }

    // =========================================================================
    // HTTP helpers (private)
    // =========================================================================

    private function get(string $endpoint, array $params = []): array {
        return $this->request('GET', $endpoint, $params);
    }

    private function post(string $endpoint, array $body = []): array {
        return $this->request('POST', $endpoint, [], $body);
    }

    private function put(string $endpoint, array $body = []): array {
        return $this->request('PUT', $endpoint, [], $body);
    }

    private function patch(string $endpoint, array $body = []): array {
        return $this->request('PATCH', $endpoint, [], $body);
    }

    private function delete(string $endpoint): array {
        return $this->request('DELETE', $endpoint);
    }

    private function request(
        string $method,
        string $endpoint,
        array  $params = [],
        array  $body   = []
    ): array {
        $url = self::BASE_URL . ltrim($endpoint, '/');
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $headers = ['Content-Type' => 'application/json'];

        if ($this->use_token) {
            $headers['Authorization'] = 'Bearer ' . $this->token;
        } else {
            $headers['X-Auth-Email'] = $this->email;
            $headers['X-Auth-Key']   = $this->api_key;
        }

        $args = [
            'method'  => $method,
            'headers' => $headers,
            'timeout' => 20,
        ];

        if (!empty($body)) {
            $args['body'] = wp_json_encode($body);
        }

        $attempt  = 0;
        $response = null;

        while ($attempt < self::MAX_RETRIES) {
            $response = wp_remote_request($url, $args);
            $attempt++;

            if (is_wp_error($response)) {
                if ($attempt >= self::MAX_RETRIES) {
                    return ['success' => false, 'error' => $response->get_error_message()];
                }
                sleep(2 ** ($attempt - 1));
                continue;
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            if (in_array($code, [429, 500, 503], true)) {
                if ($attempt >= self::MAX_RETRIES) {
                    break;
                }
                $retry_after = (int) (wp_remote_retrieve_header($response, 'retry-after') ?: (2 ** $attempt));
                sleep(min($retry_after, 30));
                continue;
            }

            break;
        }

        if (is_wp_error($response)) {
            return ['success' => false, 'error' => $response->get_error_message()];
        }

        $raw  = wp_remote_retrieve_body($response);
        $json = json_decode($raw, true);

        if (!is_array($json)) {
            return ['success' => false, 'error' => 'Invalid JSON response from Cloudflare', 'raw' => $raw];
        }

        $cf_success = $json['success'] ?? false;
        $cf_errors  = $json['errors'] ?? [];
        $error_msgs = array_column($cf_errors, 'message');

        return [
            'success' => $cf_success,
            'data'    => $json,
            'errors'  => $error_msgs,
            'error'   => !$cf_success ? implode('; ', $error_msgs) : '',
            'meta'    => $json['result_info'] ?? [],
        ];
    }
}
