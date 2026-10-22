<?php
/**
 * The News desk's writer: Claude first, Gemini as the backup.
 *
 * One call for the broadcast endpoint, so a provider outage never silences the
 * newsroom and the prompt is built once in two halves — the standing brief
 * (task, persona, format) as the system prompt, the night's data as the user
 * turn. Claude takes them as such; Gemini gets them joined into one text, the
 * way it always did.
 *
 * Config (private/config/config.php):
 *   anthropic_api_key  — Claude. Set with: php bin/set_api_key.php anthropic
 *   gemini_api_key     — Gemini.          php bin/set_api_key.php gemini
 *   news_writer        — 'claude' (default) or 'gemini': which one is tried first
 *   claude_model       — default CLAUDE_DEFAULT_MODEL
 *   claude_effort      — low | medium | high | xhigh | max (default medium)
 *   model_name         — the Gemini model, as before
 *
 * Path: /cdnmk/private/includes/newswriter.php
 */

require_once __DIR__ . '/claude_client.php';
require_once __DIR__ . '/gemini_client.php';

/** The writers that have a key, primary first. Empty when nothing is configured. */
function newsWriterOrder(array $config): array {
    $have = [];
    if (trim((string)($config['anthropic_api_key'] ?? '')) !== '') $have[] = 'claude';
    if (trim((string)($config['gemini_api_key'] ?? '')) !== '')    $have[] = 'gemini';
    $primary = (($config['news_writer'] ?? 'claude') === 'gemini') ? 'gemini' : 'claude';
    usort($have, fn($a, $b) => ($a === $primary ? 0 : 1) <=> ($b === $primary ? 0 : 1));
    return $have;
}

/**
 * Write a piece. Tries each configured writer in order and returns the first
 * text that comes back.
 *
 * @return array{text: ?string, provider: string, model: string, error: string}
 *         provider is 'claude' or 'gemini'; model is the id that answered.
 */
function newsroomWrite(array $config, string $system, string $user, int $maxTokens = 16000): array {
    $errors = [];

    foreach (newsWriterOrder($config) as $provider) {
        if ($provider === 'claude') {
            $model  = trim((string)($config['claude_model'] ?? '')) ?: CLAUDE_DEFAULT_MODEL;
            $effort = (string)($config['claude_effort'] ?? 'medium');
            if (!in_array($effort, ['low', 'medium', 'high', 'xhigh', 'max'], true)) $effort = 'medium';

            [$body, $code, $err, $used] = callClaudeWithRetry(
                $model, (string)$config['anthropic_api_key'],
                claudeMessagesPayload($model, $system, $user, $maxTokens, $effort)
            );
            if ($body !== null) {
                $text = claudeResponseText($body);
                if ($text !== '') return ['text' => $text, 'provider' => 'claude', 'model' => $used, 'error' => ''];
                $err = $model . ' — empty response';
            }
            $errors[] = 'Claude: ' . $err;
            continue;
        }

        // Gemini: one text part, the standing brief first.
        $model   = (string)($config['model_name'] ?? 'gemini-2.5-flash');
        $payload = [
            'contents'       => [['parts' => [['text' => ($system !== '' ? $system . "\n\n" : '') . $user]]]],
            'safetySettings' => [
                ['category' => 'HARM_CATEGORY_HARASSMENT',        'threshold' => 'BLOCK_ONLY_HIGH'],
                ['category' => 'HARM_CATEGORY_HATE_SPEECH',       'threshold' => 'BLOCK_ONLY_HIGH'],
                ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_ONLY_HIGH'],
                ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_ONLY_HIGH'],
            ],
        ];
        [$body, $code, $err, $used] = callGeminiWithRetry(geminiDefaultModelChain($model), (string)$config['gemini_api_key'], $payload);
        if ($body !== null) {
            $json = json_decode($body, true);
            $text = trim((string)($json['candidates'][0]['content']['parts'][0]['text'] ?? ''));
            if ($text !== '') return ['text' => $text, 'provider' => 'gemini', 'model' => $used, 'error' => ''];
            $err = $used . ' — empty response';
        }
        $errors[] = 'Gemini: ' . $err;
    }

    if (!$errors) $errors[] = 'No AI writer is configured — add a key with: php bin/set_api_key.php anthropic';
    return ['text' => null, 'provider' => '', 'model' => '', 'error' => implode("\n", $errors)];
}
