# RJV AGI Bridge — WordPress Plugin

[![Version](https://img.shields.io/badge/version-2.1.0-blue.svg)](https://rjvtechnologies.com/agi-bridge)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-purple.svg)](https://php.net)
[![WordPress](https://img.shields.io/badge/WordPress-6.4%2B-blue.svg)](https://wordpress.org)
[![License](https://img.shields.io/badge/license-Proprietary-red.svg)](LICENSE)

Enterprise AGI control interface for WordPress. Full site control via REST API with dual AI support (OpenAI GPT + Anthropic Claude) and automatic failover.

---

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Authentication](#authentication)
- [API Reference](#api-reference)
- [Security](#security)
- [Admin Dashboard](#admin-dashboard)
- [Changelog](#changelog)
- [Contributing](#contributing)
- [License](#license)

---

## Features

### 17 API Endpoint Groups

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

### Dual AI Integration
- **OpenAI** (GPT-4.1-mini default) and **Anthropic** (Claude Sonnet default)
- Automatic failover: if primary provider is unavailable, falls back to secondary
- Configurable models, temperature, and token limits per request

### 3-Tier Authority System
- **Tier 1 (Autonomous)** — Read operations. API key required.
- **Tier 2 (Supervised)** — Write operations. API key required.
- **Tier 3 (Approval Required)** — Destructive/sensitive operations. API key required + action logged with elevated audit trail.

### Enterprise Security
- Timing-safe API key comparison (`hash_equals`)
- IP allowlist with Cloudflare, proxy, and direct IP support
- Rate limiting (configurable, default 600/min) with SHA-256 key hashing
- Immutable audit log with automatic rotation
- SQL injection protection (SELECT-only, keyword blocklist, comment stripping)
- File write protection (extension allowlist, path traversal prevention, symlink detection)
- CSRF protection on admin forms
- XSS-safe admin JavaScript

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

| Setting | Description | Default |
|---------|-------------|---------|
| OpenAI Key | Your OpenAI API key | — |
| Anthropic Key | Your Anthropic API key | — |
| Default Provider | Primary AI provider | Anthropic |
| OpenAI Model | OpenAI model to use | gpt-4.1-mini |
| Anthropic Model | Anthropic model to use | claude-sonnet-4-20250514 |
| Rate Limit | Max API requests per minute | 600 |
| Audit Logging | Enable/disable audit log | Enabled |
| Log Retention | Days to keep audit entries | 90 |
| IP Allowlist | Restrict API access by IP | Empty (allow all) |

---

## Authentication

All API requests require the `X-RJV-AGI-Key` header:

```bash
curl -H "X-RJV-AGI-Key: YOUR_API_KEY" \
  https://yoursite.com/wp-json/rjv-agi/v1/health
```

Your API key is generated automatically on activation and displayed on the dashboard. You can regenerate it in Settings.

---

## API Reference

Base URL: `https://yoursite.com/wp-json/rjv-agi/v1/`

### Health & Status

#### `GET /health`
Returns system health information.

```json
{
  "success": true,
  "data": {
    "status": "healthy",
    "version": "2.1.0",
    "wordpress": "6.7",
    "php": "8.2.0",
    "mysql": "8.0.36",
    "ai": {
      "openai": { "configured": true, "model": "gpt-4.1-mini" },
      "anthropic": { "configured": true, "model": "claude-sonnet-4-20250514" }
    },
    "posts": 42,
    "pages": 5,
    "users": 3,
    "comments": 120
  }
}
```

#### `GET /health/stats`
Returns today's activity statistics.

#### `GET /audit-log`
Query audit log entries with filters: `action`, `agent_id`, `tier`, `since`, `page`, `per_page`.

### Posts

#### `GET /posts`
List posts. Parameters: `per_page` (max 100), `page`, `status`, `search`.

#### `POST /posts`
Create a post. Body:
```json
{
  "title": "My Post",
  "content": "<p>HTML content</p>",
  "status": "draft",
  "excerpt": "Short description",
  "categories": [1, 2],
  "tags": ["tag1", "tag2"],
  "featured_image_id": 123,
  "meta": { "custom_field": "value" },
  "seo": { "title": "SEO Title", "description": "Meta description", "focus_kw": "keyword" }
}
```

#### `GET /posts/{id}`
Get a single post with full content, meta, and SEO data.

#### `PUT /posts/{id}`
Update a post. Same body as create (all fields optional).

#### `DELETE /posts/{id}`
Delete a post. Body: `{ "force": true }` for permanent deletion.

#### `POST /posts/bulk`
Bulk operations. Body:
```json
{ "action": "publish|draft|trash|delete", "ids": [1, 2, 3] }
```

### Pages

#### `GET /pages` | `POST /pages` | `GET /pages/{id}` | `PUT /pages/{id}` | `DELETE /pages/{id}`
Same as Posts but for pages. Additional fields: `parent`, `template`.

### Media

#### `GET /media`
List media attachments.

#### `POST /media`
Upload a file (multipart form, field name: `file`).

#### `POST /media/sideload`
Download and attach from URL: `{ "url": "https://...", "filename": "photo.jpg", "alt": "Alt text" }`

#### `DELETE /media/{id}`
Delete a media attachment.

### Users

#### `GET /users` | `GET /users/{id}` | `PUT /users/{id}`
User management. Update limited to `name` and `email`.

### Options

#### `GET /options`
Get all whitelisted options.

#### `PUT /options`
Update writable options. Writable: `blogname`, `blogdescription`, `timezone_string`, `date_format`, `time_format`, `posts_per_page`, `page_on_front`, `page_for_posts`, `show_on_front`, `blog_public`.

### Themes

#### `GET /themes` | `POST /themes/activate` | `GET /themes/customizer` | `PUT /themes/customizer`

### Plugins

#### `GET /plugins` | `POST /plugins/toggle`
Body: `{ "plugin": "plugin-dir/plugin-file.php", "action": "activate|deactivate" }`

### Menus

#### `GET /menus` | `GET /menus/{id}` | `POST /menus/{id}/items`

### Widgets

#### `GET /widgets`
List sidebars with registered widgets.

### SEO

#### `GET /seo/audit`
SEO audit summary: total published, missing titles/descriptions, score.

#### `GET /seo/missing`
Paginated list of posts missing SEO data. Parameters: `page`, `per_page`, `type` (all|title|description).

#### `POST /seo/bulk-meta`
Bulk update SEO metadata (Yoast + RankMath compatible):
```json
{ "items": [{ "id": 1, "title": "SEO Title", "description": "Meta desc" }] }
```

### Comments

#### `GET /comments` | `POST /comments/{id}/approve` | `POST /comments/{id}/spam` | `DELETE /comments/{id}`

### Taxonomies

#### `GET /taxonomies` | `GET /taxonomies/{tax}/terms` | `POST /taxonomies/{tax}/terms`

### Database

#### `GET /database/tables`
List all tables with row counts and sizes.

#### `POST /database/query` ⚠️ Tier 3
Execute read-only SQL. Body: `{ "sql": "SELECT * FROM wp_posts LIMIT 10" }`
- Only `SELECT` statements allowed
- Blocked keywords: DROP, DELETE, UPDATE, INSERT, ALTER, TRUNCATE, GRANT, CREATE, UNION, EXEC
- Max query length: 5000 characters
- SQL comments stripped automatically

#### `POST /database/optimize`
Optimise all tables and clean expired transients.

### FileSystem

#### `GET /files/theme`
List all files in the active theme.

#### `POST /files/theme/read`
Read a theme file: `{ "file": "style.css" }`

#### `POST /files/theme/write` ⚠️ Tier 3
Write a theme file. Allowed extensions: css, js, html, json, svg, txt, md.
```json
{ "file": "custom.css", "content": "body { color: red; }" }
```

### Cron

#### `GET /cron` | `POST /cron/schedule` | `POST /cron/clear`

### AI Content Generation

#### `POST /ai/complete`
Send a message to AI:
```json
{
  "message": "Your prompt here",
  "system_prompt": "Optional system instructions",
  "provider": "anthropic|openai",
  "temperature": 0.3,
  "max_tokens": 4096
}
```

#### `POST /ai/generate-post`
Generate a blog post:
```json
{
  "topic": "AI in Healthcare",
  "tone": "professional",
  "length": "1500 words",
  "auto_create": true,
  "title": "Optional custom title",
  "provider": "anthropic"
}
```

#### `POST /ai/generate-seo`
Generate SEO metadata for a post:
```json
{ "post_id": 123, "auto_apply": true }
```

#### `POST /ai/rewrite`
Rewrite content:
```json
{ "content": "Text to rewrite", "style": "casual|professional|academic" }
```

#### `GET /ai/status`
Get AI provider configuration status.

---

## Security

### Rate Limiting
- Default: 600 requests per minute per API key
- Configurable in Settings
- Uses SHA-256 hashed key for transient storage

### IP Allowlist
- Configure allowed IPs in Settings (one per line)
- Supports Cloudflare (`CF-Connecting-IP`), proxy (`X-Forwarded-For`, `X-Real-IP`), and direct connections
- Leave empty to allow all IPs

### Audit Log
- Every API action is logged with: timestamp, agent ID, action, resource, tier, status, tokens, latency, IP
- Insert-only (no update/delete via API)
- Automatic cleanup of entries older than retention period (default 90 days)
- Queryable via API and viewable in admin dashboard

### File Security
- Theme file writes restricted to safe extensions (css, js, html, json, svg, txt, md)
- PHP/PHTML/PHAR files blocked
- Path traversal protection with `realpath()` validation
- Symlink detection and blocking
- Maximum file size: 1MB

### Database Security
- Only SELECT queries allowed
- Dangerous keywords blocked (DROP, DELETE, UPDATE, INSERT, ALTER, TRUNCATE, GRANT, CREATE, UNION, EXEC)
- SQL comments automatically stripped
- Maximum query length enforced (5000 chars)

---

## Admin Dashboard

The plugin provides four admin pages:

1. **Dashboard** — System status, today's stats, AI provider status, API key display
2. **Settings** — Configure API keys, models, rate limits, IP allowlist, log retention
3. **Audit Log** — Browse recent API activity with tier badges and status indicators
4. **AI Playground** — Test AI completions directly from WordPress admin

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for a detailed version history.

---

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines.

---

## License

Proprietary — © 2026 RJV Technologies Ltd. All rights reserved. See [LICENSE](LICENSE).
