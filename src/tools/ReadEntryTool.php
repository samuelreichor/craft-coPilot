<?php

namespace samuelreichor\coPilot\tools;

use Craft;
use craft\elements\Entry;
use samuelreichor\coPilot\CoPilot;
use samuelreichor\coPilot\services\TokenEstimator;

class ReadEntryTool implements ToolInterface
{
    public function getName(): string
    {
        return 'readEntry';
    }

    public function getDescription(): string
    {
        return 'Reads a single Craft CMS entry. Defaults to summary mode (metadata, filled/empty fields, content summary). Use detail=full for complete field values (before editing or when content details are needed).';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'entryId' => [
                    'type' => 'integer',
                    'description' => 'The Craft entry ID',
                ],
                'detail' => [
                    'type' => 'string',
                    'enum' => ['summary', 'full'],
                    'description' => 'Detail level. "summary" (default): metadata + filled/empty fields + content summary. "full": complete field values.',
                ],
                'depth' => [
                    'type' => 'integer',
                    'description' => 'Serialization depth for nested entries/relations (only used with detail=full). Default: 2. Max: 4.',
                ],
                'siteHandle' => [
                    'type' => 'string',
                    'description' => 'Optional site handle to read the entry from a specific site (e.g. "evalDe"). Defaults to the current conversation site.',
                ],
                'fields' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Optional: Load only specific field handles (only used with detail=full)',
                ],
            ],
            'required' => ['entryId'],
        ];
    }

    public function execute(array $arguments): array
    {
        $entryId = $arguments['entryId'];
        $detail = $arguments['detail'] ?? 'summary';
        $plugin = CoPilot::getInstance();

        $guard = $plugin->permissionGuard->canReadEntry($entryId);
        if (!$guard['allowed']) {
            return ['error' => $guard['reason']];
        }

        $siteHandle = $arguments['siteHandle'] ?? $arguments['_siteHandle'] ?? null;

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
            return ['error' => "Entry #{$entryId} not found."];
        }

        if ($detail === 'summary') {
            return $plugin->contextService->summarizeEntry($entry);
        }

        $settings = $plugin->getSettings();
        $depth = min($arguments['depth'] ?? $settings->defaultSerializationDepth, $settings->maxSerializationDepth);
        $fields = $arguments['fields'] ?? null;

        $data = $plugin->contextService->serializeEntry($entry, $depth, $fields);
        if ($data === null) {
            return ['error' => 'Entry serialization was cancelled.'];
        }

        $data = TokenEstimator::trim($data, $settings->maxContextTokens);

        return $data;
    }
}
