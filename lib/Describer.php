<?php
declare(strict_types=1);

require_once __DIR__ . '/util.php';

/**
 * What every AI backend does for Photo Finder: describe one photo.
 *
 * Backends (Settings > "How photos are described"):
 *   anthropic  Anthropic's Claude API with the user's own API key        (lib/Claude.php)
 *   openai     any OpenAI-compatible cloud service with the user's key   (lib/OpenAICompat.php)
 *   local      an OpenAI-compatible server on THIS PC (Ollama, LM Studio) (lib/OpenAICompat.php)
 *
 * The app ships no keys of its own: every backend uses what the user enters in Settings.
 */
interface PhotoDescriber {
    /** @return array{description:string,tags:string[],text_in_image:string} */
    public function analyzeImage(string $jpegBytes): array;

    /** Stored with each photo so a later switch of model can re-describe only what changed. */
    public function label(): string;
}

final class Describer {
    public const PROMPT = <<<TXT
        You are an image cataloging assistant building a searchable photo library.
        For each image, return ONLY a JSON object with these keys:
        - "description": one or two sentences (max ~200 chars) factually describing the scene, subjects, setting, colors, mood, and notable details. Be specific and concrete.
        - "tags": array of 8-20 lowercase single-word or short-phrase keywords for search (objects, scene, colors, activity, time-of-day, season, weather, emotions, style). Include synonyms a person might search.
        - "text_in_image": any readable text visible in the image (signs, labels, screenshots, captions). Empty string if none.
        Do not include people's identities, just descriptive labels (e.g. "man with beard", "two children", "elderly woman").
        Return strictly valid JSON. No markdown fences. No prose outside the JSON.
        TXT;

    public const USER_TEXT = 'Catalog this image. Return JSON only.';

    /** JSON Schema of the answer (used where the backend can enforce it). */
    public static function schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'description' => ['type' => 'string'],
                'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                'text_in_image' => ['type' => 'string'],
            ],
            'required' => ['description', 'tags', 'text_in_image'],
            'additionalProperties' => false,
        ];
    }

    /** Backend chosen in Settings, with names from earlier versions mapped forward. */
    public static function backendOf(array $settings): string {
        $b = (string)($settings['backend'] ?? '');
        if ($b === 'api') return 'anthropic';          // 1.0.0 - 1.0.2 name
        return in_array($b, ['anthropic', 'openai', 'local'], true) ? $b : '';
    }

    /**
     * The describer for the current settings, or a message saying what is missing.
     * @return array{0:?PhotoDescriber,1:string}
     */
    public static function fromSettings(array $s): array {
        require_once __DIR__ . '/Claude.php';
        require_once __DIR__ . '/OpenAICompat.php';
        switch (self::backendOf($s)) {
            case 'anthropic':
                $key = trim((string)($s['api_key'] ?? ''));
                if ($key === '') return [null, 'Enter your Anthropic API key in Settings.'];
                return [new Claude($key, (string)($s['model'] ?? Claude::DEFAULT_MODEL)), ''];
            case 'openai':
                $url = trim((string)($s['openai_base_url'] ?? ''));
                $model = trim((string)($s['openai_model'] ?? ''));
                if ($url === '' || $model === '') return [null, 'Enter the service address and model name in Settings.'];
                return [new OpenAICompat($url, (string)($s['openai_api_key'] ?? ''), $model, false), ''];
            case 'local':
                $url = trim((string)($s['local_base_url'] ?? ''));
                $model = trim((string)($s['local_model'] ?? ''));
                if ($url === '' || $model === '') return [null, 'Choose the local model in Settings.'];
                if (!OpenAICompat::isLoopbackUrl($url)) {
                    return [null, 'The local option only accepts an address on this PC (localhost / 127.0.0.1).'];
                }
                return [new OpenAICompat($url, '', $model, true), ''];
            default:
                return [null, 'Open Settings and pick Anthropic Claude, another AI service, or a local model on this PC.'];
        }
    }

    /**
     * Turn a model's text answer into the analysis array. Tolerates markdown fences,
     * prose around the object and trailing commas, which local models often add.
     * @return array{description:string,tags:string[],text_in_image:string}
     */
    public static function parse(string $text): array {
        $t = trim($text);
        $t = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $t) ?? $t;
        $obj = json_decode($t, true);
        if (!is_array($obj)) {
            $start = strpos($t, '{');
            $end = strrpos($t, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $slice = substr($t, $start, $end - $start + 1);
                $obj = json_decode($slice, true);
                if (!is_array($obj)) {
                    $obj = json_decode(preg_replace('/,\s*([}\]])/', '$1', $slice) ?? $slice, true);
                }
            }
        }
        if (!is_array($obj)) {
            throw new RuntimeException('The AI did not return JSON: ' . mb_substr($text, 0, 160));
        }
        $tags = $obj['tags'] ?? [];
        if (is_string($tags)) $tags = preg_split('/\s*,\s*/', $tags) ?: [];
        if (!is_array($tags)) $tags = [];
        $tags = array_values(array_filter(array_map(fn($x) => strtolower(trim((string)$x)), $tags), fn($x) => $x !== ''));
        $textIn = $obj['text_in_image'] ?? '';
        if (is_array($textIn)) $textIn = implode("\n", array_map('strval', $textIn));
        return [
            'description' => trim((string)($obj['description'] ?? '')),
            'tags' => $tags,
            'text_in_image' => trim((string)$textIn),
        ];
    }

    /** A small generated test photo, for Settings > "Test". */
    public static function testImage(): string {
        $im = imagecreatetruecolor(320, 200);
        imagefill($im, 0, 0, imagecolorallocate($im, 135, 190, 235));               // sky
        imagefilledrectangle($im, 0, 140, 319, 199, imagecolorallocate($im, 60, 150, 70)); // grass
        imagefilledellipse($im, 260, 50, 60, 60, imagecolorallocate($im, 250, 200, 40));  // sun
        imagefilledrectangle($im, 60, 90, 150, 160, imagecolorallocate($im, 180, 60, 50)); // house
        imagefilledpolygon($im, [50, 90, 105, 50, 160, 90], imagecolorallocate($im, 90, 50, 40)); // roof
        imagestring($im, 5, 70, 170, 'HELLO', imagecolorallocate($im, 255, 255, 255));
        ob_start();
        imagejpeg($im, null, 85);
        return (string)ob_get_clean();
    }
}
