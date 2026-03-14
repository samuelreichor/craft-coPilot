<?php

namespace samuelreichor\coPilot\tools;

use Craft;
use craft\elements\Entry;
use samuelreichor\coPilot\CoPilot;
use samuelreichor\coPilot\services\TokenEstimator;

class ReadEntriesTool implements ToolInterface
{
    private const MAX_IDS = 50;
    private const MAX_FULL_ENTRIES = 5;

    public function getName(): string
    {
        return 'readEntries';
    }

    public function getDescription(): string
    {
        return 'Batch-reads multiple Craft CMS entries by ID. Use this instead of calling readEntry in a loop. Summary mode returns metadata + filled/empty fields per entry. Full mode returns complete field values (max 5 entries).';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'entryIds' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'Array of Craft entry IDs to read (max 50)',
                ],
                'detail' => [
                    'type' => 'string',
                    'enum' => ['summary', 'full'],
                    'description' => 'Detail level. "summary" (default): metadata + filled/empty fields. "full": complete field values (max 5 entries).',
                ],
                'siteHandle' => [
                    'type' => 'string',
                    'description' => 'Optional site handle to read entries from a specific site (e.g. "evalDe"). Defaults to the current conversation site.',
                ],
            ],
            'required' => ['entryIds'],
        ];
    }

    public function execute(array $arguments): array
    {
        $entryIds = $arguments['entryIds'] ?? [];
        $detail = $arguments['detail'] ?? 'summary';
        $siteHandle = $arguments['siteHandle'] ?? $arguments['_siteHandle'] ?? null;
        $plugin = CoPilot::getInstance();

        if (!is_array($entryIds) || $entryIds === []) {
            return ['error' => 'entryIds must be a non-empty array of integers.'];
        }

        if (count($entryIds) > self::MAX_IDS) {
            return ['error' => 'Maximum ' . self::MAX_IDS . ' entry IDs per call.'];
        }

        if ($detail === 'full' && count($entryIds) > self::MAX_FULL_ENTRIES) {
            $entryIds = array_slice($entryIds, 0, self::MAX_FULL_ENTRIES);
        }

        $results = [];

        foreach ($entryIds as $entryId) {
            $entryId = (int) $entryId;

            $guard = $plugin->permissionGuard->canReadEntry($entryId);
            if (!$guard['allowed']) {
                $results[] = ['error' => "Entry #{$entryId}: " . $guard['reason']];
                continue;
            }

            $entry = null;
            if ($siteHandle) {
                $site = Craft::$app->getSites()->getSiteByHandle($siteHandle);
                if ($site) {
                    $entry = Entry::find()->id($entryId)->status(null)->drafts(null)->siteId($site->id)->one();
                }
            }

            // Fallback: search across all sites if not found on the active site
            if (!$entry) {
                $entry = Entry::find()->id($entryId)->status(null)->drafts(null)->site('*')->one();
            }

            if (!$entry) {
                $results[] = ['error' => "Entry #{$entryId} not found."];
                continue;
            }

            if ($detail === 'summary') {
                $results[] = $plugin->contextService->summarizeEntry($entry);
            } else {
                $settings = $plugin->getSettings();
                $data = $plugin->contextService->serializeEntry($entry, $settings->defaultSerializationDepth);
                if ($data === null) {
                    $results[] = ['error' => "Entry #{$entryId}: serialization was cancelled."];
                    continue;
                }
                $results[] = TokenEstimator::trim($data, $settings->maxContextTokens);
            }
        }

        return [
            'total' => count($results),
            'results' => $results,
        ];
    }
}
