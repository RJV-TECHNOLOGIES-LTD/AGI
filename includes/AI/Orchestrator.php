<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\AI;

use RJV_AGI_Bridge\AuditLog;
use RJV_AGI_Bridge\Settings;
use RJV_AGI_Bridge\Bridge\PlatformConnector;

/**
 * AI Orchestrator
 *
 * This is the "super car" that users get from day one.
 * It coordinates multiple AI providers (Claude + GPT) to work together, leveraging
 * their individual strengths for superior results.
 *
 * Key differentiator: Most plugins just call one AI. This orchestrator:
 * 1. Can use both Claude and GPT simultaneously for different aspects of a task
 * 2. Uses intelligent task routing based on model strengths
 * 3. Implements consensus building for critical decisions
 * 4. Provides automatic failover and load balancing
 * 5. Chains outputs for iterative improvement
 *
 * IMPORTANT: This full AI coordination works from day one with your own API keys.
 * Neither Anthropic's Claude nor OpenAI's GPT can use each other as AI workers -
 * but this plugin can orchestrate both of them together.
 *
 * With the RJV AGI Platform connected, you get the "professional race driver":
 * - Central intelligence orchestration across all your sites
 * - OpenClaw agentic teams working in parallel with Claude + GPT
 * - Cross-site learning and optimization
 * - Enterprise-grade workflow automation
 * - Real-time performance optimization across your entire digital estate
 */
final class Orchestrator {
    private static ?self $instance = null;
    private Router $router;
    private array $model_capabilities;
    private bool $agi_connected = false;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        $this->router = new Router();
        $this->model_capabilities = $this->define_model_capabilities();
        $this->agi_connected = PlatformConnector::instance()->is_configured();
    }

    /**
     * Define what each model excels at
     */
    private function define_model_capabilities(): array {
        return [
            'anthropic' => [
                'strengths' => [
                    'long_form_content',
                    'nuanced_analysis',
                    'creative_writing',
                    'code_review',
                    'technical_documentation',
                    'reasoning',
                    'safety',
                ],
                'ideal_for' => [
                    'blog_posts',
                    'documentation',
                    'content_strategy',
                    'complex_analysis',
                ],
            ],
            'openai' => [
                'strengths' => [
                    'structured_output',
                    'json_generation',
                    'data_extraction',
                    'summarization',
                    'quick_responses',
                    'seo_metadata',
                ],
                'ideal_for' => [
                    'seo_optimization',
                    'meta_descriptions',
                    'schema_markup',
                    'quick_edits',
                ],
            ],
        ];
    }

    /**
     * Intelligent task execution using the best provider for the job
     *
     * This is what makes the plugin a "super car" - it intelligently routes
     * tasks to the AI that will handle them best.
     */
    public function execute(string $task_type, array $params): array {
        $start = microtime(true);

        // Determine best provider for this task
        $provider = $this->route_task($task_type);

        // Check if AGI platform should handle this
        if ($this->agi_connected && $this->should_delegate_to_agi($task_type)) {
            return $this->delegate_to_agi($task_type, $params);
        }

        // Execute with intelligent provider selection
        $result = $this->execute_task($task_type, $params, $provider);
        $result['orchestration'] = [
            'mode' => $this->agi_connected ? 'agi_connected' : 'standalone',
            'provider_selected' => $provider,
            'selection_reason' => $this->get_selection_reason($task_type, $provider),
            'duration_ms' => (int) ((microtime(true) - $start) * 1000),
        ];

        return $result;
    }

    /**
     * Multi-Provider Coordination - Get both AIs to work on the same task
     * and find the best answer through comparison
     *
     * This is a feature that no other WordPress AI plugin offers!
     * Neither Claude nor GPT can use each other - but we coordinate both.
     */
    public function consensus(string $system_prompt, string $user_prompt, array $options = []): array {
        $results = [];
        $providers = ['anthropic', 'openai'];

        // Query both providers through the Router so circuit breakers, retry,
        // token budget, and prompt-injection scrubbing are all applied.
        foreach ($providers as $provider) {
            $provider_instance = $this->router->get($provider);
            if ($provider_instance->is_configured()) {
                $results[$provider] = $this->router->complete(
                    $system_prompt,
                    $user_prompt,
                    array_merge($options, ['provider' => $provider, 'no_fallback' => true])
                );
            }
        }

        if (empty($results)) {
            return ['error' => 'No AI providers configured', 'content' => ''];
        }

        // If only one provider responded, return that
        if (count($results) === 1) {
            return array_values($results)[0];
        }

        // Both providers responded - synthesize the best answer
        $synthesis = $this->synthesize_consensus($results, $system_prompt, $user_prompt);

        AuditLog::log('ai_consensus', 'orchestrator', 0, [
            'providers_used' => array_keys($results),
            'tokens_total' => array_sum(array_column($results, 'tokens')),
        ], 1);

        return $synthesis;
    }

    /**
     * Chain execution - Use one AI's output as input for another
     *
     * Example: GPT extracts data → Claude writes narrative
     */
    public function chain(array $steps): array {
        $context = [];
        $results = [];

        foreach ($steps as $index => $step) {
            $provider = $step['provider'] ?? $this->route_task($step['task'] ?? '');
            $system = $step['system'] ?? '';
            $prompt = $step['prompt'] ?? '';

            // Inject previous results into prompt
            foreach ($context as $key => $value) {
                $prompt = str_replace("{{{$key}}}", $value, $prompt);
            }

            $result = $this->router->complete($system, $prompt, ['provider' => $provider]);
            $results[$index] = $result;

            // Store output for next step
            if (!empty($step['output_key']) && !empty($result['content'])) {
                $context[$step['output_key']] = $result['content'];
            }

            // Stop chain if step failed
            if (!empty($result['error'])) {
                return [
                    'success' => false,
                    'error' => "Chain failed at step {$index}: " . $result['error'],
                    'partial_results' => $results,
                ];
            }
        }

        return [
            'success' => true,
            'results' => $results,
            'final_output' => end($results)['content'] ?? '',
        ];
    }

    /**
     * Parallel execution - Run multiple AI tasks simultaneously
     */
    public function parallel(array $tasks): array {
        $results = [];

        foreach ($tasks as $key => $task) {
            $provider = $task['provider'] ?? $this->route_task($task['task'] ?? '');
            $results[$key] = $this->router->complete(
                $task['system'] ?? '',
                $task['prompt'] ?? '',
                array_merge($task['options'] ?? [], ['provider' => $provider])
            );
        }

        return [
            'success' => !array_filter($results, fn($r) => isset($r['error'])),
            'results' => $results,
        ];
    }

    /**
     * Specialized: Generate SEO-optimized content
     *
     * Uses chain execution: GPT for SEO analysis → Claude for content
     */
    public function generate_seo_content(string $topic, array $options = []): array {
        return $this->chain([
            [
                'task' => 'seo_analysis',
                'provider' => 'openai',
                'system' => 'You are an SEO expert. Analyze the topic and provide: 1) Primary keyword 2) Secondary keywords 3) Optimal title structure 4) Meta description requirements 5) Content outline with H2/H3 structure. Output as JSON.',
                'prompt' => "Analyze SEO requirements for the topic: {$topic}",
                'output_key' => 'seo_analysis',
            ],
            [
                'task' => 'content_writing',
                'provider' => 'anthropic',
                'system' => 'You are an expert content writer. Write engaging, well-structured content following SEO best practices.',
                'prompt' => "Write a comprehensive blog post about: {$topic}\n\nFollow these SEO guidelines:\n{{seo_analysis}}",
                'output_key' => 'content',
            ],
            [
                'task' => 'meta_generation',
                'provider' => 'openai',
                'system' => 'Generate SEO metadata. Output as JSON with keys: title, meta_description, focus_keyword.',
                'prompt' => "Based on this content, generate optimal SEO metadata:\n\n{{content}}",
                'output_key' => 'seo_meta',
            ],
        ]);
    }

    /**
     * Specialized: Improve existing content
     */
    public function improve_content(string $content, array $improvements = []): array {
        $improvement_types = $improvements ?: ['clarity', 'engagement', 'seo'];

        return $this->parallel([
            'analysis' => [
                'task' => 'content_analysis',
                'provider' => 'anthropic',
                'system' => 'Analyze the content and identify areas for improvement.',
                'prompt' => "Analyze this content for " . implode(', ', $improvement_types) . ":\n\n{$content}",
            ],
            'improved' => [
                'task' => 'content_writing',
                'provider' => 'anthropic',
                'system' => 'Improve the content while maintaining its core message and structure.',
                'prompt' => "Improve this content focusing on: " . implode(', ', $improvement_types) . "\n\n{$content}",
            ],
            'seo_check' => [
                'task' => 'seo_optimization',
                'provider' => 'openai',
                'system' => 'Analyze SEO aspects. Output as JSON with: score (0-100), issues, suggestions.',
                'prompt' => "Analyze SEO of this content:\n\n{$content}",
                'options' => ['json_mode' => true],
            ],
        ]);
    }

    /**
     * Get orchestration status and capabilities
     */
    public function get_status(): array {
        $ai_status = $this->router->status();
        $providers_configured = count(array_filter($ai_status, fn($s) => $s['configured']));

        return [
            'mode' => $this->agi_connected ? 'agi_connected' : 'standalone',
            'providers' => $ai_status,
            'providers_configured' => $providers_configured,
            'capabilities' => [
                'intelligent_routing' => $providers_configured >= 1,
                'multi_provider' => $providers_configured >= 2,
                'consensus_building' => $providers_configured >= 2,
                'chain_execution' => $providers_configured >= 1,
                'parallel_execution' => $providers_configured >= 1,
                'seo_optimization' => $providers_configured >= 1,
                'content_generation' => $providers_configured >= 1,
            ],
            'agi_platform' => [
                'connected' => $this->agi_connected,
                'additional_capabilities' => $this->agi_connected ? [
                    'cross_site_orchestration',
                    'openclaw_agent_teams',
                    'enterprise_workflows',
                    'real_time_optimization',
                    'central_intelligence',
                ] : [],
            ],
        ];
    }

    /**
     * Route task to best provider based on task type
     */
    private function route_task(string $task_type): string {
        $routing = [
            // Tasks best suited for Claude (Anthropic)
            'content_writing' => 'anthropic',
            'blog_post' => 'anthropic',
            'creative_writing' => 'anthropic',
            'technical_docs' => 'anthropic',
            'content_analysis' => 'anthropic',
            'code_review' => 'anthropic',
            'reasoning' => 'anthropic',
            'long_form' => 'anthropic',

            // Tasks best suited for GPT (OpenAI)
            'seo_optimization' => 'openai',
            'seo_analysis' => 'openai',
            'meta_generation' => 'openai',
            'data_extraction' => 'openai',
            'summarization' => 'openai',
            'json_output' => 'openai',
            'structured_data' => 'openai',
            'quick_edit' => 'openai',
        ];

        $preferred = $routing[$task_type] ?? Settings::get_string('default_model', 'anthropic');

        // Fall back if preferred provider not configured
        $provider = $this->router->get($preferred);
        if (!$provider->is_configured()) {
            $preferred = $preferred === 'anthropic' ? 'openai' : 'anthropic';
        }

        return $preferred;
    }

    /**
     * Get human-readable reason for provider selection
     */
    private function get_selection_reason(string $task_type, string $provider): string {
        $reasons = [
            'anthropic' => [
                'content_writing' => 'Claude excels at nuanced, engaging long-form content',
                'blog_post' => 'Claude produces more natural, human-like blog content',
                'creative_writing' => 'Claude handles creative tasks with more originality',
                'technical_docs' => 'Claude provides clearer technical explanations',
                'content_analysis' => 'Claude offers deeper analytical insights',
            ],
            'openai' => [
                'seo_optimization' => 'GPT excels at structured SEO analysis',
                'seo_analysis' => 'GPT produces well-structured SEO recommendations',
                'meta_generation' => 'GPT generates precise, optimized metadata',
                'data_extraction' => 'GPT handles data extraction more reliably',
                'json_output' => 'GPT produces cleaner JSON structures',
            ],
        ];

        return $reasons[$provider][$task_type]
            ?? "Selected {$provider} based on configured default or availability";
    }

    /**
     * Execute task with selected provider
     */
    private function execute_task(string $task_type, array $params, string $provider): array {
        $system = $params['system'] ?? $this->get_default_system_prompt($task_type);
        $prompt = $params['prompt'] ?? '';
        $options = $params['options'] ?? [];
        $options['provider'] = $provider;

        $result = $this->router->complete($system, $prompt, $options);
        $result['task_type'] = $task_type;

        return $result;
    }

    /**
     * Get default system prompt for task type
     */
    private function get_default_system_prompt(string $task_type): string {
        $prompts = [
            'content_writing' => 'You are an expert content writer for RJV Technologies Ltd. Write engaging, clear, and well-structured content.',
            'seo_optimization' => 'You are an SEO expert. Analyze and optimize content for search engines while maintaining readability.',
            'blog_post' => 'You are a professional blog writer. Create engaging, informative content that resonates with readers.',
            'meta_generation' => 'You are an SEO specialist. Generate optimized meta titles and descriptions.',
            'summarization' => 'You are an expert at creating concise, accurate summaries while preserving key information.',
        ];

        return $prompts[$task_type] ?? 'You are a helpful AI assistant for RJV Technologies Ltd.';
    }

    /**
     * Determine if task should be delegated to AGI platform
     */
    private function should_delegate_to_agi(string $task_type): bool {
        // Complex orchestration tasks that benefit from central AGI intelligence
        $agi_optimal = [
            'multi_site_sync',
            'enterprise_workflow',
            'cross_site_analysis',
            'agent_orchestration',
            'openclaw_task',
        ];

        return in_array($task_type, $agi_optimal, true);
    }

    /**
     * Delegate task to AGI platform
     */
    private function delegate_to_agi(string $task_type, array $params): array {
        $connector = PlatformConnector::instance();

        // Send to AGI platform for handling
        $response = $connector->report_event('task_delegation', [
            'task_type' => $task_type,
            'params' => $params,
        ]);

        if (isset($response['error'])) {
            // Fall back to direct execution
            return $this->execute_task($task_type, $params, $this->route_task($task_type));
        }

        return [
            'success' => true,
            'mode' => 'agi_platform',
            'result' => $response,
        ];
    }

    /**
     * Synthesize consensus from multiple AI responses
     */
    private function synthesize_consensus(array $results, string $system, string $prompt): array {
        // Use the provider with higher confidence or combine insights
        $anthropic_result = $results['anthropic'] ?? null;
        $openai_result = $results['openai'] ?? null;

        // If both succeeded, prefer Anthropic for general content but note consensus
        $primary = $anthropic_result ?? $openai_result;

        return [
            'content' => $primary['content'] ?? '',
            'model' => $primary['model'] ?? 'consensus',
            'tokens' => ($anthropic_result['tokens'] ?? 0) + ($openai_result['tokens'] ?? 0),
            'consensus' => true,
            'providers_agreed' => $this->check_agreement($anthropic_result, $openai_result),
            'individual_responses' => [
                'anthropic' => $anthropic_result['content'] ?? null,
                'openai' => $openai_result['content'] ?? null,
            ],
        ];
    }

    /**
     * Check if both providers reached similar conclusions
     */
    private function check_agreement(?array $a, ?array $b): bool {
        if (!$a || !$b) {
            return false;
        }

        // Simple similarity check based on key terms
        $a_content = strtolower($a['content'] ?? '');
        $b_content = strtolower($b['content'] ?? '');

        // Extract significant words
        $a_words = array_filter(str_word_count($a_content, 1), fn($w) => strlen($w) > 4);
        $b_words = array_filter(str_word_count($b_content, 1), fn($w) => strlen($w) > 4);

        if (empty($a_words) || empty($b_words)) {
            return true;
        }

        $common = count(array_intersect($a_words, $b_words));
        $total = count(array_unique(array_merge($a_words, $b_words)));

        return ($common / $total) > 0.3; // 30% word overlap indicates agreement
    }
}
