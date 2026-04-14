<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\AI;

use RJV_AGI_Bridge\Settings;
use RJV_AGI_Bridge\AuditLog;

/**
 * Anthropic Provider
 *
 * Supports all Claude models via the Messages API (anthropic-version 2023-06-01).
 * Tracks input/output tokens separately for cost accuracy.
 */
final class Anthropic implements Provider {

    private string $key;
    private string $model;
    private int    $timeout;

    public function __construct() {
        $this->key     = Settings::get_string('anthropic_key',       '');
        $this->model   = Settings::get_string('anthropic_model',     'claude-sonnet-4-20250514');
        $this->timeout = Settings::get_int('ai_timeout_seconds',     120);
    }

    public function get_name(): string  { return 'anthropic'; }
    public function get_model(): string { return $this->model; }
    public function is_configured(): bool { return $this->key !== ''; }

    /**
     * Send a message to the Anthropic Messages API.
     *
     * @param string $sys   System prompt (passed via top-level `system` field).
     * @param string $msg   User message.
     * @param array  $opts  {
     *   @type string $model        Override model.
     *   @type int    $max_tokens   Token ceiling.
     *   @type float  $temperature  Sampling temperature (0–1).
     *   @type int    $timeout      HTTP timeout override (seconds).
     *   @type bool   $json_mode    Wrap the user message with JSON instruction.
     * }
     */
    public function complete(string $sys, string $msg, array $opts = []): array {
        if (!$this->is_configured()) {
            return $this->error_result('Anthropic not configured');
        }

        $model = sanitize_text_field((string) ($opts['model'] ?? $this->model));
        $start = microtime(true);

        $user_msg = $msg;
        if (!empty($opts['json_mode'])) {
            $user_msg .= "\n\nRespond with valid JSON only. No markdown fences, no additional text.";
        }

        $body = [
            'model'      => $model,
            'max_tokens' => (int) ($opts['max_tokens']  ?? Settings::get_int('ai_max_tokens', 4096)),
            'system'     => $sys,
            'messages'   => [['role' => 'user', 'content' => $user_msg]],
        ];

        // Anthropic temperature is 0–1 (not 0–2 like OpenAI)
        if (isset($opts['temperature'])) {
            $body['temperature'] = min(1.0, max(0.0, (float) $opts['temperature']));
        }

        $response = wp_remote_post(
            'https://api.anthropic.com/v1/messages',
            [
                'timeout' => (int) ($opts['timeout'] ?? $this->timeout),
                'headers' => [
                    'x-api-key'         => $this->key,
                    'anthropic-version' => '2023-06-01',
                    'Content-Type'      => 'application/json',
                ],
                'body' => wp_json_encode($body),
            ]
        );

        $ms   = (int) ((microtime(true) - $start) * 1000);
        $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);

        if (is_wp_error($response)) {
            $error = $response->get_error_message();
            AuditLog::log('ai_error', 'anthropic', 0, ['error' => $error], 1, 'error', $ms);
            return $this->error_result($error, $ms);
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200 || empty($data['content'][0]['text'])) {
            $error = $this->extract_error($data, $code);
            $type  = $this->classify_error($error, $code);
            AuditLog::log('ai_error', 'anthropic', 0, ['error' => $error, 'type' => $type, 'code' => $code], 1, 'error', $ms);
            return $this->error_result($error, $ms, $type);
        }

        $content    = (string) $data['content'][0]['text'];
        $input_tok  = (int) ($data['usage']['input_tokens']  ?? 0);
        $output_tok = (int) ($data['usage']['output_tokens'] ?? 0);
        $tokens     = $input_tok + $output_tok;
        $stop       = (string) ($data['stop_reason']         ?? '');

        AuditLog::log('ai_completion', 'anthropic', 0, ['model' => $model, 'stop_reason' => $stop], 1, 'success', $ms, $tokens, $model);

        return [
            'content'       => $content,
            'model'         => $model,
            'tokens'        => $tokens,
            'input_tokens'  => $input_tok,
            'output_tokens' => $output_tok,
            'latency_ms'    => $ms,
            'provider'      => 'anthropic',
            'stop_reason'   => $stop,
        ];
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function extract_error(?array $data, int $code): string {
        if ($data === null) {
            return "Anthropic HTTP {$code}";
        }
        return (string) ($data['error']['message'] ?? "Anthropic error {$code}");
    }

    /**
     * Classify an error as 'permanent' (no retry) or 'transient' (retry OK).
     */
    private function classify_error(string $error, int $code): string {
        if (in_array($code, [401, 403], true)) {
            return 'permanent';
        }
        $permanent_phrases = ['authentication', 'invalid api key', 'permission', 'not found'];
        $lower             = strtolower($error);
        foreach ($permanent_phrases as $phrase) {
            if (str_contains($lower, $phrase)) {
                return 'permanent';
            }
        }
        return 'transient';
    }

    private function error_result(string $message, int $ms = 0, string $type = 'transient'): array {
        return [
            'error'      => $message,
            'error_type' => $type,
            'content'    => '',
            'latency_ms' => $ms,
            'provider'   => 'anthropic',
        ];
    }
}
