# RJV AGI Bridge — WordPress Plugin

[![Version](https://img.shields.io/badge/version-3.2.0-blue.svg)](https://rjvtechnologies.com/agi-bridge)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-purple.svg)](https://php.net)
[![WordPress](https://img.shields.io/badge/WordPress-6.4%2B-blue.svg)](https://wordpress.org)
[![License](https://img.shields.io/badge/license-Proprietary-red.svg)](LICENSE)

**Enterprise AGI Control Interface for WordPress** — A system-level control surface that allows the central AGI platform to securely, deterministically, and audibly operate WordPress environments while preserving strict governance, isolation, and human oversight.

---

## Table of Contents

- [Overview](#overview)
- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Authentication](#authentication)
- [API Reference](#api-reference)
- [Enterprise Modules](#enterprise-modules)
- [Security](#security)
- [Admin Dashboard](#admin-dashboard)
- [Changelog](#changelog)
- [Contributing](#contributing)
- [License](#license)

---

## Overview

The RJV AGI Bridge is not a feature-based add-on, chatbot, or content generator. It is a **controlled execution interface** between WordPress and the central AGI platform of RJV Technologies Ltd. The plugin establishes:

- **Persistent, secure, bidirectional communication** with the AGI platform
- **Tenant identification and subscription validation** with capability gating
- **Structured, validated operations** that preserve system integrity
- **Full audit logging** with complete traceability
- **Human-in-the-loop controls** for critical actions
- **Multi-tenant isolation** for enterprise deployments

---

## Features

### Core API (17 Endpoint Groups)

| Group | Endpoints | Tier |
|-------|-----------|------|
| Posts | CRUD + Bulk operations | T1–T3 |
| Pages | CRUD with templates | T1–T3 |
| Media | Upload, sideload, delete | T1–T3 |
| Users | List, get, update | T1–T3 |
| Options | Read/write site settings | T1–T3 |
| Themes | List, activate, customiser | T1–T3 |
| Plugins | List, activate/deactivate | T1–T3 |
| Menus | List, get items, add items | T1–T2 |
| Widgets | List sidebars + widgets | T1 |
| SEO | Audit, bulk meta, missing | T1–T2 |
| Comments | List, approve, spam, delete | T1–T3 |
| Taxonomies | List, terms CRUD | T1–T2 |
| Database | Tables, read-only query, optimise | T1–T3 |
| FileSystem | Theme file read/write | T1–T3 |
| Cron | List, schedule, clear | T1–T3 |
| Site Health | Health check, stats, audit log | T1 |
| AI Content | Complete, generate post, SEO, rewrite | T1–T2 |

### Enterprise Modules (NEW in v3.0)

#### Platform Bridge
- Secure bidirectional communication with central AGI platform
- HMAC-signed request authentication
- Tenant identification and subscription validation
- Capability gating based on subscription plan
- Architecture and workflow definitions from platform

#### Content Versioning System
- Full history of all content changes
- Who initiated (AGI, human, agent, system)
- Diffable comparisons between versions
- One-click revert to any previous version
- No silent mutations of content

#### Design System Controller
- Enforce consistent design tokens across the site
- Style validation against constraints
- Accessibility enforcement (WCAG compliance)
- Heading hierarchy correction
- Image alt text enforcement
- Color contrast checking

#### Event Streaming
- Real-time WordPress event capture
- Content events (create, update, delete, status change)
- User events (login, logout, register, profile update)
- Media events (upload, delete)
- Plugin/theme events
- Configurable batch streaming to platform

#### Goal-Based Execution Engine
- Define objectives with action sequences
- Pre/during/post condition checking
- Automatic checkpoint creation
- Rollback on failure
- Conditional action execution

#### Approval Workflow System
- Critical actions require human approval
- Configurable per action type
- Preview generation before execution
- Email notifications to approvers
- Expiration and auto-cleanup

#### Agent Runtime (OpenClaw Model)
- Deploy specialized task agents
- Strict scope constraints
- Limited tool access
- Full audit trail
- No privilege escalation
- No agent-creates-agent

#### Security Monitor
- Vulnerability scanning
- File integrity monitoring
- Permission auditing
- Anomaly detection
- Malware signature scanning
- Security score calculation

#### Role-Based Access Control
- WordPress role to AGI capability mapping
- Custom role support
- Resource-level permissions (own, own_draft)
- Limited access with usage tracking
- AGI boundary enforcement

#### Multi-Tenant Isolation
- Strict data isolation between tenants
- Tenant-scoped options and transients
- Query scoping
- Context switching for admin
- Multisite support

#### External Integrations
- API integration management
- CRM connections
- Payment gateway support
- Encrypted credential storage
- Connection testing
- Execution tracking

#### Webhook System
- Incoming webhooks with signature validation
- Outgoing webhooks for events
- Custom headers support
- Secret regeneration
- Delivery tracking

#### Performance Optimizer
- Database analysis and optimization
- Caching configuration audit
- Asset optimization
- Server configuration check
- Performance scoring
- Automated recommendations

#### Enterprise Control Plane (NEW in v3.1)
- Program scope taxonomy and measurable acceptance targets
- Runtime policy engine with deny rules and approval guardrails
- Environment-specific capability overrides (dev/staging/production)
- Reliability telemetry with SLO metrics and request trace IDs
- Configuration drift detection and baseline snapshots
- Milestone tracking with definition-of-done metadata

#### Enterprise Hardening Program (NEW in v3.2)
- Architecture/route/module audit reporting wired to runtime code paths
- Contract manager with deprecation and sunset response headers
- Upgrade safety framework with compatibility checks and migration history
- Typed policy rules (allow/deny/approve/escalate) with conflict resolution
- Deterministic execution ledger with immutable hash chain and replay support
- Security/compliance controls for threat model, legal hold, and key rotation chain
- Reliability alerts, anomaly detection, error-budget tracking, and release gates

### Dual AI Integration
- **OpenAI** (GPT-4.1-mini default) and **Anthropic** (Claude Sonnet default)
- Automatic failover: if primary provider fails, falls back to secondary
- Configurable models, temperature, and token limits per request

### 3-Tier Authority System
- **Tier 1 (Autonomous)** — Read operations. API key required.
- **Tier 2 (Supervised)** — Write operations. API key required.
- **Tier 3 (Approval Required)** — Destructive/sensitive operations. Approval workflow.

---

## Requirements

| Requirement | Minimum |
|-------------|---------|
| WordPress | 6.4+ |
| PHP | 8.1+ |
| MySQL | 8.0+ |

### PHP Extensions
- `json`
- `mbstring`
- `openssl` (for credential encryption)

---

## Installation

### Manual Installation

1. Download or clone this repository
2. Upload the `rjv-agi-bridge/` folder to `/wp-content/plugins/`
3. Activate the plugin through **Plugins** in WordPress admin
4. Navigate to **AGI Bridge > Settings** to configure

### Via Git

```bash
cd /path/to/wordpress/wp-content/plugins/
git clone https://github.com/RJV-TECHNOLOGIES-LTD/AGI.git rjv-agi-bridge
```

Then activate in WordPress admin.

---

## Configuration

### Settings Page

Navigate to **AGI Bridge > Settings** in WordPress admin to configure:

#### AI Provider Settings
| Setting | Description | Default |
|---------|-------------|---------|
| OpenAI Key | Your OpenAI API key | — |
| Anthropic Key | Your Anthropic API key | — |
| Default Provider | Primary AI provider | Anthropic |
| OpenAI Model | OpenAI model to use | gpt-4.1-mini |
| Anthropic Model | Anthropic model to use | claude-sonnet-4-20250514 |

#### Security Settings
| Setting | Description | Default |
|---------|-------------|---------|
| Rate Limit | Max API requests per minute | 600 |
| IP Allowlist | Restrict API access by IP | Empty (allow all) |

#### Platform Settings (Enterprise)
| Setting | Description | Default |
|---------|-------------|---------|
| Platform URL | Central AGI platform API URL | https://platform.rjvtechnologies.com/api/v1 |
| Tenant ID | Your tenant identifier | — |
| Tenant Secret | Your tenant authentication secret | — |

#### Feature Toggles
| Setting | Description | Default |
|---------|-------------|---------|
| Event Streaming | Stream events to platform | Disabled |
| Design System | Enforce design constraints | Disabled |
| Performance Monitoring | Track performance metrics | Enabled |
| Security Scanning | Automated security scans | Enabled |

---

## Authentication

All API requests require the `X-RJV-AGI-Key` header:

```bash
curl -H "X-RJV-AGI-Key: YOUR_API_KEY" \
  https://yoursite.com/wp-json/rjv-agi/v1/health
```

---

## API Reference

Base URL: `https://yoursite.com/wp-json/rjv-agi/v1/`

### Enterprise Endpoints

#### Platform Status
```
GET /platform/status
```
Returns platform connection status and subscription details.

#### Capabilities
```
GET /platform/capabilities
```
Returns available capabilities and current usage.

#### Content Versions
```
GET /versions/{type}/{id}
```
Get version history for content.

```
POST /versions/{version_id}/revert
```
Revert content to a specific version.

```
POST /versions/compare
Body: { "version_a": 1, "version_b": 2 }
```
Compare two versions.

#### Goal Execution
```
POST /goals/execute
Body: {
  "objective": "Update homepage and publish",
  "actions": [
    { "type": "update_page", "params": { "id": 1, "title": "New Title" } },
    { "type": "update_post", "params": { "id": 1, "status": "publish" } }
  ],
  "conditions": {
    "pre": [{ "type": "post_exists", "params": { "id": 1 } }]
  },
  "rollback_on_failure": true
}
```

```
GET /goals/active
```
List active goal executions.

#### Approval Workflow
```
GET /approvals
```
List pending approvals.

```
POST /approvals/{id}/approve
```
Approve a pending action.

```
POST /approvals/{id}/reject
Body: { "reason": "Optional rejection reason" }
```

#### Agent Runtime
```
GET /agents
POST /agents
Body: {
  "name": "SEO Agent",
  "type": "seo",
  "scope": { "allowed_post_types": ["post", "page"] },
  "tools": ["read_post", "update_seo", "ai_complete"],
  "task": { "type": "optimize_seo", "params": {} }
}
```

```
GET /agents/{agent_id}
DELETE /agents/{agent_id}
POST /agents/{agent_id}/execute
```

#### Security
```
POST /security/scan
```
Run full security scan.

```
GET /security/status
```
Get security status summary.

#### Performance
```
GET /performance/analyze
```
Run performance analysis.

```
POST /performance/optimize
```
Optimize database.

#### Integrations
```
GET /integrations
POST /integrations
```

#### Webhooks
```
GET /webhooks
POST /webhooks
```

#### Design System
```
GET /design/tokens
PUT /design/tokens
POST /design/validate-css
```

#### Enterprise Control Plane
```
GET /program/scope
PUT /program/scope
GET /program/targets
PUT /program/targets
GET /program/contracts
GET /program/milestones
POST /program/milestones
GET /governance/policies
PUT /governance/policies
POST /governance/evaluate
GET /capabilities/effective
GET /capabilities/overrides
PUT /capabilities/overrides
GET /observability/slo
GET /observability/drift
POST /observability/baseline
```

---

## Enterprise Modules

### Content Versioning

Every content change is tracked:

```php
// Automatic versioning on save
$content_ops = RJV_AGI_Bridge\Content\ContentOperations::instance();
$result = $content_ops->update('post', 123, [
    'title' => 'Updated Title',
    'content' => 'New content here',
], [
    'initiated_by' => 'admin@example.com',
    'initiator_type' => 'human',
]);

// Revert to previous version
$versions = RJV_AGI_Bridge\Content\VersionManager::instance();
$versions->revert_to_version($version_id, 'admin', 'human');
```

### Goal Execution

Execute complex multi-step operations:

```php
$goals = RJV_AGI_Bridge\Execution\GoalExecutor::instance();
$result = $goals->execute([
    'id' => 'goal_123',
    'objective' => 'Publish seasonal content campaign',
    'actions' => [
        ['type' => 'create_post', 'params' => ['title' => 'Winter Sale', 'content' => '...']],
        ['type' => 'upload_media', 'params' => ['url' => 'https://...']],
        ['type' => 'apply_seo', 'params' => ['post_id' => 0, 'title' => 'SEO Title']],
    ],
    'conditions' => [
        'pre' => [['type' => 'user_can', 'params' => ['capability' => 'edit_posts']]],
        'post' => [['type' => 'post_status', 'params' => ['id' => 0, 'status' => 'draft']]],
    ],
    'rollback_on_failure' => true,
]);
```

### Agent Deployment

Deploy specialized agents:

```php
$agents = RJV_AGI_Bridge\Agent\AgentRuntime::instance();
$result = $agents->deploy([
    'name' => 'Content Optimizer',
    'type' => 'content',
    'scope' => [
        'allowed_post_types' => ['post'],
        'max_operations_per_execution' => 5,
    ],
    'tools' => ['read_post', 'update_post', 'ai_complete'],
]);

// Execute task with agent
$agents->start($result['agent_id']);
$agents->execute($result['agent_id'], [
    'type' => 'read_post',
    'params' => ['id' => 123],
]);
```

### Approval Workflows

Critical actions require approval:

```php
$approvals = RJV_AGI_Bridge\Execution\ApprovalWorkflow::instance();

// Submit for approval
$result = $approvals->submit('delete_post', [
    'id' => 123,
    'force' => true,
], 'api', 'agi');

// Approve (from admin interface)
$approvals->approve($result['approval_id'], get_current_user_id(), true);
```

---

## Security

### Rate Limiting
- Default: 600 requests per minute per API key
- Configurable in Settings
- Uses SHA-256 hashed key for transient storage

### IP Allowlist
- Configure allowed IPs in Settings
- Supports Cloudflare, proxy, and direct connections

### Audit Log
- Every API action logged with: timestamp, agent ID, action, resource, tier, status, tokens, latency, IP
- Insert-only (no update/delete via API)
- Automatic cleanup based on retention period

### File Security
- Theme file writes restricted to safe extensions
- PHP/PHTML/PHAR files blocked
- Path traversal protection
- Symlink detection

### Database Security
- Only SELECT queries allowed
- Dangerous keywords blocked
- SQL comments stripped
- Query length limits

### Credential Encryption
- Integration credentials encrypted with AES-256-CBC
- Uses WordPress auth salts as encryption key

---

## Admin Dashboard

The plugin provides admin pages:

1. **Dashboard** — System status, stats, AI status, API key
2. **Settings** — Configure all plugin options
3. **Audit Log** — Browse API activity
4. **AI Playground** — Test AI completions
5. **Approvals** — Review pending actions
6. **Security** — Security scan results
7. **Performance** — Performance analysis

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for a detailed version history.

---

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines.

---

## License

Proprietary — © 2026 RJV Technologies Ltd. All rights reserved. See [LICENSE](LICENSE).
