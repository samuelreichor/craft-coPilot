<?php

namespace samuelreichor\coPilot\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use samuelreichor\coPilot\constants\Constants;
use samuelreichor\coPilot\CoPilot;
use samuelreichor\coPilot\enums\AuditStatus;
use yii\db\JsonExpression;

/**
 * Logs tool executions for traceability and security auditing.
 */
class AuditService extends Component
{
    /** @var int[] IDs of audit entries created during the current request */
    private array $pendingIds = [];

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $result
     */
    public function log(string $toolName, array $params, array $result): void
    {
        $user = Craft::$app->getUser()->getIdentity();
        if (!$user) {
            return;
        }

        $status = isset($result['error']) ? AuditStatus::Denied->value : AuditStatus::Success->value;
        $db = Craft::$app->getDb();

        $db->createCommand()->insert(
            Constants::TABLE_AUDIT_LOG,
            [
                'userId' => $user->id,
                'toolName' => $toolName,
                'entryId' => $params['entryId'] ?? $result['entryId'] ?? null,
                'fieldHandle' => $params['fieldHandle'] ?? null,
                'action' => $this->resolveAction($toolName),
                'status' => $status,
                'details' => new JsonExpression([
                    'params' => $params,
                    'resultSummary' => $this->summarizeResult($result),
                ]),
                'dateCreated' => (new \DateTime())->format('Y-m-d H:i:s'),
            ],
        )->execute();

        $this->pendingIds[] = (int) $db->getLastInsertID();
    }

    public function linkToConversation(int $conversationId): void
    {
        if ($this->pendingIds === []) {
            return;
        }

        Craft::$app->getDb()->createCommand()->update(
            Constants::TABLE_AUDIT_LOG,
            ['conversationId' => $conversationId],
            ['id' => $this->pendingIds],
        )->execute();

        $this->pendingIds = [];
    }

    public function purgeOldLogs(): void
    {
        $days = CoPilot::getInstance()->getSettings()->auditLogRetentionDays;

        if ($days <= 0) {
            return;
        }

        $cutoff = (new \DateTime())->modify("-{$days} days");

        Craft::$app->getDb()->createCommand()->delete(
            Constants::TABLE_AUDIT_LOG,
            ['<', 'dateCreated', Db::prepareDateForDb($cutoff)],
        )->execute();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRecentLogs(int $limit = 50): array
    {
        return (new Query())
            ->from(Constants::TABLE_AUDIT_LOG)
            ->orderBy(['dateCreated' => SORT_DESC])
            ->limit($limit)
            ->all();
    }

    /**
     * @param array<string, int> $orderBy
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public function getWriteLogs(int $page = 1, int $perPage = 25, ?string $search = null, ?int $siteId = null, array $orderBy = [], ?string $action = null): array
    {
        $query = (new Query())
            ->from(['a' => Constants::TABLE_AUDIT_LOG])
            ->leftJoin(['u' => '{{%users}}'], '[[a.userId]] = [[u.id]]')
            ->select([
                'a.id',
                'a.userId',
                'a.conversationId',
                'a.toolName',
                'a.entryId',
                'a.fieldHandle',
                'a.action',
                'a.status',
                'a.details',
                'a.dateCreated',
                'u.username',
                'u.fullName',
            ])
            ->where(['in', 'a.action', ['create', 'update']])
            ->orderBy($orderBy !== [] ? $orderBy : ['a.dateCreated' => SORT_DESC]);

        if ($siteId) {
            $query->innerJoin(
                ['es' => '{{%elements_sites}}'],
                '[[a.entryId]] = [[es.elementId]] AND [[es.siteId]] = :siteId',
                [':siteId' => $siteId],
            );
        }

        if ($search) {
            $query->andWhere([
                'or',
                ['like', 'u.fullName', $search],
                ['like', 'u.username', $search],
                ['like', 'a.toolName', $search],
                ['like', 'a.action', $search],
                ['like', 'a.details', $search],
            ]);
        }

        if ($action) {
            $query->andWhere(['a.action' => $action]);
        }

        $total = (int) $query->count();

        $offset = ($page - 1) * $perPage;
        $items = $query->offset($offset)->limit($perPage)->all();

        foreach ($items as &$item) {
            $item['details'] = $this->decodeDetails($item['details']);
        }

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeDetails(?string $raw): array
    {
        $decoded = json_decode($raw ?? '{}', true);

        return is_array($decoded) ? $decoded : [];
    }

    private function resolveAction(string $toolName): string
    {
        return match ($toolName) {
            'readEntry', 'readEntries', 'readAsset', 'listSections', 'listSites', 'describeSection', 'describeEntryType' => 'read',
            'updateField', 'updateEntry', 'publishEntry' => 'update',
            'createEntry' => 'create',
            'searchEntries', 'searchAssets', 'searchUsers', 'searchTags' => 'search',
            default => 'unknown',
        };
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function summarizeResult(array $result): array
    {
        if (isset($result['error'])) {
            return ['error' => $result['error']];
        }

        if (isset($result['total'])) {
            return ['total' => $result['total']];
        }

        $summary = [];

        if (isset($result['success'])) {
            $summary['success'] = true;
        }

        if (isset($result['draftId'])) {
            $summary['draftId'] = $result['draftId'];
        }

        if (isset($result['entryId'])) {
            $summary['entryId'] = $result['entryId'];
        }

        if (isset($result['entryTitle'])) {
            $summary['entryTitle'] = $result['entryTitle'];
        }

        if (isset($result['cpEditUrl'])) {
            $summary['cpEditUrl'] = $result['cpEditUrl'];
        }

        if (isset($result['diff'])) {
            $summary['diff'] = $this->sanitizeDiff($result['diff']);
        }

        return $summary !== [] ? $summary : ['hasData' => true];
    }

    /**
     * @param mixed $diff
     * @return array<string, array{old: string|null, new: string|null}>
     */
    private function sanitizeDiff(mixed $diff): array
    {
        if (!is_array($diff)) {
            return [];
        }

        // Single-field diff from updateField: {old: ..., new: ...}
        if (array_key_exists('old', $diff) && array_key_exists('new', $diff)) {
            return [
                '_value' => [
                    'old' => $this->valueToString($diff['old']),
                    'new' => $this->valueToString($diff['new']),
                ],
            ];
        }

        // Multi-field diff from updateEntry/createEntry: {fieldHandle: {old: ..., new: ...}, ...}
        $sanitized = [];

        foreach ($diff as $fieldHandle => $fieldDiff) {
            if (is_array($fieldDiff) && array_key_exists('old', $fieldDiff) && array_key_exists('new', $fieldDiff)) {
                $sanitized[$fieldHandle] = [
                    'old' => $this->valueToString($fieldDiff['old']),
                    'new' => $this->valueToString($fieldDiff['new']),
                ];
            }
        }

        return $sanitized;
    }

    private function valueToString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '(array)';
        }

        return '(complex value)';
    }
}
