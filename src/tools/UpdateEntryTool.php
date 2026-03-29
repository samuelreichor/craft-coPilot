<?php

namespace samuelreichor\coPilot\tools;

use samuelreichor\coPilot\enums\AuditAction;

class UpdateEntryTool extends AbstractEntryUpdateTool
{
    public function getName(): string
    {
        return 'updateEntry';
    }

    public function getLabel(): string
    {
        return 'Update Entry';
    }

    public function getAction(): AuditAction
    {
        return AuditAction::Update;
    }

    public function getDescription(): string
    {
        return 'Updates one or more fields of an existing entry in a single save (one revision). Can also change entry status: set "enabled" (true/false), "postDate" (ISO 8601), or "expiryDate" (ISO 8601 or null) inside the fields object. For Matrix fields: by default new blocks are appended. To replace all blocks use {"_replace": true, "blocks": [...]}. To clear all blocks use []. To update an existing block in-place, include "_blockId" in the block object. Never pass a Matrix block ID as entryId — always update blocks via the parent entry\'s Matrix field.';
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
                'siteHandle' => [
                    'type' => 'string',
                    'description' => 'Optional site handle to target a specific site version of the entry (e.g. "evalDe"). Defaults to the current conversation site.',
                ],
                'fields' => [
                    'type' => 'object',
                    'description' => 'An object mapping field handles to their new values. Native attributes: "title", "slug", "enabled" (boolean), "postDate" (ISO 8601 string), "expiryDate" (ISO 8601 string or null). Example: {"title": "New Title", "enabled": false}. Supports all field types (see Field Value Formats).',
                ],
            ],
            'required' => ['entryId', 'fields'],
        ];
    }

    public function execute(array $arguments): array
    {
        $entryId = $arguments['entryId'];
        $siteHandle = $arguments['siteHandle'] ?? $arguments['_siteHandle'] ?? null;

        $fields = $this->normalizeFields($arguments);

        if ($fields === null) {
            return [
                'error' => 'The "fields" parameter must be a non-empty object. Received keys: ' . implode(', ', array_keys($arguments)),
                'retryHint' => 'You MUST include a "fields" object with at least one field handle. Example: {"entryId": 123, "fields": {"title": "New Title"}}. Do NOT put field values at the top level.',
            ];
        }

        return $this->performUpdate($entryId, $siteHandle, $fields);
    }
}
