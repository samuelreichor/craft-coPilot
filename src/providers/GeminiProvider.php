<?php

namespace samuelreichor\coPilot\providers;

use Craft;
use craft\helpers\App;
use samuelreichor\coPilot\CoPilot;
use samuelreichor\coPilot\helpers\HttpClientFactory;
use samuelreichor\coPilot\helpers\Logger;
use samuelreichor\coPilot\models\AIResponse;
use samuelreichor\coPilot\models\StreamChunk;

class GeminiProvider implements ProviderInterface
{
    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';

    public function chat(
        string $systemPrompt,
        array $messages,
        array $tools,
        ?string $model = null,
    ): AIResponse {
        $settings = CoPilot::getInstance()->getSettings();
        $apiKey = App::parseEnv($settings->geminiApiKeyEnvVar);

        if (!$apiKey) {
            return AIResponse::error('Gemini API key not configured. Set the environment variable ' . $settings->geminiApiKeyEnvVar);
        }

        $model = $model ?? $settings->geminiModel;
        $payload = $this->buildPayload($systemPrompt, $messages, $tools);

        Logger::info("Gemini API request: model={$model}");

        return $this->sendRequest($apiKey, $model, $payload);
    }

    public function chatStream(
        string $systemPrompt,
        array $messages,
        array $tools,
        ?string $model,
        callable $onChunk,
    ): void {
        $settings = CoPilot::getInstance()->getSettings();
        $apiKey = App::parseEnv($settings->geminiApiKeyEnvVar);

        if (!$apiKey) {
            $onChunk(new StreamChunk('error', error: 'Gemini API key not configured.'));
            return;
        }

        $model = $model ?? $settings->geminiModel;
        $payload = $this->buildPayload($systemPrompt, $messages, $tools);

        Logger::info("Gemini API stream request: model={$model}");

        $this->sendStreamRequest($apiKey, $model, $payload, $onChunk);
    }

    public function getAvailableModels(): array
    {
        return [
            'gemini-3.1-pro-preview',
            'gemini-3-flash-preview',
            'gemini-2.5-pro',
            'gemini-2.5-flash',
        ];
    }

    public function getName(): string
    {
        return 'Google Gemini';
    }

    public function getIcon(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0C12 6.627 6.627 12 0 12c6.627 0 12 5.373 12 12 0-6.627 5.373-12 12-12-6.627 0-12-5.373-12-12z"/></svg>';
    }

    public function validateApiKey(string $key): bool
    {
        $client = Craft::createGuzzleClient();

        try {
            $response = $client->get(self::API_BASE . 'gemini-2.0-flash', [
                'headers' => [
                    'x-goog-api-key' => $key,
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
     * @param array<int, array<string, mixed>> $tools
     * @return array<string, mixed>
     */
    private function buildPayload(string $systemPrompt, array $messages, array $tools): array
    {
        $payload = [
            'systemInstruction' => [
                'parts' => [['text' => $systemPrompt]],
            ],
            'contents' => $this->formatMessages($messages),
            // Note: Do NOT set maxOutputTokens — Gemini 2.5 thinking models may return
            // empty responses when a token budget is set (known Google issue).
        ];

        $formattedTools = ToolFormatter::forGemini($tools);
        if (!empty($formattedTools)) {
            $payload['tools'] = $formattedTools;
        }

        return $payload;
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
                // Gemini expects response as a Struct (JSON object). Ensure arrays
                // from DB round-trips are cast to objects so json_encode produces {}.
                $responseContent = is_array($message['content']) && $message['content'] !== []
                    ? $message['content']
                    : ['result' => $message['content']];

                $formatted[] = [
                    'role' => 'user',
                    'parts' => [
                        [
                            'functionResponse' => [
                                'name' => $message['toolName'] ?? 'unknown',
                                'response' => (object)$responseContent,
                            ],
                        ],
                    ],
                ];
                continue;
            }

            if ($role === 'assistant' && !empty($message['toolCalls'])) {
                // Gemini 3 requires thought signatures to be circulated back verbatim.
                // Use raw model parts when available to preserve them.
                if (!empty($message['rawModelParts'])) {
                    $formatted[] = [
                        'role' => 'model',
                        'parts' => $this->sanitizeRawModelParts($message['rawModelParts']),
                    ];
                    continue;
                }

                // Fallback: reconstruct parts (Gemini 2.x or conversation history from frontend)
                $parts = [];

                if (!empty($message['content'])) {
                    $parts[] = ['text' => $message['content']];
                }

                foreach ($message['toolCalls'] as $tc) {
                    $parts[] = [
                        'functionCall' => [
                            'name' => $tc['name'],
                            'args' => (object)($tc['arguments'] ?: []),
                        ],
                    ];
                }

                $formatted[] = [
                    'role' => 'model',
                    'parts' => $parts,
                ];
                continue;
            }

            $geminiRole = $role === 'assistant' ? 'model' : 'user';
            $formatted[] = [
                'role' => $geminiRole,
                'parts' => [
                    [
                        'text' => is_array($message['content'])
                            ? json_encode($message['content'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                            : $message['content'],
                    ],
                ],
            ];
        }

        return $formatted;
    }

    /**
     * Ensures rawModelParts are safe to re-send to the Gemini API.
     *
     * After a DB round-trip, empty JSON objects ({}) become empty PHP arrays ([]).
     * json_encode([]) produces [] (JSON array), but Gemini's protobuf schema expects
     * Struct fields (like functionCall.args) to be JSON objects ({}).
     *
     * @param array<int, array<string, mixed>> $parts
     * @return array<int, array<string, mixed>>
     */
    private function sanitizeRawModelParts(array $parts): array
    {
        foreach ($parts as &$part) {
            if (isset($part['functionCall'])) {
                $part['functionCall']['args'] = (object)($part['functionCall']['args'] ?? []);
            }
        }

        return $parts;
    }

    private function sendRequest(string $apiKey, string $model, array $payload): AIResponse
    {
        $client = HttpClientFactory::create();
        $url = self::API_BASE . $model . ':generateContent';

        try {
            $response = $client->post($url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $apiKey,
                ],
                'json' => $payload,
                'timeout' => 120,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return $this->parseResponse($data);
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $errorMsg = 'Gemini API error: ' . $e->getMessage();

            $response = $e->getResponse();
            if ($response !== null) {
                $body = (string)$response->getBody();
                $errorMsg .= ' | Response: ' . mb_substr($body, 0, 500);
            }

            Logger::error($errorMsg);

            return AIResponse::error($errorMsg);
        } catch (\Throwable $e) {
            Logger::error('Gemini API error: ' . $e->getMessage());

            return AIResponse::error('Gemini API error: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function parseResponse(array $data): AIResponse
    {
        $inputTokens = $data['usageMetadata']['promptTokenCount'] ?? 0;
        $outputTokens = $data['usageMetadata']['candidatesTokenCount'] ?? 0;

        $candidate = $data['candidates'][0] ?? null;
        if (!$candidate) {
            return AIResponse::error('No response from Gemini.', $inputTokens, $outputTokens);
        }

        $parts = $candidate['content']['parts'] ?? [];
        $textParts = [];
        $toolCalls = [];

        foreach ($parts as $part) {
            if (isset($part['text'])) {
                $textParts[] = $part['text'];
            }

            if (isset($part['functionCall'])) {
                $toolCalls[] = [
                    'id' => 'gemini_' . uniqid(),
                    'name' => $part['functionCall']['name'],
                    'arguments' => $part['functionCall']['args'] ?? [],
                ];
            }
        }

        $text = implode("\n", $textParts) ?: null;
        $finishReason = $candidate['finishReason'] ?? 'unknown';

        // Recover from MALFORMED_FUNCTION_CALL: Gemini sometimes generates Python-style
        // calls like print(default_api.updateEntry(entryId=238, fields={...})) instead of
        // structured functionCall parts. Parse the text to extract the intended tool call.
        // The function call may appear in the response text or in the candidate's finishMessage.
        if ($finishReason === 'MALFORMED_FUNCTION_CALL' && empty($toolCalls)) {
            $recoverSource = $text;

            // Also check finishMessage when text is empty (zero-part responses)
            if ($recoverSource === null && isset($candidate['finishMessage'])) {
                $recoverSource = $candidate['finishMessage'];
                // Strip "Malformed function call: " prefix if present
                $prefix = 'Malformed function call: ';
                if (str_starts_with($recoverSource, $prefix)) {
                    $recoverSource = substr($recoverSource, strlen($prefix));
                }
            }

            if ($recoverSource !== null) {
                $recovered = $this->parseMalformedFunctionCall($recoverSource);
                if ($recovered !== null) {
                    Logger::info('Gemini MALFORMED_FUNCTION_CALL recovered: ' . $recovered['name']);
                    $toolCalls[] = $recovered;
                    $text = null;
                } else {
                    Logger::warning('Gemini MALFORMED_FUNCTION_CALL could not be recovered from text: ' . mb_substr($recoverSource, 0, 300));
                }
            }
        }

        $type = !empty($toolCalls) ? 'tool_call' : 'text';

        Logger::info("Gemini API response: type={$type}, finish_reason={$finishReason}, inputTokens={$inputTokens}, outputTokens={$outputTokens}");

        if ($text === null && empty($toolCalls)) {
            Logger::warning('Gemini API returned empty response: finish_reason=' . $finishReason
                . ', parts=' . count($parts)
                . ', raw_candidate=' . json_encode($candidate));
        }

        if (!empty($toolCalls)) {
            return AIResponse::toolCall($toolCalls, $text, $inputTokens, $outputTokens, $parts);
        }

        return AIResponse::text($text ?? '', $inputTokens, $outputTokens);
    }

    /**
     * @param callable(StreamChunk): void $onChunk
     */
    private function sendStreamRequest(string $apiKey, string $model, array $payload, callable $onChunk): void
    {
        $client = HttpClientFactory::create();
        $url = self::API_BASE . $model . ':streamGenerateContent?alt=sse';
        $buffer = '';
        $hasTextContent = false;
        $hasToolCalls = false;
        $finishReason = 'unknown';
        $chunksProcessed = 0;
        /** @var array<int, array<string, mixed>> $rawModelParts Accumulated raw parts for thought signature circulation */
        $rawModelParts = [];

        // Shared line processor for both streaming and buffer flush
        $processLine = function(string $line) use (&$hasTextContent, &$hasToolCalls, &$finishReason, &$chunksProcessed, &$rawModelParts, $onChunk): void {
            $line = trim($line);
            if ($line === '' || !str_starts_with($line, 'data: ')) {
                return;
            }

            $json = json_decode(substr($line, 6), true);
            if (!is_array($json)) {
                Logger::warning('Gemini stream: failed to parse JSON from line: ' . mb_substr($line, 0, 200));
                return;
            }

            $chunksProcessed++;

            $chunkFinishReason = $json['candidates'][0]['finishReason'] ?? null;
            if ($chunkFinishReason !== null) {
                $finishReason = $chunkFinishReason;
            }

            $parts = $json['candidates'][0]['content']['parts'] ?? [];
            foreach ($parts as $part) {
                if (isset($part['text']) && $part['text'] !== '') {
                    $hasTextContent = true;
                }
                if (isset($part['functionCall'])) {
                    $hasToolCalls = true;
                }
                $rawModelParts[] = $part;
            }

            $this->processGeminiStreamChunk($json, $onChunk);
        };

        try {
            $client->post($url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $apiKey,
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
                Logger::warning('Gemini stream: flushing unparsed buffer remainder (' . strlen($buffer) . ' bytes)');
                $processLine($buffer);
                $buffer = '';
            }

            $hasText = $hasTextContent ? 'true' : 'false';
            $hasTools = $hasToolCalls ? 'true' : 'false';

            Logger::info("Gemini stream complete: finish_reason={$finishReason}, hasText={$hasText}, hasToolCalls={$hasTools}, chunks={$chunksProcessed}");

            if (!$hasTextContent && !$hasToolCalls) {
                Logger::warning("Gemini stream returned no text and no tool calls: finish_reason={$finishReason}, chunks={$chunksProcessed}");
            }

            // Emit accumulated raw model parts so thought signatures can be circulated (Gemini 3)
            if ($hasToolCalls && !empty($rawModelParts)) {
                $onChunk(new StreamChunk('model_parts', rawModelParts: $rawModelParts));
            }
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $errorMsg = 'Gemini stream error: ' . $e->getMessage();

            $response = $e->getResponse();
            if ($response !== null) {
                $body = (string)$response->getBody();
                $errorMsg .= ' | Response: ' . mb_substr($body, 0, 500);
            }

            Logger::error($errorMsg);
            $onChunk(new StreamChunk('error', error: $errorMsg));
        } catch (\Throwable $e) {
            Logger::error('Gemini stream error: ' . $e->getMessage());
            $onChunk(new StreamChunk('error', error: 'Gemini stream error: ' . $e->getMessage()));
        }
    }

    /**
     * @param array<string, mixed> $json
     * @param callable(StreamChunk): void $onChunk
     */
    private function processGeminiStreamChunk(array $json, callable $onChunk): void
    {
        if (isset($json['usageMetadata'])) {
            $onChunk(new StreamChunk(
                'usage',
                inputTokens: $json['usageMetadata']['promptTokenCount'] ?? 0,
                outputTokens: $json['usageMetadata']['candidatesTokenCount'] ?? 0,
            ));
        }

        $parts = $json['candidates'][0]['content']['parts'] ?? [];

        foreach ($parts as $part) {
            if (isset($part['text'])) {
                $type = !empty($part['thought']) ? 'thinking' : 'text_delta';
                $onChunk(new StreamChunk($type, delta: $part['text']));
            }

            if (isset($part['functionCall'])) {
                $onChunk(new StreamChunk(
                    'tool_call',
                    toolCallId: 'gemini_' . uniqid(),
                    toolName: $part['functionCall']['name'],
                    toolArguments: $part['functionCall']['args'] ?? [],
                ));
            }
        }
    }

    /**
     * Gemini sometimes outputs Python-style calls instead of structured functionCall parts.
     *
     * @return array{id: string, name: string, arguments: array<string, mixed>}|null
     */
    private function parseMalformedFunctionCall(string $text): ?array
    {
        $text = trim($text);

        if (preg_match('/^print\s*\((.+)\)\s*$/s', $text, $m)) {
            $text = trim($m[1]);
        }

        // Match default_api.functionName(...) or functionName(...)
        if (!preg_match('/(?:default_api\.)?(\w+)\s*\((.+)\)\s*$/s', $text, $m)) {
            return null;
        }

        $functionName = $m[1];
        $argsString = $m[2];

        $args = $this->parsePythonKwargs($argsString);
        if ($args === null) {
            return null;
        }

        return [
            'id' => 'gemini_' . uniqid(),
            'name' => $functionName,
            'arguments' => $args,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parsePythonKwargs(string $input): ?array
    {
        $pairs = [];
        $pos = 0;
        $len = strlen($input);

        while ($pos < $len) {
            // Skip whitespace and commas
            while ($pos < $len && ($input[$pos] === ' ' || $input[$pos] === ',' || $input[$pos] === "\n" || $input[$pos] === "\t")) {
                $pos++;
            }
            if ($pos >= $len) {
                break;
            }

            // Read key (word characters before =)
            $keyStart = $pos;
            while ($pos < $len && ctype_alnum($input[$pos]) || ($pos < $len && $input[$pos] === '_')) {
                $pos++;
            }
            $key = substr($input, $keyStart, $pos - $keyStart);
            if ($key === '' || $pos >= $len || $input[$pos] !== '=') {
                return null;
            }
            $pos++; // skip =

            // Read value with depth tracking for nested structures
            $valueStart = $pos;
            $depth = 0;
            $inString = false;
            $stringChar = null;

            while ($pos < $len) {
                $ch = $input[$pos];

                if ($inString) {
                    if ($ch === '\\' && $pos + 1 < $len) {
                        $pos++; // skip escaped char
                    } elseif ($ch === $stringChar) {
                        $inString = false;
                    }
                } else {
                    if ($ch === '"' || $ch === "'") {
                        $inString = true;
                        $stringChar = $ch;
                    } elseif ($ch === '{' || $ch === '[' || $ch === '(') {
                        $depth++;
                    } elseif ($ch === '}' || $ch === ']' || $ch === ')') {
                        if ($depth === 0) {
                            break;
                        }
                        $depth--;
                    } elseif ($ch === ',' && $depth === 0) {
                        break;
                    }
                }
                $pos++;
            }

            $valueStr = trim(substr($input, $valueStart, $pos - $valueStart));
            $pairs[$key] = $this->convertPythonValue($valueStr);
        }

        return $pairs === [] ? null : $pairs;
    }

    private function convertPythonValue(string $value): mixed
    {
        if ($value === 'True' || $value === 'true') {
            return true;
        }
        if ($value === 'False' || $value === 'false') {
            return false;
        }
        if ($value === 'None' || $value === 'null') {
            return null;
        }
        if (is_numeric($value)) {
            return str_contains($value, '.') ? (float) $value : (int) $value;
        }

        $jsonValue = preg_replace('/\bTrue\b/', 'true', $value);
        $jsonValue = preg_replace('/\bFalse\b/', 'false', $jsonValue);
        $jsonValue = preg_replace('/\bNone\b/', 'null', $jsonValue);

        $decoded = json_decode($jsonValue, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        return trim($value, "\"'");
    }
}
