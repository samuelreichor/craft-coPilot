<?php

namespace samuelreichor\coPilot\services;

use Craft;
use craft\base\Component;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\models\Site;
use samuelreichor\coPilot\CoPilot;
use samuelreichor\coPilot\enums\MessageRole;
use samuelreichor\coPilot\events\RegisterToolsEvent;
use samuelreichor\coPilot\events\ToolCallEvent;
use samuelreichor\coPilot\helpers\Logger;
use samuelreichor\coPilot\helpers\PluginHelper;
use samuelreichor\coPilot\helpers\SchemaValidator;
use samuelreichor\coPilot\models\Message;
use samuelreichor\coPilot\models\Settings;
use samuelreichor\coPilot\models\StreamChunk;
use samuelreichor\coPilot\providers\ProviderInterface;
use samuelreichor\coPilot\tools\CreateCategoryTool;
use samuelreichor\coPilot\tools\CreateEntryTool;
use samuelreichor\coPilot\tools\DescribeCategoryGroupTool;
use samuelreichor\coPilot\tools\DescribeEntryTypeTool;
use samuelreichor\coPilot\tools\DescribeSectionTool;
use samuelreichor\coPilot\tools\DescribeVolumeTool;
use samuelreichor\coPilot\tools\ListSectionsTool;
use samuelreichor\coPilot\tools\ListSitesTool;
use samuelreichor\coPilot\tools\PublishEntryTool;
use samuelreichor\coPilot\tools\ReadAssetTool;
use samuelreichor\coPilot\tools\ReadEntriesTool;
use samuelreichor\coPilot\tools\ReadEntryTool;
use samuelreichor\coPilot\tools\SearchAssetsTool;
use samuelreichor\coPilot\tools\SearchCategoriesTool;
use samuelreichor\coPilot\tools\SearchEntriesTool;
use samuelreichor\coPilot\tools\SearchFormieFormsTool;
use samuelreichor\coPilot\tools\SearchTagsTool;
use samuelreichor\coPilot\tools\SearchUsersTool;
use samuelreichor\coPilot\tools\ToolInterface;
use samuelreichor\coPilot\tools\UpdateAssetTool;
use samuelreichor\coPilot\tools\UpdateCategoryTool;
use samuelreichor\coPilot\tools\UpdateEntryTool;

/**
 * Orchestrates the AI agent loop: prompt building, provider calls, tool execution.
 */
class AgentService extends Component
{
    public const EVENT_BEFORE_TOOL_CALL = 'beforeToolCall';
    public const EVENT_AFTER_TOOL_CALL = 'afterToolCall';
    public const EVENT_REGISTER_TOOLS = 'registerTools';

    /** @var ToolInterface[]|null */
    private ?array $tools = null;

    private ?string $activeSiteHandle = null;

    /**
     * @param Message[] $conversationHistory
     * @param array<int, array<string, mixed>> $attachments
     * @return array{text: string|null, toolCalls: array<int, array<string, mixed>>|null, newMessages: array<int, array<string, mixed>>, inputTokens: int, outputTokens: int, debug: array<string, mixed>}
     */
    public function handleMessage(
        string $userMessage,
        ?int $contextEntryId = null,
        array $conversationHistory = [],
        ?string $model = null,
        array $attachments = [],
        ?string $siteHandle = null,
        ?string $executionMode = null,
        ?string $providerHandle = null,
    ): array {
        return $this->runAgentLoop(
            $userMessage,
            $contextEntryId,
            $conversationHistory,
            $model,
            null,
            $attachments,
            $siteHandle,
            $executionMode,
            $providerHandle,
        );
    }

    /**
     * @param Message[] $conversationHistory
     * @param callable(string, array<string, mixed>): void $emit Emits SSE events
     * @param array<int, array<string, mixed>> $attachments
     * @return array{text: string|null, toolCalls: array<int, array<string, mixed>>|null, newMessages: array<int, array<string, mixed>>, inputTokens: int, outputTokens: int, debug: array<string, mixed>}
     */
    public function handleMessageStream(
        string $userMessage,
        ?int $contextEntryId,
        array $conversationHistory,
        ?string $model,
        callable $emit,
        array $attachments = [],
        ?string $siteHandle = null,
        ?string $executionMode = null,
        ?string $providerHandle = null,
    ): array {
        return $this->runAgentLoop(
            $userMessage,
            $contextEntryId,
            $conversationHistory,
            $model,
            $emit,
            $attachments,
            $siteHandle,
            $executionMode,
            $providerHandle,
        );
    }

    /**
     * The agent loop shared by the streaming and non-streaming entry points.
     * When $emit is set, provider output is streamed and progress events are
     * emitted; without it, the provider is called in blocking mode.
     *
     * @param Message[] $conversationHistory
     * @param callable(string, array<string, mixed>): void|null $emit
     * @param array<int, array<string, mixed>> $attachments
     * @return array{text: string|null, toolCalls: array<int, array<string, mixed>>|null, newMessages: array<int, array<string, mixed>>, inputTokens: int, outputTokens: int, debug: array<string, mixed>}
     */
    private function runAgentLoop(
        string $userMessage,
        ?int $contextEntryId,
        array $conversationHistory,
        ?string $model,
        ?callable $emit,
        array $attachments,
        ?string $siteHandle,
        ?string $executionMode,
        ?string $providerHandle,
    ): array {
        $plugin = CoPilot::getInstance();

        Logger::info("runAgentLoop: userMessage length=" . strlen($userMessage)
            . ", contextEntryId={$contextEntryId}, attachments=" . count($attachments)
            . ", streaming=" . ($emit ? 'yes' : 'no'));

        $contextEntry = null;
        if ($contextEntryId) {
            $query = Entry::find()->id($contextEntryId)->status(null)->drafts(null);
            $query = $siteHandle ? $query->site($siteHandle) : $query->site('*');
            $contextEntry = $query->one();
        }

        $site = $this->resolveSite($siteHandle, $contextEntry);
        $this->activeSiteHandle = $site?->handle;
        $systemPrompt = $plugin->systemPromptBuilder->build($contextEntry, $site, $executionMode);

        $userMessage = $this->enrichMessageWithAttachments($userMessage, $attachments);

        // historyCount marks the boundary between old and new messages
        $historyCount = count($conversationHistory);
        $messages = $this->buildMessagesArray($conversationHistory, $userMessage);
        $toolDefs = $this->getToolDefinitions();
        $settings = $plugin->getSettings();
        $provider = $plugin->providerService->getActiveProvider($providerHandle);

        $totalInputTokens = 0;
        $totalOutputTokens = 0;
        $iteration = 0;
        /** @var array<int, array{name: string, success: bool, entryId: int|null, entryTitle: string|null, cpEditUrl: string|null}> $executedToolCalls */
        $executedToolCalls = [];

        $maxIterations = $settings->maxAgentIterations;
        $timeLimit = (int) ini_get('max_execution_time');
        $startedAt = microtime(true);
        $stopText = null;

        $finalize = function(?string $text) use (&$messages, &$executedToolCalls, &$totalInputTokens, &$totalOutputTokens, &$iteration, $systemPrompt, $model, $provider, $providerHandle, $settings, $historyCount): array {
            return [
                'text' => $text,
                'toolCalls' => $executedToolCalls !== [] ? $executedToolCalls : null,
                'newMessages' => array_slice($messages, $historyCount),
                'inputTokens' => $totalInputTokens,
                'outputTokens' => $totalOutputTokens,
                'debug' => $this->buildDebugPayload($systemPrompt, $model, $provider, $providerHandle, $settings, $messages, $iteration, $historyCount),
            ];
        };

        // Agent loop: call provider, execute tools, repeat until text response
        // or until an iteration, wall-clock, or token budget is exhausted
        while ($iteration < $maxIterations) {
            $elapsed = (int)(microtime(true) - $startedAt);
            if ($elapsed >= $settings->maxAgentSeconds) {
                Logger::warning("Agent loop stopped: wall-clock budget of {$settings->maxAgentSeconds}s used up after {$iteration} iterations");
                $stopText = 'I stopped because the time budget for this request was used up. '
                    . 'All completed work is saved — send a follow-up message to continue.';
                break;
            }

            $totalTokens = (int)$totalInputTokens + (int)$totalOutputTokens;
            if ($settings->maxTokensPerRequest > 0 && $totalTokens >= $settings->maxTokensPerRequest) {
                Logger::warning("Agent loop stopped: token budget of {$settings->maxTokensPerRequest} used up ({$totalTokens} tokens after {$iteration} iterations)");
                $stopText = 'I stopped because the token budget for this request was used up. '
                    . 'All completed work is saved — send a follow-up message to continue.';
                break;
            }

            $iteration++;

            // Reset PHP execution time limit per iteration so an iteration is
            // never killed mid-flight; the wall-clock budget above bounds the
            // total runtime.
            if ($timeLimit > 0 && function_exists('set_time_limit')) {
                set_time_limit($timeLimit);
            }

            Logger::info("Agent loop iteration {$iteration}/{$maxIterations}, sending " . count($messages) . ' messages to provider');

            $response = $this->runProviderIteration(
                $provider,
                $systemPrompt,
                $messages,
                $toolDefs,
                $model,
                $emit,
                $totalInputTokens,
                $totalOutputTokens,
            );

            if ($response['error'] !== null) {
                $errorText = 'Error: ' . $response['error'];
                $messages[] = [
                    'role' => MessageRole::Assistant->value,
                    'content' => $errorText,
                ];

                return $finalize($errorText);
            }

            if ($response['toolCalls'] === []) {
                $text = $response['text'];

                if ($text === '' && $executedToolCalls !== []) {
                    Logger::warning("runAgentLoop: provider returned empty text after tool calls, generating summary fallback");
                    $text = $this->buildToolCallSummary($executedToolCalls);
                    $this->emitTo($emit, 'text_delta', ['delta' => $text]);
                }

                if ($text === '') {
                    Logger::warning("runAgentLoop produced empty response after {$iteration} iterations, {$totalInputTokens} input / {$totalOutputTokens} output tokens");
                    $text = 'The AI model returned an empty response. This can happen with certain models — please try again or switch to a different model.';
                    $this->emitTo($emit, 'text_delta', ['delta' => $text]);
                }

                $messages[] = [
                    'role' => MessageRole::Assistant->value,
                    'content' => $text,
                ];

                Logger::info("runAgentLoop complete: {$iteration} iterations, {$totalInputTokens} input / {$totalOutputTokens} output tokens");

                return $finalize($text);
            }

            // The model requested tool calls. Pre-tool-call narration stays in
            // the message context but never becomes the final response text.
            $messages[] = [
                'role' => MessageRole::Assistant->value,
                'content' => $response['text'] !== '' ? $response['text'] : null,
                'toolCalls' => $response['toolCalls'],
                'rawModelParts' => $response['rawModelParts'],
            ];

            foreach ($response['toolCalls'] as $toolCall) {
                $this->emitTo($emit, 'tool_start', [
                    'id' => $toolCall['id'],
                    'name' => $toolCall['name'],
                ]);

                $result = $this->executeTool($toolCall['name'], $toolCall['arguments']);
                $success = !isset($result['error']);

                if (!$success) {
                    Logger::warning("Tool '{$toolCall['name']}' returned error: " . ($result['error'] ?? 'unknown'));
                }

                $executedToolCalls[] = [
                    'name' => $toolCall['name'],
                    'success' => $success,
                    'entryId' => $result['entryId'] ?? null,
                    'entryTitle' => $result['entryTitle'] ?? null,
                    'cpEditUrl' => $result['cpEditUrl'] ?? null,
                ];

                $this->emitTo($emit, 'tool_end', [
                    'id' => $toolCall['id'],
                    'name' => $toolCall['name'],
                    'success' => $success,
                    'entryId' => $result['entryId'] ?? null,
                    'entryTitle' => $result['entryTitle'] ?? null,
                    'cpEditUrl' => $result['cpEditUrl'] ?? null,
                ]);

                $messages[] = [
                    'role' => MessageRole::Tool->value,
                    'content' => $result,
                    'toolCallId' => $toolCall['id'],
                    'toolName' => $toolCall['name'],
                    'isError' => !$success,
                ];
            }
        }

        $stopText ??= 'The AI reached the maximum number of tool call iterations. Please try a simpler request.';
        $this->emitTo($emit, 'text_delta', ['delta' => $stopText]);
        $messages[] = [
            'role' => MessageRole::Assistant->value,
            'content' => $stopText,
        ];

        return $finalize($stopText);
    }

    /**
     * Runs one provider round-trip and normalizes the result. With an emitter,
     * the provider is streamed (text deltas are emitted as they arrive) and an
     * empty stream is retried once in blocking mode; without one, the provider
     * is called in blocking mode directly.
     *
     * @param array<int, array<string, mixed>> $messages
     * @param array<int, array<string, mixed>> $toolDefs
     * @param callable(string, array<string, mixed>): void|null $emit
     * @return array{text: string, toolCalls: array<int, array{id: string|null, name: string, arguments: array<string, mixed>}>, rawModelParts: array<int, array<string, mixed>>|null, error: string|null}
     */
    private function runProviderIteration(
        ProviderInterface $provider,
        string $systemPrompt,
        array $messages,
        array $toolDefs,
        ?string $model,
        ?callable $emit,
        int &$totalInputTokens,
        int &$totalOutputTokens,
    ): array {
        if ($emit === null) {
            $response = $provider->chat($systemPrompt, $messages, $toolDefs, $model);
            $totalInputTokens += $response->inputTokens;
            $totalOutputTokens += $response->outputTokens;

            return [
                'text' => $response->text ?? '',
                'toolCalls' => $response->type === 'tool_call' ? ($response->toolCalls ?? []) : [],
                'rawModelParts' => $response->rawModelParts,
                'error' => $response->type === 'error' ? ($response->error ?? 'Unknown provider error') : null,
            ];
        }

        $text = '';
        $toolCalls = [];
        $error = null;
        /** @var array<int, array<string, mixed>>|null $rawModelParts */
        $rawModelParts = null;

        $provider->chatStream(
            $systemPrompt,
            $messages,
            $toolDefs,
            $model,
            function(StreamChunk $chunk) use (&$text, &$toolCalls, &$totalInputTokens, &$totalOutputTokens, &$error, &$rawModelParts, $emit): void {
                switch ($chunk->type) {
                    case 'text_delta':
                        $text .= $chunk->delta;
                        $emit('text_delta', ['delta' => $chunk->delta]);
                        break;
                    case 'tool_call':
                        $toolCalls[] = [
                            'id' => $chunk->toolCallId,
                            'name' => $chunk->toolName,
                            'arguments' => $chunk->toolArguments ?? [],
                        ];
                        break;
                    case 'model_parts':
                        $rawModelParts = $chunk->rawModelParts;
                        break;
                    case 'usage':
                        $totalInputTokens += $chunk->inputTokens;
                        $totalOutputTokens += $chunk->outputTokens;
                        break;
                    case 'error':
                        $error = $chunk->error ?? 'Unknown stream error';
                        $emit('error', ['message' => $error]);
                        break;
                }
            },
        );

        // If the stream returned nothing, retry once in blocking mode
        // (same model — an alternate model would burn a second rate limit)
        if ($error === null && $text === '' && $toolCalls === []) {
            Logger::warning("Stream returned empty response, falling back to non-streaming");
            $fallbackResponse = $provider->chat($systemPrompt, $messages, $toolDefs, $model);
            $totalInputTokens += $fallbackResponse->inputTokens;
            $totalOutputTokens += $fallbackResponse->outputTokens;

            if ($fallbackResponse->type === 'error') {
                $error = $fallbackResponse->error ?? 'Unknown provider error';
                $emit('error', ['message' => $error]);
            } else {
                $text = $fallbackResponse->text ?? '';
                if ($text !== '') {
                    $emit('text_delta', ['delta' => $text]);
                }

                if ($fallbackResponse->type === 'tool_call' && $fallbackResponse->toolCalls) {
                    $toolCalls = $fallbackResponse->toolCalls;
                }

                $rawModelParts = $fallbackResponse->rawModelParts;
            }
        }

        return [
            'text' => $text,
            'toolCalls' => $toolCalls,
            'rawModelParts' => $rawModelParts,
            'error' => $error,
        ];
    }

    /**
     * @param callable(string, array<string, mixed>): void|null $emit
     * @param array<string, mixed> $data
     */
    private function emitTo(?callable $emit, string $event, array $data): void
    {
        if ($emit !== null) {
            $emit($event, $data);
        }
    }

    /**
     * @return array<string, ToolInterface>
     */
    public function getTools(): array
    {
        if ($this->tools !== null) {
            return $this->tools;
        }

        $event = new RegisterToolsEvent();
        $event->tools = [
            new ReadEntryTool(),
            new ReadEntriesTool(),
            new UpdateEntryTool(),
            new PublishEntryTool(),
            new CreateEntryTool(),
            new SearchEntriesTool(),
            new SearchAssetsTool(),
            new SearchTagsTool(),
            new SearchCategoriesTool(),
            new CreateCategoryTool(),
            new UpdateCategoryTool(),
            new SearchUsersTool(),
            new UpdateAssetTool(),
            new ListSectionsTool(),
            new ListSitesTool(),
            new DescribeSectionTool(),
            new DescribeEntryTypeTool(),
            new DescribeCategoryGroupTool(),
            new DescribeVolumeTool(),
            new ReadAssetTool(),
        ];

        if (PluginHelper::isPluginInstalledAndEnabled('formie')) {
            $event->tools[] = new SearchFormieFormsTool();
        }

        $this->trigger(self::EVENT_REGISTER_TOOLS, $event);

        $this->tools = [];
        foreach ($event->tools as $tool) {
            $this->tools[$tool->getName()] = $tool;
        }

        return $this->tools;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getToolDefinitions(): array
    {
        $tools = $this->getTools();

        return array_values(array_map(fn(ToolInterface $tool) => [
            'name' => $tool->getName(),
            'description' => $tool->getDescription(),
            'parameters' => $tool->getParameters(),
        ], $tools));
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function executeTool(string $toolName, array $arguments): array
    {
        $tools = $this->getTools();

        if (!isset($tools[$toolName])) {
            return ['error' => "Unknown tool: {$toolName}"];
        }

        // _siteHandle is a harness-owned parameter: always drop whatever the
        // model sent so it cannot redirect a tool to a different site, then
        // inject the active site so tools can scope queries to it. Tools expose
        // an explicit siteHandle parameter for model-controlled site targeting.
        unset($arguments['_siteHandle']);

        $validation = SchemaValidator::validate($arguments, $tools[$toolName]->getParameters());
        if (!$validation['valid']) {
            Logger::warning("Tool '{$toolName}' called with invalid arguments: " . implode(' ', $validation['errors']));

            return [
                'error' => 'Invalid tool arguments: ' . implode(' ', $validation['errors']),
                'retryHint' => 'Fix the listed arguments and call the tool again with a complete, valid argument set.',
            ];
        }
        $arguments = $validation['arguments'];

        if ($this->activeSiteHandle !== null) {
            $arguments['_siteHandle'] = $this->activeSiteHandle;
        }

        $beforeEvent = new ToolCallEvent();
        $beforeEvent->toolName = $toolName;
        $beforeEvent->params = $arguments;
        $this->trigger(self::EVENT_BEFORE_TOOL_CALL, $beforeEvent);

        if ($beforeEvent->cancel) {
            return ['error' => "Tool call '{$toolName}' was cancelled."];
        }

        Logger::info("Executing tool '{$toolName}' with arguments: " . json_encode($arguments));

        try {
            $result = $tools[$toolName]->execute($arguments);

            if (isset($result['error'])) {
                Logger::warning("Tool '{$toolName}' returned error: {$result['error']}");
            } else {
                Logger::info("Tool '{$toolName}' executed successfully");
                Logger::info("Tool '{$toolName}' result: " . mb_substr(json_encode($result), 0, 2000));
            }
        } catch (\Throwable $e) {
            Logger::error("Tool '{$toolName}' failed with exception: {$e->getMessage()}");
            $result = ['error' => "Tool execution failed: {$e->getMessage()}"];
        }

        $afterEvent = new ToolCallEvent();
        $afterEvent->toolName = $toolName;
        $afterEvent->params = $arguments;
        $afterEvent->result = $result;
        $this->trigger(self::EVENT_AFTER_TOOL_CALL, $afterEvent);

        $this->logToolCall($toolName, $arguments, $afterEvent->result ?? $result, $tools[$toolName]->getAction()->value);

        return $afterEvent->result ?? $result;
    }

    /**
     * @param array<int, array{name: string, success: bool, entryId: int|null, entryTitle: string|null, cpEditUrl: string|null}> $toolCalls
     */
    private function buildToolCallSummary(array $toolCalls): string
    {
        $counts = [];
        foreach ($toolCalls as $call) {
            $name = $call['name'];
            if (!isset($counts[$name])) {
                $counts[$name] = 0;
            }
            $counts[$name]++;
        }

        $parts = [];
        foreach ($counts as $name => $count) {
            $parts[] = $count > 1 ? "{$name} ({$count}x)" : $name;
        }

        return 'Done. Completed: ' . implode(', ', $parts) . '.';
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @return array<string, mixed>
     */
    private function buildDebugPayload(
        string $systemPrompt,
        ?string $model,
        ProviderInterface $provider,
        ?string $providerHandle,
        Settings $settings,
        array $messages,
        int $iterations,
        int $historyCount,
    ): array {
        return [
            'systemPrompt' => $systemPrompt,
            'model' => $model ?? $provider->getModel(),
            'provider' => $providerHandle ?? $settings->defaultProvider,
            'messages' => array_values(array_slice($messages, $historyCount)),
            'iterations' => $iterations,
        ];
    }

    private const MAX_ATTACHMENTS = 5;
    private const MAX_FILE_SIZE = 102400; // 100 KB
    private const ALLOWED_FILE_EXTENSIONS = ['txt', 'csv', 'json', 'xml', 'md', 'html', 'htm', 'yaml', 'yml', 'log'];

    /**
     * @param array<int, array<string, mixed>> $attachments
     */
    private function enrichMessageWithAttachments(string $message, array $attachments): string
    {
        if (empty($attachments)) {
            return $message;
        }

        $plugin = CoPilot::getInstance();
        $contextParts = [];
        $processed = 0;

        foreach ($attachments as $attachment) {
            if ($processed >= self::MAX_ATTACHMENTS) {
                break;
            }

            if (!is_array($attachment)) {
                continue;
            }

            $type = $attachment['type'] ?? '';
            $label = is_string($attachment['label'] ?? null) ? $attachment['label'] : '';

            if ($type === 'asset' && isset($attachment['id'])) {
                $assetId = (int)$attachment['id'];

                $guard = $plugin->permissionGuard->canReadAsset($assetId);
                if (!$guard['allowed']) {
                    continue;
                }

                $asset = Asset::find()->id($assetId)->one();
                if ($asset) {
                    $serialized = $plugin->contextService->serializeAsset($asset);
                    $contextParts[] = "--- Attached Asset: {$asset->filename} ---\n"
                        . json_encode($serialized, JSON_UNESCAPED_SLASHES) . "\n---";
                    $processed++;
                }
            } elseif ($type === 'entry' && isset($attachment['id'])) {
                $entryId = (int)$attachment['id'];
                $siteId = isset($attachment['siteId']) ? (int)$attachment['siteId'] : null;

                $guard = $plugin->permissionGuard->canReadEntry($entryId);
                if (!$guard['allowed']) {
                    continue;
                }

                $entry = null;

                // 1. Try explicit siteId from the element selector modal
                if ($siteId) {
                    $entry = Entry::find()->id($entryId)->status(null)->drafts(null)->siteId($siteId)->one();
                }

                // 2. Fallback: try the active conversation site
                if (!$entry && $this->activeSiteHandle) {
                    $activeSite = Craft::$app->getSites()->getSiteByHandle($this->activeSiteHandle);
                    if ($activeSite) {
                        $entry = Entry::find()->id($entryId)->status(null)->drafts(null)->siteId($activeSite->id)->one();
                    }
                }

                // 3. Final fallback: any site
                if (!$entry) {
                    $entry = Entry::find()->id($entryId)->status(null)->drafts(null)->site('*')->one();
                }

                if ($entry) {
                    $summary = $plugin->contextService->summarizeEntry($entry);
                    $siteInfo = $entry->getSite();
                    $contextParts[] = "--- Attached Entry (site: {$siteInfo->handle}, language: {$siteInfo->language}) ---\n"
                        . json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n---";
                    $processed++;
                }
            } elseif ($type === 'file' && isset($attachment['content']) && is_string($attachment['content'])) {
                $extension = strtolower(pathinfo($label, PATHINFO_EXTENSION));
                if (!in_array($extension, self::ALLOWED_FILE_EXTENSIONS, true)) {
                    continue;
                }

                $content = $attachment['content'];

                if (strlen($content) > self::MAX_FILE_SIZE) {
                    continue;
                }

                $contextParts[] = "--- Attached File: {$label} ---\n{$content}\n---";
                $processed++;
            }
        }

        if (empty($contextParts)) {
            return $message;
        }

        return $message . "\n\n" . implode("\n\n", $contextParts);
    }

    /**
     * @param Message[] $history
     * @return array<int, array<string, mixed>>
     */
    private function buildMessagesArray(array $history, string $userMessage): array
    {
        $messages = [];

        foreach ($history as $msg) {
            $messages[] = $msg->toArray();
        }

        $messages[] = [
            'role' => MessageRole::User->value,
            'content' => $userMessage,
        ];

        return $messages;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $result
     */
    private function logToolCall(string $toolName, array $params, array $result, string $action): void
    {
        try {
            $plugin = CoPilot::getInstance();
            $plugin->auditService->log($toolName, $params, $result, $action);
        } catch (\Throwable $e) {
            Logger::error("Audit log failed: {$e->getMessage()}");
        }
    }

    private function resolveSite(?string $siteHandle, ?Entry $contextEntry): ?Site
    {
        if ($siteHandle) {
            $site = Craft::$app->getSites()->getSiteByHandle($siteHandle);
            if ($site) {
                return $site;
            }
        }

        if ($contextEntry) {
            return $contextEntry->getSite();
        }

        return null;
    }
}
