=== RJV AGI Bridge ===
Contributors: rjvtechnologies
Tags: ai, api, automation, openai, anthropic, claude, gpt, seo, content-generation, ai-orchestration
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 3.2.0
License: Proprietary
License URI: https://rjvtechnologies.com/license

The most powerful AI plugin for WordPress. Multi-AI coordination with Claude + GPT, complete REST API, and bridge to the RJV AGI Platform.

== Description ==

**RJV AGI Bridge is not just another AI plugin. It's the bridge to a complete AI orchestration system.**

Think of it like this: you're getting a super car from day one. The plugin coordinates both OpenAI (GPT) and Anthropic (Claude) together - something neither AI can do on their own. No other WordPress plugin offers this level of AI coordination.

= What Makes This Different? =

**Other AI plugins** call one AI provider at a time. If you have both ChatGPT and Claude, they just sit there independently - they cannot use each other.

**RJV AGI Bridge** coordinates multiple AI providers together:

* **Neither Claude nor GPT can use each other** - But this plugin orchestrates both as coordinated AI workers
* **Intelligent Task Routing** - Tasks are automatically sent to the AI that handles them best
* **Complete Site Control** - 19 API endpoint groups for full WordPress management
* **Enterprise Security** - Immutable audit log, approval workflows, 3-tier authority system

= The "Super Car" You Get From Day One =

With just your OpenAI and/or Anthropic API keys, you have:

* **Multi-AI Coordination** - No other WordPress plugin coordinates Claude + GPT together
* **19 API Endpoint Groups** - Posts, pages, media, SEO, users, themes, plugins, tools, multisite awareness, and more
* **Content Versioning** - Every change tracked, diffable, and reversible
* **Approval Workflows** - Human-in-the-loop controls for critical actions
* **Security & Audit** - Rate limiting, IP allowlisting, immutable audit log
* **3-Tier Authority System** - Autonomous, Supervised, and Approval Required levels

= Want the Professional Race Driver? =

Connect to the **RJV AGI Platform** to unlock:

* **OpenClaw Agentic Teams** - Multiple specialized AI agents working together in parallel
* **Full Dashboard Management** - All complex settings, agent definitions, and workflows managed visually
* **Cross-Site Intelligence** - Learnings from one site improve all your sites
* **Claude + GPT as Orchestrated Workers** - Our AGI uses both as AI workers at a higher level
* **Enterprise Workflow Automation** - Complex multi-step workflows across your digital estate
* **Real-Time Optimization** - Continuous improvement without manual intervention
* **Multi-Site Management** - Manage all connected sites from one central dashboard

The plugin doesn't limit you - it's already the super car. The AGI Platform adds the professional race driver. All complex settings, agent configurations, and advanced features are managed in your AGI Platform account, not in the plugin.

= Key Features =

**AI Integration**
* Dual AI Support: OpenAI (GPT-4.1-mini) and Anthropic (Claude Sonnet)
* Automatic failover between providers
* Intelligent task routing based on AI strengths
* Coordinated multi-AI execution

**Content Management**
* Full CRUD operations for posts, pages, media
* Bulk operations with validation
* SEO meta management (Yoast + RankMath compatible)
* AI-powered content generation
* Complete version history with one-click revert

**Enterprise Features**
* Approval workflows for sensitive actions
* External integrations with encrypted credentials
* Webhook system with signature validation
* Event streaming to AGI Platform
* Enterprise control-plane governance endpoints
* Environment-specific capability overrides
* SLO telemetry, request trace IDs, and drift baselines
* API contract/deprecation headers and upgrade safety status
* Typed policy rules and deterministic execution ledger replay
* Security/compliance controls (key rotation chain, legal hold, export snapshot)

**Security & Compliance**
* Rate limiting and IP allowlisting
* Immutable audit log with full traceability
* Role-based access control
* 3-tier authority system

= Requirements =

* WordPress 6.4 or higher
* PHP 8.1 or higher
* MySQL 8.0 or higher
* OpenAI and/or Anthropic API key

== Installation ==

1. Upload the `rjv-agi-bridge` folder to `/wp-content/plugins/`
2. Activate the plugin through 'Plugins' in WordPress admin
3. Navigate to **AGI Bridge > Settings** to configure your AI provider keys
4. Optionally connect to the AGI Platform for enhanced capabilities

= Via Git =

`cd /path/to/wordpress/wp-content/plugins/
git clone https://github.com/RJV-TECHNOLOGIES-LTD/AGI.git rjv-agi-bridge`

== Frequently Asked Questions ==

= Do I need the AGI Platform to use this plugin? =

No! The plugin works standalone with your own OpenAI and Anthropic API keys. You get the full "super car" - multi-AI coordination, complete REST API, versioning, and more. The AGI Platform is optional and adds the "professional race driver" - central management, agentic teams, cross-site intelligence, and advanced workflows. All complex configuration is done in your AGI Platform account.

= Can I use only OpenAI or only Anthropic? =

Yes. The plugin works with either or both. If you have both configured, it will intelligently route tasks to the best provider. If you have only one, all features still work with that provider.

= What is multi-AI coordination? =

Unlike other plugins that call one AI at a time, RJV AGI Bridge coordinates multiple AI providers. Neither Claude nor GPT can use each other on their own - but this plugin orchestrates both together, producing better results than any single AI could.

= Where do I configure agents and advanced settings? =

All complex settings, agent definitions, and advanced configurations are managed in your RJV AGI Platform account at platform.rjvtechnologies.com. The plugin provides the connection and API - the Platform provides the management interface.

= How is this different from premium AI plugins? =

Most premium AI plugins are wrappers around a single AI API. They call GPT or Claude and return the response. RJV AGI Bridge:
- Coordinates multiple AIs together
- Provides a complete REST API for automation
- Includes enterprise features like versioning, approvals, and audit logs
- Connects to a central platform for advanced management

= Is the audit log tamper-proof? =

The audit log is insert-only with no update or delete API. It records timestamps, actions, IP addresses, user agents, and AI token usage for complete traceability.

== Screenshots ==

1. Dashboard with AI coordination status and capabilities overview
2. AGI Platform connection showing enhanced capabilities
3. Settings page with AI provider configuration
4. Audit log with complete action history

== Changelog ==

= 3.2.0 =
* NEW: Foundation hardening with architecture audit and API contract/deprecation manager
* NEW: Upgrade safety framework with compatibility report, rollback guard, and migration history
* NEW: Typed policy rules (allow/deny/approve/escalate) with deterministic conflict resolution
* NEW: Deterministic execution ledger with immutable hash chain and replay endpoints
* NEW: Compliance baseline APIs (threat controls, legal hold, secret rotation chain, export snapshot)
* NEW: Observability alerts, anomaly/error-budget reporting, and release gate telemetry

= 3.1.0 =
* NEW: Enterprise Control Plane API for program scope, policy governance, capabilities, and observability
* NEW: Runtime Policy Engine with deny routes and approval guardrails
* NEW: Environment-specific capability overrides for dev/staging/production
* NEW: Reliability monitor with SLO telemetry and request trace headers
* NEW: Configuration drift reporting and baseline snapshot workflows

= 3.0.0 =
* NEW: Multi-AI Coordination - orchestrates Claude + GPT together
* NEW: Enhanced dashboard with clear value proposition
* NEW: AGI Platform integration with comparison view
* NEW: Content Versioning System with diff and revert
* NEW: Approval Workflow System for critical actions
* NEW: Design System Controller for consistent styling
* NEW: Real-time Event Streaming to Platform
* NEW: Security Monitor with vulnerability scanning
* NEW: Integration Manager with encrypted credentials
* NEW: Webhook System with signature validation
* Improved: Complete admin UI redesign
* Improved: 17 REST API endpoint groups

= 2.1.0 =
* Security: Fixed SQL injection vulnerability in Database API
* Security: Removed dangerous file upload capabilities
* Security: Added path traversal protection
* Added: Proper uninstall cleanup
* Added: Audit log rotation
* Added: Enhanced admin dashboard
* Improved: Tier enforcement with admin capability checks

= 2.0.0 =
* Initial release with 17 API endpoint groups
* Dual AI support with auto-failover
* Admin dashboard with AI playground

== Upgrade Notice ==

= 3.2.0 =
Enterprise hardening release adding contract/deprecation governance, upgrade safety, typed policy routing, deterministic execution ledger, compliance controls, and reliability release gates.
