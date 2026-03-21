<?php

namespace samuelreichor\coPilot\providers;

use Craft;
use craft\helpers\App;
use samuelreichor\coPilot\CoPilot;
use samuelreichor\coPilot\helpers\HttpClientFactory;
use samuelreichor\coPilot\helpers\Logger;
use samuelreichor\coPilot\models\AIResponse;
use samuelreichor\coPilot\models\StreamChunk;

class AnthropicProvider implements ProviderInterface
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';

    public function chat(
        string $systemPrompt,
        array $messages,
        array $tools,
        ?string $model = null,
    ): AIResponse {
        $settings = CoPilot::getInstance()->getSettings();
        $apiKey = App::parseEnv($settings->anthropicApiKeyEnvVar);

        if (!$apiKey) {
            return AIResponse::error('Anthropic API key not configured. Set the environment variable ' . $settings->anthropicApiKeyEnvVar);
        }

        $model = $model ?? $settings->anthropicModel;

        $payload = [
            'model' => $model,
            'max_tokens' => 4096,
            'system' => $this->formatSystemPrompt($systemPrompt),
            'messages' => $this->formatMessages($messages),
        ];

        $formattedTools = ToolFormatter::forAnthropic($tools);
        if ($settings->webSearchEnabled) {
            $formattedTools[] = [
                'type' => 'web_search_20260209',
                'name' => 'web_search',
                'max_uses' => 3,
            ];
            $formattedTools[] = [
                'type' => 'web_fetch_20260209',
                'name' => 'web_fetch',
                'max_uses' => 3,
            ];
        }

        if (!empty($formattedTools)) {
            $this->applyCacheControl($formattedTools);
            $payload['tools'] = $formattedTools;
        }

        Logger::info("Anthropic API request: model={$model}");

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
        $apiKey = App::parseEnv($settings->anthropicApiKeyEnvVar);

        if (!$apiKey) {
            $onChunk(new StreamChunk('error', error: 'Anthropic API key not configured.'));
            return;
        }

        $model = $model ?? $settings->anthropicModel;

        $payload = [
            'model' => $model,
            'max_tokens' => 4096,
            'system' => $this->formatSystemPrompt($systemPrompt),
            'messages' => $this->formatMessages($messages),
            'stream' => true,
        ];

        $formattedTools = ToolFormatter::forAnthropic($tools);
        if ($settings->webSearchEnabled) {
            $formattedTools[] = [
                'type' => 'web_search_20260209',
                'name' => 'web_search',
                'max_uses' => 3,
            ];
            $formattedTools[] = [
                'type' => 'web_fetch_20260209',
                'name' => 'web_fetch',
                'max_uses' => 3,
            ];
        }

        if (!empty($formattedTools)) {
            $this->applyCacheControl($formattedTools);
            $payload['tools'] = $formattedTools;
        }

        Logger::info("Anthropic API stream request: model={$model}");

        $this->sendStreamRequest($apiKey, $payload, $onChunk);
    }

    public function getAvailableModels(): array
    {
        return [
            'claude-opus-4-6',
            'claude-sonnet-4-6',
            'claude-opus-4-5-20251101',
            'claude-sonnet-4-5-20250929',
        ];
    }

    public function getTitleModel(): string
    {
        return 'claude-sonnet-4-6';
    }

    public function getName(): string
    {
        return 'Anthropic';
    }

    public function getIcon(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M13.827 3.52h3.603L24 20.48h-3.603l-6.57-16.96zm-7.258 0h3.767L16.906 20.48h-3.674l-1.343-3.461H5.017l-1.344 3.46H0l6.57-16.96zm1.04 3.88L5.2 13.796h4.822L7.609 7.4z"/></svg>';
    }

    public function validateApiKey(string $key): bool
    {
        $client = Craft::createGuzzleClient();

        try {
            $response = $client->post(self::API_URL, [
                'headers' => [
                    'x-api-key' => $key,
                    'anthropic-version' => self::API_VERSION,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => 'claude-haiku-4-20250414',
                    'max_tokens' => 1,
                    'messages' => [['role' => 'user', 'content' => 'hi']],
                ],
                'timeout' => 10,
            ]);

            return $response->getStatusCode() === 200;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Formats the system prompt as an array of content blocks with cache_control.
     *
     * @return array<int, array<string, mixed>>
     */
    private function formatSystemPrompt(string $systemPrompt): array
    {
        return [
            [
                'type' => 'text',
                'text' => $systemPrompt,
                'cache_control' => ['type' => 'ephemeral'],
            ],
        ];
    }

    /**
     * Adds cache_control to the last element of an array (tools or content blocks).
     *
     * @param array<int, array<string, mixed>> &$items
     */
    private function applyCacheControl(array &$items): void
    {
        if (empty($items)) {
            return;
        }

        $lastIndex = array_key_last($items);
        $items[$lastIndex]['cache_control'] = ['type' => 'ephemeral'];
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @return array<int, array<string, mixed>>
     */
    private function formatMessages(array $messages): array
    {
        $formatted = [];

        foreach ($messages as $message) {
            $role = $message['role'];

            if ($role === 'tool') {
                $toolResult = [
                    'type' => 'tool_result',
                    'tool_use_id' => $message['toolCallId'],
                    'content' => is_array($message['content'])
                        ? json_encode($message['content'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                        : $message['content'],
                ];

                if (!empty($message['isError'])) {
                    $toolResult['is_error'] = true;
                }

                $formatted[] = [
                    'role' => 'user',
                    'content' => [$toolResult],
                ];
                continue;
            }

            if ($role === 'assistant' && !empty($message['toolCalls'])) {
                $content = [];

                if (!empty($message['content'])) {
                    $content[] = [
                        'type' => 'text',
                        'text' => $message['content'],
                    ];
                }

                foreach ($message['toolCalls'] as $tc) {
                    $content[] = [
                        'type' => 'tool_use',
                        'id' => $tc['id'],
                        'name' => $tc['name'],
                        // Anthropic API requires input to be a JSON object, never an array.
                        // PHP's empty array [] serializes as JSON array, so cast to object.
                        'input' => (object) ($tc['arguments'] ?: []),
                    ];
                }

                $formatted[] = [
                    'role' => 'assistant',
                    'content' => $content,
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

        // Add cache breakpoint on the last user message so the conversation
        // history up to that point is cached during agent tool-use loops.
        $this->addMessageCacheBreakpoint($formatted);

        return $formatted;
    }

    /**
     * Adds cache_control to the last user-role message in the formatted array.
     * For multi-turn agent loops this caches everything up to the latest turn.
     *
     * @param array<int, array<string, mixed>> &$formatted
     */
    private function addMessageCacheBreakpoint(array &$formatted): void
    {
        // Walk backwards to find the last user message
        for ($i = count($formatted) - 1; $i >= 0; $i--) {
            if (($formatted[$i]['role'] ?? '') !== 'user') {
                continue;
            }

            $content = $formatted[$i]['content'];

            // Already an array of content blocks (e.g. tool_result) — mark last block
            if (is_array($content)) {
                $lastKey = array_key_last($content);
                $content[$lastKey]['cache_control'] = ['type' => 'ephemeral'];
                $formatted[$i]['content'] = $content;
            } else {
                // String content — convert to content block array
                $formatted[$i]['content'] = [
                    [
                        'type' => 'text',
                        'text' => $content,
                        'cache_control' => ['type' => 'ephemeral'],
                    ],
                ];
            }

            break;
        }
    }

    private function sendRequest(string $apiKey, array $payload): AIResponse
    {
        $client = HttpClientFactory::create();

        try {
            $response = $client->post(self::API_URL, [
                'headers' => [
                    'x-api-key' => $apiKey,
                    'anthropic-version' => self::API_VERSION,
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
                'timeout' => 120,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return $this->parseResponse($data);
        } catch (\Throwable $e) {
            Logger::error('Anthropic API error: ' . $e->getMessage());

            return AIResponse::error('Anthropic API error: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function parseResponse(array $data): AIResponse
    {
        $usage = $data['usage'] ?? [];
        $inputTokens = $usage['input_tokens'] ?? 0;
        $outputTokens = $usage['output_tokens'] ?? 0;
        $cacheRead = $usage['cache_read_input_tokens'] ?? 0;
        $cacheCreation = $usage['cache_creation_input_tokens'] ?? 0;

        $content = $data['content'] ?? [];
        $textParts = [];
        $toolCalls = [];

        foreach ($content as $block) {
            if ($block['type'] === 'text') {
                $textParts[] = $block['text'];
            }

            if ($block['type'] === 'tool_use') {
                $toolCalls[] = [
                    'id' => $block['id'],
                    'name' => $block['name'],
                    'arguments' => $block['input'] ?? [],
                ];
            }
        }

        $text = implode("\n\n", $textParts) ?: null;
        $type = !empty($toolCalls) ? 'tool_call' : 'text';
        $stopReason = $data['stop_reason'] ?? 'unknown';

        Logger::info("Anthropic API response: type={$type}, stop_reason={$stopReason}, inputTokens={$inputTokens}, outputTokens={$outputTokens}, cacheRead={$cacheRead}, cacheCreation={$cacheCreation}");

        if ($text === null && empty($toolCalls)) {
            Logger::warning('Anthropic API returned empty response: stop_reason=' . $stopReason
                . ', content_blocks=' . count($content));
        }

        if (!empty($toolCalls)) {
            return AIResponse::toolCall($toolCalls, $text, $inputTokens, $outputTokens);
        }

        return AIResponse::text($text ?? '', $inputTokens, $outputTokens);
    }

    /**
     * @param callable(StreamChunk): void $onChunk
     */
    private function sendStreamRequest(string $apiKey, array $payload, callable $onChunk): void
    {
        $client = HttpClientFactory::create();
        $buffer = '';
        /** @var array<string, array{id: string, name: string, input: string}> $toolCalls */
        $toolCalls = [];
        $currentBlockType = null;
        $currentBlockId = null;
        $currentEvent = null;
        $hasTextContent = false;
        $stopReason = 'unknown';
        $chunksProcessed = 0;

        // Shared line processor for both streaming and buffer flush
        $processLine = function(string $line) use (&$toolCalls, &$currentBlockType, &$currentBlockId, &$currentEvent, &$hasTextContent, &$stopReason, &$chunksProcessed, $onChunk): void {
            $line = trim($line);
            if ($line === '') {
                return;
            }

            if (str_starts_with($line, 'event: ')) {
                $currentEvent = substr($line, 7);
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

            if ($currentEvent === 'message_delta' && isset($json['delta']['stop_reason'])) {
                $stopReason = $json['delta']['stop_reason'];
            }

            $this->processAnthropicEvent(
                $currentEvent,
                $json,
                $toolCalls,
                $currentBlockType,
                $currentBlockId,
                $hasTextContent,
                $onChunk,
            );
        };

        try {
            $client->post(self::API_URL, [
                'headers' => [
                    'x-api-key' => $apiKey,
                    'anthropic-version' => self::API_VERSION,
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
                Logger::warning('Anthropic stream: flushing unparsed buffer remainder (' . strlen($buffer) . ' bytes)');
                $processLine($buffer);
                $buffer = '';
            }

            $hasText = $hasTextContent ? 'true' : 'false';

            Logger::info("Anthropic stream complete: stop_reason={$stopReason}, hasText={$hasText}, toolCalls=" . count($toolCalls) . ", chunks={$chunksProcessed}");

            if (!$hasTextContent && empty($toolCalls)) {
                Logger::warning("Anthropic stream returned no text and no tool calls: stop_reason={$stopReason}, chunks={$chunksProcessed}");
            }

            // Emit accumulated tool calls after stream completes
            foreach ($toolCalls as $tc) {
                $onChunk(new StreamChunk(
                    'tool_call',
                    toolCallId: $tc['id'],
                    toolName: $tc['name'],
                    toolArguments: json_decode($tc['input'], true) ?? [],
                ));
            }
        } catch (\Throwable $e) {
            Logger::error('Anthropic stream error: ' . $e->getMessage());
            $onChunk(new StreamChunk('error', error: 'Anthropic stream error: ' . $e->getMessage()));
        }
    }

    /**
     * @param array<string, array{id: string, name: string, input: string}> &$toolCalls
     * @param callable(StreamChunk): void $onChunk
     */
    private function processAnthropicEvent(
        ?string $event,
        array $json,
        array &$toolCalls,
        ?string &$currentBlockType,
        ?string &$currentBlockId,
        bool &$hasTextContent,
        callable $onChunk,
    ): void {
        switch ($event) {
            case 'content_block_start':
                $block = $json['content_block'] ?? [];
                $currentBlockType = $block['type'] ?? null;
                if ($currentBlockType === 'tool_use') {
                    $currentBlockId = $block['id'] ?? '';
                    $toolCalls[$currentBlockId] = [
                        'id' => $currentBlockId,
                        'name' => $block['name'] ?? '',
                        'input' => '',
                    ];
                } elseif ($currentBlockType === 'text' && $hasTextContent) {
                    // Separate multiple text blocks (e.g. before/after web search)
                    // with a blank line so Markdown headings render correctly.
                    $onChunk(new StreamChunk('text_delta', delta: "\n\n"));
                }
                break;

            case 'content_block_delta':
                $delta = $json['delta'] ?? [];
                $deltaType = $delta['type'] ?? '';

                if ($deltaType === 'text_delta') {
                    $hasTextContent = true;
                    $onChunk(new StreamChunk('text_delta', delta: $delta['text'] ?? ''));
                } elseif ($deltaType === 'thinking_delta') {
                    $onChunk(new StreamChunk('thinking', delta: $delta['thinking'] ?? ''));
                } elseif ($deltaType === 'input_json_delta' && $currentBlockId && isset($toolCalls[$currentBlockId])) {
                    $toolCalls[$currentBlockId]['input'] .= $delta['partial_json'] ?? '';
                }
                break;

            case 'content_block_stop':
                $currentBlockType = null;
                $currentBlockId = null;
                break;

            case 'message_delta':
                $usage = $json['usage'] ?? [];
                if (!empty($usage)) {
                    $onChunk(new StreamChunk(
                        'usage',
                        outputTokens: $usage['output_tokens'] ?? 0,
                    ));
                }
                break;

            case 'message_start':
                $usage = $json['message']['usage'] ?? [];
                if (!empty($usage)) {
                    $onChunk(new StreamChunk(
                        'usage',
                        inputTokens: $usage['input_tokens'] ?? 0,
                    ));
                }
                break;
        }
    }
}
