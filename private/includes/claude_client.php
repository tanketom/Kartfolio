<?php
/**
 * Shared Claude client — the Anthropic Messages API over curl, the same shape
 * as gemini_client.php (this project has no Composer, so no SDK).
 *
 *   [$body, $httpCode, $error, $modelUsed] = callClaudeWithRetry($model, $apiKey,
 *       claudeMessagesPayload($model, $systemPrompt, $userPrompt));
 *   $text = claudeResponseText($body);
 *
 * Retries 429 / 500 / 502 / 503 / 529 with backoff (1s, 2s); hard 4xx fail at
 * once. A safety decline (stop_reason "refusal") is a failure too, so the
 * caller can fall back to another writer. The request opts into server-side
 * fallbacks ("fallbacks": "default"): on a policy decline the API re-runs the
 * request on another model inside the same call. That needs a beta header; if
 * the organisation is not enabled for it the API answers 400, and the call is
 * repeated once without it.
 *
 * Path: /cdnmk/private/includes/claude_client.php
 */

const CLAUDE_API_VERSION   = '2026-10-21';
const CLAUDE_DEFAULT_MODEL = 'claude-sonnet-5-5';
const CLAUDE_FALLBACK_BETA = 'server-side-fallback-2026-07-01';

/**
 * A single-turn request: the standing brief as the system prompt, the night's
 * data as the user turn. No sampling parameters — current models reject them;
 * depth is steered with output_config.effort (low … max).
 */
function claudeMessagesPayload(string $model, string $system, string $user, int $maxTokens = 16000, string $effort = 'medium'): array {
    $payload = [
        'model'         => $model,
        'max_tokens'    => $maxTokens,
        'messages'      => [['role' => 'user', 'content' => $user]],
        'output_config' => ['effort' => $effort],
        'fallbacks'     => 'default',
    ];
    if ($system !== '') $payload['system'] = $system;
    return $payload;
}

/**
 * @return array{0: ?string, 1: int, 2: string, 3: string}
 *         [responseBody|null, lastHttpCode, error, modelUsed]
 */
function callClaudeWithRetry(string $model, string $apiKey, array $payload): array {
    $maxAttempts  = 3;
    $errors       = [];
    $lastCode     = 0;
    $useFallbacks = array_key_exists('fallbacks', $payload);
    $payload['model'] = $model;

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $headers = [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: ' . CLAUDE_API_VERSION,
        ];
        $body = $payload;
        if ($useFallbacks) $headers[] = 'anthropic-beta: ' . CLAUDE_FALLBACK_BETA;
        else unset($body['fallbacks']);

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST,           true);
        curl_setopt($ch, CURLOPT_HTTPHEADER,     $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS,     json_encode($body, JSON_UNESCAPED_UNICODE));
        // Thinking is always on for current models, so a broadcast-length
        // reply can take a minute or two. Still well inside set_time_limit(300).
        curl_setopt($ch, CURLOPT_TIMEOUT,        150);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        $response  = curl_exec($ch);
        $httpCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);

        if ($curlError) {
            $errors[] = "cURL ({$curlErrno}): {$curlError}";
            $lastCode = 0;
            if ($attempt < $maxAttempts) { sleep(1 << ($attempt - 1)); continue; }
            break;
        }

        $json = $response ? json_decode($response, true) : null;

        if ($httpCode === 200 && is_array($json)) {
            if (($json['stop_reason'] ?? '') === 'refusal') {
                $errors[] = 'declined by the safety classifier'
                          . (!empty($json['stop_details']['explanation']) ? ': ' . $json['stop_details']['explanation'] : '');
                $lastCode = 200;
                break;   // not transient — let the caller try another writer
            }
            return [$response, 200, '', (string)($json['model'] ?? $model)];
        }

        $type    = (string)($json['error']['type'] ?? '');
        $message = (string)($json['error']['message'] ?? ($response ? substr($response, 0, 200) : 'Empty response'));
        $lastCode = $httpCode;

        // Organisation not enabled for the fallback beta: drop it and go again.
        // Does not count as an attempt; $useFallbacks=false makes this one-shot.
        if ($httpCode === 400 && $useFallbacks
            && (stripos($message, 'anthropic-beta') !== false || stripos($message, 'fallbacks') !== false)) {
            $useFallbacks = false;
            $attempt--;
            continue;
        }

        $errors[] = "HTTP {$httpCode}" . ($type !== '' ? " {$type}" : '') . ": {$message}";
        $transient = in_array($httpCode, [429, 500, 502, 503, 529], true) || $type === 'overloaded_error';
        if (!$transient) break;
        if ($attempt < $maxAttempts) sleep(1 << ($attempt - 1));
    }

    return [null, $lastCode, $model . ' — ' . implode(' · ', array_unique($errors)), ''];
}

/** The text of a Messages API response: every text block joined, thinking/fallback blocks skipped. */
function claudeResponseText(string $responseBody): string {
    $json = json_decode($responseBody, true);
    $out  = '';
    foreach ($json['content'] ?? [] as $block) {
        if (($block['type'] ?? '') === 'text') $out .= (string)($block['text'] ?? '');
    }
    return trim($out);
}
