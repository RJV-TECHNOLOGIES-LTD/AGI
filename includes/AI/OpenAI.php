<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\AI;

use RJV_AGI_Bridge\Settings;
use RJV_AGI_Bridge\AuditLog;

/**
 * OpenAI Provider
 *
 * Supports GPT-4.1 family and o-series models via the Chat Completions API.
 * Classifies errors as permanent (auth / billing) or transient (rate-limit /
 * server error) to assist the Router's retry and circuit-breaker logic.
 */
final class OpenAI implements Provider {

    private string $key;
    private string $model;
    private string $org;
    private int    $timeout;

    public function __construct() {
        $this->key     = Settings::get_string('openai_key',          '');
        $this->model   = Settings::get_string('openai_model',        'gpt-4.1-mini');
        $this->org     = Settings::get_string('openai_org',          '');
        $this->timeout = Settings::get_int('ai_timeout_seconds',     120);
    }

    public function get_name(): string  { return 'openai'; }
    public function get_model(): string { return $this->model; }
    public function is_configured(): bool { return $this->key !== ''; }

    /**
     * Send a chat completion request to the OpenAI API.
     *
     * @param string $sys   System prompt.
     * @param string $msg   User message.
     * @param array  $opts  {
     *   @type string $model        Override model.
     *   @type int    $max_tokens   Token ceiling.
     *   @type float  $temperature  Sampling temperature (0–2).
     *   @type int    $timeout      HTTP timeout override (seconds).
     *   @type bool   $json_mode    Force JSON output via response_format.
     *   @type array  $tools        Function / tool definitions.
     * }
     */
    public function complete(string $sys, string $msg, array $opts = []): array {
        if (!$this->is_configured()) {
            return $this->error_result('OpenAI not configured');
        }

        $model = sanitize_text_field((string) ($opts['model'] ?? $this->model));
        $start = microtime(true);

        $body = [
            'model'       => $model,
            'messages'    => [
                ['role' => 'system', 'content' => $sys],
                ['role' => 'user',   'content' => $msg],
            ],
            'max_tokens'  => (int) ($opts['max_tokens']  ?? Settings::get_int('ai_max_tokens', 4096)),
            'temperature' => (float) ($opts['temperature'] ?? Settings::get_float('ai_temperature', 0.3)),
        ];

        if (!empty($opts['json_mode'])) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        if (!empty($opts['tools']) && is_array($opts['tools'])) {
            $body['tools'] = $opts['tools'];
        }

        $headers = [
            'Authorization' => 'Bearer ' . $this->key,
            'Content-Type'  => 'application/json',
        ];

        if ($this->org !== '') {
            $headers['OpenAI-Organization'] = $this->org;
        }

        $response = wp_remote_post(
            'https://api.openai.com/v1/chat/completions',
            [
                'timeout' => (int) ($opts['timeout'] ?? $this->timeout),
                'headers' => $headers,
                'body'    => wp_json_encode($body),
            ]
        );

        $ms   = (int) ((microtime(true) - $start) * 1000);
        $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);

        if (is_wp_error($response)) {
            $error = $response->get_error_message();
            AuditLog::log('ai_error', 'openai', 0, ['error' => $error], 1, 'error', $ms);
            return $this->error_result($error, $ms);
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200 || empty($data['choices'][0]['message']['content'])) {
            $error = $this->extract_error($data, $code);
            $type  = $this->classify_error($error, $code);
            AuditLog::log('ai_error', 'openai', 0, ['error' => $error, 'type' => $type, 'code' => $code], 1, 'error', $ms);
            return $this->error_result($error, $ms, $type);
        }

        $content    = (string) $data['choices'][0]['message']['content'];
        $tokens     = (int) ($data['usage']['total_tokens']         ?? 0);
        $input_tok  = (int) ($data['usage']['prompt_tokens']        ?? 0);
        $output_tok = (int) ($data['usage']['completion_tokens']    ?? 0);
        $finish     = (string) ($data['choices'][0]['finish_reason'] ?? '');

        AuditLog::log('ai_completion', 'openai', 0, ['model' => $model, 'finish_reason' => $finish], 1, 'success', $ms, $tokens, $model);

        return [
            'content'       => $content,
            'model'         => $model,
            'tokens'        => $tokens,
            'input_tokens'  => $input_tok,
            'output_tokens' => $output_tok,
            'latency_ms'    => $ms,
            'provider'      => 'openai',
            'finish_reason' => $finish,
        ];
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function extract_error(?array $data, int $code): string {
        if ($data === null) {
            return "OpenAI HTTP {$code}";
        }
        return (string) ($data['error']['message'] ?? "OpenAI error {$code}");
    }

    /**
     * Classify an error message as 'permanent' or 'transient'.
     * The Router uses this to decide whether to retry.
     */
    private function classify_error(string $error, int $code): string {
        if (in_array($code, [401, 403], true)) {
            return 'permanent';
        }
        $permanent_phrases = ['invalid_api_key', 'incorrect api key', 'billing', 'quota', 'model not found'];
        $lower             = strtolower($error);
        foreach ($permanent_phrases as $phrase) {
            if (str_contains($lower, $phrase)) {
                return 'permanent';
            }
        }
        return 'transient'; // 429, 500, 503, network errors, etc.
    }

    private function error_result(string $message, int $ms = 0, string $type = 'transient'): array {
        return [
            'error'      => $message,
            'error_type' => $type,
            'content'    => '',
            'latency_ms' => $ms,
            'provider'   => 'openai',
        ];
    }
}
