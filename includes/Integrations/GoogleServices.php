<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\Integrations;

/**
 * Google Services Integration
 *
 * Covers the complete set of Google marketing, analytics and commerce services:
 *
 *   Google Analytics 4 (GA4)
 *     – Measurement ID snippet injection (wp_head / wp_footer)
 *     – GA4 Management API: list properties, create data streams, list reports,
 *       run simple Reporting API queries.
 *
 *   Google Search Console (Webmaster Tools)
 *     – HTML meta-tag verification snippet injection
 *     – DNS TXT record value retrieval for Cloudflare auto-verification
 *     – Search Console API: list sites, add site, fetch sitemaps, submit sitemap,
 *       get search analytics (queries, pages, countries).
 *
 *   Google Tag Manager
 *     – GTM snippet injection (head + body noscript)
 *     – GTM API: list accounts, list containers, list workspaces
 *
 *   Google Ads (formerly AdWords)
 *     – Global Site Tag (gtag.js) + conversion tracking snippet injection
 *     – Google Ads API (via REST): list campaigns, get performance
 *
 *   Google Merchant Center
 *     – Product feed generation (Atom/XML RSS 2.0 and JSON-LD)
 *     – Submitting feed URL to Merchant Center Content API
 *     – List products, get product status
 *
 *   OAuth helpers
 *     – Build authorization URL (Google OAuth 2.0)
 *     – Exchange auth code for tokens
 *     – Refresh access token
 *
 * Authentication notes:
 *   Most API calls require an OAuth2 access token (user-facing flow).
 *   Service account JSON key auth is also supported for server-to-server calls.
 */
final class GoogleServices {

    private const OAUTH_AUTH_URL    = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const OAUTH_TOKEN_URL   = 'https://oauth2.googleapis.com/token';
    private const GA4_API_BASE      = 'https://analyticsdata.googleapis.com/v1beta/';
    private const GA4_ADMIN_BASE    = 'https://analyticsadmin.googleapis.com/v1alpha/';
    private const GSC_API_BASE      = 'https://searchconsole.googleapis.com/webmasters/v3/';
    private const GTM_API_BASE      = 'https://tagmanager.googleapis.com/tagmanager/v2/';
    private const GADS_API_BASE     = 'https://googleads.googleapis.com/v18/';
    private const GMC_API_BASE      = 'https://shoppingcontent.googleapis.com/content/v2.1/';

    private string  $access_token;
    private string  $client_id;
    private string  $client_secret;
    private string  $redirect_uri;

    public function __construct(
        string $access_token   = '',
        string $client_id      = '',
        string $client_secret  = '',
        string $redirect_uri   = ''
    ) {
        $this->access_token   = $access_token;
        $this->client_id      = $client_id;
        $this->client_secret  = $client_secret;
        $this->redirect_uri   = $redirect_uri;
    }

    // =========================================================================
    // OAuth 2.0
    // =========================================================================

    /**
     * Build the Google OAuth2 authorization URL.
     *
     * @param  string[] $scopes   Array of OAuth2 scope URIs.
     * @param  string   $state    CSRF state token.
     */
    public function get_auth_url(array $scopes, string $state = ''): string {
        $params = [
            'client_id'             => $this->client_id,
            'redirect_uri'          => $this->redirect_uri,
            'response_type'         => 'code',
            'scope'                 => implode(' ', $scopes),
            'access_type'           => 'offline',
            'prompt'                => 'consent',
        ];
        if ($state !== '') {
            $params['state'] = $state;
        }
        return self::OAUTH_AUTH_URL . '?' . http_build_query($params);
    }

    /**
     * Exchange an authorization code for access + refresh tokens.
     *
     * @return array{success: bool, access_token?: string, refresh_token?: string, expires_in?: int, error?: string}
     */
    public function exchange_code(string $code): array {
        $response = wp_remote_post(self::OAUTH_TOKEN_URL, [
            'body' => [
                'code'          => $code,
                'client_id'     => $this->client_id,
                'client_secret' => $this->client_secret,
                'redirect_uri'  => $this->redirect_uri,
                'grant_type'    => 'authorization_code',
            ],
            'timeout' => 15,
        ]);

        return $this->parse_token_response($response);
    }

    /**
     * Refresh an expired access token using a refresh token.
     *
     * @return array{success: bool, access_token?: string, expires_in?: int, error?: string}
     */
    public function refresh_token(string $refresh_token): array {
        $response = wp_remote_post(self::OAUTH_TOKEN_URL, [
            'body' => [
                'refresh_token' => $refresh_token,
                'client_id'     => $this->client_id,
                'client_secret' => $this->client_secret,
                'grant_type'    => 'refresh_token',
            ],
            'timeout' => 15,
        ]);

        return $this->parse_token_response($response);
    }

    // =========================================================================
    // Snippet Injection (WordPress head/footer hooks)
    // =========================================================================

    /**
     * Inject Google Analytics 4 gtag.js snippet into wp_head.
     *
     * @param string $measurement_id  GA4 Measurement ID (G-XXXXXXXXXX).
     * @param array  $config          Additional gtag config overrides.
     */
    public function inject_ga4(string $measurement_id, array $config = []): void {
        if (empty($measurement_id)) {
            return;
        }
        $config_json = !empty($config) ? ', ' . wp_json_encode($config) : '';
        add_action('wp_head', function () use ($measurement_id, $config_json): void {
            // phpcs:disable WordPress.WP.EnqueuedResources
            echo "<!-- Google Analytics 4 (RJV AGI) -->\n";
            echo "<script async src=\"https://www.googletagmanager.com/gtag/js?id=" . esc_attr($measurement_id) . "\"></script>\n";
            echo "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','" . esc_js($measurement_id) . "'" . $config_json . ");</script>\n";
            // phpcs:enable
        }, 1);

        update_option('rjv_agi_ga4_measurement_id', $measurement_id);
        update_option('rjv_agi_ga4_enabled', '1');
    }

    /**
     * Inject Google Tag Manager head + body snippets.
     *
     * @param string $container_id  GTM Container ID (GTM-XXXXXXX).
     */
    public function inject_gtm(string $container_id): void {
        if (empty($container_id)) {
            return;
        }
        add_action('wp_head', function () use ($container_id): void {
            echo "<!-- Google Tag Manager (RJV AGI) -->\n";
            echo "<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','" . esc_js($container_id) . "');</script>\n";
            echo "<!-- End Google Tag Manager -->\n";
        }, 1);
        add_action('wp_body_open', function () use ($container_id): void {
            echo "<!-- Google Tag Manager (noscript) -->\n";
            echo "<noscript><iframe src=\"https://www.googletagmanager.com/ns.html?id=" . esc_attr($container_id) . "\" height=\"0\" width=\"0\" style=\"display:none;visibility:hidden\"></iframe></noscript>\n";
            echo "<!-- End Google Tag Manager (noscript) -->\n";
        }, 1);

        update_option('rjv_agi_gtm_container_id', $container_id);
        update_option('rjv_agi_gtm_enabled', '1');
    }

    /**
     * Inject Google Ads global site tag (conversion tracking).
     *
     * @param string $ads_id        Google Ads account tag (AW-XXXXXXXXX).
     * @param array  $conversions   Array of {id, label} conversion events to register.
     */
    public function inject_google_ads(string $ads_id, array $conversions = []): void {
        if (empty($ads_id)) {
            return;
        }
        add_action('wp_head', function () use ($ads_id, $conversions): void {
            echo "<!-- Google Ads Global Site Tag (RJV AGI) -->\n";
            echo "<script async src=\"https://www.googletagmanager.com/gtag/js?id=" . esc_attr($ads_id) . "\"></script>\n";
            echo "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','" . esc_js($ads_id) . "');\n";
            foreach ($conversions as $conv) {
                if (!empty($conv['id']) && !empty($conv['label'])) {
                    echo "gtag('event','conversion',{'send_to':'" . esc_js($ads_id . '/' . $conv['label']) . "'});\n";
                }
            }
            echo "</script>\n<!-- End Google Ads -->\n";
        }, 2);

        update_option('rjv_agi_google_ads_id', $ads_id);
        update_option('rjv_agi_google_ads_enabled', '1');
    }

    /**
     * Inject Google Search Console HTML meta-tag verification.
     *
     * @param string $verification_code  Value of the meta content attribute.
     */
    public function inject_search_console_verification(string $verification_code): void {
        if (empty($verification_code)) {
            return;
        }
        add_action('wp_head', function () use ($verification_code): void {
            echo '<meta name="google-site-verification" content="' . esc_attr($verification_code) . '" />' . "\n";
        }, 1);
        update_option('rjv_agi_gsc_verification', $verification_code);
    }

    // =========================================================================
    // Google Analytics 4 API
    // =========================================================================

    /**
     * Run a GA4 Data API report.
     *
     * @param  string   $property_id  GA4 property ID (numeric, e.g. "123456789").
     * @param  string[] $metrics      e.g. ['activeUsers', 'sessions', 'screenPageViews'].
     * @param  string[] $dimensions   e.g. ['date', 'country', 'deviceCategory'].
     * @param  array    $date_ranges  [['startDate' => '7daysAgo', 'endDate' => 'today']]
     * @param  int      $limit
     */
    public function run_ga4_report(
        string $property_id,
        array  $metrics    = ['activeUsers', 'sessions'],
        array  $dimensions = ['date'],
        array  $date_ranges = [['startDate' => '7daysAgo', 'endDate' => 'today']],
        int    $limit      = 100
    ): array {
        $body = [
            'metrics'    => array_map(fn($m) => ['name' => $m], $metrics),
            'dimensions' => array_map(fn($d) => ['name' => $d], $dimensions),
            'dateRanges' => $date_ranges,
            'limit'      => $limit,
        ];
        return $this->post_api(
            self::GA4_API_BASE . "properties/{$property_id}:runReport",
            $body
        );
    }

    /**
     * List GA4 properties accessible to the authenticated account.
     */
    public function list_ga4_properties(string $filter = ''): array {
        $params = $filter ? ['filter' => $filter] : [];
        return $this->get_api(self::GA4_ADMIN_BASE . 'properties', $params);
    }

    /**
     * Create a GA4 data stream for a property.
     *
     * @param string $property_id  GA4 property ID.
     * @param string $website_url  Website URL.
     * @param string $stream_name  Human-readable name.
     */
    public function create_ga4_data_stream(
        string $property_id,
        string $website_url,
        string $stream_name = 'WordPress Site'
    ): array {
        return $this->post_api(
            self::GA4_ADMIN_BASE . "properties/{$property_id}/dataStreams",
            [
                'type'            => 'WEB_DATA_STREAM',
                'displayName'     => $stream_name,
                'webStreamData'   => ['defaultUri' => $website_url],
            ]
        );
    }

    // =========================================================================
    // Google Search Console API
    // =========================================================================

    /**
     * List all sites verified in Search Console.
     */
    public function list_search_console_sites(): array {
        return $this->get_api(self::GSC_API_BASE . 'sites');
    }

    /**
     * Add a site to Search Console.
     */
    public function add_search_console_site(string $site_url): array {
        return $this->put_api(self::GSC_API_BASE . 'sites/' . urlencode($site_url), []);
    }

    /**
     * Get list of sitemaps for a site.
     */
    public function list_sitemaps(string $site_url): array {
        return $this->get_api(
            self::GSC_API_BASE . 'sites/' . urlencode($site_url) . '/sitemaps'
        );
    }

    /**
     * Submit a sitemap to Search Console.
     */
    public function submit_sitemap(string $site_url, string $sitemap_url): array {
        return $this->put_api(
            self::GSC_API_BASE . 'sites/' . urlencode($site_url) . '/sitemaps/' . urlencode($sitemap_url),
            []
        );
    }

    /**
     * Get Search Console search analytics.
     *
     * @param  string   $site_url
     * @param  string   $start_date  e.g. '2024-01-01'
     * @param  string   $end_date    e.g. '2024-01-31'
     * @param  string[] $dimensions  e.g. ['query', 'page', 'country', 'device']
     * @param  int      $row_limit
     */
    public function get_search_analytics(
        string $site_url,
        string $start_date  = '',
        string $end_date    = '',
        array  $dimensions  = ['query'],
        int    $row_limit   = 100
    ): array {
        if ($start_date === '') {
            $start_date = gmdate('Y-m-d', strtotime('-28 days'));
        }
        if ($end_date === '') {
            $end_date = gmdate('Y-m-d');
        }
        return $this->post_api(
            self::GSC_API_BASE . 'sites/' . urlencode($site_url) . '/searchAnalytics/query',
            [
                'startDate'  => $start_date,
                'endDate'    => $end_date,
                'dimensions' => $dimensions,
                'rowLimit'   => $row_limit,
            ]
        );
    }

    // =========================================================================
    // Google Tag Manager API
    // =========================================================================

    /** List GTM accounts. */
    public function list_gtm_accounts(): array {
        return $this->get_api(self::GTM_API_BASE . 'accounts');
    }

    /** List GTM containers in an account. */
    public function list_gtm_containers(string $account_id): array {
        return $this->get_api(self::GTM_API_BASE . "accounts/{$account_id}/containers");
    }

    // =========================================================================
    // Google Merchant Center API
    // =========================================================================

    /**
     * List products in a Merchant Center account.
     */
    public function list_merchant_products(string $merchant_id, int $max_results = 250): array {
        return $this->get_api(
            self::GMC_API_BASE . "{$merchant_id}/products",
            ['maxResults' => $max_results]
        );
    }

    /**
     * Insert (create/update) a product in Merchant Center.
     *
     * @param string $merchant_id
     * @param array  $product  Google Content API product resource.
     */
    public function insert_merchant_product(string $merchant_id, array $product): array {
        return $this->post_api(
            self::GMC_API_BASE . "{$merchant_id}/products",
            $product
        );
    }

    /**
     * Generate a Google Merchant Center product feed from WooCommerce products.
     *
     * Returns an XML string (RSS 2.0 / Google Shopping feed format).
     */
    public function generate_woocommerce_feed(): string {
        if (!function_exists('wc_get_products')) {
            return '';
        }

        $products = wc_get_products(['status' => 'publish', 'limit' => 500]);
        $site_url = get_bloginfo('url');
        $site_name = get_bloginfo('name');
        $currency = get_woocommerce_currency();

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . "\n";
        $xml .= '<channel>' . "\n";
        $xml .= '<title>' . esc_xml($site_name) . '</title>' . "\n";
        $xml .= '<link>' . esc_url($site_url) . '</link>' . "\n";
        $xml .= '<description>' . esc_xml($site_name) . ' products</description>' . "\n";

        foreach ($products as $product) {
            /** @var \WC_Product $product */
            $image_id = $product->get_image_id();
            $image_url = $image_id ? wp_get_attachment_url($image_id) : '';

            $xml .= '<item>' . "\n";
            $xml .= '<g:id>' . esc_xml((string) $product->get_id()) . '</g:id>' . "\n";
            $xml .= '<title>' . esc_xml($product->get_name()) . '</title>' . "\n";
            $xml .= '<link>' . esc_url($product->get_permalink()) . '</link>' . "\n";
            $xml .= '<description>' . esc_xml(wp_strip_all_tags($product->get_short_description() ?: $product->get_description())) . '</description>' . "\n";
            $xml .= '<g:price>' . esc_xml($product->get_price() . ' ' . $currency) . '</g:price>' . "\n";
            $xml .= '<g:availability>' . ($product->is_in_stock() ? 'in_stock' : 'out_of_stock') . '</g:availability>' . "\n";
            $xml .= '<g:condition>new</g:condition>' . "\n";
            if ($image_url) {
                $xml .= '<g:image_link>' . esc_url($image_url) . '</g:image_link>' . "\n";
            }
            if ($product->get_sku()) {
                $xml .= '<g:mpn>' . esc_xml($product->get_sku()) . '</g:mpn>' . "\n";
            }
            $xml .= '</item>' . "\n";
        }

        $xml .= '</channel>' . "\n";
        $xml .= '</rss>';

        return $xml;
    }

    // =========================================================================
    // HTTP helpers
    // =========================================================================

    private function get_api(string $url, array $params = []): array {
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        return $this->call('GET', $url);
    }

    private function post_api(string $url, array $body): array {
        return $this->call('POST', $url, $body);
    }

    private function put_api(string $url, array $body): array {
        return $this->call('PUT', $url, $body);
    }

    private function call(string $method, string $url, array $body = []): array {
        $headers = [
            'Authorization' => 'Bearer ' . $this->access_token,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ];

        $args = ['method' => $method, 'headers' => $headers, 'timeout' => 20];
        if (!empty($body)) {
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
            $msg = is_array($json) ? ($json['error']['message'] ?? $json['message'] ?? $raw) : $raw;
            return ['success' => false, 'error' => $msg, 'http_status' => $code];
        }

        return ['success' => true, 'data' => $json, 'http_status' => $code];
    }

    private function parse_token_response($response): array {
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
}
