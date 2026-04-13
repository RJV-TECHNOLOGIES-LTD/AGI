# Contributing to RJV AGI Bridge

Thank you for your interest in contributing to RJV AGI Bridge. This document provides guidelines for contributing to the project.

## Development Setup

### Prerequisites

- WordPress 6.4+ development environment
- PHP 8.1+
- MySQL 8.0+
- Git

### Getting Started

1. Fork the repository
2. Clone your fork into your WordPress plugins directory:
   ```bash
   cd /path/to/wordpress/wp-content/plugins/
   git clone https://github.com/YOUR_USERNAME/AGI.git rjv-agi-bridge
   ```
3. Create a feature branch:
   ```bash
   git checkout -b feature/your-feature-name
   ```
4. Activate the plugin in WordPress admin

## Code Standards

### PHP
- PHP 8.1+ strict types required (`declare(strict_types=1)`)
- Follow WordPress Coding Standards where applicable
- Use the `RJV_AGI_Bridge` namespace for all classes
- All user input must be sanitised using appropriate WordPress functions
- All output must be escaped using `esc_html()`, `esc_attr()`, `wp_kses_post()`, etc.

### JavaScript
- jQuery-based (loaded via WordPress)
- Use strict mode
- Avoid `.html()` with dynamic data — use `.text()` or safe DOM construction

### CSS
- Use `.rjv-` prefix for all class names
- Mobile-first responsive approach
- Follow existing patterns in `admin/css/admin.css`

## Security Guidelines

Security is paramount. All contributions must:

- Sanitise all input (`sanitize_text_field`, `sanitize_textarea_field`, `absint`, etc.)
- Escape all output (`esc_html`, `esc_attr`, `esc_url`, etc.)
- Use `$wpdb->prepare()` for all database queries with user input
- Validate file paths with `realpath()` to prevent directory traversal
- Use nonces for all admin form submissions
- Never trust `$_GET`, `$_POST`, or `$_REQUEST` directly
- Never expose API keys or sensitive data in responses

## Architecture

### Directory Structure

```
rjv-agi-bridge/
├── rjv-agi-bridge.php   # Plugin bootstrap
├── uninstall.php         # Cleanup on deletion
├── admin/
│   ├── css/admin.css     # Admin stylesheet
│   └── js/admin.js       # Admin JavaScript
├── includes/
│   ├── Plugin.php        # Singleton, route registration, rate limiting
│   ├── Installer.php     # Activation, deactivation, migrations
│   ├── Settings.php      # Options wrapper
│   ├── Auth.php          # API key + IP validation + tier enforcement
│   ├── AuditLog.php      # Immutable audit logging + cleanup
│   ├── AI/
│   │   ├── Provider.php  # AI provider interface
│   │   ├── OpenAI.php    # OpenAI implementation
│   │   ├── Anthropic.php # Anthropic implementation
│   │   └── Router.php    # Provider selection + failover
│   ├── API/
│   │   ├── Base.php      # Abstract base for all API controllers
│   │   ├── Posts.php      # Post CRUD + bulk
│   │   ├── Pages.php      # Page CRUD
│   │   ├── Media.php      # Media upload/sideload
│   │   ├── Users.php      # User management
│   │   ├── Options.php    # Site options
│   │   ├── Themes.php     # Theme management
│   │   ├── Plugins.php    # Plugin management
│   │   ├── Menus.php      # Menu management
│   │   ├── Widgets.php    # Widget listing
│   │   ├── SEO.php        # SEO audit + bulk meta
│   │   ├── Comments.php   # Comment moderation
│   │   ├── Taxonomies.php # Taxonomy + term CRUD
│   │   ├── Database.php   # Read-only DB queries
│   │   ├── FileSystem.php # Theme file management
│   │   ├── Cron.php       # Cron job management
│   │   ├── SiteHealth.php # Health checks + stats
│   │   └── ContentGen.php # AI content generation
│   └── Admin/
│       └── Dashboard.php  # Admin pages
└── languages/
    └── rjv-agi-bridge.pot # Translation template
```

### Adding a New API Endpoint

1. Create a new class in `includes/API/` extending `Base`
2. Implement `register_routes()` with appropriate tier callbacks
3. Add the class to the controller list in `Plugin::register_routes()`
4. Add the file to the require list in `Plugin::__construct()`
5. Document the endpoint in README.md

### Adding a New AI Provider

1. Create a new class in `includes/AI/` implementing `Provider`
2. Implement `complete()`, `get_name()`, `get_model()`, `is_configured()`
3. Register the provider in `Router::__construct()`

## Pull Request Process

1. Ensure your code follows the standards above
2. Update documentation if adding/changing functionality
3. Update `CHANGELOG.md` with your changes
4. Create a pull request with a clear description
5. Reference any related issues

## Reporting Issues

- Use GitHub Issues for bug reports and feature requests
- Include WordPress version, PHP version, and plugin version
- Provide steps to reproduce for bugs
- Include relevant error logs if available

## Contact

For questions about contributing, contact: info@rjvtechnologies.com
