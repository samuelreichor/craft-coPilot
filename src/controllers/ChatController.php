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
        $conversations = $plugin->conversationService->getForCurrentUser(contextType: 'global');

        $conversationsData = array_map(fn(Conversation $c) => [
            'id' => $c->id,
            'title' => $c->title,
            'dateUpdated' => $c->dateUpdated,
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

        return $this->renderTemplate('co-pilot/chat/index', [
            'conversationsJson' => json_encode($conversationsData),
            'contextId' => $contextId ? (int)$contextId : null,
            'activeConversationId' => $activeConversationId,
            'selectedSite' => Cp::requestedSite(),
        ]);
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

        $modelProperty = $settings->defaultProvider . 'Model';
        $currentModel = $settings->$modelProperty ?? null;

        $providers = [];
        foreach ($configuredProviders as $handle => $provider) {
            $provModelProp = $handle . 'Model';
            $providers[] = [
                'handle' => $handle,
                'name' => $provider->getName(),
                'models' => $provider->getAvailableModels(),
                'defaultModel' => $settings->$provModelProp ?? null,
            ];
        }

        return $this->asJson([
            'provider' => $settings->defaultProvider,
            'providerName' => $defaultProvider->getName(),
            'models' => $defaultProvider->getAvailableModels(),
            'currentModel' => $currentModel,
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

        $export = [
            'meta' => [
                'conversationId' => $conversation->id,
                'title' => $conversation->title,
                'userId' => $conversation->userId,
                'contextType' => $conversation->contextType,
                'contextId' => $conversation->contextId,
                'exportedAt' => (new \DateTimeImmutable())->format('c'),
                'exportedBy' => $user ? $user->username : null,
            ],
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

        $element = Craft::$app->getElements()->getElementById($elementId);
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

        $conversations = CoPilot::getInstance()->conversationService->getForCurrentUser(contextType: 'global');

        $data = array_map(fn(Conversation $c) => [
            'id' => $c->id,
            'title' => $c->title,
            'dateUpdated' => $c->dateUpdated,
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

        $contextId = $this->request->getRequiredBodyParam('contextId');
        $user = Craft::$app->getUser()->getIdentity();
        if (!$user) {
            return $this->asJson([]);
        }

        $conversations = CoPilot::getInstance()->conversationService->getAllForContext(
            $user->id,
            'entry',
            (int)$contextId,
        );

        $data = array_map(fn(Conversation $c) => [
            'id' => $c->id,
            'title' => $c->title,
            'dateUpdated' => $c->dateUpdated,
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

        $this->sendSSE('done', [
            'conversationId' => $conversationId,
            'inputTokens' => $result['inputTokens'],
            'outputTokens' => $result['outputTokens'],
        ]);

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

        $title = 'New conversation';
        foreach ($newMessages as $msg) {
            if (($msg['role'] ?? '') === MessageRole::User->value && is_string($msg['content'] ?? null)) {
                $title = $this->generateTitle($msg['content']);
                break;
            }
        }

        if ($conversation === null) {
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

            return null;
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
    private function buildUiMessages(array $messages): array
    {
        $uiMessages = [];
        /** @var array<int, array{name: string|null, success: bool, entryId: int|null, entryTitle: string|null, cpEditUrl: string|null}> $pendingToolCalls */
        $pendingToolCalls = [];

        foreach ($messages as $msg) {
            if ($msg->role === MessageRole::Tool) {
                $content = is_array($msg->content) ? $msg->content : [];
                $pendingToolCalls[] = [
                    'name' => $msg->toolName,
                    'success' => $msg->isError !== true,
                    'entryId' => isset($content['entryId']) ? (int)$content['entryId'] : null,
                    'entryTitle' => $content['entryTitle'] ?? null,
                    'cpEditUrl' => $content['cpEditUrl'] ?? null,
                ];

                continue;
            }

            if ($msg->role === MessageRole::Assistant && $msg->content === null) {
                continue;
            }

            if ($msg->role === MessageRole::User) {
                $uiMessages[] = $msg->toArray();

                continue;
            }

            if ($msg->role === MessageRole::Assistant) {
                $data = $msg->toArray();

                if ($pendingToolCalls !== []) {
                    $data['toolCalls'] = $pendingToolCalls;
                    $pendingToolCalls = [];
                }

                $uiMessages[] = $data;
            }
        }

        return $uiMessages;
    }

    private function generateTitle(string $userMessage): string
    {
        $fallback = mb_substr($userMessage, 0, 100);

        try {
            $provider = CoPilot::getInstance()->providerService->getActiveProvider();
            $response = $provider->chat(
                'You generate ultra-short conversation titles. Respond with only 2-4 words, no punctuation.',
                [['role' => 'user', 'content' => 'Summarize this message as a title: ' . mb_substr($userMessage, 0, 200)]],
                [],
            );

            $title = trim($response->text ?? '');

            return $title !== '' ? mb_substr($title, 0, 100) : $fallback;
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
