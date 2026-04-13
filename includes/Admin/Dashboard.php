<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\Admin;

use RJV_AGI_Bridge\{Settings, AuditLog};
use RJV_AGI_Bridge\Bridge\PlatformConnector;
use RJV_AGI_Bridge\AI\Orchestrator;

class Dashboard {
    public function register_menu(): void {
        add_menu_page('RJV AGI Bridge', 'AGI Bridge', 'manage_options', 'rjv-agi-bridge', [$this, 'render'], 'dashicons-superhero-alt', 3);
        add_submenu_page('rjv-agi-bridge', 'Dashboard', 'Dashboard', 'manage_options', 'rjv-agi-bridge', [$this, 'render']);
        add_submenu_page('rjv-agi-bridge', 'AI Orchestrator', 'AI Orchestrator', 'manage_options', 'rjv-agi-orchestrator', [$this, 'orchestrator']);
        add_submenu_page('rjv-agi-bridge', 'AGI Platform', 'AGI Platform', 'manage_options', 'rjv-agi-platform', [$this, 'platform']);
        add_submenu_page('rjv-agi-bridge', 'Settings', 'Settings', 'manage_options', 'rjv-agi-settings', [$this, 'settings']);
        add_submenu_page('rjv-agi-bridge', 'Audit Log', 'Audit Log', 'manage_options', 'rjv-agi-audit', [$this, 'audit']);
        add_submenu_page('rjv-agi-bridge', 'AI Playground', 'AI Playground', 'manage_options', 'rjv-agi-playground', [$this, 'playground']);
    }
    public function enqueue_assets(string $hook): void {
        if (strpos($hook, 'rjv-agi') === false) {
            return;
        }
        wp_enqueue_style('rjv-agi', RJV_AGI_PLUGIN_URL . 'admin/css/admin.css', [], RJV_AGI_VERSION);
        wp_enqueue_script('rjv-agi', RJV_AGI_PLUGIN_URL . 'admin/js/admin.js', ['jquery'], RJV_AGI_VERSION, true);
        wp_localize_script('rjv-agi', 'rjvAgi', [
            'restUrl' => rest_url('rjv-agi/v1/'),
            'nonce' => wp_create_nonce('wp_rest'),
            'apiKey' => Settings::get('api_key'),
            'platformConnected' => PlatformConnector::instance()->is_configured(),
        ]);
    }

    /**
     * Main Dashboard - The "Super Car" Welcome
     */
    public function render(): void {
        $s = Settings::all();
        $platform = PlatformConnector::instance();
        $is_connected = $platform->is_configured();
        ?>
<div class="wrap rjv-agi-wrap">
    <!-- Hero Section: The Value Proposition -->
    <div class="rjv-hero">
        <div class="rjv-hero-content">
            <h1>🚀 <?php esc_html_e('RJV AGI Bridge', 'rjv-agi-bridge'); ?></h1>
            <p class="rjv-hero-tagline"><?php esc_html_e('The Most Powerful AI Plugin for WordPress', 'rjv-agi-bridge'); ?></p>
            <p class="rjv-hero-desc">
                <?php esc_html_e('You\'re not just using another AI plugin. You have a complete AI orchestration system that coordinates multiple AI providers (Claude + GPT) to deliver superior results. Think of it as owning a super car.', 'rjv-agi-bridge'); ?>
            </p>
        </div>
        <div class="rjv-hero-badge">
            <?php if ($is_connected): ?>
                <span class="rjv-badge rjv-badge-premium">🏎️ <?php esc_html_e('PRO DRIVER MODE', 'rjv-agi-bridge'); ?></span>
                <p><?php esc_html_e('AGI Platform Connected', 'rjv-agi-bridge'); ?></p>
            <?php else: ?>
                <span class="rjv-badge rjv-badge-standard">🚗 <?php esc_html_e('SUPER CAR MODE', 'rjv-agi-bridge'); ?></span>
                <p><?php esc_html_e('Local AI Orchestration', 'rjv-agi-bridge'); ?></p>
            <?php endif; ?>
        </div>
    </div>

    <!-- What You Have Section -->
    <div class="rjv-section">
        <h2>✅ <?php esc_html_e('What You Have Right Now', 'rjv-agi-bridge'); ?></h2>
        <p class="rjv-section-desc">
            <?php esc_html_e('These premium features are included and fully functional - no subscription required:', 'rjv-agi-bridge'); ?>
        </p>

        <div class="rjv-feature-grid">
            <div class="rjv-feature-card rjv-feature-included">
                <span class="dashicons dashicons-admin-site-alt3"></span>
                <h3><?php esc_html_e('Multi-AI Coordination', 'rjv-agi-bridge'); ?></h3>
                <p><?php esc_html_e('Coordinates both Claude and GPT together. No other plugin does this - neither AI can use the other on their own.', 'rjv-agi-bridge'); ?></p>
            </div>

            <div class="rjv-feature-card rjv-feature-included">
                <span class="dashicons dashicons-rest-api"></span>
                <h3><?php esc_html_e('Complete REST API', 'rjv-agi-bridge'); ?></h3>
                <p><?php esc_html_e('17 endpoint groups for posts, pages, media, SEO, users, themes, plugins, and more. Full site control.', 'rjv-agi-bridge'); ?></p>
            </div>

            <div class="rjv-feature-card rjv-feature-included">
                <span class="dashicons dashicons-backup"></span>
                <h3><?php esc_html_e('Content Versioning', 'rjv-agi-bridge'); ?></h3>
                <p><?php esc_html_e('Every change is tracked, diffable, and reversible. Full history of who changed what and when.', 'rjv-agi-bridge'); ?></p>
            </div>

            <div class="rjv-feature-card rjv-feature-included">
                <span class="dashicons dashicons-shield"></span>
                <h3><?php esc_html_e('Security & Audit', 'rjv-agi-bridge'); ?></h3>
                <p><?php esc_html_e('Immutable audit log, rate limiting, IP allowlisting, and 3-tier authority system for safe operations.', 'rjv-agi-bridge'); ?></p>
            </div>

            <div class="rjv-feature-card rjv-feature-included">
                <span class="dashicons dashicons-yes-alt"></span>
                <h3><?php esc_html_e('Approval Workflows', 'rjv-agi-bridge'); ?></h3>
                <p><?php esc_html_e('Critical actions require explicit approval. Human-in-the-loop controls for sensitive operations.', 'rjv-agi-bridge'); ?></p>
            </div>

            <div class="rjv-feature-card rjv-feature-included">
                <span class="dashicons dashicons-cloud"></span>
                <h3><?php esc_html_e('AGI Platform Bridge', 'rjv-agi-bridge'); ?></h3>
                <p><?php esc_html_e('Connect to the RJV AGI Platform for central management, OpenClaw agents, and cross-site intelligence.', 'rjv-agi-bridge'); ?></p>
            </div>
        </div>
    </div>

    <!-- The Difference Section -->
    <div class="rjv-section rjv-section-comparison">
        <h2>🏁 <?php esc_html_e('Super Car vs. Professional Race Driver', 'rjv-agi-bridge'); ?></h2>
        <p class="rjv-section-desc">
            <?php esc_html_e('You already own the super car. The question is: who\'s driving?', 'rjv-agi-bridge'); ?>
        </p>

        <div class="rjv-comparison-table">
            <div class="rjv-comparison-header">
                <div class="rjv-comparison-col"><?php esc_html_e('Capability', 'rjv-agi-bridge'); ?></div>
                <div class="rjv-comparison-col rjv-col-current">
                    🚗 <?php esc_html_e('What You Have', 'rjv-agi-bridge'); ?>
                    <span><?php esc_html_e('(Plugin + Your AI Keys)', 'rjv-agi-bridge'); ?></span>
                </div>
                <div class="rjv-comparison-col rjv-col-agi">
                    🏎️ <?php esc_html_e('With AGI Platform', 'rjv-agi-bridge'); ?>
                    <span><?php esc_html_e('(Professional Driver)', 'rjv-agi-bridge'); ?></span>
                </div>
            </div>

            <div class="rjv-comparison-row">
                <div class="rjv-comparison-col"><?php esc_html_e('AI Providers', 'rjv-agi-bridge'); ?></div>
                <div class="rjv-comparison-col rjv-col-current">✅ <?php esc_html_e('Claude + GPT coordination', 'rjv-agi-bridge'); ?></div>
                <div class="rjv-comparison-col rjv-col-agi">✅ <?php esc_html_e('Claude + GPT as orchestrated workers', 'rjv-agi-bridge'); ?></div>
            </div>

            <div class="rjv-comparison-row">
                <div class="rjv-comparison-col"><?php esc_html_e('Task Routing', 'rjv-agi-bridge'); ?></div>
                <div class="rjv-comparison-col rjv-col-current">✅ <?php esc_html_e('Intelligent routing', 'rjv-agi-bridge'); ?></div>
                <div class="rjv-comparison-col rjv-col-agi">✅ <?php esc_html_e('Central AGI intelligence routing', 'rjv-agi-bridge'); ?></div>
            </div>

            <div class="rjv-comparison-row">
                <div class="rjv-comparison-col"><?php esc_html_e('Agent Management', 'rjv-agi-bridge'); ?></div>
                <div class="rjv-comparison-col rjv-col-current">✅ <?php esc_html_e('API access for agents', 'rjv-agi-bridge'); ?></div>
                <div class="rjv-comparison-col rjv-col-agi">✅ <?php esc_html_e('OpenClaw agentic teams + visual management', 'rjv-agi-bridge'); ?></div>
            </div>

            <div class="rjv-comparison-row">
                <div class="rjv-comparison-col"><?php esc_html_e('Configuration & Settings', 'rjv-agi-bridge'); ?></div>
                <div class="rjv-comparison-col rjv-col-current">✅ <?php esc_html_e('Basic plugin settings', 'rjv-agi-bridge'); ?></div>
                <div class="rjv-comparison-col rjv-col-agi">✅ <?php esc_html_e('Full dashboard with advanced controls', 'rjv-agi-bridge'); ?></div>
            </div>

            <div class="rjv-comparison-row">
                <div class="rjv-comparison-col"><?php esc_html_e('Cross-Site Intelligence', 'rjv-agi-bridge'); ?></div>
                <div class="rjv-comparison-col rjv-col-current">—</div>
                <div class="rjv-comparison-col rjv-col-agi">✅ <?php esc_html_e('Unified intelligence across all sites', 'rjv-agi-bridge'); ?></div>
            </div>

            <div class="rjv-comparison-row">
                <div class="rjv-comparison-col"><?php esc_html_e('Parallel Agent Teams', 'rjv-agi-bridge'); ?></div>
                <div class="rjv-comparison-col rjv-col-current">—</div>
                <div class="rjv-comparison-col rjv-col-agi">✅ <?php esc_html_e('Multiple agents working together', 'rjv-agi-bridge'); ?></div>
            </div>

            <div class="rjv-comparison-row">
                <div class="rjv-comparison-col"><?php esc_html_e('Real-time Optimization', 'rjv-agi-bridge'); ?></div>
                <div class="rjv-comparison-col rjv-col-current">—</div>
                <div class="rjv-comparison-col rjv-col-agi">✅ <?php esc_html_e('Continuous optimization', 'rjv-agi-bridge'); ?></div>
            </div>

            <div class="rjv-comparison-row">
                <div class="rjv-comparison-col"><?php esc_html_e('Enterprise Workflows', 'rjv-agi-bridge'); ?></div>
                <div class="rjv-comparison-col rjv-col-current">—</div>
                <div class="rjv-comparison-col rjv-col-agi">✅ <?php esc_html_e('Orchestrated multi-step workflows', 'rjv-agi-bridge'); ?></div>
            </div>

            <div class="rjv-comparison-row">
                <div class="rjv-comparison-col"><?php esc_html_e('Multi-Site Management', 'rjv-agi-bridge'); ?></div>
                <div class="rjv-comparison-col rjv-col-current">—</div>
                <div class="rjv-comparison-col rjv-col-agi">✅ <?php esc_html_e('Manage all sites from one dashboard', 'rjv-agi-bridge'); ?></div>
            </div>
        </div>

        <?php if (!$is_connected): ?>
        <div class="rjv-cta">
            <p><?php esc_html_e('Ready for a professional race driver? Create a free account on the RJV AGI Platform.', 'rjv-agi-bridge'); ?></p>
            <a href="<?php echo esc_url(admin_url('admin.php?page=rjv-agi-platform')); ?>" class="button button-primary button-hero">
                <?php esc_html_e('Connect to AGI Platform', 'rjv-agi-bridge'); ?>
            </a>
            <a href="https://rjvtechnologies.com/agi-platform" target="_blank" class="button button-secondary button-hero">
                <?php esc_html_e('Learn More', 'rjv-agi-bridge'); ?>
            </a>
        </div>
        <?php else: ?>
        <div class="rjv-cta rjv-cta-connected">
            <p>✅ <?php esc_html_e('You\'re connected to the AGI Platform. You have the professional race driver! Manage your agents and settings at', 'rjv-agi-bridge'); ?> <a href="https://platform.rjvtechnologies.com" target="_blank">platform.rjvtechnologies.com</a></p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Quick Stats Grid -->
    <div class="rjv-grid">
        <div class="rjv-card">
            <h2><?php esc_html_e('System Status', 'rjv-agi-bridge'); ?></h2>
            <div id="rjv-health" class="rjv-loading"><?php esc_html_e('Loading...', 'rjv-agi-bridge'); ?></div>
        </div>
        <div class="rjv-card">
            <h2><?php esc_html_e('Today\'s Activity', 'rjv-agi-bridge'); ?></h2>
            <div id="rjv-stats" class="rjv-loading"><?php esc_html_e('Loading...', 'rjv-agi-bridge'); ?></div>
        </div>
        <div class="rjv-card">
            <h2><?php esc_html_e('AI Providers', 'rjv-agi-bridge'); ?></h2>
            <div id="rjv-ai" class="rjv-loading"><?php esc_html_e('Loading...', 'rjv-agi-bridge'); ?></div>
        </div>
        <div class="rjv-card rjv-card-full">
            <h2><?php esc_html_e('API Key', 'rjv-agi-bridge'); ?></h2>
            <p><?php esc_html_e('Header:', 'rjv-agi-bridge'); ?> <code>X-RJV-AGI-Key</code></p>
            <div class="rjv-key-box">
                <code id="rjv-key"><?php echo esc_html($s['api_key']); ?></code>
                <button class="button" onclick="navigator.clipboard.writeText(document.getElementById('rjv-key').textContent)">
                    <?php esc_html_e('Copy', 'rjv-agi-bridge'); ?>
                </button>
            </div>
        </div>
    </div>
</div>
        <?php
    }

    /**
     * AI Orchestrator Page - Shows the power of multi-AI coordination
     */
    public function orchestrator(): void {
        ?>
<div class="wrap rjv-agi-wrap">
    <h1>🎯 <?php esc_html_e('AI Orchestrator', 'rjv-agi-bridge'); ?></h1>
    <p class="rjv-section-desc">
        <?php esc_html_e('This is what makes your plugin a "super car" - intelligent coordination of multiple AI providers for superior results.', 'rjv-agi-bridge'); ?>
    </p>

    <div class="rjv-orchestrator-grid">
        <!-- Chain Execution -->
        <div class="rjv-orchestrator-card">
            <h2>🔗 <?php esc_html_e('Chain Execution', 'rjv-agi-bridge'); ?></h2>
            <p><?php esc_html_e('Use one AI\'s output as input for another. Perfect for complex workflows.', 'rjv-agi-bridge'); ?></p>
            <div class="rjv-chain-visual">
                <div class="rjv-chain-step">
                    <span class="rjv-provider-badge rjv-openai">GPT</span>
                    <span><?php esc_html_e('SEO Analysis', 'rjv-agi-bridge'); ?></span>
                </div>
                <span class="dashicons dashicons-arrow-right-alt"></span>
                <div class="rjv-chain-step">
                    <span class="rjv-provider-badge rjv-anthropic">Claude</span>
                    <span><?php esc_html_e('Write Content', 'rjv-agi-bridge'); ?></span>
                </div>
                <span class="dashicons dashicons-arrow-right-alt"></span>
                <div class="rjv-chain-step">
                    <span class="rjv-provider-badge rjv-openai">GPT</span>
                    <span><?php esc_html_e('Meta Tags', 'rjv-agi-bridge'); ?></span>
                </div>
            </div>
            <button class="button button-primary" id="rjv-demo-chain"><?php esc_html_e('Try Chain Execution', 'rjv-agi-bridge'); ?></button>
        </div>

        <!-- Consensus Building -->
        <div class="rjv-orchestrator-card">
            <h2>🤝 <?php esc_html_e('Consensus Building', 'rjv-agi-bridge'); ?></h2>
            <p><?php esc_html_e('Get both AIs to work on the same task and synthesize the best answer.', 'rjv-agi-bridge'); ?></p>
            <div class="rjv-consensus-visual">
                <div class="rjv-consensus-inputs">
                    <div class="rjv-provider-badge rjv-anthropic">Claude</div>
                    <div class="rjv-provider-badge rjv-openai">GPT</div>
                </div>
                <span class="dashicons dashicons-arrow-down-alt"></span>
                <div class="rjv-consensus-output">
                    <span class="dashicons dashicons-lightbulb"></span>
                    <span><?php esc_html_e('Best Combined Answer', 'rjv-agi-bridge'); ?></span>
                </div>
            </div>
            <button class="button button-primary" id="rjv-demo-consensus"><?php esc_html_e('Try Consensus', 'rjv-agi-bridge'); ?></button>
        </div>

        <!-- Intelligent Routing -->
        <div class="rjv-orchestrator-card">
            <h2>🎯 <?php esc_html_e('Intelligent Routing', 'rjv-agi-bridge'); ?></h2>
            <p><?php esc_html_e('Tasks are automatically routed to the AI that handles them best.', 'rjv-agi-bridge'); ?></p>
            <div class="rjv-routing-table">
                <div class="rjv-routing-row">
                    <span><?php esc_html_e('Long-form Content', 'rjv-agi-bridge'); ?></span>
                    <span class="rjv-provider-badge rjv-anthropic">→ Claude</span>
                </div>
                <div class="rjv-routing-row">
                    <span><?php esc_html_e('SEO Metadata', 'rjv-agi-bridge'); ?></span>
                    <span class="rjv-provider-badge rjv-openai">→ GPT</span>
                </div>
                <div class="rjv-routing-row">
                    <span><?php esc_html_e('Creative Writing', 'rjv-agi-bridge'); ?></span>
                    <span class="rjv-provider-badge rjv-anthropic">→ Claude</span>
                </div>
                <div class="rjv-routing-row">
                    <span><?php esc_html_e('Data Extraction', 'rjv-agi-bridge'); ?></span>
                    <span class="rjv-provider-badge rjv-openai">→ GPT</span>
                </div>
            </div>
        </div>

        <!-- Parallel Execution -->
        <div class="rjv-orchestrator-card">
            <h2>⚡ <?php esc_html_e('Parallel Execution', 'rjv-agi-bridge'); ?></h2>
            <p><?php esc_html_e('Run multiple AI tasks simultaneously for faster results.', 'rjv-agi-bridge'); ?></p>
            <div class="rjv-parallel-visual">
                <div class="rjv-parallel-tasks">
                    <div class="rjv-parallel-task">
                        <span class="rjv-provider-badge rjv-anthropic">Claude</span>
                        <span><?php esc_html_e('Analyze', 'rjv-agi-bridge'); ?></span>
                    </div>
                    <div class="rjv-parallel-task">
                        <span class="rjv-provider-badge rjv-openai">GPT</span>
                        <span><?php esc_html_e('SEO Check', 'rjv-agi-bridge'); ?></span>
                    </div>
                    <div class="rjv-parallel-task">
                        <span class="rjv-provider-badge rjv-anthropic">Claude</span>
                        <span><?php esc_html_e('Improve', 'rjv-agi-bridge'); ?></span>
                    </div>
                </div>
                <span class="rjv-parallel-arrow">↓ <?php esc_html_e('All at once', 'rjv-agi-bridge'); ?> ↓</span>
            </div>
            <button class="button button-primary" id="rjv-demo-parallel"><?php esc_html_e('Try Parallel Execution', 'rjv-agi-bridge'); ?></button>
        </div>
    </div>

    <div id="rjv-orchestrator-output" style="display: none;" class="rjv-card rjv-card-full">
        <h3><?php esc_html_e('Result', 'rjv-agi-bridge'); ?></h3>
        <pre id="rjv-orchestrator-result"></pre>
    </div>
</div>
        <?php
    }

    /**
     * AGI Platform Connection Page
     */
    public function platform(): void {
        $platform = PlatformConnector::instance();
        $is_connected = $platform->is_configured();
        $subscription = $is_connected ? $platform->validate_subscription() : null;

        // Handle form submission
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_admin_referer('rjv_platform')) {
            if (isset($_POST['rjv_tenant_id'])) {
                Settings::set('tenant_id', sanitize_text_field(wp_unslash($_POST['rjv_tenant_id'])));
            }
            if (isset($_POST['rjv_tenant_secret'])) {
                Settings::set('tenant_secret', sanitize_text_field(wp_unslash($_POST['rjv_tenant_secret'])));
            }
            if (isset($_POST['rjv_platform_url'])) {
                Settings::set('platform_url', esc_url_raw(wp_unslash($_POST['rjv_platform_url'])));
            }
            echo '<div class="notice notice-success"><p>' . esc_html__('Platform settings saved.', 'rjv-agi-bridge') . '</p></div>';

            // Refresh connection status
            $platform = PlatformConnector::instance();
            $is_connected = $platform->is_configured();
            $subscription = $is_connected ? $platform->validate_subscription() : null;
        }
        ?>
<div class="wrap rjv-agi-wrap">
    <h1>🌐 <?php esc_html_e('RJV AGI Platform', 'rjv-agi-bridge'); ?></h1>

    <?php if ($is_connected && !empty($subscription['valid'])): ?>
    <!-- Connected State -->
    <div class="rjv-platform-connected">
        <div class="rjv-platform-status">
            <span class="dashicons dashicons-yes-alt"></span>
            <div>
                <h2><?php esc_html_e('Connected to AGI Platform', 'rjv-agi-bridge'); ?></h2>
                <p><?php printf(esc_html__('Plan: %s', 'rjv-agi-bridge'), esc_html($subscription['plan'] ?? 'Unknown')); ?></p>
            </div>
        </div>

        <div class="rjv-platform-features">
            <h3><?php esc_html_e('Enhanced Capabilities Unlocked', 'rjv-agi-bridge'); ?></h3>
            <ul>
                <li>✅ <?php esc_html_e('OpenClaw agentic teams working in parallel', 'rjv-agi-bridge'); ?></li>
                <li>✅ <?php esc_html_e('Cross-site intelligence and optimization', 'rjv-agi-bridge'); ?></li>
                <li>✅ <?php esc_html_e('Claude + GPT as orchestrated AI workers', 'rjv-agi-bridge'); ?></li>
                <li>✅ <?php esc_html_e('Enterprise workflow automation', 'rjv-agi-bridge'); ?></li>
                <li>✅ <?php esc_html_e('Multi-site management from central platform', 'rjv-agi-bridge'); ?></li>
                <li>✅ <?php esc_html_e('Real-time performance optimization', 'rjv-agi-bridge'); ?></li>
            </ul>
        </div>
    </div>

    <?php else: ?>
    <!-- Not Connected State -->
    <div class="rjv-platform-hero">
        <div class="rjv-platform-hero-content">
            <h2><?php esc_html_e('Upgrade to Professional Race Driver', 'rjv-agi-bridge'); ?></h2>
            <p>
                <?php esc_html_e('You already have the super car (this plugin). Connect to the AGI Platform to get the professional race driver.', 'rjv-agi-bridge'); ?>
            </p>

            <div class="rjv-platform-benefits">
                <div class="rjv-platform-benefit">
                    <span class="dashicons dashicons-networking"></span>
                    <h3><?php esc_html_e('OpenClaw Agentic Teams', 'rjv-agi-bridge'); ?></h3>
                    <p><?php esc_html_e('Multiple specialized AI agents working together in parallel. While Claude writes, GPT optimizes, and another agent monitors quality.', 'rjv-agi-bridge'); ?></p>
                </div>

                <div class="rjv-platform-benefit">
                    <span class="dashicons dashicons-admin-multisite"></span>
                    <h3><?php esc_html_e('Cross-Site Intelligence', 'rjv-agi-bridge'); ?></h3>
                    <p><?php esc_html_e('Learnings from one site improve all your sites. Unified optimization across your entire digital estate.', 'rjv-agi-bridge'); ?></p>
                </div>

                <div class="rjv-platform-benefit">
                    <span class="dashicons dashicons-superhero"></span>
                    <h3><?php esc_html_e('Claude + GPT as Workers', 'rjv-agi-bridge'); ?></h3>
                    <p><?php esc_html_e('Neither Claude nor GPT can use each other. Our AGI orchestrates both as AI workers, plus our own OpenClaw agents.', 'rjv-agi-bridge'); ?></p>
                </div>

                <div class="rjv-platform-benefit">
                    <span class="dashicons dashicons-performance"></span>
                    <h3><?php esc_html_e('Real-Time Optimization', 'rjv-agi-bridge'); ?></h3>
                    <p><?php esc_html_e('Continuous monitoring and optimization across all connected sites. No manual intervention required.', 'rjv-agi-bridge'); ?></p>
                </div>
            </div>

            <div class="rjv-platform-cta">
                <a href="https://rjvtechnologies.com/agi-platform/register" target="_blank" class="button button-primary button-hero">
                    <?php esc_html_e('Create Free Account', 'rjv-agi-bridge'); ?>
                </a>
                <a href="https://rjvtechnologies.com/agi-platform" target="_blank" class="button button-secondary button-hero">
                    <?php esc_html_e('Learn More', 'rjv-agi-bridge'); ?>
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Connection Form -->
    <div class="rjv-card">
        <h2><?php esc_html_e('Platform Connection', 'rjv-agi-bridge'); ?></h2>
        <p><?php esc_html_e('Enter your AGI Platform credentials to connect.', 'rjv-agi-bridge'); ?></p>

        <form method="post">
            <?php wp_nonce_field('rjv_platform'); ?>
            <table class="form-table">
                <tr>
                    <th><?php esc_html_e('Platform URL', 'rjv-agi-bridge'); ?></th>
                    <td>
                        <input type="url" name="rjv_platform_url" value="<?php echo esc_attr(Settings::get('platform_url')); ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Tenant ID', 'rjv-agi-bridge'); ?></th>
                    <td>
                        <input type="text" name="rjv_tenant_id" value="<?php echo esc_attr(Settings::get('tenant_id')); ?>" class="regular-text">
                        <p class="description"><?php esc_html_e('Found in your AGI Platform dashboard.', 'rjv-agi-bridge'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Tenant Secret', 'rjv-agi-bridge'); ?></th>
                    <td>
                        <input type="password" name="rjv_tenant_secret" value="<?php echo esc_attr(Settings::get('tenant_secret')); ?>" class="regular-text">
                        <p class="description"><?php esc_html_e('Your secure authentication secret.', 'rjv-agi-bridge'); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button(__('Save Connection', 'rjv-agi-bridge')); ?>
        </form>
    </div>
</div>
        <?php
    }

    public function settings(): void {
        if($_SERVER['REQUEST_METHOD']==='POST'&&check_admin_referer('rjv_s')){
            foreach(['openai_key','anthropic_key','default_model','openai_model','anthropic_model','rate_limit','audit_enabled','allowed_ips','log_retention_days'] as $f)
                if(isset($_POST["rjv_{$f}"]))Settings::set($f,sanitize_textarea_field(wp_unslash($_POST["rjv_{$f}"])));
            if(isset($_POST['rjv_regen']))Settings::set('api_key',wp_generate_password(64,false));
            echo '<div class="notice notice-success"><p>' . esc_html__('Settings saved.', 'rjv-agi-bridge') . '</p></div>';
        }$s=Settings::all(); ?>
<div class="wrap rjv-agi-wrap"><h1><?php esc_html_e('AGI Bridge Settings', 'rjv-agi-bridge'); ?></h1><form method="post"><?php wp_nonce_field('rjv_s');?>
<table class="form-table">
<tr><th><?php esc_html_e('OpenAI Key', 'rjv-agi-bridge'); ?></th><td><input type="password" name="rjv_openai_key" value="<?php echo esc_attr(Settings::get('openai_key'));?>" class="regular-text"></td></tr>
<tr><th><?php esc_html_e('Anthropic Key', 'rjv-agi-bridge'); ?></th><td><input type="password" name="rjv_anthropic_key" value="<?php echo esc_attr(Settings::get('anthropic_key'));?>" class="regular-text"></td></tr>
<tr><th><?php esc_html_e('Default Provider', 'rjv-agi-bridge'); ?></th><td><select name="rjv_default_model"><option value="anthropic" <?php selected($s['default_model'],'anthropic');?>>Anthropic</option><option value="openai" <?php selected($s['default_model'],'openai');?>>OpenAI</option></select></td></tr>
<tr><th><?php esc_html_e('OpenAI Model', 'rjv-agi-bridge'); ?></th><td><input name="rjv_openai_model" value="<?php echo esc_attr($s['openai_model']);?>" class="regular-text"></td></tr>
<tr><th><?php esc_html_e('Anthropic Model', 'rjv-agi-bridge'); ?></th><td><input name="rjv_anthropic_model" value="<?php echo esc_attr($s['anthropic_model']);?>" class="regular-text"></td></tr>
<tr><th><?php esc_html_e('Rate Limit/min', 'rjv-agi-bridge'); ?></th><td><input type="number" name="rjv_rate_limit" value="<?php echo esc_attr($s['rate_limit']);?>" min="1"></td></tr>
<tr><th><?php esc_html_e('Audit Logging', 'rjv-agi-bridge'); ?></th><td><label><input type="checkbox" name="rjv_audit_enabled" value="1" <?php checked($s['audit_enabled'],'1');?>> <?php esc_html_e('Enable', 'rjv-agi-bridge'); ?></label></td></tr>
<tr><th><?php esc_html_e('Log Retention (days)', 'rjv-agi-bridge'); ?></th><td><input type="number" name="rjv_log_retention_days" value="<?php echo esc_attr($s['log_retention_days']??90);?>" min="1" max="365"><p class="description"><?php esc_html_e('Audit log entries older than this will be automatically deleted.', 'rjv-agi-bridge'); ?></p></td></tr>
<tr><th><?php esc_html_e('IP Allowlist', 'rjv-agi-bridge'); ?></th><td><textarea name="rjv_allowed_ips" rows="3" class="large-text"><?php echo esc_textarea($s['allowed_ips']??'');?></textarea><p class="description"><?php esc_html_e('One IP per line. Leave empty to allow all.', 'rjv-agi-bridge'); ?></p></td></tr>
<tr><th><?php esc_html_e('Regenerate Key', 'rjv-agi-bridge'); ?></th><td><label><input type="checkbox" name="rjv_regen" value="1"> <?php esc_html_e('Generate new API key on save', 'rjv-agi-bridge'); ?></label></td></tr>
</table><?php submit_button();?></form></div><?php }

    public function audit(): void { $entries=AuditLog::query(['per_page'=>100]); ?>
<div class="wrap rjv-agi-wrap"><h1><?php esc_html_e('Audit Log', 'rjv-agi-bridge'); ?></h1>
<table class="wp-list-table widefat fixed striped rjv-audit-table"><thead><tr><th><?php esc_html_e('Time', 'rjv-agi-bridge'); ?></th><th><?php esc_html_e('Agent', 'rjv-agi-bridge'); ?></th><th><?php esc_html_e('Action', 'rjv-agi-bridge'); ?></th><th><?php esc_html_e('Resource', 'rjv-agi-bridge'); ?></th><th><?php esc_html_e('Tier', 'rjv-agi-bridge'); ?></th><th><?php esc_html_e('Status', 'rjv-agi-bridge'); ?></th><th><?php esc_html_e('Tokens', 'rjv-agi-bridge'); ?></th><th><?php esc_html_e('Latency', 'rjv-agi-bridge'); ?></th></tr></thead><tbody>
<?php foreach($entries as $e):?><tr><td><?php echo esc_html($e['timestamp']);?></td><td><?php echo esc_html($e['agent_id']);?></td><td><code><?php echo esc_html($e['action']);?></code></td><td><?php echo esc_html($e['resource_type'].($e['resource_id']?" #{$e['resource_id']}":'')); ?></td><td><span class="tier-badge tier-<?php echo esc_attr($e['tier']);?>">T<?php echo esc_html($e['tier']);?></span></td><td class="status-<?php echo esc_attr($e['status']);?>"><?php echo esc_html($e['status']);?></td><td><?php echo $e['tokens_used']?number_format((int)$e['tokens_used']):'-';?></td><td><?php echo $e['execution_time_ms']?esc_html($e['execution_time_ms']).'ms':'-';?></td></tr>
<?php endforeach;?></tbody></table></div><?php }

    public function playground(): void { ?>
<div class="wrap rjv-agi-wrap"><h1><?php esc_html_e('AI Playground', 'rjv-agi-bridge'); ?></h1>
<div class="rjv-playground">
<p><select id="rjv-prov"><option value=""><?php esc_html_e('Default', 'rjv-agi-bridge'); ?></option><option value="anthropic">Anthropic</option><option value="openai">OpenAI</option></select></p>
<p><strong><?php esc_html_e('System:', 'rjv-agi-bridge'); ?></strong><br><textarea id="rjv-sys" rows="2">You are a helpful assistant for RJV Technologies Ltd.</textarea></p>
<p><strong><?php esc_html_e('Message:', 'rjv-agi-bridge'); ?></strong><br><textarea id="rjv-msg" rows="4"></textarea></p>
<p><button class="button button-primary" id="rjv-send"><?php esc_html_e('Send', 'rjv-agi-bridge'); ?></button> <span id="rjv-load" style="display:none" class="rjv-loading"><?php esc_html_e('Working...', 'rjv-agi-bridge'); ?></span></p>
<div id="rjv-out" style="display:none" class="rjv-playground-output"><pre id="rjv-text"></pre><p id="rjv-meta" class="rjv-playground-meta"></p></div>
</div></div><?php }
}
