<?php
declare(strict_types=1);

require_once __DIR__ . '/util.php';
require_once __DIR__ . '/Describer.php';

/**
 * Any service that speaks the OpenAI "chat completions" API with image input: cloud services
 * (OpenAI, Google Gemini's OpenAI-compatible endpoint, OpenRouter, Azure OpenAI...) with the
 * user's own key, or a server on this PC (Ollama, LM Studio) for the local-only option.
 * One photo per request. JSON is requested in the prompt rather than with response_format,
 * which not every compatible server accepts; Describer::parse() tolerates the usual extras.
 */
final class OpenAICompat implements PhotoDescriber {
    public function __construct(
        private string $baseUrl,
        private string $apiKey,
        private string $model,
        private bool $local,
    ) {
        $this->baseUrl = rtrim(trim($this->baseUrl), '/');
    }

    public function label(): string { return ($this->local ? 'local:' : 'openai:') . $this->model; }

    /** True only for http(s) addresses on this PC; the local option refuses anything else. */
    public static function isLoopbackUrl(string $url): bool {
        $p = parse_url(trim($url));
        if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true)) return false;
        $host = strtolower(trim((string)($p['host'] ?? ''), '[]'));
        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }

    public function analyzeImage(string $jpegBytes, array $people = []): array {
        $payload = [
            'model' => $this->model,
            'max_tokens' => 1000,
            'messages' => [
                ['role' => 'system', 'content' => Describer::PROMPT],
                ['role' => 'user', 'content' => [
                    ['type' => 'text', 'text' => Describer::userText($people)],
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,' . base64_encode($jpegBytes)]],
                ]],
            ],
        ];
        [$status, $body] = $this->request('POST', '/chat/completions', $payload, $this->local ? 600 : 180);
        $decoded = json_decode($body, true);
        if ($status !== 200) {
            $msg = is_array($decoded) ? (string)($decoded['error']['message'] ?? $decoded['error'] ?? '') : '';
            if ($msg === '') $msg = substr($body, 0, 300);
            if ($status === 401 || $status === 403) $msg = 'the API key was rejected (check it in Settings)';
            if ($status === 404) $msg .= ' (check the address and the model name in Settings)';
            throw new RuntimeException(($this->local ? 'Local model' : 'AI service') . " HTTP {$status}: {$msg}");
        }
        $text = $decoded['choices'][0]['message']['content'] ?? null;
        if (is_array($text)) {   // some servers return content parts
            $text = implode('', array_map(fn($p) => is_array($p) ? (string)($p['text'] ?? '') : (string)$p, $text));
        }
        if (!is_string($text) || trim($text) === '') {
            $finish = (string)($decoded['choices'][0]['finish_reason'] ?? '');
            throw new RuntimeException('The model returned no description' . ($finish !== '' ? " ({$finish})" : '') . '.');
        }
        return Describer::parse($text);
    }

    /** Model names the server offers (GET /models), for the Settings picker. */
    public function listModels(): array {
        [$status, $body] = $this->request('GET', '/models', null, 20);
        if ($status !== 200) throw new RuntimeException("Could not list models (HTTP {$status}).");
        $decoded = json_decode($body, true);
        $ids = [];
        foreach ((array)($decoded['data'] ?? []) as $m) {
            if (isset($m['id'])) $ids[] = (string)$m['id'];
        }
        sort($ids, SORT_NATURAL | SORT_FLAG_CASE);
        return $ids;
    }

    /** @return array{0:int,1:string} */
    private function request(string $method, string $path, ?array $payload, int $timeout): array {
        if (!preg_match('#^https?://#i', $this->baseUrl)) throw new RuntimeException('The service address must start with http:// or https://');
        if ($this->local && !self::isLoopbackUrl($this->baseUrl)) throw new RuntimeException('The local option only accepts an address on this PC.');
        $ch = curl_init($this->baseUrl . $path);
        $headers = ['Accept: application/json'];
        if ($payload !== null) $headers[] = 'Content-Type: application/json';
        if (trim($this->apiKey) !== '') $headers[] = 'Authorization: Bearer ' . trim($this->apiKey);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $this->local ? 5 : 15,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
        if (stripos($this->baseUrl, 'https://') === 0) pf_curl_tls($ch);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            throw new RuntimeException($this->local
                ? "Could not reach the local model server at {$this->baseUrl} ({$err}). Is Ollama or LM Studio running?"
                : "Could not reach {$this->baseUrl} ({$err}).");
        }
        return [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), (string)$body];
    }
}
