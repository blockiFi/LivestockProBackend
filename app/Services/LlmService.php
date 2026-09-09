<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class LlmService
{
    protected ?string $lastError = null;

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    protected function setLastError(?string $msg): void
    {
        $this->lastError = $msg;
    }

    public function chat(string $systemPrompt, string $userPrompt): ?string
    {
        $this->setLastError(null);
        $provider = config('llm.provider', 'openai');

        if ($provider !== 'openai') {
            // For now, only OpenAI-style APIs are supported
            $this->setLastError('Unsupported LLM provider: ' . $provider);
            return null;
        }

        $config = config('llm.openai');
        $apiKey = $config['api_key'] ?? null;
        $baseUrl = $config['base_url'] ?? 'https://api.openai.com';
        $model = $config['model'] ?? 'gpt-4o';

        if (!$apiKey) {
            $this->setLastError('Missing LLM API key (AI_API_KEY/LLM_API_KEY)');
            return null;
        }

        try {
            $response = Http::withToken($apiKey)
                ->timeout($config['timeout'] ?? 30)
                ->post("{$baseUrl}/v1/chat/completions", [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                    'temperature' => 0.3,
                ]);

            if (!$response->ok()) {
                $this->setLastError('LLM HTTP error ' . $response->status() . ': ' . substr($response->body() ?? '', 0, 500));
                return null;
            }

            $data = $response->json();
            $content = $data['choices'][0]['message']['content'] ?? null;
            if (!$content) {
                $this->setLastError('LLM response missing choices[0].message.content');
            }
            return $content ?: null;
        } catch (\Throwable $e) {
            // In case of any HTTP/JSON error, fall back silently
            $this->setLastError('LLM exception: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Multi-turn chat with optional OpenAI-style tools.
     *
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>  $tools
     * @param  array{temperature?: float, tool_choice?: string|array}  $options
     * @return array{content: ?string, tool_calls: list<array{id: string, name: string, arguments: array}>, raw: ?array}|null
     */
    public function chatWithTools(array $messages, array $tools = [], array $options = []): ?array
    {
        $this->setLastError(null);
        $provider = config('llm.provider', 'openai');

        if ($provider !== 'openai') {
            $this->setLastError('Unsupported LLM provider: '.$provider);

            return null;
        }

        $config = config('llm.openai');
        $apiKey = $config['api_key'] ?? null;
        $baseUrl = $config['base_url'] ?? 'https://api.openai.com';
        $model = $config['model'] ?? 'gpt-4o';

        if (! $apiKey) {
            $this->setLastError('Missing LLM API key (AI_API_KEY/LLM_API_KEY)');

            return null;
        }

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => $options['temperature'] ?? 0.3,
        ];

        if ($tools !== []) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = $options['tool_choice'] ?? 'auto';
        }

        try {
            $response = Http::withToken($apiKey)
                ->timeout($config['timeout'] ?? 120)
                ->post("{$baseUrl}/v1/chat/completions", $payload);

            if (! $response->ok()) {
                $this->setLastError('LLM HTTP error '.$response->status().': '.substr($response->body() ?? '', 0, 500));

                return null;
            }

            $data = $response->json();
            $message = $data['choices'][0]['message'] ?? null;
            if (! is_array($message)) {
                $this->setLastError('LLM response missing choices[0].message');

                return null;
            }

            $toolCalls = [];
            foreach ($message['tool_calls'] ?? [] as $call) {
                if (! is_array($call)) {
                    continue;
                }
                $fn = $call['function'] ?? [];
                $argsRaw = $fn['arguments'] ?? '{}';
                $args = is_string($argsRaw) ? json_decode($argsRaw, true) : $argsRaw;
                if (! is_array($args)) {
                    $args = [];
                }
                $toolCalls[] = [
                    'id' => (string) ($call['id'] ?? uniqid('call_', true)),
                    'name' => (string) ($fn['name'] ?? ''),
                    'arguments' => $args,
                ];
            }

            $content = $message['content'] ?? null;

            return [
                'content' => is_string($content) ? $content : null,
                'tool_calls' => array_values(array_filter($toolCalls, fn ($c) => $c['name'] !== '')),
                'raw' => $message,
            ];
        } catch (\Throwable $e) {
            $this->setLastError('LLM exception: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Vision-capable chat: sends a single image (as base64 data URL) + text prompt.
     *
     * @param string $mime   e.g. image/png
     * @param string $base64 base64-encoded image bytes (no data: prefix)
     */
    public function visionChat(string $systemPrompt, string $userPrompt, string $mime, string $base64): ?string
    {
        return $this->visionChatMany($systemPrompt, $userPrompt, [
            ['mime' => $mime, 'base64' => $base64],
        ]);
    }

    /**
     * Vision-capable chat: sends many images + text prompt.
     *
     * @param array<int,array{mime:string,base64:string}> $images
     */
    /**
     * @param  list<array{mime?: string, base64?: string}>  $images
     * @param  array{json?: bool}  $options
     */
    public function visionChatMany(string $systemPrompt, string $userPrompt, array $images, array $options = []): ?string
    {
        $this->setLastError(null);
        $provider = config('llm.provider', 'openai');

        if ($provider !== 'openai') {
            $this->setLastError('Unsupported LLM provider: ' . $provider);
            return null;
        }

        $config = config('llm.openai');
        $apiKey = $config['api_key'] ?? null;
        $baseUrl = $config['base_url'] ?? 'https://api.openai.com';
        $model = $config['model'] ?? 'gpt-4o';

        if (!$apiKey) {
            $this->setLastError('Missing LLM API key (AI_API_KEY/LLM_API_KEY)');
            return null;
        }

        $content = [
            ['type' => 'text', 'text' => $userPrompt],
        ];
        foreach ($images as $img) {
            $mime = $img['mime'] ?? 'image/png';
            $base64 = $img['base64'] ?? null;
            if (!$base64) continue;
            $dataUrl = "data:{$mime};base64,{$base64}";
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]];
        }

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                [
                    'role' => 'user',
                    'content' => $content,
                ],
            ],
            'temperature' => 0.2,
        ];

        // Force a JSON object when callers need structured extraction.
        if (!empty($options['json'])) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        try {
            $response = Http::withToken($apiKey)
                ->timeout($config['timeout'] ?? 30)
                ->post("{$baseUrl}/v1/chat/completions", $payload);

            if (!$response->ok()) {
                $this->setLastError('LLM HTTP error ' . $response->status() . ': ' . substr($response->body() ?? '', 0, 500));
                return null;
            }

            $data = $response->json();
            $content = $data['choices'][0]['message']['content'] ?? null;
            if (!$content) {
                $this->setLastError('LLM response missing choices[0].message.content');
            }
            return $content ?: null;
        } catch (\Throwable $e) {
            $this->setLastError('LLM exception: ' . $e->getMessage());
            return null;
        }
    }
}

