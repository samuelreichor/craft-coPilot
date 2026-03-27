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
use samuelreichor\coPilot\models\Message;
use samuelreichor\coPilot\models\Settings;
use samuelreichor\coPilot\models\StreamChunk;
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
        $plugin = CoPilot::getInstance();

        Logger::info("handleMessage: userMessage length=" . strlen($userMessage)
            . ", contextEntryId={$contextEntryId}, attachments=" . count($attachments));

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

        $maxIterations = $plugin->getSettings()->maxAgentIterations;
        $timeLimit = (int) ini_get('max_execution_time');

        // Agent loop: call provider, execute tools, repeat until text response or max iterations
        while ($iteration < $maxIterations) {
            $iteration++;

            // Reset PHP execution time limit per iteration to prevent timeouts during long agent loops
            if ($timeLimit > 0 && function_exists('set_time_limit')) {
                set_time_limit($timeLimit);
            }

            Logger::info("Agent loop iteration {$iteration}/{$maxIterations}, sending " . count($messages) . ' messages to provider');

            $response = $provider->chat($systemPrompt, $messages, $toolDefs, $model);
            $totalInputTokens += $response->inputTokens;
            $totalOutputTokens += $response->outputTokens;

            if ($response->type === 'error') {
                $errorText = 'Error: ' . $response->error;
                $messages[] = [
                    'role' => MessageRole::Assistant->value,
                    'content' => $errorText,
                ];

                return [
                    'text' => $errorText,
                    'toolCalls' => $executedToolCalls !== [] ? $executedToolCalls : null,
                    'newMessages' => array_slice($messages, $historyCount),
                    'inputTokens' => $totalInputTokens,
                    'outputTokens' => $totalOutputTokens,
                    'debug' => $this->buildDebugPayload($systemPrompt, $model, $settings, $messages, $iteration, $historyCount),
                ];
            }

            if ($response->type === 'text') {
                $text = $response->text;
                if (($text === null || $text === '') && $executedToolCalls !== []) {
                    Logger::warning("handleMessage: provider returned empty text after tool calls, generating summary fallback");
                    $text = $this->buildToolCallSummary($executedToolCalls);
                }

                $messages[] = [
                    'role' => MessageRole::Assistant->value,
                    'content' => $text,
                ];

                Logger::info("handleMessage complete: {$iteration} iterations, {$totalInputTokens} input / {$totalOutputTokens} output tokens");

                return [
                    'text' => $text,
                    'toolCalls' => $executedToolCalls !== [] ? $executedToolCalls : null,
                    'newMessages' => array_slice($messages, $historyCount),
                    'inputTokens' => $totalInputTokens,
                    'outputTokens' => $totalOutputTokens,
                    'debug' => $this->buildDebugPayload($systemPrompt, $model, $settings, $messages, $iteration, $historyCount),
                ];
            }

            if ($response->type === 'tool_call' && $response->toolCalls) {
                $messages[] = [
                    'role' => MessageRole::Assistant->value,
                    'content' => $response->text,
                    'toolCalls' => $response->toolCalls,
                    'rawModelParts' => $response->rawModelParts,
                ];

                foreach ($response->toolCalls as $toolCall) {
                    $result = $this->executeTool($toolCall['name'], $toolCall['arguments']);

                    $executedToolCalls[] = [
                        'name' => $toolCall['name'],
                        'success' => !isset($result['error']),
                        'entryId' => $result['entryId'] ?? null,
                        'entryTitle' => $result['entryTitle'] ?? null,
                        'cpEditUrl' => $result['cpEditUrl'] ?? null,
                    ];

                    $messages[] = [
                        'role' => MessageRole::Tool->value,
                        'content' => $result,
                        'toolCallId' => $toolCall['id'],
                        'toolName' => $toolCall['name'],
                        'isError' => isset($result['error']),
                    ];
                }
            }
        }

        $maxIterText = 'The AI reached the maximum number of tool call iterations. Please try a simpler request.';
        $messages[] = [
            'role' => MessageRole::Assistant->value,
            'content' => $maxIterText,
        ];

        return [
            'text' => $maxIterText,
            'toolCalls' => $executedToolCalls !== [] ? $executedToolCalls : null,
            'newMessages' => array_slice($messages, $historyCount),
            'inputTokens' => $totalInputTokens,
            'outputTokens' => $totalOutputTokens,
            'debug' => $this->buildDebugPayload($systemPrompt, $model, $settings, $messages, $iteration, $historyCount),
        ];
    }

    /**
     * @param Message[] $conversationHistory
     * @param callable(string, array<string, mixed>): void $emit Emits SSE events
     * @param array<int, array<string, mixed>> $attachments
     * @return array{text: string|null, newMessages: array<int, array<string, mixed>>, inputTokens: int, outputTokens: int, debug: array<string, mixed>}
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
        $plugin = CoPilot::getInstance();

        Logger::info("handleMessageStream: userMessage length=" . strlen($userMessage)
            . ", contextEntryId={$contextEntryId}, attachments=" . count($attachments));

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
        $historyCount = count($conversationHistory);
        $messages = $this->buildMessagesArray($conversationHistory, $userMessage);
        $toolDefs = $this->getToolDefinitions();
        $settings = $plugin->getSettings();
        $provider = $plugin->providerService->getActiveProvider($providerHandle);

        $totalInputTokens = 0;
        $totalOutputTokens = 0;
        $fullText = '';
        $iteration = 0;
        $hasFallenBack = false;
        $hadStreamError = false;
        $maxIterations = $settings->maxAgentIterations;
        $timeLimit = (int) ini_get('max_execution_time');

        while ($iteration < $maxIterations) {
            $iteration++;

            // Reset PHP execution time limit per iteration to prevent timeouts during long agent loops
            if ($timeLimit > 0 && function_exists('set_time_limit')) {
                set_time_limit($timeLimit);
            }

            Logger::info("Agent stream loop iteration {$iteration}/{$maxIterations}, sending " . count($messages) . ' messages to provider');

            $iterationText = '';
            $iterationToolCalls = [];
            $iterationHadError = false;
            /** @var array<int, array<string, mixed>>|null $iterationRawModelParts */
            $iterationRawModelParts = null;

            $provider->chatStream(
                $systemPrompt,
                $messages,
                $toolDefs,
                $model,
                function(StreamChunk $chunk) use (&$iterationText, &$iterationToolCalls, &$totalInputTokens, &$totalOutputTokens, &$iterationHadError, &$iterationRawModelParts, $emit): void {
                    switch ($chunk->type) {
                        case 'text_delta':
                            $iterationText .= $chunk->delta;
                            $emit('text_delta', ['delta' => $chunk->delta]);
                            break;
                        case 'tool_call':
                            $iterationToolCalls[] = [
                                'id' => $chunk->toolCallId,
                                'name' => $chunk->toolName,
                                'arguments' => $chunk->toolArguments ?? [],
                            ];
                            break;
                        case 'model_parts':
                            $iterationRawModelParts = $chunk->rawModelParts;
                            break;
                        case 'usage':
                            $totalInputTokens += $chunk->inputTokens;
                            $totalOutputTokens += $chunk->outputTokens;
                            break;
                        case 'error':
                            $iterationHadError = true;
                            $emit('error', ['message' => $chunk->error]);
                            break;
                    }
                },
            );

            // Stream error — stop immediately, error was already emitted to the client
            if ($iterationHadError) {
                $hadStreamError = true;
                break;
            }

            // If the stream returned nothing, retry once with non-streaming (no alternate model — saves rate limit)
            if ($iterationText === '' && empty($iterationToolCalls) && !$hasFallenBack) {
                $hasFallenBack = true;

                Logger::warning("Stream returned empty response on iteration {$iteration}, falling back to non-streaming");
                $fallbackResponse = $provider->chat($systemPrompt, $messages, $toolDefs, $model);
                $totalInputTokens += $fallbackResponse->inputTokens;
                $totalOutputTokens += $fallbackResponse->outputTokens;

                if ($fallbackResponse->type === 'error') {
                    $emit('error', ['message' => $fallbackResponse->error]);
                    break;
                }

                $iterationText = $fallbackResponse->text ?? '';
                if ($iterationText !== '') {
                    $emit('text_delta', ['delta' => $iterationText]);
                }

                if ($fallbackResponse->type === 'tool_call' && $fallbackResponse->toolCalls) {
                    $iterationToolCalls = $fallbackResponse->toolCalls;
                }
            }

            // No tool calls so we're done
            if (empty($iterationToolCalls)) {
                $fullText .= $iterationText;
                break;
            }

            // Don't accumulate pre-tool-call narration into the final response text.
            // The text is still preserved in the messages array for the model context.

            $messages[] = [
                'role' => MessageRole::Assistant->value,
                'content' => $iterationText ?: null,
                'toolCalls' => $iterationToolCalls,
                'rawModelParts' => $iterationRawModelParts,
            ];

            foreach ($iterationToolCalls as $toolCall) {
                $emit('tool_start', [
                    'id' => $toolCall['id'],
                    'name' => $toolCall['name'],
                ]);

                $result = $this->executeTool($toolCall['name'], $toolCall['arguments']);
                $success = !isset($result['error']);

                if (!$success) {
                    Logger::warning("Stream tool '{$toolCall['name']}' returned error: " . ($result['error'] ?? 'unknown'));
                }

                $emit('tool_end', [
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

        if ($fullText === '' && !$hadStreamError) {
            Logger::warning("handleMessageStream produced empty response after {$iteration} iterations, {$totalInputTokens} input / {$totalOutputTokens} output tokens");

            // Provide a clear user-facing message instead of silence
            $fallbackMsg = 'The AI model returned an empty response. This can happen with certain models — please try again or switch to a different model.';
            $emit('text_delta', ['delta' => $fallbackMsg]);
            $fullText = $fallbackMsg;
        } else {
            Logger::info("handleMessageStream complete: {$iteration} iterations, {$totalInputTokens} input / {$totalOutputTokens} output tokens");
        }

        $finalText = $fullText ?: null;
        if ($finalText !== null) {
            $messages[] = [
                'role' => MessageRole::Assistant->value,
                'content' => $finalText,
            ];
        }

        return [
            'text' => $finalText,
            'newMessages' => array_slice($messages, $historyCount),
            'inputTokens' => $totalInputTokens,
            'outputTokens' => $totalOutputTokens,
            'debug' => $this->buildDebugPayload($systemPrompt, $model, $settings, $messages, $iteration, $historyCount),
        ];
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

        // Inject active site handle so tools can scope queries to the current site
        if ($this->activeSiteHandle !== null && !isset($arguments['_siteHandle'])) {
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
        Settings $settings,
        array $messages,
        int $iterations,
        int $historyCount,
    ): array {
        $provider = CoPilot::getInstance()->providerService->getActiveProvider();

        return [
            'systemPrompt' => $systemPrompt,
            'model' => $model ?? $provider->getModel(),
            'provider' => $settings->defaultProvider,
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
