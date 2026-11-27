<?php
declare(strict_types=1);

require_once __DIR__ . '/util.php';
require_once __DIR__ . '/Describer.php';

/**
 * Anthropic Messages API, with the user's own API key (Settings). One photo per request.
 * Structured output guarantees the JSON shape; low effort keeps thinking (on by default on
 * current models) short for a simple description task.
 */
final class Claude implements PhotoDescriber {
    public const DEFAULT_MODEL = 'claude-haiku-5-5';
    /** Offered in Settings: label => model id. */
    public const MODELS = [
        'claude-haiku-5-5' => 'Claude Haiku 5.5 (fast, lowest cost; recommended)',
        'claude-sonnet-5-5' => 'Claude Sonnet 5.5 (more detail, about 20x the cost)',
        'claude-opus-5-5' => 'Claude Opus 5.5 (most capable, about 40x the cost)',
    ];
    private const API_VERSION = '2026-10-21';

    /** ANTHROPIC_BASE_URL (the variable Anthropic's own tools honour) can point at a gateway or proxy. */
    private static function endpoint(): string {
        $base = getenv('ANTHROPIC_BASE_URL');
        $base = is_string($base) && trim($base) !== '' ? rtrim(trim($base), '/') : 'https://api.anthropic.com';
        return $base . '/v1/messages';
    }

    public function __construct(
        private string $apiKey,
        private string $model,
    ) {
        if ($this->model === '' || $this->model === 'claude-haiku-4-5-20251001') $this->model = self::DEFAULT_MODEL;
    }

    public function label(): string { return 'anthropic:' . $this->model; }

    public function analyzeImage(string $jpegBytes): array {
        $payload = [
            'model' => $this->model,
            'max_tokens' => 4000,
            'system' => Describer::PROMPT,
            'output_config' => [
                'effort' => 'low',
                'format' => ['type' => 'json_schema', 'schema' => Describer::schema()],
            ],
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'image', 'source' => [
                        'type' => 'base64', 'media_type' => 'image/jpeg', 'data' => base64_encode($jpegBytes),
                    ]],
                    ['type' => 'text', 'text' => Describer::USER_TEXT],
                ],
            ]],
        ];
        $headers = [];
        // Sonnet 5.5 / Opus 5.5 can hand a declined request to another model server-side.
        if (str_starts_with($this->model, 'claude-sonnet-5-5') || str_starts_with($this->model, 'claude-opus-5-5')) {
            $payload['fallbacks'] = 'default';
            $headers[] = 'anthropic-beta: server-side-fallback-2026-07-01';
        }

        [$status, $body] = $this->postJson($payload, $headers);
        $decoded = json_decode($body, true);
        if ($status !== 200) {
            $msg = is_array($decoded) && isset($decoded['error']['message']) ? $decoded['error']['message'] : substr($body, 0, 300);
            if ($status === 401) $msg = 'the Anthropic API key was rejected (check it in Settings)';
            throw new RuntimeException("Anthropic API HTTP {$status}: {$msg}");
        }
        if (!is_array($decoded)) throw new RuntimeException('Anthropic API returned an unreadable response.');

        $stop = (string)($decoded['stop_reason'] ?? '');
        if ($stop === 'refusal') {
            $cat = (string)($decoded['stop_details']['category'] ?? '');
            throw new RuntimeException('Claude declined to describe this photo' . ($cat !== '' ? " ({$cat})" : '') . '.');
        }
        if ($stop === 'max_tokens') throw new RuntimeException('Claude\'s answer was cut off (max_tokens).');

        $text = '';
        foreach ((array)($decoded['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') $text .= (string)($block['text'] ?? '');
        }
        if (trim($text) === '') throw new RuntimeException('Claude returned no description.');
        return Describer::parse($text);
    }

    /** One request with any content blocks (text and images), answered as JSON in $schema's shape
     *  (structured output). Used by Fix blur / Enlarge's batch to judge a photo's versions. */
    public function structured(string $system, array $content, array $schema, string $effort = 'low', int $maxTokens = 4000): array {
        $payload = [
            'model' => $this->model,
            'max_tokens' => $maxTokens,
            'system' => $system,
            'output_config' => ['effort' => $effort, 'format' => ['type' => 'json_schema', 'schema' => $schema]],
            'messages' => [['role' => 'user', 'content' => $content]],
        ];
        $headers = [];
        if (str_starts_with($this->model, 'claude-sonnet-5-5') || str_starts_with($this->model, 'claude-opus-5-5')) {
            $payload['fallbacks'] = 'default';
            $headers[] = 'anthropic-beta: server-side-fallback-2026-07-01';
        }
        [$status, $body] = $this->postJson($payload, $headers);
        $decoded = json_decode($body, true);
        if ($status !== 200) {
            $msg = is_array($decoded) && isset($decoded['error']['message']) ? $decoded['error']['message'] : substr($body, 0, 300);
            if ($status === 401) $msg = 'the Anthropic API key was rejected (check it in Settings)';
            throw new RuntimeException("Anthropic API HTTP {$status}: {$msg}");
        }
        if (!is_array($decoded)) throw new RuntimeException('Anthropic API returned an unreadable response.');
        $stop = (string)($decoded['stop_reason'] ?? '');
        if ($stop === 'refusal') throw new RuntimeException('Claude declined this request.');
        if ($stop === 'max_tokens') throw new RuntimeException('Claude\'s answer was cut off (max_tokens).');
        $text = '';
        foreach ((array)($decoded['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') $text .= (string)($block['text'] ?? '');
        }
        $out = json_decode(trim($text), true);
        if (!is_array($out)) throw new RuntimeException('Claude returned an answer that is not JSON.');
        return $out;
    }

    /** @return array{0:int,1:string} */
    private function postJson(array $payload, array $extraHeaders): array {
        $ch = curl_init(self::endpoint());
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => array_merge([
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: ' . self::API_VERSION,
            ], $extraHeaders),
            CURLOPT_TIMEOUT => 180,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        pf_curl_tls($ch);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            throw new RuntimeException('Could not reach the Anthropic API: ' . $err);
        }
        return [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), (string)$body];
    }
}
