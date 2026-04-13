# RJV AGI Bridge — WordPress Plugin

Enterprise AGI control interface for WordPress. Full site control via REST API + dual AI (OpenAI + Anthropic).

## 17 API Endpoint Groups

Posts, Pages, Media, Users, Options, Themes, Plugins, Menus, Widgets, SEO, Comments, Taxonomies, Database, FileSystem, Cron, SiteHealth, AI ContentGen

## Features

- **Dual AI**: OpenAI (GPT) + Anthropic (Claude) with auto-failover
- **Full CRUD**: Create, read, update, delete for all WordPress content types
- **SEO**: Audit scores, bulk meta update, AI-generated SEO (Yoast + RankMath compatible)
- **AI Content**: Blog post generation, content rewriting, SEO metadata generation
- **Database**: Table listing, read-only queries, optimisation
- **File Management**: Theme file read/write with security constraints
- **3-Tier Authority**: Autonomous (T1), Supervised (T2), Approval Required (T3)
- **Immutable Audit Log**: Every action logged with agent, tier, tokens, latency
- **Rate Limiting + IP Allowlist**: Enterprise security
- **Admin Dashboard**: Status, API reference, AI playground

## Install

1. Upload `rjv-agi-bridge/` to `/wp-content/plugins/`
2. Activate in WordPress admin
3. Configure at AGI Bridge > Settings
4. Use API key in `X-RJV-AGI-Key` header

## Requirements

WordPress 6.4+ | PHP 8.1+ | MySQL 8.0+
