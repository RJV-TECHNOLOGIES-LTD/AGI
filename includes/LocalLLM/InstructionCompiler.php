<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\LocalLLM;

/**
 * InstructionCompiler
 *
 * Converts an AGI instruction package into a tightly constrained [system, user]
 * prompt pair for the local LLM. The system prompt hard-limits the model to
 * emitting a single JSON object describing one authorised plugin action —
 * no free-form text, no extra keys, no actions outside the allowed list.
 */
final class InstructionCompiler {

    /**
     * Default allowed action types when the AGI does not specify a scope.
     * Restricted to low-risk, read-heavy operations.
     */
    private const DEFAULT_ALLOWED_ACTIONS = [
        'read_post', 'update_post',
        'read_seo', 'update_seo',
        'ai_complete',
        'noop',
    ];

    /**
     * Actions that are never allowed regardless of scope.
     */
    private const ALWAYS_FORBIDDEN = [
        'delete_post', 'delete_page', 'delete_user',
        'activate_plugin', 'deactivate_plugin',
        'raw_sql', 'file_write', 'file_delete',
        'create_agent', 'modify_agent',
    ];

    /**
     * Compile an instruction package into a [system, user] prompt pair.
     *
     * @param array $instructions  Array of instruction objects from the AGI.
     * @param array $scope         Allowed action scope (allowed_action_types, etc.).
     * @param array $constraints   Hard constraints (forbidden_actions, max_ops, etc.).
     * @return array{system: string, user: string}
     */
    public static function compile(array $instructions, array $scope = [], array $constraints = []): array {
        $allowed_types = (array) ($scope['allowed_action_types'] ?? self::DEFAULT_ALLOWED_ACTIONS);

        // Strip any always-forbidden items even if the AGI mistakenly listed them
        $allowed_types = array_values(array_diff($allowed_types, self::ALWAYS_FORBIDDEN));

        // Ensure noop is always available as a safe fallback
        if (!in_array('noop', $allowed_types, true)) {
            $allowed_types[] = 'noop';
        }

        $allowed_str  = implode(', ', $allowed_types);
        $forbidden    = array_unique(array_merge(
            self::ALWAYS_FORBIDDEN,
            (array) ($constraints['forbidden_actions'] ?? [])
        ));
        $forbidden_str = implode(', ', $forbidden);

        $system = <<<SYSTEM
You are a WordPress plugin action executor operating inside the RJV AGI Bridge. Your ONLY role is to translate the given instruction into a single, valid JSON action object. You MUST follow every rule below without exception.

RULES:
1. Respond with ONLY a single JSON object. No markdown, no code fences, no explanation, no extra text.
2. The JSON object MUST exactly match the output schema below.
3. The "action" field MUST be one of the allowed action types listed below.
4. You MUST NOT use any action listed under FORBIDDEN ACTIONS.
5. If the instruction cannot be satisfied by any allowed action, respond with action "noop".
6. The "params" field MUST only contain keys that are valid for the chosen action.
7. All string values MUST be sanitised — no HTML, no SQL, no shell metacharacters.

ALLOWED ACTION TYPES: {$allowed_str}

FORBIDDEN ACTIONS (never use these): {$forbidden_str}

OUTPUT SCHEMA (respond with exactly this structure, nothing else):
{"action":"<allowed_action_type>","params":{"<key>":"<value>"},"rationale":"<one sentence>"}

PARAM REFERENCE:
- read_post:   {"id": <integer>}
- update_post: {"id": <integer>, "title": "<string>", "status": "draft|publish|pending|private"}
- read_seo:    {"id": <integer>}
- update_seo:  {"id": <integer>, "title": "<string>", "description": "<string>"}
- ai_complete: {"prompt": "<string>"}
- noop:        {}
SYSTEM;

        $user = (string) wp_json_encode([
            'instructions' => $instructions,
            'scope'        => $scope,
            'constraints'  => $constraints,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return [
            'system' => $system,
            'user'   => $user,
        ];
    }

    /**
     * Parse and validate the LLM's raw response text into a structured action.
     *
     * Strips accidental markdown fences, decodes JSON, validates the action
     * type against the compiled scope, and sanitises all string values.
     *
     * @param string $raw_response  Raw text returned by the LLM.
     * @param array  $scope         Scope used during compilation (for re-validation).
     * @return array{action: string, params: array, rationale: string}|null
     *         Returns null when the response cannot be parsed or contains a forbidden action.
     */
    public static function parse_response(string $raw_response, array $scope = []): ?array {
        // Strip accidental markdown code fences
        $cleaned = preg_replace('/^```(?:json)?\s*/m', '', $raw_response) ?? $raw_response;
        $cleaned = preg_replace('/\s*```$/m', '', $cleaned) ?? $cleaned;
        $cleaned = trim($cleaned);

        $decoded = json_decode($cleaned, true);
        if (!is_array($decoded)) {
            return null;
        }

        $action = sanitize_key((string) ($decoded['action'] ?? ''));
        if ($action === '') {
            return null;
        }

        // Re-validate against scope (defence-in-depth)
        $allowed_types = (array) ($scope['allowed_action_types'] ?? self::DEFAULT_ALLOWED_ACTIONS);
        $allowed_types = array_values(array_diff($allowed_types, self::ALWAYS_FORBIDDEN));
        if (!in_array('noop', $allowed_types, true)) {
            $allowed_types[] = 'noop';
        }

        if (!in_array($action, $allowed_types, true)) {
            return null;
        }

        // Sanitise all param values
        $raw_params = is_array($decoded['params'] ?? null) ? $decoded['params'] : [];
        $params     = [];
        foreach ($raw_params as $key => $value) {
            $key = sanitize_key((string) $key);
            if ($key === '') {
                continue;
            }
            $params[$key] = is_string($value)
                ? sanitize_text_field($value)
                : $value;
        }

        return [
            'action'    => $action,
            'params'    => $params,
            'rationale' => sanitize_text_field((string) ($decoded['rationale'] ?? '')),
        ];
    }
}
