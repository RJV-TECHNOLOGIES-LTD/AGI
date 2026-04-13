<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\Content;

use RJV_AGI_Bridge\AuditLog;

/**
 * Structured Content Operations
 *
 * All content changes must go through this layer to ensure:
 * - Validation of data structure
 * - Design system compliance
 * - Accessibility standards
 * - Performance constraints
 * - Automatic versioning
 */
final class ContentOperations {
    private static ?self $instance = null;
    private VersionManager $versions;
    private array $validators = [];

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        $this->versions = VersionManager::instance();
        $this->register_default_validators();
    }

    /**
     * Create content with full validation and versioning
     */
    public function create(string $type, array $data, array $context = []): array {
        // Validate structure
        $validation = $this->validate($type, $data);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'error' => 'Validation failed',
                'validation_errors' => $validation['errors'],
            ];
        }

        // Apply constraints
        $data = $this->apply_constraints($type, $data);

        // Create content
        $result = $this->execute_create($type, $data);
        if (!$result['success']) {
            return $result;
        }

        // Save initial version
        $this->versions->save_version(
            $type,
            $result['id'],
            $this->build_snapshot($type, $result['id']),
            $context['initiated_by'] ?? 'system',
            $context['initiator_type'] ?? 'system',
            $context['agent_id'] ?? null,
            'Initial creation'
        );

        return $result;
    }

    /**
     * Update content with full validation and versioning
     */
    public function update(string $type, int $id, array $data, array $context = []): array {
        // Get current state for comparison
        $current = $this->build_snapshot($type, $id);
        if (!$current) {
            return ['success' => false, 'error' => 'Content not found'];
        }

        // Merge with existing data for validation
        $merged = array_merge($current, $data);

        // Validate structure
        $validation = $this->validate($type, $merged, $id);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'error' => 'Validation failed',
                'validation_errors' => $validation['errors'],
            ];
        }

        // Apply constraints
        $data = $this->apply_constraints($type, $data);

        // Execute update
        $result = $this->execute_update($type, $id, $data);
        if (!$result['success']) {
            return $result;
        }

        // Save version
        $this->versions->save_version(
            $type,
            $id,
            $this->build_snapshot($type, $id),
            $context['initiated_by'] ?? 'system',
            $context['initiator_type'] ?? 'system',
            $context['agent_id'] ?? null,
            $this->generate_change_summary($current, $data)
        );

        return $result;
    }

    /**
     * Delete content with versioning
     */
    public function delete(string $type, int $id, bool $force = false, array $context = []): array {
        // Save final version before deletion
        $snapshot = $this->build_snapshot($type, $id);
        if ($snapshot) {
            $this->versions->save_version(
                $type,
                $id,
                $snapshot,
                $context['initiated_by'] ?? 'system',
                $context['initiator_type'] ?? 'system',
                $context['agent_id'] ?? null,
                $force ? 'Permanent deletion' : 'Moved to trash'
            );
        }

        return $this->execute_delete($type, $id, $force);
    }

    /**
     * Register a content validator
     */
    public function register_validator(string $type, callable $validator): void {
        if (!isset($this->validators[$type])) {
            $this->validators[$type] = [];
        }
        $this->validators[$type][] = $validator;
    }

    /**
     * Validate content against all registered validators
     */
    public function validate(string $type, array $data, ?int $id = null): array {
        $errors = [];

        // Run type-specific validators
        if (isset($this->validators[$type])) {
            foreach ($this->validators[$type] as $validator) {
                $result = $validator($data, $id);
                if (!$result['valid']) {
                    $errors = array_merge($errors, $result['errors'] ?? []);
                }
            }
        }

        // Run global validators
        if (isset($this->validators['*'])) {
            foreach ($this->validators['*'] as $validator) {
                $result = $validator($data, $id);
                if (!$result['valid']) {
                    $errors = array_merge($errors, $result['errors'] ?? []);
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Register default validators
     */
    private function register_default_validators(): void {
        // Post/Page validator
        $this->register_validator('post', function (array $data, ?int $id): array {
            $errors = [];

            if (empty($data['title']) && empty($data['post_title'])) {
                $errors[] = ['field' => 'title', 'message' => 'Title is required'];
            }

            $title = $data['title'] ?? $data['post_title'] ?? '';
            if (strlen($title) > 200) {
                $errors[] = ['field' => 'title', 'message' => 'Title must be under 200 characters'];
            }

            return ['valid' => empty($errors), 'errors' => $errors];
        });

        $this->register_validator('page', function (array $data, ?int $id): array {
            $errors = [];

            if (empty($data['title']) && empty($data['post_title'])) {
                $errors[] = ['field' => 'title', 'message' => 'Title is required'];
            }

            return ['valid' => empty($errors), 'errors' => $errors];
        });

        // Global content validator
        $this->register_validator('*', function (array $data, ?int $id): array {
            $errors = [];

            // Check for potentially harmful content
            $content = $data['content'] ?? $data['post_content'] ?? '';
            if (preg_match('/<script\b[^>]*>/i', $content)) {
                $errors[] = ['field' => 'content', 'message' => 'Script tags are not allowed'];
            }

            return ['valid' => empty($errors), 'errors' => $errors];
        });
    }

    /**
     * Apply design and accessibility constraints
     */
    private function apply_constraints(string $type, array $data): array {
        // Apply heading hierarchy constraints
        if (isset($data['content'])) {
            $data['content'] = $this->fix_heading_hierarchy($data['content']);
        }

        // Apply image accessibility constraints
        if (isset($data['content'])) {
            $data['content'] = $this->ensure_image_alt($data['content']);
        }

        return $data;
    }

    /**
     * Fix heading hierarchy for accessibility
     */
    private function fix_heading_hierarchy(string $content): string {
        // Ensure headings follow proper hierarchy (h1 > h2 > h3, etc.)
        preg_match_all('/<h(\d)[^>]*>/i', $content, $matches);
        if (empty($matches[1])) {
            return $content;
        }

        $levels = array_map('intval', $matches[1]);
        $min_level = min($levels);

        // If content starts with h1, adjust to start with h2 (h1 is typically page title)
        if ($min_level === 1) {
            $content = preg_replace_callback('/<(\/?)h(\d)([^>]*)>/i', function ($m) {
                $level = (int) $m[2];
                return "<{$m[1]}h" . min($level + 1, 6) . "{$m[3]}>";
            }, $content);
        }

        return $content;
    }

    /**
     * Ensure all images have alt text
     */
    private function ensure_image_alt(string $content): string {
        return preg_replace_callback('/<img([^>]*?)>/i', function ($match) {
            $attrs = $match[1];
            if (!preg_match('/\balt\s*=/i', $attrs)) {
                $attrs .= ' alt=""';
            }
            return "<img{$attrs}>";
        }, $content);
    }

    /**
     * Execute content creation based on type
     */
    private function execute_create(string $type, array $data): array {
        switch ($type) {
            case 'post':
                $post_data = [
                    'post_title' => sanitize_text_field($data['title'] ?? ''),
                    'post_content' => wp_kses_post($data['content'] ?? ''),
                    'post_status' => sanitize_text_field($data['status'] ?? 'draft'),
                    'post_excerpt' => sanitize_textarea_field($data['excerpt'] ?? ''),
                    'post_type' => 'post',
                    'post_author' => (int) ($data['author'] ?? get_current_user_id()),
                ];
                $id = wp_insert_post($post_data, true);
                if (is_wp_error($id)) {
                    return ['success' => false, 'error' => $id->get_error_message()];
                }
                $this->apply_post_meta($id, $data);
                return ['success' => true, 'id' => $id];

            case 'page':
                $page_data = [
                    'post_title' => sanitize_text_field($data['title'] ?? ''),
                    'post_content' => wp_kses_post($data['content'] ?? ''),
                    'post_status' => sanitize_text_field($data['status'] ?? 'draft'),
                    'post_type' => 'page',
                    'post_parent' => (int) ($data['parent'] ?? 0),
                ];
                $id = wp_insert_post($page_data, true);
                if (is_wp_error($id)) {
                    return ['success' => false, 'error' => $id->get_error_message()];
                }
                if (!empty($data['template'])) {
                    update_post_meta($id, '_wp_page_template', sanitize_text_field($data['template']));
                }
                return ['success' => true, 'id' => $id];

            default:
                return ['success' => false, 'error' => "Unknown content type: {$type}"];
        }
    }

    /**
     * Execute content update based on type
     */
    private function execute_update(string $type, int $id, array $data): array {
        switch ($type) {
            case 'post':
            case 'page':
                $post_data = ['ID' => $id];
                if (isset($data['title'])) {
                    $post_data['post_title'] = sanitize_text_field($data['title']);
                }
                if (isset($data['content'])) {
                    $post_data['post_content'] = wp_kses_post($data['content']);
                }
                if (isset($data['status'])) {
                    $post_data['post_status'] = sanitize_text_field($data['status']);
                }
                if (isset($data['excerpt'])) {
                    $post_data['post_excerpt'] = sanitize_textarea_field($data['excerpt']);
                }
                if (isset($data['slug'])) {
                    $post_data['post_name'] = sanitize_title($data['slug']);
                }
                $result = wp_update_post($post_data, true);
                if (is_wp_error($result)) {
                    return ['success' => false, 'error' => $result->get_error_message()];
                }
                $this->apply_post_meta($id, $data);
                return ['success' => true, 'id' => $id];

            default:
                return ['success' => false, 'error' => "Unknown content type: {$type}"];
        }
    }

    /**
     * Execute content deletion based on type
     */
    private function execute_delete(string $type, int $id, bool $force): array {
        switch ($type) {
            case 'post':
            case 'page':
                $result = wp_delete_post($id, $force);
                return ['success' => $result !== false, 'id' => $id];

            default:
                return ['success' => false, 'error' => "Unknown content type: {$type}"];
        }
    }

    /**
     * Apply post meta data
     */
    private function apply_post_meta(int $id, array $data): void {
        if (!empty($data['categories'])) {
            wp_set_post_categories($id, array_map('absint', (array) $data['categories']));
        }
        if (!empty($data['tags'])) {
            wp_set_post_tags($id, array_map('sanitize_text_field', (array) $data['tags']));
        }
        if (!empty($data['featured_image_id'])) {
            set_post_thumbnail($id, (int) $data['featured_image_id']);
        }
        if (!empty($data['meta']) && is_array($data['meta'])) {
            foreach ($data['meta'] as $key => $value) {
                update_post_meta($id, sanitize_key($key), $value);
            }
        }
        if (!empty($data['seo'])) {
            $this->apply_seo_meta($id, $data['seo']);
        }
    }

    /**
     * Apply SEO meta data
     */
    private function apply_seo_meta(int $id, array $seo): void {
        if (isset($seo['title'])) {
            update_post_meta($id, '_yoast_wpseo_title', sanitize_text_field($seo['title']));
            update_post_meta($id, 'rank_math_title', sanitize_text_field($seo['title']));
        }
        if (isset($seo['description'])) {
            update_post_meta($id, '_yoast_wpseo_metadesc', sanitize_textarea_field($seo['description']));
            update_post_meta($id, 'rank_math_description', sanitize_textarea_field($seo['description']));
        }
        if (isset($seo['focus_kw'])) {
            update_post_meta($id, '_yoast_wpseo_focuskw', sanitize_text_field($seo['focus_kw']));
            update_post_meta($id, 'rank_math_focus_keyword', sanitize_text_field($seo['focus_kw']));
        }
    }

    /**
     * Build a snapshot of current content state
     */
    private function build_snapshot(string $type, int $id): ?array {
        switch ($type) {
            case 'post':
            case 'page':
                $post = get_post($id);
                if (!$post) {
                    return null;
                }
                return [
                    'title' => $post->post_title,
                    'content' => $post->post_content,
                    'excerpt' => $post->post_excerpt,
                    'status' => $post->post_status,
                    'slug' => $post->post_name,
                    'author' => (int) $post->post_author,
                    'parent' => (int) $post->post_parent,
                    'categories' => wp_get_post_categories($id),
                    'tags' => wp_get_post_tags($id, ['fields' => 'ids']),
                    'featured_image_id' => get_post_thumbnail_id($id),
                    'meta' => get_post_meta($id),
                    'template' => get_page_template_slug($id),
                ];

            default:
                return null;
        }
    }

    /**
     * Generate a summary of changes
     */
    private function generate_change_summary(array $old, array $new): string {
        $changes = [];
        foreach ($new as $key => $value) {
            if (!isset($old[$key]) || $old[$key] !== $value) {
                $changes[] = $key;
            }
        }
        if (empty($changes)) {
            return 'No changes detected';
        }
        return 'Updated: ' . implode(', ', array_slice($changes, 0, 5)) . (count($changes) > 5 ? '...' : '');
    }
}
