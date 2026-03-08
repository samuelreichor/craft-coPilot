<?php

namespace samuelreichor\coPilot\providers;

use Craft;
use craft\helpers\App;
use samuelreichor\coPilot\CoPilot;
use samuelreichor\coPilot\helpers\HttpClientFactory;
use samuelreichor\coPilot\helpers\Logger;
use samuelreichor\coPilot\models\AIResponse;
use samuelreichor\coPilot\models\StreamChunk;

class OpenAIProvider implements ProviderInterface
{
    private const API_URL = 'https://api.openai.com/v1/chat/completions';

    public function chat(
        string $systemPrompt,
        array $messages,
        array $tools,
        ?string $model = null,
    ): AIResponse {
        $settings = CoPilot::getInstance()->getSettings();
        $apiKey = App::parseEnv($settings->openaiApiKeyEnvVar);

        if (!$apiKey) {
            return AIResponse::error('OpenAI API key not configured. Set the environment variable ' . $settings->openaiApiKeyEnvVar);
        }

        $model = $model ?? $settings->openaiModel;

        $payload = [
            'model' => $model,
            'messages' => $this->formatMessages($systemPrompt, $messages),
        ];

        $formattedTools = ToolFormatter::forOpenAI($tools);
        if (!empty($formattedTools)) {
            $payload['tools'] = $formattedTools;
        }

        Logger::info("OpenAI API request: model={$model}");

        return $this->sendRequest($apiKey, $payload);
    }

    public function chatStream(
        string $systemPrompt,
        array $messages,
        array $tools,
        ?string $model,
        callable $onChunk,
    ): void {
        $settings = CoPilot::getInstance()->getSettings();
        $apiKey = App::parseEnv($settings->openaiApiKeyEnvVar);

        if (!$apiKey) {
            $onChunk(new StreamChunk('error', error: 'OpenAI API key not configured.'));
            return;
        }

        $model = $model ?? $settings->openaiModel;

        $payload = [
            'model' => $model,
            'messages' => $this->formatMessages($systemPrompt, $messages),
            'stream' => true,
            'stream_options' => ['include_usage' => true],
        ];

        $formattedTools = ToolFormatter::forOpenAI($tools);
        if (!empty($formattedTools)) {
            $payload['tools'] = $formattedTools;
        }

        Logger::info("OpenAI API stream request: model={$model}");

        $this->sendStreamRequest($apiKey, $payload, $onChunk);
    }

    public function getAvailableModels(): array
    {
        return [
            'gpt-5.1',
            'gpt-5-mini',
            'gpt-5-nano',
            'gpt-4.1',
            'gpt-4.1-mini',
            'gpt-4.1-nano',
            'gpt-4o',
            'o3',
            'o4-mini',
        ];
    }

    public function getName(): string
    {
        return 'OpenAI';
    }

    public function getIcon(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M22.282 9.821a5.985 5.985 0 0 0-.516-4.91 6.046 6.046 0 0 0-6.51-2.9A6.065 6.065 0 0 0 4.981 4.18a5.985 5.985 0 0 0-3.998 2.9 6.046 6.046 0 0 0 .743 7.097 5.98 5.98 0 0 0 .51 4.911 6.051 6.051 0 0 0 6.515 2.9A5.985 5.985 0 0 0 13.26 24a6.056 6.056 0 0 0 5.772-4.206 5.99 5.99 0 0 0 3.997-2.9 6.056 6.056 0 0 0-.747-7.073zM13.26 22.43a4.476 4.476 0 0 1-2.876-1.04l.141-.081 4.779-2.758a.795.795 0 0 0 .392-.681v-6.737l2.02 1.168a.071.071 0 0 1 .038.052v5.583a4.504 4.504 0 0 1-4.494 4.494zM3.6 18.304a4.47 4.47 0 0 1-.535-3.014l.142.085 4.783 2.759a.771.771 0 0 0 .78 0l5.843-3.369v2.332a.08.08 0 0 1-.033.062L9.74 19.95a4.5 4.5 0 0 1-6.14-1.646zM2.34 7.896a4.485 4.485 0 0 1 2.366-1.973V11.6a.766.766 0 0 0 .388.676l5.815 3.355-2.02 1.168a.076.076 0 0 1-.071 0l-4.83-2.786A4.504 4.504 0 0 1 2.34 7.872zm16.597 3.855l-5.833-3.387L15.119 7.2a.076.076 0 0 1 .071 0l4.83 2.791a4.494 4.494 0 0 1-.676 8.105v-5.678a.79.79 0 0 0-.407-.667zm2.01-3.023l-.141-.085-4.774-2.782a.776.776 0 0 0-.785 0L9.409 9.23V6.897a.066.066 0 0 1 .028-.061l4.83-2.787a4.5 4.5 0 0 1 6.68 4.66zm-12.64 4.135l-2.02-1.164a.08.08 0 0 1-.038-.057V6.075a4.5 4.5 0 0 1 7.375-3.453l-.142.08L8.704 5.46a.795.795 0 0 0-.393.681zm1.097-2.365l2.602-1.5 2.607 1.5v2.999l-2.597 1.5-2.607-1.5z"/></svg>';
    }

    public function validateApiKey(string $key): bool
    {
        $client = Craft::createGuzzleClient();

        try {
            $response = $client->get('https://api.openai.com/v1/models', [
                'headers' => [
                    'Authorization' => "Bearer {$key}",
                ],
                'timeout' => 10,
            ]);

            return $response->getStatusCode() === 200;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @return array<int, array<string, mixed>>
     */
    private function formatMessages(string $systemPrompt, array $messages): array
    {
        $formatted = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];

        foreach ($messages as $message) {
            $role = $message['role'];

            if ($role === 'tool') {
                $formatted[] = [
                    'role' => 'tool',
                    'tool_call_id' => $message['toolCallId'],
                    'content' => is_array($message['content'])
                        ? json_encode($message['content'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                        : $message['content'],
                ];
                continue;
            }

            if ($role === 'assistant' && !empty($message['toolCalls'])) {
                $formatted[] = [
                    'role' => 'assistant',
                    'content' => $message['content'] ?? null,
                    'tool_calls' => array_map(fn(array $tc) => [
                        'id' => $tc['id'],
                        'type' => 'function',
                        'function' => [
                            'name' => $tc['name'],
                            'arguments' => json_encode($tc['arguments'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        ],
                    ], $message['toolCalls']),
                ];
                continue;
            }

            $formatted[] = [
                'role' => $role,
                'content' => is_array($message['content'])
                    ? json_encode($message['content'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    : $message['content'],
            ];
        }

        return $formatted;
    }

    private function sendRequest(string $apiKey, array $payload): AIResponse
    {
        $client = HttpClientFactory::create();

        try {
            $response = $client->post(self::API_URL, [
                'headers' => [
                    'Authorization' => "Bearer {$apiKey}",
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
                'timeout' => 120,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return $this->parseResponse($data);
        } catch (\Throwable $e) {
            Logger::error('OpenAI API error: ' . $e->getMessage());

            return AIResponse::error('OpenAI API error: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function parseResponse(array $data): AIResponse
    {
        $inputTokens = $data['usage']['prompt_tokens'] ?? 0;
        $outputTokens = $data['usage']['completion_tokens'] ?? 0;
        $choice = $data['choices'][0] ?? null;

        if (!$choice) {
            return AIResponse::error('No response from OpenAI.', $inputTokens, $outputTokens);
        }

        $message = $choice['message'];
        $type = !empty($message['tool_calls']) ? 'tool_call' : 'text';
        $finishReason = $choice['finish_reason'] ?? 'unknown';

        Logger::info("OpenAI API response: type={$type}, finish_reason={$finishReason}, inputTokens={$inputTokens}, outputTokens={$outputTokens}");

        if (empty($message['content']) && empty($message['tool_calls'])) {
            Logger::warning('OpenAI API returned empty response: finish_reason=' . $finishReason);
        }

        if (!empty($message['tool_calls'])) {
            $toolCalls = array_map(fn(array $tc) => [
                'id' => $tc['id'],
                'name' => $tc['function']['name'],
                'arguments' => json_decode(self::fixBrokenUnicodeEscapes($tc['function']['arguments']), true) ?? [],
            ], $message['tool_calls']);

            return AIResponse::toolCall($toolCalls, $message['content'] ?? null, $inputTokens, $outputTokens);
        }

        return AIResponse::text($message['content'] ?? '', $inputTokens, $outputTokens);
    }

    /**
     * @param callable(StreamChunk): void $onChunk
     */
    private function sendStreamRequest(string $apiKey, array $payload, callable $onChunk): void
    {
        $client = HttpClientFactory::create();
        $buffer = '';
        /** @var array<int, array{id: string, name: string, arguments: string}> $toolCalls */
        $toolCalls = [];
        $hasTextContent = false;
        $finishReason = 'unknown';
        $chunksProcessed = 0;

        // Shared line processor for both streaming and buffer flush
        $processLine = function(string $line) use (&$toolCalls, &$hasTextContent, &$finishReason, &$chunksProcessed, $onChunk): void {
            $line = trim($line);
            if ($line === '' || $line === 'data: [DONE]') {
                return;
            }

            if (!str_starts_with($line, 'data: ')) {
                return;
            }

            $json = json_decode(substr($line, 6), true);
            if (!is_array($json)) {
                return;
            }

            $chunksProcessed++;

            $chunkFinishReason = $json['choices'][0]['finish_reason'] ?? null;
            if ($chunkFinishReason !== null) {
                $finishReason = $chunkFinishReason;
            }

            $delta = $json['choices'][0]['delta'] ?? [];
            if (isset($delta['content']) && $delta['content'] !== '') {
                $hasTextContent = true;
            }

            $this->processStreamChunk($json, $toolCalls, $onChunk);
        };

        try {
            $client->post(self::API_URL, [
                'headers' => [
                    'Authorization' => "Bearer {$apiKey}",
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode($payload),
                'timeout' => 120,
                'curl' => [
                    CURLOPT_WRITEFUNCTION => function($ch, $data) use (&$buffer, $processLine) {
                        $buffer .= $data;
                        $lines = explode("\n", $buffer);
                        $buffer = (string)array_pop($lines);

                        foreach ($lines as $line) {
                            $processLine($line);
                        }

                        return strlen($data);
                    },
                ],
            ]);

            // Flush any remaining buffer data
            if (trim($buffer) !== '') {
                Logger::warning('OpenAI stream: flushing unparsed buffer remainder (' . strlen($buffer) . ' bytes)');
                $processLine($buffer);
                $buffer = '';
            }

            $hasText = $hasTextContent ? 'true' : 'false';

            Logger::info("OpenAI stream complete: finish_reason={$finishReason}, hasText={$hasText}, toolCalls=" . count($toolCalls) . ", chunks={$chunksProcessed}");

            if (!$hasTextContent && empty($toolCalls)) {
                Logger::warning("OpenAI stream returned no text and no tool calls: finish_reason={$finishReason}, chunks={$chunksProcessed}");
            }

            // Emit accumulated tool calls after stream completes
            foreach ($toolCalls as $tc) {
                $onChunk(new StreamChunk(
                    'tool_call',
                    toolCallId: $tc['id'],
                    toolName: $tc['name'],
                    toolArguments: json_decode(self::fixBrokenUnicodeEscapes($tc['arguments']), true) ?? [],
                ));
            }
        } catch (\Throwable $e) {
            Logger::error('OpenAI stream error: ' . $e->getMessage());
            $onChunk(new StreamChunk('error', error: 'OpenAI stream error: ' . $e->getMessage()));
        }
    }

    /**
     * @param array<string, mixed> $json
     * @param array<int, array{id: string, name: string, arguments: string}> &$toolCalls
     * @param callable(StreamChunk): void $onChunk
     */
    private function processStreamChunk(array $json, array &$toolCalls, callable $onChunk): void
    {
        if (isset($json['usage'])) {
            $onChunk(new StreamChunk(
                'usage',
                inputTokens: $json['usage']['prompt_tokens'] ?? 0,
                outputTokens: $json['usage']['completion_tokens'] ?? 0,
            ));
        }

        $delta = $json['choices'][0]['delta'] ?? null;
        if (!$delta) {
            return;
        }

        if (isset($delta['content']) && $delta['content'] !== '') {
            $onChunk(new StreamChunk('text_delta', delta: $delta['content']));
        }

        if (isset($delta['tool_calls'])) {
            foreach ($delta['tool_calls'] as $tcDelta) {
                $index = $tcDelta['index'] ?? 0;

                if (isset($tcDelta['id'])) {
                    $toolCalls[$index] = [
                        'id' => $tcDelta['id'],
                        'name' => $tcDelta['function']['name'] ?? '',
                        'arguments' => $tcDelta['function']['arguments'] ?? '',
                    ];
                } elseif (isset($toolCalls[$index])) {
                    $toolCalls[$index]['arguments'] .= $tcDelta['function']['arguments'] ?? '';
                }
            }
        }
    }

    /**
     * Fixes broken Unicode escapes in JSON tool arguments from some models.
     * Some models output \u0000XX (null char + hex) instead of \u00XX (proper Unicode escape).
     * For example: \u0000e4 instead of \u00e4 (ä), \u0000f6 instead of \u00f6 (ö).
     */
    private static function fixBrokenUnicodeEscapes(string $json): string
    {
        return preg_replace('/\\\\u0000([0-9a-fA-F]{2})/', '\\u00$1', $json) ?? $json;
    }
}
