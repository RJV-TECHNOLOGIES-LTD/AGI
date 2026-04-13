=== RJV AGI Bridge ===
Contributors: rjvtechnologies
Tags: ai, api, automation, openai, anthropic, seo, content-generation
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 2.1.0
License: Proprietary
License URI: https://rjvtechnologies.com/license

Enterprise AGI control interface for WordPress. Full site control via REST API with dual AI support (OpenAI + Anthropic).

== Description ==

RJV AGI Bridge provides a comprehensive REST API for WordPress site management with integrated AI capabilities. Designed for enterprise use, it offers full CRUD operations across 17 endpoint groups with a 3-tier security model.

= Key Features =

* **Dual AI Integration** - OpenAI (GPT) and Anthropic (Claude) with automatic failover
* **17 API Endpoint Groups** - Posts, Pages, Media, Users, Options, Themes, Plugins, Menus, Widgets, SEO, Comments, Taxonomies, Database, FileSystem, Cron, SiteHealth, AI ContentGen
* **3-Tier Authority System** - Autonomous (T1), Supervised (T2), Approval Required (T3)
* **SEO Management** - Audit scores, bulk meta updates, AI-generated SEO (Yoast + RankMath compatible)
* **AI Content Generation** - Blog posts, content rewriting, SEO metadata
* **Immutable Audit Log** - Every action logged with agent, tier, tokens, latency
* **Enterprise Security** - Rate limiting, IP allowlist, timing-safe key comparison
* **Admin Dashboard** - Status overview, API reference, AI playground

= Requirements =

* WordPress 6.4 or higher
* PHP 8.1 or higher
* MySQL 8.0 or higher

== Installation ==

1. Upload the `rjv-agi-bridge` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to AGI Bridge > Settings to configure API keys
4. Use your API key in the `X-RJV-AGI-Key` header for API requests

== Frequently Asked Questions ==

= How do I authenticate API requests? =

Include your API key in the `X-RJV-AGI-Key` HTTP header with every request.

= Can I use both OpenAI and Anthropic? =

Yes. Configure both API keys in Settings. The plugin automatically falls back to the secondary provider if the primary is unavailable.

= What is the tier system? =

* **Tier 1** - Read operations (GET requests)
* **Tier 2** - Write operations (POST/PUT requests)
* **Tier 3** - Destructive or sensitive operations (DELETE, admin-level changes)

= Is the audit log tamper-proof? =

The audit log is insert-only with no update or delete API. It records timestamps, actions, IP addresses, and AI token usage.

== Changelog ==

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
