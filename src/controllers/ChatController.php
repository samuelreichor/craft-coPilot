<?php

namespace samuelreichor\coPilot\controllers;

use Craft;
use craft\helpers\Cp;
use craft\web\Controller;
use samuelreichor\coPilot\constants\Constants;
use samuelreichor\coPilot\CoPilot;
use samuelreichor\coPilot\enums\MessageRole;
use samuelreichor\coPilot\helpers\Logger;
use samuelreichor\coPilot\models\Conversation;
use samuelreichor\coPilot\models\Message;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class ChatController extends Controller
{
    protected array|bool|int $allowAnonymous = false;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Constants::PERMISSION_VIEW_CHAT);

        return true;
    }

    /**
     * GET /admin/co-pilot
     * GET /admin/co-pilot/{conversationId}
     */
    public function actionIndex(?int $conversationId = null): Response
    {
        $plugin = CoPilot::getInstance();
        $canViewAll = Craft::$app->getUser()->checkPermission(Constants::PERMISSION_VIEW_OTHER_USERS_CHATS);
        $conversations = $canViewAll
            ? $plugin->conversationService->getAll(contextType: 'global')
            : $plugin->conversationService->getForCurrentUser(contextType: 'global');

        $user = Craft::$app->getUser();
        $currentUserId = $user->getId();

        $conversationsData = array_map(fn(Conversation $c) => [
            'id' => $c->id,
            'title' => $c->title,
            'dateUpdated' => $c->dateUpdated,
            'userId' => $c->userId,
        ], $conversations);

        $contextId = $this->request->getQueryParam('entryId');

        $activeConversationId = null;
        if ($conversationId) {
            try {
                $this->getConversation($conversationId);
                $activeConversationId = $conversationId;
            } catch (\Throwable) {
                // Not found or no access — show empty chat
            }
        }

        $permissions = [
            'createChat' => $user->checkPermission(Constants::PERMISSION_CREATE_CHAT),
            'deleteChat' => $user->checkPermission(Constants::PERMISSION_DELETE_CHAT),
            'deleteOtherUsersChats' => $user->checkPermission(Constants::PERMISSION_DELETE_OTHER_USERS_CHATS),
            'editOtherUsersChats' => $user->checkPermission(Constants::PERMISSION_EDIT_OTHER_USERS_CHATS),
            'changeExecutionMode' => $user->checkPermission(Constants::PERMISSION_CHANGE_EXECUTION_MODE),
            'changeProvider' => $user->checkPermission(Constants::PERMISSION_CHANGE_PROVIDER),
            'changeModel' => $user->checkPermission(Constants::PERMISSION_CHANGE_MODEL),
        ];

        return $this->renderTemplate('co-pilot/chat/index', [
            'conversationsJson' => json_encode($conversationsData),
            'contextId' => $contextId ? (int)$contextId : null,
            'activeConversationId' => $activeConversationId,
            'selectedSite' => Cp::requestedSite(),
            'currentUserId' => $currentUserId,
            'permissionsJson' => json_encode($permissions),
        ]);
    }

    /**
     * POST /actions/co-pilot/chat/get-commands
     */
    public function actionGetCommands(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $commands = CoPilot::getInstance()->commandService->getCommandDefinitions();

        return $this->asJson($commands);
    }

    /**
     * POST /actions/co-pilot/chat/get-models
     */
    public function actionGetModels(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $plugin = CoPilot::getInstance();
        $settings = $plugin->getSettings();
        $configuredProviders = $plugin->providerService->getConfiguredProviders();
        $defaultProvider = $plugin->providerService->getActiveProvider();

        $providers = [];
        foreach ($configuredProviders as $handle => $provider) {
            $models = $provider->getAvailableModels();
            if ($models) {
                $providers[] = [
                    'handle' => $handle,
                    'name' => $provider->getName(),
                    'models' => $models,
                    'defaultModel' => $provider->getModel(),
                ];
            }
        }

        return $this->asJson([
            'provider' => $settings->defaultProvider,
            'providerName' => $defaultProvider->getName(),
            'models' => $defaultProvider->getAvailableModels(),
            'currentModel' => $defaultProvider->getModel(),
            'providers' => $providers,
        ]);
    }

    /**
     * POST /actions/co-pilot/chat/load-conversation
     */
    public function actionLoadConversation(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $id = $this->request->getRequiredBodyParam('id');
        $conversation = $this->getConversation((int)$id);

        return $this->asJson([
            'id' => $conversation->id,
            'title' => $conversation->title,
            'contextId' => $conversation->contextId,
            'messages' => $this->buildUiMessages($conversation->messages),
        ]);
    }

    /**
     * POST /actions/co-pilot/chat/delete-conversation
     */
    public function actionDeleteConversation(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $id = $this->request->getRequiredBodyParam('id');
        $conversation = $this->getConversation((int)$id);

        $user = Craft::$app->getUser()->getIdentity();
        if ($user && $conversation->userId === $user->id) {
            $this->requirePermission(Constants::PERMISSION_DELETE_CHAT);
        } else {
            $this->requirePermission(Constants::PERMISSION_DELETE_OTHER_USERS_CHATS);
        }

        CoPilot::getInstance()->conversationService->deleteById((int)$id);

        return $this->asJson(['success' => true]);
    }

    /**
     * POST /actions/co-pilot/chat/export-debug
     */
    public function actionExportDebug(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $id = $this->request->getRequiredBodyParam('id');
        $conversation = $this->getConversation((int)$id);

        $auditLog = (new \craft\db\Query())
            ->from(Constants::TABLE_AUDIT_LOG)
            ->where(['conversationId' => $conversation->id])
            ->orderBy(['dateCreated' => SORT_ASC])
            ->all();

        $user = Craft::$app->getUser()->getIdentity();

        $plugin = CoPilot::getInstance();

        $export = [
            'meta' => [
                'conversationId' => $conversation->id,
                'title' => $conversation->title,
                'userId' => $conversation->userId,
                'contextType' => $conversation->contextType,
                'contextId' => $conversation->contextId,
                'exportedAt' => (new \DateTimeImmutable())->format('c'),
                'exportedBy' => $user ? $user->username : null,
                'craftVersion' => Craft::$app->getVersion(),
                'copilotVersion' => $plugin->getVersion(),
                'phpVersion' => PHP_VERSION,
            ],
            'systemPrompt' => $conversation->lastSystemPrompt,
            'turns' => $conversation->debugLog,
            'auditLog' => $auditLog,
        ];

        return $this->asJson($export);
    }

    /**
     * GET /actions/co-pilot/chat/open-element?elementId=123
     */
    public function actionOpenElement(): Response
    {
        $elementId = (int)$this->request->getRequiredQueryParam('elementId');
        $siteId = $this->request->getQueryParam('siteId');

        $element = null;
        if ($siteId) {
            $element = Craft::$app->getElements()->getElementById($elementId, null, (int)$siteId);
        }

        if ($element === null) {
            $element = Craft::$app->getElements()->getElementById($elementId);
        }

        if ($element === null) {
            throw new NotFoundHttpException('Element not found.');
        }

        $editUrl = $element->getCpEditUrl();
        if ($editUrl === null) {
            throw new NotFoundHttpException('Element has no edit URL.');
        }

        return $this->redirect($editUrl);
    }

    /**
     * POST /actions/co-pilot/chat/get-conversations
     */
    public function actionGetConversations(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $user = Craft::$app->getUser();
        $canViewAll = $user->checkPermission(Constants::PERMISSION_VIEW_OTHER_USERS_CHATS);

        $conversations = $canViewAll
            ? CoPilot::getInstance()->conversationService->getAll(contextType: 'global')
            : CoPilot::getInstance()->conversationService->getForCurrentUser(contextType: 'global');

        $data = array_map(fn(Conversation $c) => [
            'id' => $c->id,
            'title' => $c->title,
            'dateUpdated' => $c->dateUpdated,
            'userId' => $c->userId,
        ], $conversations);

        return $this->asJson($data);
    }

    /**
     * POST /actions/co-pilot/chat/get-entry-conversations
     */
    public function actionGetEntryConversations(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $contextId = (int)$this->request->getRequiredBodyParam('contextId');
        $user = Craft::$app->getUser();
        $identity = $user->getIdentity();
        if (!$identity) {
            return $this->asJson([]);
        }

        $canViewAll = $user->checkPermission(Constants::PERMISSION_VIEW_OTHER_USERS_CHATS);

        $conversations = $canViewAll
            ? CoPilot::getInstance()->conversationService->getAllForContextAllUsers('entry', $contextId)
            : CoPilot::getInstance()->conversationService->getAllForContext($identity->id, 'entry', $contextId);

        $data = array_map(fn(Conversation $c) => [
            'id' => $c->id,
            'title' => $c->title,
            'dateUpdated' => $c->dateUpdated,
            'userId' => $c->userId,
        ], $conversations);

        return $this->asJson($data);
    }

    /**
     * POST /actions/co-pilot/chat/load-entry-conversation
     */
    public function actionLoadEntryConversation(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $contextId = $this->request->getRequiredBodyParam('contextId');
        $user = Craft::$app->getUser()->getIdentity();
        if (!$user) {
            return $this->asJson(['id' => null, 'messages' => []]);
        }

        $conversation = CoPilot::getInstance()->conversationService->getByContext(
            $user->id,
            'entry',
            (int)$contextId,
        );

        if ($conversation === null) {
            return $this->asJson(['id' => null, 'messages' => []]);
        }

        return $this->asJson([
            'id' => $conversation->id,
            'messages' => $this->buildUiMessages($conversation->messages),
        ]);
    }

    /**
     * POST /actions/co-pilot/chat/send
     */
    public function actionSend(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission(Constants::PERMISSION_CREATE_CHAT);

        $plugin = CoPilot::getInstance();
        $message = $this->request->getRequiredBodyParam('message');
        $contextId = $this->request->getBodyParam('contextId');
        $conversationId = $this->request->getBodyParam('conversationId');
        $contextType = $this->request->getBodyParam('contextType');
        $model = $this->request->getBodyParam('model');
        $attachments = $this->request->getBodyParam('attachments') ?? [];

        $messages = [];
        if ($conversationId) {
            $conversation = $this->getConversation((int)$conversationId);
            $messages = $conversation->messages;
        }

        $siteHandle = $this->request->getBodyParam('siteHandle');
        $executionMode = $this->request->getBodyParam('executionMode');
        $providerHandle = $this->request->getBodyParam('provider');

        $result = $plugin
            ->agentService
            ->handleMessage(
                $message,
                $contextId ? (int)$contextId : null,
                $messages,
                $model ?: null,
                $attachments,
                $siteHandle,
                $executionMode,
                $providerHandle,
            );

        $persistContextType = $contextType === 'entry' ? 'entry' : 'global';
        $persistContextId = $persistContextType === 'entry' && $contextId ? (int)$contextId : null;
        $isNewConversation = $conversationId === null;

        $conversationId = $this->persistConversation(
            $conversationId ? (int)$conversationId : null,
            $result['newMessages'],
            $persistContextType,
            $persistContextId,
            $result['debug'],
            $result['inputTokens'],
            $result['outputTokens'],
        );

        $plugin->auditService->linkToConversation($conversationId);

        if ($isNewConversation && $conversationId) {
            $providerHandle = $this->request->getBodyParam('provider');
            $this->generateAndUpdateTitle($conversationId, $message, $providerHandle);
        }

        $includeDebug = (bool) $this->request->getBodyParam('debug');
        if ($includeDebug && isset($result['debug']['messages'])) {
            $result['debugMessages'] = $result['debug']['messages'];
        }

        unset($result['debug'], $result['newMessages']);
        $result['conversationId'] = $conversationId;

        return $this->asJson($result);
    }

    /**
     * POST /actions/co-pilot/chat/send-stream
     */
    public function actionSendStream(): void
    {
        $this->requirePostRequest();
        $this->requirePermission(Constants::PERMISSION_CREATE_CHAT);

        // Ensure the script completes even if the client disconnects mid-stream,
        // so that persistConversation() is always reached.
        ignore_user_abort(true);

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        $plugin = CoPilot::getInstance();

        $rawBody = $this->request->getRawBody();
        $body = json_decode($rawBody, true) ?? [];

        $message = $body['message'] ?? '';
        $contextId = $body['contextId'] ?? null;
        $conversationId = $body['conversationId'] ?? null;
        $contextType = $body['contextType'] ?? 'global';
        $model = $body['model'] ?? null;
        $attachments = $body['attachments'] ?? [];
        $siteHandle = $body['siteHandle'] ?? null;
        $executionMode = $body['executionMode'] ?? null;
        $providerHandle = $body['provider'] ?? null;

        if ($message === '') {
            $this->sendSSE('error', ['message' => 'Message is required.']);
            $this->endSSE();
            return;
        }

        $messages = [];
        if ($conversationId) {
            $conversation = $this->getConversation((int)$conversationId);
            $messages = $conversation->messages;
        }

        $result = $plugin
            ->agentService
            ->handleMessageStream(
                $message,
                $contextId ? (int)$contextId : null,
                $messages,
                $model ?: null,
                function(string $eventType, array $data): void {
                    $this->sendSSE($eventType, $data);
                },
                $attachments,
                $siteHandle,
                $executionMode,
                $providerHandle,
            );

        $persistContextType = $contextType === 'entry' ? 'entry' : 'global';
        $persistContextId = $persistContextType === 'entry' && $contextId ? (int)$contextId : null;

        $isNewConversation = $conversationId === null;

        $conversationId = $this->persistConversation(
            $conversationId ? (int)$conversationId : null,
            $result['newMessages'],
            $persistContextType,
            $persistContextId,
            $result['debug'],
            $result['inputTokens'],
            $result['outputTokens'],
        );

        $plugin->auditService->linkToConversation($conversationId);

        if ($result['text'] === null) {
            Logger::warning('Stream response completed with no text content for message: '
                . mb_substr($message, 0, 100));
        }

        // Send done immediately so the client stops waiting
        $this->sendSSE('done', [
            'conversationId' => $conversationId,
            'inputTokens' => $result['inputTokens'],
            'outputTokens' => $result['outputTokens'],
        ]);

        // Generate a proper title after the stream is done (client won't notice the delay)
        if ($isNewConversation && $conversationId) {
            $this->generateAndUpdateTitle($conversationId, $message, $providerHandle);
        }

        $this->endSSE();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function sendSSE(string $event, array $data): void
    {
        echo "event: {$event}\ndata: " . json_encode($data) . "\n\n";
        flush();
    }

    private function endSSE(): void
    {
        try {
            Craft::$app->end();
        } catch (\yii\web\HeadersAlreadySentException) { // @phpstan-ignore catch.neverThrown
            // Expected for SSE — headers were already sent via header()
            exit;
        }
    }

    /**
     * @throws NotFoundHttpException
     * @throws ForbiddenHttpException|\Throwable
     */
    private function getConversation(int $id): Conversation
    {
        $conversation = CoPilot::getInstance()->conversationService->getById($id);
        if ($conversation === null) {
            throw new NotFoundHttpException('Conversation not found.');
        }

        $user = Craft::$app->getUser()->getIdentity();
        if (!$user) {
            throw new ForbiddenHttpException('You must be logged in.');
        }

        if ($conversation->userId !== $user->id) {
            $this->requirePermission(Constants::PERMISSION_VIEW_OTHER_USERS_CHATS);
        }

        return $conversation;
    }

    /**
     * @param array<int, array<string, mixed>> $newMessages
     * @param array<string, mixed>|null $debug
     */
    private function persistConversation(
        ?int $conversationId,
        array $newMessages,
        string $contextType,
        ?int $contextId,
        ?array $debug = null,
        int $inputTokens = 0,
        int $outputTokens = 0,
    ): ?int {
        $user = Craft::$app->getUser()->getIdentity();
        if (!$user) {
            return null;
        }

        $plugin = CoPilot::getInstance();
        $conversation = null;

        if ($conversationId) {
            $conversation = $plugin->conversationService->getById($conversationId);
        }

        if ($conversation === null) {
            $title = mb_substr($this->extractUserMessage($newMessages), 0, 100) ?: 'New conversation';

            $conversation = new Conversation(
                userId: $user->id,
                title: $title,
                contextType: $contextType,
                contextId: $contextId,
            );
        }

        foreach ($newMessages as $msg) {
            $conversation->addMessage(Message::fromArray($msg));
        }

        if ($debug !== null) {
            $conversation->addDebugTurn($debug, $inputTokens, $outputTokens);
        }

        try {
            $plugin->conversationService->save($conversation);
        } catch (\Throwable $e) {
            Logger::error('Failed to save conversation: ' . $e->getMessage());

            throw $e;
        }

        return $conversation->id;
    }

    /**
     * Collects tool call metadata from Tool-role messages and attaches them
     * to the next assistant message with content.
     *
     * @param Message[] $messages
     * @return array<int, array<string, mixed>>
     */
    /**
     * Collapses agent-loop messages into one assistant UI message per turn.
     * Each turn: user message → (N agent iterations) → final assistant text.
     * Result: tool calls from all iterations + only the final assistant text.
     *
     * @param Message[] $messages
     * @return array<int, array<string, mixed>>
     */
    private function buildUiMessages(array $messages): array
    {
        $uiMessages = [];
        /** @var array<int, array{name: string|null, success: bool, entryId: int|null, entryTitle: string|null, cpEditUrl: string|null}> $turnToolCalls */
        $turnToolCalls = [];
        /** @var string|null $lastAssistantText */
        $lastAssistantText = null;

        foreach ($messages as $msg) {
            if ($msg->role === MessageRole::Tool) {
                $content = is_array($msg->content) ? $msg->content : [];
                $turnToolCalls[] = [
                    'name' => $msg->toolName,
                    'success' => $msg->isError !== true,
                    'entryId' => isset($content['entryId']) ? (int)$content['entryId'] : null,
                    'entryTitle' => $content['entryTitle'] ?? null,
                    'cpEditUrl' => $content['cpEditUrl'] ?? null,
                ];

                continue;
            }

            if ($msg->role === MessageRole::Assistant) {
                // Track the latest assistant text (overwrite previous narration)
                if ($msg->content !== null) {
                    $lastAssistantText = is_string($msg->content) ? $msg->content : json_encode($msg->content);
                }

                continue;
            }

            if ($msg->role === MessageRole::User) {
                // Flush any pending assistant turn before adding the next user message
                if ($lastAssistantText !== null) {
                    $assistantMsg = [
                        'role' => MessageRole::Assistant->value,
                        'content' => $lastAssistantText,
                    ];

                    if ($turnToolCalls !== []) {
                        $assistantMsg['toolCalls'] = $turnToolCalls;
                        $turnToolCalls = [];
                    }

                    $uiMessages[] = $assistantMsg;
                    $lastAssistantText = null;
                }

                $uiMessages[] = $msg->toArray();
            }
        }

        // Flush the last assistant turn
        if ($lastAssistantText !== null) {
            $assistantMsg = [
                'role' => MessageRole::Assistant->value,
                'content' => $lastAssistantText,
            ];

            if ($turnToolCalls !== []) {
                $assistantMsg['toolCalls'] = $turnToolCalls;
            }

            $uiMessages[] = $assistantMsg;
        }

        return $uiMessages;
    }

    /**
     * POST /actions/co-pilot/chat/compact-conversation
     */
    public function actionCompactConversation(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $id = $this->request->getRequiredBodyParam('id');
        $conversation = $this->getConversation((int)$id);

        if (empty($conversation->messages)) {
            return $this->asJson(['success' => false, 'error' => 'No messages to compact.']);
        }

        $serialized = '';
        foreach ($conversation->messages as $msg) {
            $role = $msg->role->value;
            $content = is_array($msg->content) ? json_encode($msg->content) : ($msg->content ?? '');
            if ($content !== '') {
                $serialized .= "{$role}: {$content}\n";
            }
        }

        try {
            $provider = CoPilot::getInstance()->providerService->getActiveProvider();
            $response = $provider->chat(
                'You summarize conversations concisely. Preserve all important context, decisions, and facts so the conversation can continue meaningfully. Respond with only the summary, no preamble.',
                [['role' => 'user', 'content' => "Summarize this conversation:\n\n" . $serialized]],
                [],
            );

            $summary = trim($response->text ?? '');
            if ($summary === '') {
                return $this->asJson(['success' => false, 'error' => 'AI returned an empty summary.']);
            }
        } catch (\Throwable $e) {
            Logger::error('Compact conversation failed: ' . $e->getMessage());

            return $this->asJson(['success' => false, 'error' => 'Failed to generate summary.']);
        }

        $conversation->replaceMessages([
            Message::fromArray(['role' => MessageRole::Assistant->value, 'content' => $summary]),
        ]);

        try {
            CoPilot::getInstance()->conversationService->save($conversation);
        } catch (\Throwable $e) {
            Logger::error('Failed to save compacted conversation: ' . $e->getMessage());

            return $this->asJson(['success' => false, 'error' => 'Failed to save compacted conversation.']);
        }

        return $this->asJson([
            'success' => true,
            'summary' => $summary,
            'conversationId' => $conversation->id,
        ]);
    }

    /**
     * Generates a title with a fast model and updates the conversation in the database.
     * Called after the stream's done event so the client doesn't wait for it.
     */
    private function generateAndUpdateTitle(int $conversationId, string $userMessage, ?string $providerHandle): void
    {
        try {
            $plugin = CoPilot::getInstance();
            $provider = $plugin->providerService->getActiveProvider($providerHandle);
            $response = $provider->chat(
                'You generate ultra-short conversation titles. Respond with only 2-4 words, no punctuation.',
                [['role' => 'user', 'content' => 'Summarize this message as a title: ' . mb_substr($userMessage, 0, 200)]],
                [],
                $provider->getTitleModel(),
            );

            $title = trim($response->text ?? '');
            if ($title === '') {
                return;
            }

            $title = mb_substr($title, 0, 100);

            Craft::$app->getDb()->createCommand()->update(
                Constants::TABLE_CONVERSATIONS,
                ['title' => $title],
                ['id' => $conversationId],
            )->execute();
        } catch (\Throwable $e) {
            Logger::warning('Failed to generate title: ' . $e->getMessage());
        }
    }

    /**
     * Extracts the first user message from a new-messages array.
     *
     * @param array<int, array<string, mixed>> $messages
     */
    private function extractUserMessage(array $messages): string
    {
        foreach ($messages as $msg) {
            if (($msg['role'] ?? '') === MessageRole::User->value && is_string($msg['content'] ?? null)) {
                return $msg['content'];
            }
        }

        return '';
    }
}
