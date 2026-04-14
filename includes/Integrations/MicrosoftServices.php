<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\Integrations;

/**
 * Microsoft Services Integration
 *
 * Covers:
 *
 *   Microsoft Clarity
 *     – Project tag snippet injection (wp_head)
 *     – Clarity API: list projects, get project, get heatmap recordings
 *
 *   Microsoft Advertising (Bing Ads / UET)
 *     – Universal Event Tracking (UET) global tag injection
 *     – Conversion goal event firing helpers
 *     – Ads API (REST): list accounts, campaigns, ad groups, ads
 *
 *   Bing Webmaster Tools
 *     – HTML meta-tag verification snippet injection
 *     – Webmaster API: add site, get site info, submit sitemap,
 *       get keyword stats, get organic search stats
 *
 *   Microsoft Azure App Insights (optional)
 *     – JavaScript snippet injection for frontend telemetry
 *
 *   OAuth helpers
 *     – Build authorization URL (Microsoft Identity Platform / MSAL)
 *     – Exchange auth code for access + refresh tokens
 *     – Refresh access token
 *
 * Authentication:
 *   Microsoft Advertising and Bing Webmaster use OAuth2 (Authorization Code
 *   flow) with the Microsoft Identity Platform v2.0 endpoint.
 *   Clarity API uses its own bearer-token scheme.
 */
final class MicrosoftServices {

    private const MSFT_AUTH_URL    = 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize';
    private const MSFT_TOKEN_URL   = 'https://login.microsoftonline.com/common/oauth2/v2.0/token';

    private const CLARITY_API_BASE    = 'https://www.clarity.ms/api/v1/';
    private const BING_ADS_API_BASE   = 'https://campaign.api.bingads.microsoft.com/api/advertiser/v13/';
    private const BING_WMT_API_BASE   = 'https://ssl.bing.com/webmaster/api.svc/json/';
    private const BING_ADS_AUTH_BASE  = 'https://api.ads.microsoft.com/';

    private string $access_token;
    private string $client_id;
    private string $client_secret;
    private string $redirect_uri;
    private string $clarity_api_key;
    private string $bing_wmt_api_key;

    /**
     * @param string $access_token    OAuth2 access token (Ads / Bing WMT).
     * @param string $client_id       Azure App client ID.
     * @param string $client_secret   Azure App client secret.
     * @param string $redirect_uri    OAuth2 redirect URI.
     * @param string $clarity_api_key Microsoft Clarity API key (project-level).
     * @param string $bing_wmt_api_key Bing Webmaster Tools API key.
     */
    public function __construct(
        string $access_token    = '',
        string $client_id       = '',
        string $client_secret   = '',
        string $redirect_uri    = '',
        string $clarity_api_key = '',
        string $bing_wmt_api_key = ''
    ) {
        $this->access_token     = $access_token;
        $this->client_id        = $client_id;
        $this->client_secret    = $client_secret;
        $this->redirect_uri     = $redirect_uri;
        $this->clarity_api_key  = $clarity_api_key;
        $this->bing_wmt_api_key = $bing_wmt_api_key;
    }

    // =========================================================================
    // OAuth 2.0 (Microsoft Identity Platform)
    // =========================================================================

    /**
     * Build the Microsoft OAuth2 authorization URL.
     *
     * @param  string[] $scopes  e.g. ['https://ads.microsoft.com/msads.manage', 'offline_access']
     * @param  string   $state   CSRF state token.
     */
    public function get_auth_url(array $scopes, string $state = ''): string {
        $params = [
            'client_id'     => $this->client_id,
            'redirect_uri'  => $this->redirect_uri,
            'response_type' => 'code',
            'scope'         => implode(' ', $scopes),
            'response_mode' => 'query',
        ];
        if ($state !== '') {
            $params['state'] = $state;
        }
        return self::MSFT_AUTH_URL . '?' . http_build_query($params);
    }

    /**
     * Exchange an authorization code for access + refresh tokens.
     *
     * @return array{success: bool, access_token?: string, refresh_token?: string, expires_in?: int, error?: string}
     */
    public function exchange_code(string $code): array {
        return $this->token_request([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $this->redirect_uri,
        ]);
    }

    /**
     * Refresh an expired access token.
     */
    public function refresh_token(string $refresh_token, string $scope = ''): array {
        $body = [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refresh_token,
        ];
        if ($scope !== '') {
            $body['scope'] = $scope;
        }
        return $this->token_request($body);
    }

    // =========================================================================
    // Microsoft Clarity – snippet injection
    // =========================================================================

    /**
     * Inject the Microsoft Clarity project tag into wp_head.
     *
     * @param string $project_id  Clarity project ID (e.g. "abc1234xyz").
     */
    public function inject_clarity(string $project_id): void {
        if (empty($project_id)) {
            return;
        }
        add_action('wp_head', function () use ($project_id): void {
            echo "<!-- Microsoft Clarity (RJV AGI) -->\n";
            echo "<script type=\"text/javascript\">(function(c,l,a,r,i,t,y){c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};t=l.createElement(r);t.async=1;t.src=\"https://www.clarity.ms/tag/\"+i;y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y)})(window,document,\"clarity\",\"script\",\"" . esc_js($project_id) . "\");</script>\n";
            echo "<!-- End Microsoft Clarity -->\n";
        }, 1);
        update_option('rjv_agi_clarity_project_id', $project_id);
        update_option('rjv_agi_clarity_enabled', '1');
    }

    // =========================================================================
    // Microsoft Clarity – API
    // =========================================================================

    /**
     * List Clarity projects.
     */
    public function list_clarity_projects(): array {
        return $this->clarity_request('GET', 'projects');
    }

    /**
     * Get a single Clarity project.
     */
    public function get_clarity_project(string $project_id): array {
        return $this->clarity_request('GET', "projects/{$project_id}");
    }

    /**
     * Get recordings for a Clarity project.
     *
     * @param  array $filters  Optional filter parameters.
     */
    public function get_clarity_recordings(string $project_id, array $filters = []): array {
        return $this->clarity_request('GET', "projects/{$project_id}/recordings", $filters);
    }

    /**
     * Get Clarity heatmap data.
     */
    public function get_clarity_heatmap(string $project_id, string $page_url): array {
        return $this->clarity_request('GET', "projects/{$project_id}/heatmaps", [
            'url' => $page_url,
        ]);
    }

    // =========================================================================
    // Microsoft Advertising (Bing Ads) – UET snippet injection
    // =========================================================================

    /**
     * Inject Bing Ads Universal Event Tracking (UET) global tag.
     *
     * @param string $uet_tag_id   UET tag ID (numeric string).
     */
    public function inject_bing_uet(string $uet_tag_id): void {
        if (empty($uet_tag_id)) {
            return;
        }
        add_action('wp_head', function () use ($uet_tag_id): void {
            echo "<!-- Microsoft Advertising UET (RJV AGI) -->\n";
            echo "<script>(function(w,d,t,r,u){var f,n,i;w[u]=w[u]||[],f=function(){var o={ti:\"" . esc_js($uet_tag_id) . "\"};o.q=w[u],w[u]=new UET(o),w[u].push(\"pageLoad\")},n=d.createElement(t),n.src=r,n.async=1,n.onload=n.onreadystatechange=function(){var s=this.readyState;s&&s!==\"loaded\"&&s!==\"complete\"||(f(),n.onload=n.onreadystatechange=null)},i=d.getElementsByTagName(t)[0],i.parentNode.insertBefore(n,i)})(window,document,\"script\",\"//bat.bing.com/bat.js\",\"uetq\");</script>\n";
            echo "<!-- End Microsoft Advertising UET -->\n";
        }, 2);
        update_option('rjv_agi_bing_uet_tag_id', $uet_tag_id);
        update_option('rjv_agi_bing_ads_enabled', '1');
    }

    // =========================================================================
    // Microsoft Advertising API (Bing Ads)
    // =========================================================================

    /**
     * List Bing Ads accounts accessible to the authenticated user.
     *
     * @param  string $customer_id  Bing Ads customer ID.
     */
    public function list_ads_accounts(string $customer_id): array {
        return $this->ads_request('GET', "customers/{$customer_id}/accounts");
    }

    /**
     * List campaigns in a Bing Ads account.
     */
    public function list_campaigns(string $account_id): array {
        return $this->ads_request('GET', "accounts/{$account_id}/campaigns");
    }

    /**
     * Get campaign performance statistics.
     *
     * @param string $account_id
     * @param string $campaign_id
     * @param string $start_date  e.g. '2024-01-01'
     * @param string $end_date    e.g. '2024-01-31'
     */
    public function get_campaign_stats(
        string $account_id,
        string $campaign_id,
        string $start_date = '',
        string $end_date   = ''
    ): array {
        if ($start_date === '') {
            $start_date = gmdate('Y-m-d', strtotime('-30 days'));
        }
        if ($end_date === '') {
            $end_date = gmdate('Y-m-d');
        }
        return $this->ads_request('GET', "accounts/{$account_id}/campaigns/{$campaign_id}/stats", [
            'startDate' => $start_date,
            'endDate'   => $end_date,
        ]);
    }

    // =========================================================================
    // Bing Webmaster Tools – snippet injection
    // =========================================================================

    /**
     * Inject Bing Webmaster Tools HTML meta-tag site verification.
     *
     * @param string $verification_code  The content value from the meta tag.
     */
    public function inject_bing_verification(string $verification_code): void {
        if (empty($verification_code)) {
            return;
        }
        add_action('wp_head', function () use ($verification_code): void {
            echo '<meta name="msvalidate.01" content="' . esc_attr($verification_code) . '" />' . "\n";
        }, 1);
        update_option('rjv_agi_bing_verification', $verification_code);
    }

    // =========================================================================
    // Bing Webmaster Tools API
    // =========================================================================

    /**
     * Add a site to Bing Webmaster Tools.
     *
     * @param string $site_url  Site URL (e.g. "https://example.com/").
     */
    public function add_wmt_site(string $site_url): array {
        return $this->wmt_request('AddSite', ['siteUrl' => $site_url]);
    }

    /**
     * Get site info from Bing Webmaster Tools.
     */
    public function get_wmt_site(string $site_url): array {
        return $this->wmt_request('GetSiteInfo', ['siteUrl' => $site_url]);
    }

    /**
     * Submit a sitemap to Bing Webmaster Tools.
     */
    public function submit_wmt_sitemap(string $site_url, string $sitemap_url): array {
        return $this->wmt_request('SubmitSitemap', [
            'siteUrl'    => $site_url,
            'feedUrl'    => $sitemap_url,
        ]);
    }

    /**
     * Get keyword stats from Bing Webmaster Tools.
     *
     * @param string $site_url
     * @param string $start_date  e.g. '2024-01-01'
     * @param string $end_date    e.g. '2024-01-31'
     */
    public function get_keyword_stats(string $site_url, string $start_date, string $end_date): array {
        return $this->wmt_request('GetKeywordStats', [
            'siteUrl'   => $site_url,
            'startDate' => $start_date,
            'endDate'   => $end_date,
        ]);
    }

    /**
     * Get organic search stats (clicks, impressions) from Bing Webmaster Tools.
     */
    public function get_organic_search_stats(string $site_url, string $start_date, string $end_date): array {
        return $this->wmt_request('GetSearchKeywordCounts', [
            'siteUrl'   => $site_url,
            'startDate' => $start_date,
            'endDate'   => $end_date,
        ]);
    }

    // =========================================================================
    // Azure Application Insights – snippet injection
    // =========================================================================

    /**
     * Inject Azure Application Insights JavaScript SDK snippet.
     *
     * @param string $instrumentation_key  App Insights instrumentation key.
     * @param string $connection_string    Connection string (preferred, overrides key).
     */
    public function inject_app_insights(string $instrumentation_key, string $connection_string = ''): void {
        if (empty($instrumentation_key) && empty($connection_string)) {
            return;
        }
        $config = $connection_string !== ''
            ? '"connectionString":"' . esc_js($connection_string) . '"'
            : '"instrumentationKey":"' . esc_js($instrumentation_key) . '"';

        add_action('wp_head', function () use ($config): void {
            echo "<!-- Azure App Insights (RJV AGI) -->\n";
            echo "<script type=\"text/javascript\">!function(T,l,y){var S=T.location,k=\"script\",D=\"instrumentationKey\",C=\"ingestionendpoint\",I=\"disableExceptionTracking\",E=\"ai.device.\";y=l.createElement(k),S=l.getElementsByTagName(k)[0];y.src=\"https://js.monitor.azure.com/scripts/b/ai.2.min.js\";y.onload=function(){var a={" . $config . "};var b=new Microsoft.ApplicationInsights.ApplicationInsights({config:a});b.loadAppInsights();b.trackPageView({})};S.parentNode.insertBefore(y,S)}(window,document);</script>\n";
            echo "<!-- End Azure App Insights -->\n";
        }, 3);
        update_option('rjv_agi_appinsights_key', $instrumentation_key);
        update_option('rjv_agi_appinsights_enabled', '1');
    }

    // =========================================================================
    // Batch "install all Microsoft tracking" helper
    // =========================================================================

    /**
     * Inject all configured Microsoft tracking snippets in one call.
     *
     * Reads configuration from WP options and activates whichever services
     * have been configured.
     */
    public function inject_all_from_options(): void {
        $clarity_id  = (string) get_option('rjv_agi_clarity_project_id', '');
        $uet_tag     = (string) get_option('rjv_agi_bing_uet_tag_id', '');
        $bing_verify = (string) get_option('rjv_agi_bing_verification', '');
        $ai_key      = (string) get_option('rjv_agi_appinsights_key', '');
        $ai_cs       = (string) get_option('rjv_agi_appinsights_connection_string', '');

        if ($clarity_id)  $this->inject_clarity($clarity_id);
        if ($uet_tag)     $this->inject_bing_uet($uet_tag);
        if ($bing_verify) $this->inject_bing_verification($bing_verify);
        if ($ai_key || $ai_cs) $this->inject_app_insights($ai_key, $ai_cs);
    }

    // =========================================================================
    // HTTP helpers (private)
    // =========================================================================

    private function clarity_request(string $method, string $path, array $params = []): array {
        $url = self::CLARITY_API_BASE . ltrim($path, '/');
        if ($method === 'GET' && !empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        return $this->call($method, $url, $params, [
            'Authorization' => 'Bearer ' . $this->clarity_api_key,
        ]);
    }

    private function ads_request(string $method, string $path, array $params = []): array {
        $url = self::BING_ADS_API_BASE . ltrim($path, '/');
        if ($method === 'GET' && !empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        return $this->call($method, $url, $method !== 'GET' ? $params : [], [
            'Authorization'     => 'Bearer ' . $this->access_token,
            'DeveloperToken'    => (string) get_option('rjv_agi_bing_developer_token', ''),
            'CustomerId'        => (string) get_option('rjv_agi_bing_customer_id', ''),
            'CustomerAccountId' => (string) get_option('rjv_agi_bing_account_id', ''),
        ]);
    }

    private function wmt_request(string $method, array $body = []): array {
        $url = self::BING_WMT_API_BASE . $method . '?apikey=' . urlencode($this->bing_wmt_api_key);
        return $this->call('POST', $url, $body, [
            'Content-Type' => 'application/json; charset=utf-8',
        ]);
    }

    private function token_request(array $body): array {
        $response = wp_remote_post(self::MSFT_TOKEN_URL, [
            'body' => array_merge($body, [
                'client_id'     => $this->client_id,
                'client_secret' => $this->client_secret,
            ]),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'error' => $response->get_error_message()];
        }

        $json = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($json)) {
            return ['success' => false, 'error' => 'Invalid token response'];
        }
        if (!empty($json['error'])) {
            return ['success' => false, 'error' => $json['error_description'] ?? $json['error']];
        }
        return array_merge(['success' => true], $json);
    }

    private function call(string $method, string $url, array $body = [], array $extra_headers = []): array {
        $headers = array_merge([
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ], $extra_headers);

        if ($this->access_token && !isset($extra_headers['Authorization'])) {
            $headers['Authorization'] = 'Bearer ' . $this->access_token;
        }

        $args = ['method' => $method, 'headers' => $headers, 'timeout' => 20];
        if (!empty($body) && $method !== 'GET') {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return ['success' => false, 'error' => $response->get_error_message()];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw  = wp_remote_retrieve_body($response);
        $json = json_decode($raw, true);

        if ($code >= 400) {
            $msg = is_array($json) ? ($json['Message'] ?? $json['message'] ?? $raw) : $raw;
            return ['success' => false, 'error' => $msg, 'http_status' => $code];
        }

        return ['success' => true, 'data' => $json ?? $raw, 'http_status' => $code];
    }
}
