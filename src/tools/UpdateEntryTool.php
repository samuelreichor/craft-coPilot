<?php

namespace samuelreichor\coPilot\tools;

use Craft;
use craft\elements\Entry;
use samuelreichor\coPilot\CoPilot;
use samuelreichor\coPilot\enums\ElementUpdateBehavior;

class UpdateEntryTool implements ToolInterface
{
    public function getName(): string
    {
        return 'updateEntry';
    }

    public function getDescription(): string
    {
        return 'Updates one or more fields of an existing entry in a single save (one revision). Can also change entry status: set "enabled" (true/false), "postDate" (ISO 8601), or "expiryDate" (ISO 8601 or null) inside the fields object. For Matrix fields: by default new blocks are appended. To replace all blocks use {"_replace": true, "blocks": [...]}. To clear all blocks use []. To update a single Matrix block field, pass the block\'s _blockId as entryId.';
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

        // AI models sometimes send field values as top-level arguments instead of
        // wrapping them in a "fields" object. Detect and normalize this.
        if (!isset($arguments['fields'])) {
            $reserved = ['entryId', 'siteHandle', '_siteHandle'];
            $flatFields = array_diff_key($arguments, array_flip($reserved));
            $fields = $flatFields !== [] ? $flatFields : [];
        } else {
            $fields = $arguments['fields'];

            // Models may send native fields at top level alongside a "fields" object.
            // Merge them in so they aren't silently dropped.
            foreach (['title', 'slug', 'enabled', 'postDate', 'expiryDate'] as $native) {
                if (array_key_exists($native, $arguments) && !array_key_exists($native, $fields)) {
                    $fields[$native] = $arguments[$native];
                }
            }
        }

        if (!is_array($fields) || $fields === []) {
            return [
                'error' => 'The "fields" parameter must be a non-empty object.',
                'retryHint' => 'Provide at least one field handle with a value in the "fields" object.',
            ];
        }

        $plugin = CoPilot::getInstance();

        $guard = $plugin->permissionGuard->canWriteEntry($entryId);
        if (!$guard['allowed']) {
            return [
                'error' => $guard['reason'],
                'retryHint' => null,
            ];
        }

        // Correct siteId needed so nested elements inherit it
        $query = Entry::find()->id($entryId)->status(null)->drafts(null);

        if ($siteHandle) {
            $site = Craft::$app->getSites()->getSiteByHandle($siteHandle);
            if ($site) {
                $query->siteId($site->id);
            } else {
                $query->site('*');
            }
        } else {
            $query->site('*');
        }

        $entry = $query->one();
        if (!$entry) {
            return [
                'error' => "Entry #{$entryId} not found.",
                'retryHint' => null,
            ];
        }

        unset($fields['_type']);

        $diff = [];
        $skippedFields = [];
        $nativeFields = ['title', 'slug', 'enabled', 'postDate', 'expiryDate'];

        foreach ($fields as $fieldHandle => $value) {
            $oldValue = $this->getFieldValue($entry, $fieldHandle);

            if (in_array($fieldHandle, $nativeFields, true)) {
                if ($fieldHandle === 'enabled') {
                    $entry->enabled = (bool) $value;
                    $value = (bool) $value;
                } elseif ($fieldHandle === 'postDate' || $fieldHandle === 'expiryDate') {
                    $entry->{$fieldHandle} = $value !== null ? new \DateTime($value) : null;
                } else {
                    $entry->{$fieldHandle} = $value;
                }
            } else {
                $value = CoPilot::getInstance()->fieldNormalizer->normalize($fieldHandle, $value, $entry);

                try {
                    $entry->setFieldValue($fieldHandle, $value);
                } catch (\Throwable $e) {
                    $skippedFields[$fieldHandle] = $e->getMessage();

                    continue;
                }

                // Address elements only support the primary site, but Craft's
                // Addresses field copies the owner's siteId to new addresses.
                $this->fixNewAddressSiteIds($entry, $fieldHandle);
            }

            $diff[$fieldHandle] = [
                'old' => $oldValue,
                'new' => $value,
            ];
        }

        $settings = CoPilot::getInstance()->getSettings();
        $updateBehavior = ElementUpdateBehavior::tryFrom($settings->elementUpdateBehavior)
            ?? ElementUpdateBehavior::ProvisionalDraft;

        $targetEntry = $entry;
        $behaviorMessage = 'Entry updated successfully.';

        if ($updateBehavior !== ElementUpdateBehavior::DirectSave) {
            // Can't create a draft from a draft, fall back to direct save
            if ($entry->getIsDraft()) {
                $behaviorMessage = 'Draft entry updated directly.';
            } else {
                $user = Craft::$app->getUser()->getIdentity();
                if (!$user) {
                    return [
                        'error' => 'Access denied – no authenticated user.',
                        'retryHint' => null,
                    ];
                }

                $targetEntry = $this->resolveTargetEntry($entry, $user, $updateBehavior);
                $this->applyFields($targetEntry, $fields, $nativeFields);

                $behaviorMessage = match ($updateBehavior) {
                    ElementUpdateBehavior::Draft => 'Entry changes saved as a new draft.',
                    ElementUpdateBehavior::ProvisionalDraft => 'Entry changes saved as a provisional draft (unsaved changes visible in the control panel).',
                };
            }
        }

        try {
            $saved = Craft::$app->getElements()->saveElement($targetEntry);
        } catch (\craft\errors\UnsupportedSiteException $e) {
            $element = $e->element;
            $debugInfo = sprintf(
                'elementType=%s, elementId=%s, siteId=%s, entrySiteId=%s, siteHandle=%s',
                get_class($element),
                $element->id ?? 'new',
                $e->siteId,
                $entry->siteId,
                $siteHandle ?? 'null',
            );
            Craft::warning("UpdateEntry UnsupportedSiteException: {$debugInfo}", 'co-pilot');

            return [
                'error' => "Save failed: {$e->getMessage()} ({$debugInfo})",
                'retryHint' => null,
            ];
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            $retryHint = null;

            if (stripos($message, 'unknown property') !== false) {
                $retryHint = 'A field handle is incorrect (case-sensitive). Call describeSection to verify the exact handles and retry.';
            }

            return [
                'error' => "Save failed: {$message}",
                'retryHint' => $retryHint,
            ];
        }

        if (!$saved) {
            $errors = $targetEntry->getFirstErrors();

            foreach ($fields as $fieldHandle => $value) {
                if (in_array($fieldHandle, $nativeFields, true)) {
                    continue;
                }

                $nestedErrors = $this->collectNestedErrors($targetEntry, $fieldHandle);
                if (!empty($nestedErrors)) {
                    $errors["nestedElementErrors.{$fieldHandle}"] = $nestedErrors;
                }
            }

            return [
                'error' => 'Failed to save entry.',
                'validationErrors' => $errors,
                'retryHint' => 'Fix the fields listed in validationErrors and retry.',
            ];
        }

        $result = [
            'success' => true,
            'entryId' => $targetEntry->id,
            'entryTitle' => $targetEntry->title,
            'cpEditUrl' => $targetEntry->getCpEditUrl(),
            'updatedFields' => array_keys($diff),
            'diff' => $diff,
            'message' => $behaviorMessage . ' ' . count($diff) . ' field(s) changed.',
        ];

        if ($targetEntry->draftId) {
            $result['draftId'] = $targetEntry->draftId;
        }

        if ($skippedFields !== []) {
            $result['skippedFields'] = $skippedFields;
            $result['message'] .= ' ' . count($skippedFields) . ' field(s) skipped due to invalid handles.';
        }

        return $result;
    }

    private function resolveTargetEntry(Entry $entry, \craft\elements\User $user, ElementUpdateBehavior $behavior): Entry
    {
        if ($behavior === ElementUpdateBehavior::ProvisionalDraft) {
            $existingDraft = Entry::find()
                ->provisionalDrafts()
                ->draftOf($entry)
                ->draftCreator($user->id)
                ->siteId($entry->siteId)
                ->status(null)
                ->one();

            if ($existingDraft) {
                return $existingDraft;
            }

            return Craft::$app->getDrafts()->createDraft(
                $entry,
                $user->id,
                null,
                null,
                [],
                true,
            );
        }

        // ElementUpdateBehavior::Draft
        return Craft::$app->getDrafts()->createDraft(
            $entry,
            $user->id,
            'CoPilot Draft',
        );
    }

    /**
     * Applies field values to a target entry.
     *
     * @param array<string, mixed> $fields
     * @param array<int, string> $nativeFields
     */
    private function applyFields(Entry $target, array $fields, array $nativeFields): void
    {
        foreach ($fields as $fieldHandle => $value) {
            if (in_array($fieldHandle, $nativeFields, true)) {
                if ($fieldHandle === 'enabled') {
                    $target->enabled = (bool) $value;
                } elseif ($fieldHandle === 'postDate' || $fieldHandle === 'expiryDate') {
                    $target->{$fieldHandle} = $value !== null ? new \DateTime($value) : null;
                } else {
                    $target->{$fieldHandle} = $value;
                }
            } else {
                $value = CoPilot::getInstance()->fieldNormalizer->normalize($fieldHandle, $value, $target);

                try {
                    $target->setFieldValue($fieldHandle, $value);
                } catch (\Throwable) {
                    continue;
                }

                $this->fixNewAddressSiteIds($target, $fieldHandle);
            }
        }
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function collectNestedErrors(Entry $entry, string $fieldHandle): array
    {
        $nestedErrors = [];

        try {
            $fieldValue = $entry->getFieldValue($fieldHandle);
        } catch (\Throwable) {
            return [];
        }

        if ($fieldValue instanceof \craft\elements\db\ElementQuery) {
            $elements = $fieldValue->getCachedResult() ?? [];
            foreach ($elements as $i => $element) {
                if ($element->hasErrors()) {
                    $nestedErrors[$i] = $element->getFirstErrors();
                }
            }
        }

        if ($fieldValue instanceof \craft\base\ElementInterface && $fieldValue->hasErrors()) {
            $nestedErrors[] = $fieldValue->getFirstErrors();
        }

        return $nestedErrors;
    }

    /**
     * Fixes siteId on new Address elements after setFieldValue.
     *
     * Craft's Addresses field copies the owner entry's siteId to new addresses,
     * but Address elements are not localized and only support the primary site.
     * This also recurses into nested Matrix blocks.
     */
    private function fixNewAddressSiteIds(Entry $entry, string $fieldHandle): void
    {
        $primarySiteId = Craft::$app->getSites()->getPrimarySite()->id;
        if ($entry->siteId === $primarySiteId) {
            return;
        }

        try {
            $fieldValue = $entry->getFieldValue($fieldHandle);
        } catch (\Throwable) {
            return;
        }

        if (!$fieldValue instanceof \craft\elements\db\ElementQuery) {
            return;
        }

        $cached = $fieldValue->getCachedResult();
        if ($cached === null) {
            return;
        }

        foreach ($cached as $nested) {
            if ($nested instanceof \craft\elements\Address && !$nested->id) {
                $nested->siteId = $primarySiteId;
            }

            // Recurse into Matrix blocks (Entry elements in Craft 5)
            if ($nested instanceof Entry) {
                $this->fixAddressSiteIdsRecursive($nested, $primarySiteId);
            }
        }
    }

    /**
     * Recursively fixes Address siteIds inside nested Entry elements (Matrix blocks).
     */
    private function fixAddressSiteIdsRecursive(Entry $block, int $primarySiteId): void
    {
        $fieldLayout = $block->getFieldLayout();

        foreach ($fieldLayout->getCustomFields() as $field) {
            try {
                $value = $block->getFieldValue($field->handle);
            } catch (\Throwable) {
                continue;
            }

            if (!$value instanceof \craft\elements\db\ElementQuery) {
                continue;
            }

            $cached = $value->getCachedResult();
            if ($cached === null) {
                continue;
            }

            foreach ($cached as $nested) {
                if ($nested instanceof \craft\elements\Address && !$nested->id) {
                    $nested->siteId = $primarySiteId;
                }

                // Recurse further for deeply nested Matrix blocks
                if ($nested instanceof Entry) {
                    $this->fixAddressSiteIdsRecursive($nested, $primarySiteId);
                }
            }
        }
    }

    private function getFieldValue(Entry $entry, string $fieldHandle): mixed
    {
        if ($fieldHandle === 'title') {
            return $entry->title;
        }

        if ($fieldHandle === 'slug') {
            return $entry->slug;
        }

        if ($fieldHandle === 'enabled') {
            return $entry->enabled;
        }

        if ($fieldHandle === 'postDate') {
            return $entry->postDate?->format('c');
        }

        if ($fieldHandle === 'expiryDate') {
            return $entry->expiryDate?->format('c');
        }

        try {
            $value = $entry->getFieldValue($fieldHandle);

            if (is_scalar($value) || $value === null) {
                return $value;
            }

            if (is_object($value) && method_exists($value, '__toString')) {
                return (string) $value;
            }

            if (is_array($value)) {
                return $value;
            }

            if ($value instanceof \craft\elements\db\ElementQuery) {
                return $value->ids();
            }

            return '(complex value)';
        } catch (\Throwable) {
            return null;
        }
    }
}
