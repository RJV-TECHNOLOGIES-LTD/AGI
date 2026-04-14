<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;

use RJV_AGI_Bridge\Integrations\GoogleServices;
use RJV_AGI_Bridge\Integrations\MicrosoftServices;
use RJV_AGI_Bridge\AuditLog;

/**
 * External Platforms REST Controller
 *
 * Exposes REST endpoints for Google and Microsoft service integrations,
 * covering tracking snippet setup, OAuth flows, analytics data retrieval,
 * Search Console / Webmaster Tools, Ads platforms, and product feed generation.
 *
 * ── Google ──────────────────────────────────────────────────────────────────
 *
 *   OAuth
 *     GET  /external-platforms/google/auth-url       – Get OAuth2 authorization URL
 *     POST /external-platforms/google/exchange-code  – Exchange code for tokens
 *     POST /external-platforms/google/refresh-token  – Refresh access token
 *
 *   Analytics 4
 *     POST /external-platforms/google/ga4/inject      – Save+inject GA4 snippet
 *     GET  /external-platforms/google/ga4/properties  – List GA4 properties
 *     POST /external-platforms/google/ga4/report      – Run GA4 report
 *     POST /external-platforms/google/ga4/stream      – Create GA4 data stream
 *
 *   Google Tag Manager
 *     POST /external-platforms/google/gtm/inject      – Save+inject GTM snippet
 *     GET  /external-platforms/google/gtm/accounts    – List GTM accounts
 *
 *   Google Ads
 *     POST /external-platforms/google/ads/inject      – Save+inject Ads global tag
 *
 *   Search Console
 *     POST /external-platforms/google/gsc/inject      – Inject verification meta tag
 *     GET  /external-platforms/google/gsc/sites       – List verified sites
 *     POST /external-platforms/google/gsc/add-site    – Add site
 *     POST /external-platforms/google/gsc/submit-sitemap – Submit sitemap
 *     GET  /external-platforms/google/gsc/analytics   – Get search analytics
 *
 *   Merchant Center
 *     GET  /external-platforms/google/merchant/products – List MC products
 *     GET  /external-platforms/google/merchant/feed     – Generate WooCommerce feed XML
 *
 *   Batch
 *     POST /external-platforms/google/setup           – One-shot: inject all configured snippets
 *
 * ── Microsoft ───────────────────────────────────────────────────────────────
 *
 *   OAuth
 *     GET  /external-platforms/microsoft/auth-url       – Get OAuth2 authorization URL
 *     POST /external-platforms/microsoft/exchange-code  – Exchange code for tokens
 *     POST /external-platforms/microsoft/refresh-token  – Refresh access token
 *
 *   Clarity
 *     POST /external-platforms/microsoft/clarity/inject     – Save+inject Clarity snippet
 *     GET  /external-platforms/microsoft/clarity/projects   – List Clarity projects
 *     GET  /external-platforms/microsoft/clarity/recordings – Get recordings
 *
 *   Bing Ads (UET)
 *     POST /external-platforms/microsoft/bing-ads/inject    – Save+inject UET tag
 *     GET  /external-platforms/microsoft/bing-ads/campaigns – List campaigns
 *
 *   Bing Webmaster Tools
 *     POST /external-platforms/microsoft/bing-wmt/inject    – Inject verification meta tag
 *     POST /external-platforms/microsoft/bing-wmt/add-site  – Add site
 *     POST /external-platforms/microsoft/bing-wmt/submit-sitemap – Submit sitemap
 *
 *   App Insights
 *     POST /external-platforms/microsoft/app-insights/inject – Save+inject snippet
 *
 *   Batch
 *     POST /external-platforms/microsoft/setup         – One-shot: inject all configured snippets
 */
class ExternalPlatforms extends Base {

    public function register_routes(): void {
        $ns = $this->namespace;

        // ── Google ──────────────────────────────────────────────────────────

        // OAuth
        register_rest_route($ns, '/external-platforms/google/auth-url', [
            'methods'             => 'GET',
            'callback'            => [$this, 'google_auth_url'],
            'permission_callback' => [$this, 'can_manage'],
            'args'                => [
                'scopes' => ['type' => 'array', 'default' => [
                    'https://www.googleapis.com/auth/analytics.readonly',
                    'https://www.googleapis.com/auth/webmasters.readonly',
                ]],
                'state'  => ['type' => 'string', 'default' => ''],
            ],
        ]);

        register_rest_route($ns, '/external-platforms/google/exchange-code', [
            'methods'             => 'POST',
            'callback'            => [$this, 'google_exchange_code'],
            'permission_callback' => [$this, 'can_manage'],
            'args'                => [
                'code' => ['type' => 'string', 'required' => true],
            ],
        ]);

        register_rest_route($ns, '/external-platforms/google/refresh-token', [
            'methods'             => 'POST',
            'callback'            => [$this, 'google_refresh_token'],
            'permission_callback' => [$this, 'can_manage'],
            'args'                => [
                'refresh_token' => ['type' => 'string', 'required' => true],
            ],
        ]);

        // GA4
        register_rest_route($ns, '/external-platforms/google/ga4/inject', [
            'methods'             => 'POST',
            'callback'            => [$this, 'ga4_inject'],
            'permission_callback' => [$this, 'can_manage'],
            'args'                => [
                'measurement_id' => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'config'         => ['type' => 'object', 'default' => []],
            ],
        ]);

        register_rest_route($ns, '/external-platforms/google/ga4/properties', [
            'methods'             => 'GET',
            'callback'            => [$this, 'ga4_list_properties'],
            'permission_callback' => [$this, 'can_manage'],
        ]);

        register_rest_route($ns, '/external-platforms/google/ga4/report', [
            'methods'             => 'POST',
            'callback'            => [$this, 'ga4_report'],
            'permission_callback' => [$this, 'can_manage'],
            'args'                => [
                'property_id' => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'metrics'     => ['type' => 'array', 'default' => ['activeUsers', 'sessions']],
                'dimensions'  => ['type' => 'array', 'default' => ['date']],
                'date_ranges' => ['type' => 'array', 'default' => [['startDate' => '7daysAgo', 'endDate' => 'today']]],
                'limit'       => ['type' => 'integer', 'default' => 100, 'minimum' => 1, 'maximum' => 1000],
            ],
        ]);

        register_rest_route($ns, '/external-platforms/google/ga4/stream', [
            'methods'             => 'POST',
            'callback'            => [$this, 'ga4_create_stream'],
            'permission_callback' => [$this, 'can_manage'],
            'args'                => [
                'property_id' => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'website_url' => ['type' => 'string', 'sanitize_callback' => 'esc_url_raw'],
                'stream_name' => ['type' => 'string', 'default' => 'WordPress Site', 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        // GTM
        register_rest_route($ns, '/external-platforms/google/gtm/inject', [
            'methods'             => 'POST',
            'callback'            => [$this, 'gtm_inject'],
            'permission_callback' => [$this, 'can_manage'],
            'args'                => [
                'container_id' => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        register_rest_route($ns, '/external-platforms/google/gtm/accounts', [
            'methods'             => 'GET',
            'callback'            => [$this, 'gtm_accounts'],
            'permission_callback' => [$this, 'can_manage'],
        ]);

        // Google Ads
        register_rest_route($ns, '/external-platforms/google/ads/inject', [
            'methods'             => 'POST',
            'callback'            => [$this, 'google_ads_inject'],
            'permission_callback' => [$this, 'can_manage'],
            'args'                => [
                'ads_id'      => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'conversions' => ['type' => 'array', 'default' => []],
            ],
        ]);

        // Search Console
        register_rest_route($ns, '/external-platforms/google/gsc/inject', [
            'methods'             => 'POST',
            'callback'            => [$this, 'gsc_inject'],
            'permission_callback' => [$this, 'can_manage'],
            'args'                => [
                'verification_code' => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        register_rest_route($ns, '/external-platforms/google/gsc/sites', [
            'methods'             => 'GET',
            'callback'            => [$this, 'gsc_list_sites'],
            'permission_callback' => [$this, 'can_manage'],
        ]);

        register_rest_route($ns, '/external-platforms/google/gsc/add-site', [
            'methods'             => 'POST',
            'callback'            => [$this, 'gsc_add_site'],
            'permission_callback' => [$this, 'can_manage'],
            'args'                => [
                'site_url' => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'esc_url_raw'],
            ],
        ]);

        register_rest_route($ns, '/external-platforms/google/gsc/submit-sitemap', [
            'methods'             => 'POST',
            'callback'            => [$this, 'gsc_submit_sitemap'],
            'permission_callback' => [$this, 'can_manage'],
            'args'                => [
                'site_url'    => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'esc_url_raw'],
                'sitemap_url' => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'esc_url_raw'],
            ],
        ]);

        register_rest_route($ns, '/external-platforms/google/gsc/analytics', [
            'methods'             => 'GET',
            'callback'            => [$this, 'gsc_analytics'],
            'permission_callback' => [$this, 'can_manage'],
            'args'                => [
                'site_url'   => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'esc_url_raw'],
                'start_date' => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'end_date'   => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'dimensions' => ['type' => 'array', 'default' => ['query']],
                'row_limit'  => ['type' => 'integer', 'default' => 100, 'minimum' => 1, 'maximum' => 1000],
            ],
        ]);

        // Merchant Center
        register_rest_route($ns, '/external-platforms/google/merchant/products', [
            'methods'             => 'GET',
            'callback'            => [$this, 'merchant_products'],
            'permission_callback' => [$this, 'can_manage'],
            'args'                => [
                'merchant_id' => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'max_results' => ['type' => 'integer', 'default' => 250, 'minimum' => 1, 'maximum' => 1000],
            ],
        ]);

        register_rest_route($ns, '/external-platforms/google/merchant/feed', [
            'methods'             => 'GET',
            'callback'            => [$this, 'merchant_feed'],
            'permission_callback' => [$this, 'can_manage'],
        ]);

        // Batch setup
        register_rest_route($ns, '/external-platforms/google/setup', [
            'methods'             => 'POST',
            'callback'            => [$this, 'google_setup'],
            'permission_callback' => [$this, 'can_manage'],
            'args'                => [
                'measurement_id'    => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => ''],
                'container_id'      => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => ''],
                'ads_id'            => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => ''],
                'gsc_verification'  => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => ''],
            ],
        ]);

        // ── Microsoft ────────────────────────────────────────────────────────

        // OAuth
        register_rest_route($ns, '/external-platforms/microsoft/auth-url', [
            'methods'             => 'GET',
            'callback'            => [$this, 'microsoft_auth_url'],
            'permission_callback' => [$this, 'can_manage'],
            'args'                => [
                'scopes' => ['type' => 'array', 'default' => ['https://ads.microsoft.com/msads.manage', 'offline_access']],
                'state'  => ['type' => 'string', 'default' => ''],
            ],
        ]);

        register_rest_route($ns, '/external-platforms/microsoft/exchange-code', [
            'methods'             => 'POST',
            'callback'            => [$this, 'microsoft_exchange_code'],
            'permission_callback' => [$this, 'can_manage'],
            'args'                => ['code' => ['type' => 'string', 'required' => true]],
        ]);

        register_rest_route($ns, '/external-platforms/microsoft/refresh-token', [
            'methods'             => 'POST',
            'callback'            => [$this, 'microsoft_refresh_token'],
            'permission_callback' => [$this, 'can_manage'],
            'args'                => [
                'refresh_token' => ['type' => 'string', 'required' => true],
                'scope'         => ['type' => 'string', 'default' => ''],
            ],
        ]);

        // Clarity
        register_rest_route($ns, '/external-platforms/microsoft/clarity/inject', [
            'methods'             => 'POST',
            'callback'            => [$this, 'clarity_inject'],
            'permission_callback' => [$this, 'can_manage'],
            'args'                => [
                'project_id' => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        register_rest_route($ns, '/external-platforms/microsoft/clarity/projects', [
            'methods'             => 'GET',
            'callback'            => [$this, 'clarity_projects'],
            'permission_callback' => [$this, 'can_manage'],
        ]);

        register_rest_route($ns, '/external-platforms/microsoft/clarity/recordings', [
            'methods'             => 'GET',
            'callback'            => [$this, 'clarity_recordings'],
            'permission_callback' => [$this, 'can_manage'],
            'args'                => [
                'project_id' => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        // Bing Ads (UET)
        register_rest_route($ns, '/external-platforms/microsoft/bing-ads/inject', [
            'methods'             => 'POST',
            'callback'            => [$this, 'bing_uet_inject'],
            'permission_callback' => [$this, 'can_manage'],
            'args'                => [
                'uet_tag_id' => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        register_rest_route($ns, '/external-platforms/microsoft/bing-ads/campaigns', [
            'methods'             => 'GET',
            'callback'            => [$this, 'bing_ads_campaigns'],
            'permission_callback' => [$this, 'can_manage'],
            'args'                => [
                'account_id' => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        // Bing Webmaster Tools
        register_rest_route($ns, '/external-platforms/microsoft/bing-wmt/inject', [
            'methods'             => 'POST',
            'callback'            => [$this, 'bing_wmt_inject'],
            'permission_callback' => [$this, 'can_manage'],
            'args'                => [
                'verification_code' => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        register_rest_route($ns, '/external-platforms/microsoft/bing-wmt/add-site', [
            'methods'             => 'POST',
            'callback'            => [$this, 'bing_wmt_add_site'],
            'permission_callback' => [$this, 'can_manage'],
            'args'                => [
                'site_url' => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'esc_url_raw'],
            ],
        ]);

        register_rest_route($ns, '/external-platforms/microsoft/bing-wmt/submit-sitemap', [
            'methods'             => 'POST',
            'callback'            => [$this, 'bing_wmt_submit_sitemap'],
            'permission_callback' => [$this, 'can_manage'],
            'args'                => [
                'site_url'    => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'esc_url_raw'],
                'sitemap_url' => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'esc_url_raw'],
            ],
        ]);

        // App Insights
        register_rest_route($ns, '/external-platforms/microsoft/app-insights/inject', [
            'methods'             => 'POST',
            'callback'            => [$this, 'app_insights_inject'],
            'permission_callback' => [$this, 'can_manage'],
            'args'                => [
                'instrumentation_key' => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => ''],
                'connection_string'   => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => ''],
            ],
        ]);

        // Batch Microsoft setup
        register_rest_route($ns, '/external-platforms/microsoft/setup', [
            'methods'             => 'POST',
            'callback'            => [$this, 'microsoft_setup'],
            'permission_callback' => [$this, 'can_manage'],
            'args'                => [
                'clarity_project_id'      => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => ''],
                'bing_uet_tag_id'         => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => ''],
                'bing_verification'       => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => ''],
                'appinsights_key'         => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => ''],
                'appinsights_connection'  => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => ''],
            ],
        ]);
    }

    // =========================================================================
    // Google handlers
    // =========================================================================

    public function google_auth_url(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        $google = $this->build_google();
        $url    = $google->get_auth_url((array) $req->get_param('scopes'), (string) $req->get_param('state'));
        return $this->success(['auth_url' => $url]);
    }

    public function google_exchange_code(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        $result = $this->build_google()->exchange_code((string) $req->get_param('code'));
        if (!$result['success']) {
            return $this->error($result['error'] ?? 'OAuth exchange failed', 400);
        }
        // Persist tokens
        if (!empty($result['access_token']))  update_option('rjv_agi_google_access_token',  $result['access_token']);
        if (!empty($result['refresh_token'])) update_option('rjv_agi_google_refresh_token', $result['refresh_token']);
        AuditLog::log('google_oauth_exchange', 'external_platforms', 0, [], 2);
        return $this->success($result);
    }

    public function google_refresh_token(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        $result = $this->build_google()->refresh_token((string) $req->get_param('refresh_token'));
        if (!$result['success']) {
            return $this->error($result['error'] ?? 'Token refresh failed', 400);
        }
        if (!empty($result['access_token'])) update_option('rjv_agi_google_access_token', $result['access_token']);
        return $this->success($result);
    }

    public function ga4_inject(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        $measurement_id = (string) $req->get_param('measurement_id');
        $config         = (array)  $req->get_param('config');
        $this->build_google()->inject_ga4($measurement_id, $config);
        AuditLog::log('ga4_snippet_saved', 'external_platforms', 0, ['measurement_id' => $measurement_id], 2);
        return $this->success(['measurement_id' => $measurement_id, 'injected' => true]);
    }

    public function ga4_list_properties(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        $result = $this->build_google()->list_ga4_properties();
        return $this->google_result($result);
    }

    public function ga4_report(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        $result = $this->build_google()->run_ga4_report(
            (string) $req->get_param('property_id'),
            (array)  $req->get_param('metrics'),
            (array)  $req->get_param('dimensions'),
            (array)  $req->get_param('date_ranges'),
            (int)    $req->get_param('limit')
        );
        return $this->google_result($result);
    }

    public function ga4_create_stream(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        $website_url = (string) ($req->get_param('website_url') ?: get_bloginfo('url'));
        $result = $this->build_google()->create_ga4_data_stream(
            (string) $req->get_param('property_id'),
            $website_url,
            (string) $req->get_param('stream_name')
        );
        return $this->google_result($result);
    }

    public function gtm_inject(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        $container_id = (string) $req->get_param('container_id');
        $this->build_google()->inject_gtm($container_id);
        AuditLog::log('gtm_snippet_saved', 'external_platforms', 0, ['container_id' => $container_id], 2);
        return $this->success(['container_id' => $container_id, 'injected' => true]);
    }

    public function gtm_accounts(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        return $this->google_result($this->build_google()->list_gtm_accounts());
    }

    public function google_ads_inject(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        $ads_id      = (string) $req->get_param('ads_id');
        $conversions = (array)  $req->get_param('conversions');
        $this->build_google()->inject_google_ads($ads_id, $conversions);
        AuditLog::log('google_ads_snippet_saved', 'external_platforms', 0, ['ads_id' => $ads_id], 2);
        return $this->success(['ads_id' => $ads_id, 'injected' => true]);
    }

    public function gsc_inject(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        $code = (string) $req->get_param('verification_code');
        $this->build_google()->inject_search_console_verification($code);
        AuditLog::log('gsc_verification_saved', 'external_platforms', 0, [], 2);
        return $this->success(['verification_code' => $code, 'injected' => true]);
    }

    public function gsc_list_sites(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        return $this->google_result($this->build_google()->list_search_console_sites());
    }

    public function gsc_add_site(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        $result = $this->build_google()->add_search_console_site((string) $req->get_param('site_url'));
        return $this->google_result($result);
    }

    public function gsc_submit_sitemap(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        $result = $this->build_google()->submit_sitemap(
            (string) $req->get_param('site_url'),
            (string) $req->get_param('sitemap_url')
        );
        return $this->google_result($result);
    }

    public function gsc_analytics(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        $result = $this->build_google()->get_search_analytics(
            (string) $req->get_param('site_url'),
            (string) ($req->get_param('start_date') ?? ''),
            (string) ($req->get_param('end_date') ?? ''),
            (array)  $req->get_param('dimensions'),
            (int)    $req->get_param('row_limit')
        );
        return $this->google_result($result);
    }

    public function merchant_products(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        $result = $this->build_google()->list_merchant_products(
            (string) $req->get_param('merchant_id'),
            (int)    $req->get_param('max_results')
        );
        return $this->google_result($result);
    }

    public function merchant_feed(\WP_REST_Request $req): \WP_REST_Response {
        $xml = $this->build_google()->generate_woocommerce_feed();
        // Return as raw response so content type is set correctly
        $response = new \WP_REST_Response($xml, 200);
        $response->header('Content-Type', 'application/xml; charset=UTF-8');
        return $response;
    }

    public function google_setup(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        $google  = $this->build_google();
        $applied = [];

        $mid = (string) $req->get_param('measurement_id');
        $cid = (string) $req->get_param('container_id');
        $aid = (string) $req->get_param('ads_id');
        $gsc = (string) $req->get_param('gsc_verification');

        if ($mid) { $google->inject_ga4($mid);              $applied[] = 'ga4'; }
        if ($cid) { $google->inject_gtm($cid);              $applied[] = 'gtm'; }
        if ($aid) { $google->inject_google_ads($aid);       $applied[] = 'google_ads'; }
        if ($gsc) { $google->inject_search_console_verification($gsc); $applied[] = 'search_console'; }

        AuditLog::log('google_setup_batch', 'external_platforms', 0, ['applied' => $applied], 2);
        return $this->success(['applied' => $applied, 'count' => count($applied)]);
    }

    // =========================================================================
    // Microsoft handlers
    // =========================================================================

    public function microsoft_auth_url(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        $ms  = $this->build_microsoft();
        $url = $ms->get_auth_url((array) $req->get_param('scopes'), (string) $req->get_param('state'));
        return $this->success(['auth_url' => $url]);
    }

    public function microsoft_exchange_code(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        $result = $this->build_microsoft()->exchange_code((string) $req->get_param('code'));
        if (!$result['success']) {
            return $this->error($result['error'] ?? 'OAuth exchange failed', 400);
        }
        if (!empty($result['access_token']))  update_option('rjv_agi_microsoft_access_token',  $result['access_token']);
        if (!empty($result['refresh_token'])) update_option('rjv_agi_microsoft_refresh_token', $result['refresh_token']);
        AuditLog::log('microsoft_oauth_exchange', 'external_platforms', 0, [], 2);
        return $this->success($result);
    }

    public function microsoft_refresh_token(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        $result = $this->build_microsoft()->refresh_token(
            (string) $req->get_param('refresh_token'),
            (string) $req->get_param('scope')
        );
        if (!$result['success']) {
            return $this->error($result['error'] ?? 'Token refresh failed', 400);
        }
        if (!empty($result['access_token'])) update_option('rjv_agi_microsoft_access_token', $result['access_token']);
        return $this->success($result);
    }

    public function clarity_inject(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        $project_id = (string) $req->get_param('project_id');
        $this->build_microsoft()->inject_clarity($project_id);
        AuditLog::log('clarity_snippet_saved', 'external_platforms', 0, ['project_id' => $project_id], 2);
        return $this->success(['project_id' => $project_id, 'injected' => true]);
    }

    public function clarity_projects(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        $result = $this->build_microsoft()->list_clarity_projects();
        return $this->ms_result($result);
    }

    public function clarity_recordings(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        $result = $this->build_microsoft()->get_clarity_recordings((string) $req->get_param('project_id'));
        return $this->ms_result($result);
    }

    public function bing_uet_inject(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        $tag_id = (string) $req->get_param('uet_tag_id');
        $this->build_microsoft()->inject_bing_uet($tag_id);
        AuditLog::log('bing_uet_snippet_saved', 'external_platforms', 0, ['tag_id' => $tag_id], 2);
        return $this->success(['uet_tag_id' => $tag_id, 'injected' => true]);
    }

    public function bing_ads_campaigns(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        $result = $this->build_microsoft()->list_campaigns((string) $req->get_param('account_id'));
        return $this->ms_result($result);
    }

    public function bing_wmt_inject(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        $code = (string) $req->get_param('verification_code');
        $this->build_microsoft()->inject_bing_verification($code);
        AuditLog::log('bing_wmt_verification_saved', 'external_platforms', 0, [], 2);
        return $this->success(['verification_code' => $code, 'injected' => true]);
    }

    public function bing_wmt_add_site(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        $result = $this->build_microsoft()->add_wmt_site((string) $req->get_param('site_url'));
        return $this->ms_result($result);
    }

    public function bing_wmt_submit_sitemap(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        $result = $this->build_microsoft()->submit_wmt_sitemap(
            (string) $req->get_param('site_url'),
            (string) $req->get_param('sitemap_url')
        );
        return $this->ms_result($result);
    }

    public function app_insights_inject(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        $key = (string) $req->get_param('instrumentation_key');
        $cs  = (string) $req->get_param('connection_string');
        if ($key === '' && $cs === '') {
            return $this->error('instrumentation_key or connection_string required', 400);
        }
        $this->build_microsoft()->inject_app_insights($key, $cs);
        AuditLog::log('appinsights_snippet_saved', 'external_platforms', 0, [], 2);
        return $this->success(['injected' => true]);
    }

    public function microsoft_setup(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        $ms      = $this->build_microsoft();
        $applied = [];

        $clarity    = (string) $req->get_param('clarity_project_id');
        $uet        = (string) $req->get_param('bing_uet_tag_id');
        $bing_v     = (string) $req->get_param('bing_verification');
        $ai_key     = (string) $req->get_param('appinsights_key');
        $ai_cs      = (string) $req->get_param('appinsights_connection');

        if ($clarity) { $ms->inject_clarity($clarity);           $applied[] = 'clarity'; }
        if ($uet)     { $ms->inject_bing_uet($uet);              $applied[] = 'bing_uet'; }
        if ($bing_v)  { $ms->inject_bing_verification($bing_v);  $applied[] = 'bing_wmt_verification'; }
        if ($ai_key || $ai_cs) { $ms->inject_app_insights($ai_key, $ai_cs); $applied[] = 'app_insights'; }

        AuditLog::log('microsoft_setup_batch', 'external_platforms', 0, ['applied' => $applied], 2);
        return $this->success(['applied' => $applied, 'count' => count($applied)]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    public function can_manage(): bool {
        return current_user_can('manage_options');
    }

    private function build_google(): GoogleServices {
        return new GoogleServices(
            (string) get_option('rjv_agi_google_access_token', ''),
            (string) get_option('rjv_agi_google_client_id', ''),
            (string) get_option('rjv_agi_google_client_secret', ''),
            (string) get_option('rjv_agi_google_redirect_uri', admin_url('admin-ajax.php?action=rjv_agi_google_oauth'))
        );
    }

    private function build_microsoft(): MicrosoftServices {
        return new MicrosoftServices(
            (string) get_option('rjv_agi_microsoft_access_token', ''),
            (string) get_option('rjv_agi_microsoft_client_id', ''),
            (string) get_option('rjv_agi_microsoft_client_secret', ''),
            (string) get_option('rjv_agi_microsoft_redirect_uri', admin_url('admin-ajax.php?action=rjv_agi_microsoft_oauth')),
            (string) get_option('rjv_agi_clarity_api_key', ''),
            (string) get_option('rjv_agi_bing_wmt_api_key', '')
        );
    }

    private function google_result(array $result): \WP_REST_Response|\WP_Error {
        if (!$result['success']) {
            return $this->error($result['error'] ?? 'Google API error', 502);
        }
        return $this->success($result['data'] ?? $result);
    }

    private function ms_result(array $result): \WP_REST_Response|\WP_Error {
        if (!$result['success']) {
            return $this->error($result['error'] ?? 'Microsoft API error', 502);
        }
        return $this->success($result['data'] ?? $result);
    }
}
