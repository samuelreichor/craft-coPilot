<?php

namespace samuelreichor\coPilot\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use samuelreichor\coPilot\constants\Constants;
use samuelreichor\coPilot\helpers\Logger;
use samuelreichor\coPilot\models\Conversation;
use samuelreichor\coPilot\models\Message;

/**
 * Manages conversation persistence.
 */
class ConversationService extends Component
{
    public function save(Conversation $conversation): bool
    {
        $tableSchema = Craft::$app->getDb()->getSchema()->getTableSchema(Constants::TABLE_CONVERSATIONS);
        $messagesDbType = $tableSchema->columns['messages']->dbType ?? null;
        $messages = Db::prepareValueForDb($conversation->getMessagesArray(), $messagesDbType);
        $debugLog = !empty($conversation->debugLog) ? json_encode([
            'systemPrompt' => $conversation->lastSystemPrompt,
            'turns' => $conversation->debugLog,
        ]) : null;

        if ($conversation->id) {
            Craft::$app->getDb()->createCommand()->update(
                Constants::TABLE_CONVERSATIONS,
                [
                    'title' => $conversation->title,
                    'messages' => $messages,
                    'debugLog' => $debugLog,
                    'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
                ],
                ['id' => $conversation->id],
            )->execute();

            return true;
        }

        Craft::$app->getDb()->createCommand()->insert(
            Constants::TABLE_CONVERSATIONS,
            [
                'userId' => $conversation->userId,
                'title' => $conversation->title,
                'contextType' => $conversation->contextType,
                'contextId' => $conversation->contextId,
                'messages' => $messages,
                'debugLog' => $debugLog,
                'dateCreated' => Db::prepareDateForDb(new \DateTime()),
                'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
                'uid' => StringHelper::UUID(),
            ],
        )->execute();

        $lastId = Craft::$app->getDb()->getLastInsertID();
        if ($lastId !== null && $lastId !== '' && $lastId !== false) {
            $conversation->id = (int)$lastId;
        }

        return true;
    }

    public function getById(int $id): ?Conversation
    {
        $row = (new Query())
            ->from(Constants::TABLE_CONVERSATIONS)
            ->where(['id' => $id])
            ->one();

        if (!$row) {
            return null;
        }

        return $this->hydrateConversation($row);
    }

    /**
     * @return Conversation[]
     */
    public function getAll(int $limit = 20, ?string $contextType = null): array
    {
        $query = (new Query())
            ->from(Constants::TABLE_CONVERSATIONS)
            ->orderBy(['dateUpdated' => SORT_DESC])
            ->limit($limit);

        if ($contextType !== null) {
            $query->andWhere(['contextType' => $contextType]);
        }

        $rows = $query->all();

        return array_map(fn(array $row) => $this->hydrateConversation($row), $rows);
    }

    /**
     * @return Conversation[]
     */
    public function getForCurrentUser(int $limit = 20, ?string $contextType = null): array
    {
        $user = Craft::$app->getUser()->getIdentity();
        if (!$user) {
            return [];
        }

        $query = (new Query())
            ->from(Constants::TABLE_CONVERSATIONS)
            ->where(['userId' => $user->id])
            ->orderBy(['dateUpdated' => SORT_DESC])
            ->limit($limit);

        if ($contextType !== null) {
            $query->andWhere(['contextType' => $contextType]);
        }

        $rows = $query->all();

        return array_map(fn(array $row) => $this->hydrateConversation($row), $rows);
    }

    public function getByContext(int $userId, string $contextType, int $contextId): ?Conversation
    {
        $row = (new Query())
            ->from(Constants::TABLE_CONVERSATIONS)
            ->where([
                'userId' => $userId,
                'contextType' => $contextType,
                'contextId' => $contextId,
            ])
            ->orderBy(['dateUpdated' => SORT_DESC])
            ->limit(1)
            ->one();

        if (!$row) {
            return null;
        }

        return $this->hydrateConversation($row);
    }

    /**
     * @return Conversation[]
     */
    public function getAllForContext(int $userId, string $contextType, int $contextId, int $limit = 20): array
    {
        $rows = (new Query())
            ->from(Constants::TABLE_CONVERSATIONS)
            ->where([
                'userId' => $userId,
                'contextType' => $contextType,
                'contextId' => $contextId,
            ])
            ->orderBy(['dateUpdated' => SORT_DESC])
            ->limit($limit)
            ->all();

        return array_map(fn(array $row) => $this->hydrateConversation($row), $rows);
    }

    /**
     * @return Conversation[]
     */
    public function getAllForContextAllUsers(string $contextType, int $contextId, int $limit = 20): array
    {
        $rows = (new Query())
            ->from(Constants::TABLE_CONVERSATIONS)
            ->where([
                'contextType' => $contextType,
                'contextId' => $contextId,
            ])
            ->orderBy(['dateUpdated' => SORT_DESC])
            ->limit($limit)
            ->all();

        return array_map(fn(array $row) => $this->hydrateConversation($row), $rows);
    }

    public function deleteById(int $id): bool
    {
        $affected = Craft::$app->getDb()->createCommand()->delete(
            Constants::TABLE_CONVERSATIONS,
            ['id' => $id],
        )->execute();

        return $affected > 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateConversation(array $row): Conversation
    {
        $conversation = new Conversation(
            userId: (int)$row['userId'],
            title: $row['title'] ?? 'New conversation',
            contextType: $row['contextType'] ?? null,
            contextId: $row['contextId'] ? (int)$row['contextId'] : null,
            id: (int)$row['id'],
            dateCreated: $row['dateCreated'] ?? null,
            dateUpdated: $row['dateUpdated'] ?? null,
        );

        $messagesData = json_decode($row['messages'] ?? '[]', true);
        if (!is_array($messagesData)) {
            $messagesData = [];
        }
        foreach ($messagesData as $msgData) {
            try {
                $conversation->addMessage(Message::fromArray($msgData));
            } catch (\InvalidArgumentException $e) {
                Logger::warning("Skipping invalid message in conversation #{$conversation->id}: " . $e->getMessage());
            }
        }

        $debugLog = json_decode($row['debugLog'] ?? '[]', true);
        $conversation->debugLog = $debugLog['turns'] ?? [];
        $conversation->lastSystemPrompt = $debugLog['systemPrompt'] ?? null;

        return $conversation;
    }
}
