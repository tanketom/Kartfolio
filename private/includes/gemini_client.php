<?php
/**
 * Shared Gemini API client with model fallback + retry.
 *
 * Used by every page/endpoint that calls Gemini, so transient overloads
 * (503 / 429 / UNAVAILABLE) don't take down individual features.
 *
 * Path: /cdnmk/private/includes/gemini_client.php
 */

/**
 * Call Gemini with a chain of model fallbacks and per-model retries.
 *
 * Retries on transient errors (HTTP 429, 503, or "UNAVAILABLE" in the body)
 * with exponential backoff (≈1s, 2s, 4s). Falls through to the next model
 * once a model is exhausted. Hard errors (4xx that aren't 429) skip retries
 * and move straight to the next model.
 *
 * @param string[] $modelChain Ordered list of model names; first is preferred.
 * @param string   $apiKey     Gemini API key.
 * @param array    $payload    Full request body (contents, safetySettings, etc.).
 * @return array{0: ?string, 1: int, 2: string, 3: string}
 *               [body|null, lastHttpCode, cumulativeError, modelUsed]
 */
function callGeminiWithRetry(array $modelChain, string $apiKey, array $payload): array {
    $maxRetriesPerModel = 3;
    $perModelErrors = [];   // model => final error string
    $lastCode  = 0;
    $bodyJson  = json_encode($payload);

    foreach ($modelChain as $model) {
        $apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        for ($attempt = 1; $attempt <= $maxRetriesPerModel; $attempt++) {
            $ch = curl_init($apiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST,           true);
            curl_setopt($ch, CURLOPT_HTTPHEADER,     ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POSTFIELDS,     $bodyJson);
            // Per-call cap. With up to 4 models × 3 retries and exponential
            // backoff we want this comfortably below the script's overall
            // set_time_limit so we don't blow the budget on a single hang.
            curl_setopt($ch, CURLOPT_TIMEOUT,        90);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

            $response  = curl_exec($ch);
            $httpCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            $curlErrno = curl_errno($ch);
            curl_close($ch);

            if ($curlError) {
                $perModelErrors[$model] = "cURL ({$curlErrno}): {$curlError}";
                $lastCode = 0;
                break;
            }

            if ($httpCode === 200 && $response) {
                return [$response, $httpCode, '', $model];
            }

            $isTransient = ($httpCode === 503 || $httpCode === 429)
                || ($response && stripos($response, 'UNAVAILABLE') !== false);

            $detail = $response ? substr($response, 0, 200) : 'Empty response';
            // Try to pull just the human-readable message out of Gemini's
            // JSON envelope so the cumulative error doesn't drown the user.
            if ($response && ($decoded = json_decode($response, true)) && isset($decoded['error']['message'])) {
                $detail = $decoded['error']['message'];
            }
            $perModelErrors[$model] = "HTTP {$httpCode}: {$detail}";
            $lastCode = $httpCode;

            if (!$isTransient) break;
            if ($attempt < $maxRetriesPerModel) sleep(1 << ($attempt - 1)); // 1s, 2s, 4s
        }
    }

    $combined = "All models failed:\n";
    foreach ($perModelErrors as $model => $err) {
        $combined .= "  • {$model} — {$err}\n";
    }
    return [null, $lastCode, rtrim($combined), ''];
}

/**
 * Build the default model fallback chain for a given primary model.
 *
 * If $primary is one of the known modern models, we return [primary, lite
 * sibling, previous-gen, previous-gen lite] with duplicates removed. The
 * order is "try the asked-for model first, then progressively lighter /
 * older ones drawn from different capacity pools."
 */
function geminiDefaultModelChain(string $primary): array {
    $chain = array_merge([$primary], [
        'gemini-2.5-flash-lite',
        'gemini-2.0-flash',
        'gemini-2.0-flash-lite',
    ]);
    return array_values(array_unique(array_filter($chain)));
}
