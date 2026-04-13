# Changelog

All notable changes to the RJV AGI Bridge plugin will be documented in this file.

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
