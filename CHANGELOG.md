# Changelog

All notable changes to the RJV AGI Bridge plugin will be documented in this file.

## [Unreleased]

### Added
- **Google Gemini AI provider** (`AI/Google.php`): Full Gemini 2.5 Pro / 2.0 Flash support via the Generative Language API. Implements the `Provider` interface with system-instruction mapping, generation config (max tokens, temperature, JSON mode), per-request token tracking via `usageMetadata`, permanent vs. transient error classification, and integrated audit logging. The `Router` now activates the Gemini provider when a `google_key` is configured, completing the three-provider chain (OpenAI → Anthropic → Google) with automatic fallback.
- **Expanded `Settings::SCHEMA`** with 70+ previously unschema'd settings covering: API security (`replay_protection`, `named_keys`), AI response caching (`ai_response_cache_ttl`), reliability alert thresholds (`alert_*`), threat-detector tuning (`threat_block_score`, `threat_ban_score`, `threat_ban_ttl`, `threat_detector_mode`), feature flags (`event_streaming`, `design_system_enabled`, `multi_tenant_enabled`, `performance_monitoring`, `security_scan_enabled`), platform connection (`platform_url`, `tenant_id`, `tenant_secret`), Cloudflare/Tunnel (`cloudflare_*`, `tunnel_*`), Google OAuth/analytics (`google_client_*`, `ga4_*`, `gtm_*`, `google_ads_*`), Microsoft/Azure (`microsoft_*`, `clarity_*`, `bing_*`, `appinsights_*`), provisioning (`provision_*`), and all governance array types. All these settings now benefit from typed reads/writes and secret-redaction in `Settings::all()`.
- **Explicit file-load manifest expanded**: `AI/Google`, `AI/Orchestrator`, `Security/SecretsVault`, `Automation/ProvisioningOrchestrator`, `Hosting/TunnelManager`, `Hosting/TunnelHealthMonitor`, `Integrations/CloudflareAPI`, `Integrations/GoogleServices`, `Integrations/MicrosoftServices` added to Plugin bootstrap load list.

### Fixed
- **`Installer::deactivate()` now clears all plugin cron events**: `rjv_agi_webhook_retry`, `rjv_agi_alert_check`, and `rjv_agi_tunnel_heartbeat` were not cleared on plugin deactivation, leaving orphaned scheduled hooks in WordPress.
- **Removed duplicate rate-limiter from `Plugin::rate_limit()`**: The `rest_pre_dispatch` rate-limiter was a weaker, parallel implementation that counted the same request against two separate buckets with different hashing schemes — effectively halving the actual permitted rate. The definitive per-key sliding-window rate limiter in `Auth::check_rate_limit()` is the sole enforcement point.


  - Posts/pages revision list + restore endpoints
  - Media metadata update and attachment metadata regeneration endpoint
  - Users lifecycle endpoints (create/delete) and role visibility endpoint
  - Plugins install/update/delete operations
  - Menus full CRUD for menus and menu items
  - Taxonomy term update/delete operations
  - Comments bulk moderation endpoint and generic status update endpoint
  - Privacy tools request lifecycle endpoints (export/erase request create/list/delete)
  - Multisite awareness endpoints for current site context and network site listing
  - Scheduled content lifecycle controls for posts/pages (queue/list, reschedule, cancel, publish-now)
  - Users expansion: password set/reset, extended profile updates, capability diff, safe role transition enforcement
  - Tools import/export execution endpoints with persistent job status tracking
  - Widgets full lifecycle operations (create/read/update/delete) and sidebar placement/move controls
  - Themes install/update/delete endpoints with guarded active-theme rollback behavior

### Improved
- Options API now covers a broader set of default WordPress settings (reading, writing, discussion, media, permalink, general/privacy-adjacent keys) with typed sanitization.
- Theme API now exposes template parts, registered block patterns, and global styles visibility for modern WordPress default appearance features.
- Request governance lineage now emits unified audit fields for request_id, trace_id, policy decision/reason, approval linkage, and actor context in pre-dispatch policy enforcement.
- Pre-dispatch governance now enforces mandatory approval binding for critical classes (user role transition, plugin/theme mutation, destructive/force actions) even when policy rules do not explicitly require approval.

## [3.2.0] - 2026-04-14

### Added
- Foundation hardening controls:
  - Architecture/route/module audit report (`/program/audit`)
  - Contract/deprecation manager (`/governance/contracts`, `/governance/deprecations`) with runtime `Deprecation`/`Sunset` headers
  - Upgrade safety framework (`/governance/upgrade/status`) with compatibility checks, migration history, and rollback guard state
- Typed governance policy rules (`allow`, `deny`, `approve`, `escalate`) with deterministic conflict resolution and policy evaluation audit trails
- Deterministic execution ledger with immutable hash chaining and replay endpoints:
  - `/execution/ledger`
  - `/execution/ledger/{execution_id}`
- Security/compliance baseline API:
  - threat controls
  - compliance controls
  - legal hold workflow
  - secret rotation chain integrity
  - compliance snapshot export
- Reliability industrialization APIs:
  - anomaly detection
  - error-budget monitoring
  - active alert feed
  - remediation playbooks
  - release gate telemetry

### Improved
- Capability governance now supports plan-level overrides and explicit context/provenance in effective capability resolution
- Goal and agent execution paths now emit deterministic ledger records for replayable enterprise traceability
- Policy approval path now supports escalation-specific approval routing

## [3.1.0] - 2026-04-14

### Added
- Enterprise control-plane API (`/program/*`, `/governance/*`, `/capabilities/*`, `/observability/*`) for product scope governance, policy controls, capability overrides, and reliability telemetry
- `ProgramRegistry` module for feature taxonomy, measurable targets, milestone/DoD tracking, and API contract/deprecation metadata
- `PolicyEngine` module for runtime route governance with deny rules, capability checks, and approval guardrails
- `ReliabilityMonitor` module for request trace IDs, SLO metrics (availability/error-rate/p95 latency), drift reporting, and baseline snapshotting
- `policy_guardrail_request` approval workflow action type for safe human-in-the-loop execution deferrals

### Improved
- `CapabilityGate` now supports environment-aware effective capability resolution and per-environment override management
- REST responses now include enterprise observability headers (`X-RJV-Trace-ID`, `X-RJV-API-Version`, `X-RJV-Governance`)
- Installer defaults now seed enterprise program, policy, and capability-override control-plane options

## [2.1.0] - 2026-04-13

### Security
- Fixed SQL injection vulnerability in Database API query endpoint
- Removed dangerous `allow_php` flag from FileSystem API
- Added path traversal protection to file write operations
- Added file size limits and blocked executable extensions in file uploads
- Sanitised IP addresses in audit logging
- Fixed XSS potential in admin dashboard JavaScript

### Added
- `uninstall.php` for proper plugin cleanup on deletion
- Comprehensive admin CSS stylesheet replacing inline styles
- Version migration system in Installer for future upgrades
- Audit log rotation and cleanup (90-day retention)
- SEO audit pagination support
- Enhanced rate limiting with SHA-256 key hashing
- i18n text domain support throughout plugin
- `.gitignore`, `composer.json`, `.editorconfig` configuration files
- WordPress.org standard `readme.txt`
- `CONTRIBUTING.md` guide
- `CHANGELOG.md` (this file)
- `LICENSE` file

### Improved
- Differentiated tier enforcement (Tier 3 now validates admin capability)
- Enhanced admin dashboard UI with proper CSS classes
- Better error handling and user feedback in admin JavaScript
- Input validation across all API endpoints
- Plugin singleton no longer creates duplicate Dashboard instances

### Fixed
- Settings page now properly masks API key in `all()` method
- Rate limiting uses cryptographically secure key hashing
- AuditLog query handles empty results gracefully

## [2.0.0] - 2026-03-01

### Added
- Initial release with 17 API endpoint groups
- Dual AI support (OpenAI + Anthropic) with auto-failover
- 3-tier authority system
- Immutable audit logging
- Admin dashboard with AI playground
- Rate limiting and IP allowlist
