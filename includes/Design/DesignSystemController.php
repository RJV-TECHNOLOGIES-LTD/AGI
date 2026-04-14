<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\Design;

use RJV_AGI_Bridge\AuditLog;
use RJV_AGI_Bridge\Settings;
use RJV_AGI_Bridge\Bridge\PlatformConnector;

/**
 * Design System Controller
 *
 * Enforces a consistent design system across the entire WordPress site.
 * Controls styles, layout behaviour, responsive rules, and accessibility constraints.
 * The AGI operates within defined design system boundaries.
 */
final class DesignSystemController {
    private static ?self $instance = null;
    private array $design_tokens = [];
    private array $constraints = [];
    private bool $initialized = false;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_design_system'], 5);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_design_system'], 5);
        add_filter('the_content', [$this, 'enforce_content_constraints'], 20);
        add_filter('wp_kses_allowed_html', [$this, 'filter_allowed_html'], 10, 2);
    }

    /**
     * Initialize design system from configuration
     */
    public function initialize(): void {
        if ($this->initialized) {
            return;
        }

        $this->design_tokens = $this->load_design_tokens();
        $this->constraints = $this->load_constraints();
        $this->initialized = true;
    }

    /**
     * Load design tokens from platform or local config
     */
    private function load_design_tokens(): array {
        $cached = get_transient('rjv_agi_design_tokens');
        if ($cached !== false) {
            return $cached;
        }

        // Try to get from platform
        $connector = PlatformConnector::instance();
        if ($connector->is_configured()) {
            $architecture = $connector->get_architecture();
            if (!empty($architecture['design_tokens'])) {
                set_transient('rjv_agi_design_tokens', $architecture['design_tokens'], HOUR_IN_SECONDS);
                return $architecture['design_tokens'];
            }
        }

        // Use default tokens
        $tokens = $this->get_default_tokens();
        set_transient('rjv_agi_design_tokens', $tokens, HOUR_IN_SECONDS);
        return $tokens;
    }

    /**
     * Load design constraints
     */
    private function load_constraints(): array {
        return [
            'max_content_width' => 1200,
            'min_font_size' => 14,
            'max_font_size' => 72,
            'min_line_height' => 1.4,
            'max_heading_levels' => 6,
            'color_contrast_ratio' => 4.5,
            'allowed_font_families' => [
                'system-ui',
                '-apple-system',
                'BlinkMacSystemFont',
                'Segoe UI',
                'Roboto',
                'Helvetica Neue',
                'Arial',
                'sans-serif',
            ],
            'blocked_css_properties' => [
                'position: fixed',
                'z-index: 999999',
            ],
            'allowed_layout_types' => [
                'container', 'grid', 'flex', 'stack',
            ],
        ];
    }

    /**
     * Get default design tokens
     */
    private function get_default_tokens(): array {
        return [
            'colors' => [
                'primary' => '#2271b1',
                'secondary' => '#1d2327',
                'accent' => '#00a32a',
                'background' => '#ffffff',
                'surface' => '#f0f0f1',
                'text' => '#1d2327',
                'text-muted' => '#646970',
                'border' => '#c3c4c7',
                'error' => '#d63638',
                'warning' => '#dba617',
                'success' => '#00a32a',
            ],
            'typography' => [
                'font-family-base' => 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif',
                'font-family-heading' => 'inherit',
                'font-family-mono' => 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace',
                'font-size-base' => '16px',
                'font-size-sm' => '14px',
                'font-size-lg' => '18px',
                'font-size-xl' => '24px',
                'font-size-2xl' => '32px',
                'font-size-3xl' => '48px',
                'line-height-base' => '1.6',
                'line-height-heading' => '1.3',
                'font-weight-normal' => '400',
                'font-weight-medium' => '500',
                'font-weight-bold' => '700',
            ],
            'spacing' => [
                'xs' => '4px',
                'sm' => '8px',
                'md' => '16px',
                'lg' => '24px',
                'xl' => '32px',
                '2xl' => '48px',
                '3xl' => '64px',
            ],
            'breakpoints' => [
                'sm' => '640px',
                'md' => '768px',
                'lg' => '1024px',
                'xl' => '1280px',
                '2xl' => '1536px',
            ],
            'borders' => [
                'radius-sm' => '4px',
                'radius-md' => '8px',
                'radius-lg' => '12px',
                'radius-full' => '9999px',
                'width' => '1px',
            ],
            'shadows' => [
                'sm' => '0 1px 2px 0 rgb(0 0 0 / 0.05)',
                'md' => '0 4px 6px -1px rgb(0 0 0 / 0.1)',
                'lg' => '0 10px 15px -3px rgb(0 0 0 / 0.1)',
            ],
        ];
    }

    /**
     * Get current design tokens
     */
    public function get_tokens(): array {
        $this->initialize();
        return $this->design_tokens;
    }

    /**
     * Get design constraints
     */
    public function get_constraints(): array {
        $this->initialize();
        return $this->constraints;
    }

    /**
     * Update design tokens (with validation)
     */
    public function update_tokens(array $tokens, string $initiated_by = 'system'): array {
        $validation = $this->validate_tokens($tokens);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'errors' => $validation['errors'],
            ];
        }

        $this->design_tokens = array_merge($this->design_tokens, $tokens);
        delete_transient('rjv_agi_design_tokens');
        set_transient('rjv_agi_design_tokens', $this->design_tokens, HOUR_IN_SECONDS);

        // Save to options for persistence
        update_option('rjv_agi_design_tokens', $this->design_tokens);

        AuditLog::log('design_tokens_updated', 'design', 0, [
            'initiated_by' => $initiated_by,
            'updated_keys' => array_keys($tokens),
        ], 2);

        return [
            'success' => true,
            'tokens' => $this->design_tokens,
        ];
    }

    /**
     * Validate design tokens against constraints
     */
    public function validate_tokens(array $tokens): array {
        $errors = [];

        // Validate colors
        if (isset($tokens['colors'])) {
            foreach ($tokens['colors'] as $name => $value) {
                if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $value) && !preg_match('/^rgb\(/', $value)) {
                    $errors[] = "Invalid color format for '{$name}'";
                }
            }
        }

        // Validate typography
        if (isset($tokens['typography'])) {
            if (isset($tokens['typography']['font-size-base'])) {
                $size = (int) $tokens['typography']['font-size-base'];
                if ($size < $this->constraints['min_font_size'] || $size > $this->constraints['max_font_size']) {
                    $errors[] = "Base font size must be between {$this->constraints['min_font_size']}px and {$this->constraints['max_font_size']}px";
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Validate CSS against design constraints
     */
    public function validate_css(string $css): array {
        $errors = [];
        $warnings = [];

        // Check for blocked properties
        foreach ($this->constraints['blocked_css_properties'] as $blocked) {
            if (stripos($css, $blocked) !== false) {
                $errors[] = "Blocked CSS property: {$blocked}";
            }
        }

        // Check for !important abuse
        $important_count = substr_count(strtolower($css), '!important');
        if ($important_count > 5) {
            $warnings[] = "Excessive use of !important ({$important_count} occurrences)";
        }

        // Check for inline styles with very high z-index
        if (preg_match('/z-index\s*:\s*(\d+)/i', $css, $matches)) {
            if ((int) $matches[1] > 1000) {
                $warnings[] = "Very high z-index detected ({$matches[1]})";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Generate CSS variables from tokens
     */
    public function generate_css_variables(): string {
        $this->initialize();
        $css = ":root {\n";

        foreach ($this->design_tokens as $category => $values) {
            if (!is_array($values)) {
                continue;
            }
            foreach ($values as $name => $value) {
                $var_name = "--rjv-{$category}-{$name}";
                $css .= "  {$var_name}: {$value};\n";
            }
        }

        $css .= "}\n";
        return $css;
    }

    /**
     * Enqueue design system styles
     */
    public function enqueue_design_system(): void {
        if (!Settings::get_bool('design_system_enabled')) {
            return;
        }

        $css = $this->generate_css_variables();
        wp_add_inline_style('wp-block-library', $css);
    }

    /**
     * Enqueue admin design system styles
     */
    public function enqueue_admin_design_system(): void {
        if (!Settings::get_bool('design_system_enabled')) {
            return;
        }

        $css = $this->generate_css_variables();
        wp_add_inline_style('wp-admin', $css);
    }

    /**
     * Enforce content constraints on output
     */
    public function enforce_content_constraints(string $content): string {
        // Ensure proper heading hierarchy
        $content = $this->enforce_heading_hierarchy($content);

        // Ensure images have alt text
        $content = $this->enforce_image_alt($content);

        // Ensure links are accessible
        $content = $this->enforce_link_accessibility($content);

        return $content;
    }

    /**
     * Enforce heading hierarchy
     */
    private function enforce_heading_hierarchy(string $content): string {
        // Track heading levels and ensure proper nesting
        $last_level = 0;
        return preg_replace_callback('/<h([1-6])([^>]*)>/i', function ($matches) use (&$last_level) {
            $level = (int) $matches[1];
            $attrs = $matches[2];

            // Don't allow skipping more than one level
            if ($last_level > 0 && $level > $last_level + 1) {
                $level = $last_level + 1;
            }

            $last_level = $level;
            return "<h{$level}{$attrs}>";
        }, $content);
    }

    /**
     * Ensure all images have alt attributes
     */
    private function enforce_image_alt(string $content): string {
        return preg_replace_callback('/<img([^>]*)>/i', function ($match) {
            $attrs = $match[1];
            if (!preg_match('/\balt\s*=/i', $attrs)) {
                $attrs .= ' alt=""';
            }
            return "<img{$attrs}>";
        }, $content);
    }

    /**
     * Ensure links meet accessibility standards
     */
    private function enforce_link_accessibility(string $content): string {
        // Add aria-label to links that open in new tab
        return preg_replace_callback('/<a([^>]*target\s*=\s*["\']_blank["\'][^>]*)>/i', function ($match) {
            $attrs = $match[1];
            if (!preg_match('/\brel\s*=/i', $attrs)) {
                $attrs .= ' rel="noopener noreferrer"';
            }
            if (!preg_match('/\baria-label\s*=/i', $attrs)) {
                $attrs .= ' aria-label="Opens in a new tab"';
            }
            return "<a{$attrs}>";
        }, $content);
    }

    /**
     * Filter allowed HTML based on design constraints
     */
    public function filter_allowed_html(array $allowed, string $context): array {
        if ($context !== 'post') {
            return $allowed;
        }

        // Ensure style attribute is allowed but sanitized
        if (isset($allowed['div'])) {
            $allowed['div']['style'] = true;
            $allowed['div']['class'] = true;
        }

        return $allowed;
    }

    /**
     * Check color contrast ratio
     */
    public function check_contrast(string $foreground, string $background): array {
        $fg_lum = $this->get_luminance($foreground);
        $bg_lum = $this->get_luminance($background);

        $lighter = max($fg_lum, $bg_lum);
        $darker = min($fg_lum, $bg_lum);

        $ratio = ($lighter + 0.05) / ($darker + 0.05);

        return [
            'ratio' => round($ratio, 2),
            'passes_aa' => $ratio >= 4.5,
            'passes_aaa' => $ratio >= 7,
        ];
    }

    /**
     * Calculate relative luminance of a color
     */
    private function get_luminance(string $hex): float {
        $hex = ltrim($hex, '#');
        $r = hexdec(substr($hex, 0, 2)) / 255;
        $g = hexdec(substr($hex, 2, 2)) / 255;
        $b = hexdec(substr($hex, 4, 2)) / 255;

        $r = $r <= 0.03928 ? $r / 12.92 : pow(($r + 0.055) / 1.055, 2.4);
        $g = $g <= 0.03928 ? $g / 12.92 : pow(($g + 0.055) / 1.055, 2.4);
        $b = $b <= 0.03928 ? $b / 12.92 : pow(($b + 0.055) / 1.055, 2.4);

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    /**
     * Validate layout structure
     */
    public function validate_layout(array $layout): array {
        $errors = [];

        if (isset($layout['type']) && !in_array($layout['type'], $this->constraints['allowed_layout_types'], true)) {
            $errors[] = "Invalid layout type: {$layout['type']}";
        }

        if (isset($layout['maxWidth'])) {
            $width = (int) $layout['maxWidth'];
            if ($width > $this->constraints['max_content_width']) {
                $errors[] = "Max width exceeds limit ({$width}px > {$this->constraints['max_content_width']}px)";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }
}
