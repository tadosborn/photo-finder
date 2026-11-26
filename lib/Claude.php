<?php
declare(strict_types=1);

require_once __DIR__ . '/util.php';

final class Claude {
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';
    private const SYSTEM_PROMPT = <<<TXT
        You are an image cataloging assistant building a searchable photo library.
        For each image, return ONLY a JSON object with these keys:
        - "description": one or two sentences (max ~200 chars) factually describing the scene, subjects, setting, colors, mood, and notable details. Be specific and concrete.
        - "tags": array of 8-20 lowercase single-word or short-phrase keywords for search (objects, scene, colors, activity, time-of-day, season, weather, emotions, style). Include synonyms a person might search.
        - "text_in_image": any readable text visible in the image (signs, labels, screenshots, captions). Empty string if none.
        Do not include people's identities, just descriptive labels (e.g. "man with beard", "two children", "elderly woman").
        Return strictly valid JSON. No markdown fences. No prose outside the JSON.
        TXT;

    public function __construct(
        private string $apiKey,
        private string $model,
    ) {}

    public function analyzeImage(string $jpegBytes): array {
        $payload = [
            'model' => $this->model,
            'max_tokens' => 700,
            'system' => [[
                'type' => 'text',
                'text' => self::SYSTEM_PROMPT,
                'cache_control' => ['type' => 'ephemeral'],
            ]],
            'messages' => [[
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'image',
                        'source' => [
                            'type' => 'base64',
                            'media_type' => 'image/jpeg',
                            'data' => base64_encode($jpegBytes),
                        ],
                    ],
                    [
                        'type' => 'text',
                        'text' => 'Catalog this image. Return JSON only.',
                    ],
                ],
            ]],
        ];

        [$status, $body] = $this->postJson($payload);
        if ($status !== 200) {
            $errMsg = 'HTTP ' . $status;
            $decoded = json_decode($body, true);
            if (isset($decoded['error']['message'])) {
                $errMsg .= ': ' . $decoded['error']['message'];
            } else {
                $errMsg .= ': ' . substr($body, 0, 300);
            }
            throw new RuntimeException($errMsg);
        }
        $decoded = json_decode($body, true);
        if (!isset($decoded['content'][0]['text'])) {
            throw new RuntimeException('Unexpected response shape');
        }
        $text = trim($decoded['content'][0]['text']);
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text) ?? $text;
        $parsed = json_decode($text, true);
        if (!is_array($parsed)) {
            $brace = strpos($text, '{');
            $end = strrpos($text, '}');
            if ($brace !== false && $end !== false && $end > $brace) {
                $parsed = json_decode(substr($text, $brace, $end - $brace + 1), true);
            }
        }
        if (!is_array($parsed)) {
            throw new RuntimeException('Could not parse JSON from model output: ' . substr($text, 0, 200));
        }
        return [
            'description' => (string)($parsed['description'] ?? ''),
            'tags' => array_values(array_filter(
                (array)($parsed['tags'] ?? []),
                fn($t) => is_string($t) && trim($t) !== ''
            )),
            'text_in_image' => (string)($parsed['text_in_image'] ?? ''),
            'usage' => $decoded['usage'] ?? [],
        ];
    }

    private function postJson(array $payload): array {
        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: ' . self::API_VERSION,
            ],
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        pf_curl_tls($ch);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('cURL error: ' . $err);
        }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [(int)$status, (string)$body];
    }
}
