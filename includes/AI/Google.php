<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\AI;

use RJV_AGI_Bridge\Settings;
use RJV_AGI_Bridge\AuditLog;

/**
 * Google Gemini Provider
 *
 * Supports Gemini 2.5 Pro / 2.0 Flash and the full Gemini family via the
 * Generative Language API (v1beta).
 *
 * Request format  : POST https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent
 * Authentication  : x-goog-api-key header
 * Token tracking  : usageMetadata.{promptTokenCount, candidatesTokenCount, totalTokenCount}
 * Stop reason     : candidates[0].finishReason
 */
final class Google implements Provider {

    /** @var string Base URL for the Generative Language API. */
    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';

    private string $key;
    private string $model;
    private int    $timeout;

    public function __construct() {
        $this->key     = Settings::get_string('google_key',          '');
        $this->model   = Settings::get_string('google_model',        'gemini-2.5-pro');
        $this->timeout = Settings::get_int('ai_timeout_seconds',     120);
    }

    public function get_name(): string  { return 'google'; }
    public function get_model(): string { return $this->model; }
    public function is_configured(): bool { return $this->key !== ''; }

    /**
     * Send a generateContent request to the Gemini API.
     *
     * @param string $sys   System prompt (mapped to systemInstruction).
     * @param string $msg   User message.
     * @param array  $opts  {
     *   @type string $model        Override model name.
     *   @type int    $max_tokens   Maximum output tokens.
     *   @type float  $temperature  Sampling temperature (0–2).
     *   @type int    $timeout      HTTP timeout override (seconds).
     *   @type bool   $json_mode    Request JSON output via responseMimeType.
     * }
     * @return array{content: string, model: string, tokens: int, input_tokens: int, output_tokens: int, latency_ms: int, provider: string, finish_reason?: string}|array{error: string, error_type: string, content: string, latency_ms: int, provider: string}
     */
    public function complete(string $sys, string $msg, array $opts = []): array {
        if (!$this->is_configured()) {
            return $this->error_result('Google Gemini not configured');
        }

        $model   = sanitize_text_field((string) ($opts['model'] ?? $this->model));
        $start   = microtime(true);
        $url     = self::API_BASE . rawurlencode($model) . ':generateContent';

        $generation_config = [
            'maxOutputTokens' => (int) ($opts['max_tokens'] ?? Settings::get_int('ai_max_tokens', 4096)),
        ];

        if (isset($opts['temperature'])) {
            $generation_config['temperature'] = (float) $opts['temperature'];
        } else {
            $temp = Settings::get_float('ai_temperature', 0.3);
            if ($temp > 0.0) {
                $generation_config['temperature'] = $temp;
            }
        }

        if (!empty($opts['json_mode'])) {
            $generation_config['responseMimeType'] = 'application/json';
        }

        $body = [
            'contents' => [
                [
                    'role'  => 'user',
                    'parts' => [['text' => $msg]],
                ],
            ],
            'generationConfig' => $generation_config,
        ];

        // Gemini supports a top-level systemInstruction for system prompts
        if ($sys !== '') {
            $body['systemInstruction'] = [
                'parts' => [['text' => $sys]],
            ];
        }

        $response = wp_remote_post(
            $url,
            [
                'timeout' => (int) ($opts['timeout'] ?? $this->timeout),
                'headers' => [
                    'x-goog-api-key' => $this->key,
                    'Content-Type'   => 'application/json',
                ],
                'body' => wp_json_encode($body),
            ]
        );

        $ms   = (int) ((microtime(true) - $start) * 1000);
        $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);

        if (is_wp_error($response)) {
            $error = $response->get_error_message();
            AuditLog::log('ai_error', 'google', 0, ['error' => $error], 1, 'error', $ms);
            return $this->error_result($error, $ms);
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200 || empty($data['candidates'][0]['content']['parts'][0]['text'])) {
            $error = $this->extract_error($data, $code);
            $type  = $this->classify_error($error, $code);
            AuditLog::log('ai_error', 'google', 0, ['error' => $error, 'type' => $type, 'code' => $code], 1, 'error', $ms);
            return $this->error_result($error, $ms, $type);
        }

        $content    = (string) $data['candidates'][0]['content']['parts'][0]['text'];
        $finish     = (string) ($data['candidates'][0]['finishReason'] ?? '');
        $usage      = $data['usageMetadata'] ?? [];
        $input_tok  = (int) ($usage['promptTokenCount']     ?? 0);
        $output_tok = (int) ($usage['candidatesTokenCount'] ?? 0);
        $tokens     = (int) ($usage['totalTokenCount']      ?? ($input_tok + $output_tok));

        AuditLog::log('ai_completion', 'google', 0, ['model' => $model, 'finish_reason' => $finish], 1, 'success', $ms, $tokens, $model);

        return [
            'content'       => $content,
            'model'         => $model,
            'tokens'        => $tokens,
            'input_tokens'  => $input_tok,
            'output_tokens' => $output_tok,
            'latency_ms'    => $ms,
            'provider'      => 'google',
            'finish_reason' => $finish,
        ];
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function extract_error(?array $data, int $code): string {
        if ($data === null) {
            return "Google Gemini HTTP {$code}";
        }
        // Gemini error envelope: { "error": { "code": 400, "message": "...", "status": "INVALID_ARGUMENT" } }
        return (string) ($data['error']['message'] ?? "Google Gemini error {$code}");
    }

    /**
     * Classify an error as 'permanent' (no retry) or 'transient' (retry OK).
     */
    private function classify_error(string $error, int $code): string {
        if (in_array($code, [401, 403], true)) {
            return 'permanent';
        }
        $permanent_phrases = [
            'api_key', 'api key', 'authentication', 'permission', 'not found',
            'invalid_argument', 'resource_exhausted billing',
        ];
        $lower = strtolower($error);
        foreach ($permanent_phrases as $phrase) {
            if (str_contains($lower, $phrase)) {
                return 'permanent';
            }
        }
        return 'transient'; // 429 (quota), 500, 503, network errors
    }

    private function error_result(string $message, int $ms = 0, string $type = 'transient'): array {
        return [
            'error'      => $message,
            'error_type' => $type,
            'content'    => '',
            'latency_ms' => $ms,
            'provider'   => 'google',
        ];
    }
}
