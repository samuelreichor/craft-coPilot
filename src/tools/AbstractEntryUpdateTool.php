<?php

namespace samuelreichor\coPilot\tools;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\elements\Address;
use craft\elements\ContentBlock;
use craft\elements\db\ElementQuery;
use craft\elements\Entry;
use craft\elements\User;
use craft\enums\PropagationMethod;
use craft\errors\UnsupportedSiteException;
use samuelreichor\coPilot\CoPilot;
use samuelreichor\coPilot\enums\ElementUpdateBehavior;
use samuelreichor\coPilot\helpers\Logger;

abstract class AbstractEntryUpdateTool implements ToolInterface
{
    protected const NATIVE_FIELDS = ['title', 'slug', 'enabled', 'postDate', 'expiryDate'];

    /**
     * Core update logic shared by UpdateEntryTool and PublishEntryTool.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    protected function performUpdate(int $entryId, ?string $siteHandle, array $fields, ?ElementUpdateBehavior $behaviorOverride = null): array
    {
        $plugin = CoPilot::getInstance();

        $guard = $plugin->permissionGuard->canWriteEntry($entryId);
        if (!$guard['allowed']) {
            return ['error' => $guard['reason'], 'retryHint' => null];
        }

        $entry = $this->resolveEntry($entryId, $siteHandle);
        if (is_array($entry)) {
            return $entry;
        }

        unset($fields['_type']);

        // 1. Capture old values before any modifications
        $diff = $this->buildDiff($entry, $fields);

        // 2. Resolve target entry (draft or original)
        [$targetEntry, $behaviorMessage] = $this->resolveTarget($entry, $plugin, $behaviorOverride);
        if (is_array($targetEntry)) {
            return $targetEntry; // error response
        }

        // 3. Apply fields — normalize against original entry, write to target
        $skippedFields = $this->applyFields($targetEntry, $entry, $fields);

        // 4. Update diff with normalized values
        foreach ($fields as $fieldHandle => $value) {
            if (!in_array($fieldHandle, self::NATIVE_FIELDS, true)) {
                $diff[$fieldHandle]['new'] = CoPilot::getInstance()->fieldNormalizer->normalize($fieldHandle, $value, $entry);
            }
        }

        // 5. Save
        return $this->saveAndBuildResult($targetEntry, $entry, $siteHandle, $diff, $skippedFields, $behaviorMessage, $fields);
    }

    /**
     * Resolves the target entry for saving (draft or original).
     *
     * @return array{0: Entry|array<string, mixed>, 1: string}
     */
    private function resolveTarget(Entry $entry, CoPilot $plugin, ?ElementUpdateBehavior $behaviorOverride): array
    {
        $updateBehavior = $behaviorOverride
            ?? ElementUpdateBehavior::tryFrom($plugin->getSettings()->elementUpdateBehavior)
            ?? ElementUpdateBehavior::ProvisionalDraft;

        if ($updateBehavior === ElementUpdateBehavior::DirectSave || $entry->getIsDraft()) {
            $message = $entry->getIsDraft() ? 'Draft entry updated directly.' : 'Entry updated successfully.';

            return [$entry, $message];
        }

        $user = Craft::$app->getUser()->getIdentity();
        if (!$user) {
            return [['error' => 'Access denied – no authenticated user.', 'retryHint' => null], ''];
        }

        $targetEntry = $this->createOrResolveDraft($entry, $user, $updateBehavior);

        $message = match ($updateBehavior) {
            ElementUpdateBehavior::Draft => 'Entry changes saved as a new draft.',
            ElementUpdateBehavior::ProvisionalDraft => 'Entry changes saved as a provisional draft (unsaved changes visible in the control panel).',
        };

        return [$targetEntry, $message];
    }

    /**
     * Applies field values to the target entry.
     * Uses the original entry as normalization context for ContentBlock/Matrix merging.
     *
     * @param array<string, mixed> $fields
     * @return array<string, string> Skipped fields with error messages
     */
    protected function applyFields(Entry $target, Entry $sourceEntry, array $fields): array
    {
        $skippedFields = [];

        foreach ($fields as $fieldHandle => $value) {
            if (in_array($fieldHandle, self::NATIVE_FIELDS, true)) {
                $this->applyNativeField($target, $fieldHandle, $value);
            } else {
                $value = CoPilot::getInstance()->fieldNormalizer->normalize($fieldHandle, $value, $sourceEntry);

                try {
                    $target->setFieldValue($fieldHandle, $value);
                } catch (\Throwable $e) {
                    $skippedFields[$fieldHandle] = $e->getMessage();

                    continue;
                }

                $this->fixAddressSiteIds($target, $fieldHandle);
            }
        }

        return $skippedFields;
    }

    private function applyNativeField(Entry $entry, string $fieldHandle, mixed $value): void
    {
        match ($fieldHandle) {
            'enabled' => (function() use ($entry, $value) {
                $entry->enabled = (bool) $value;
                $entry->setEnabledForSite((bool) $value);
            }
            )(),
            'postDate', 'expiryDate' => $entry->{$fieldHandle} = $value !== null ? new \DateTime($value) : null,
            default => $entry->{$fieldHandle} = $value,
        };
    }

    /**
     * @param array<string, mixed> $diff
     * @param array<string, string> $skippedFields
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function saveAndBuildResult(
        Entry $targetEntry,
        Entry $sourceEntry,
        ?string $siteHandle,
        array $diff,
        array $skippedFields,
        string $behaviorMessage,
        array $fields,
    ): array {
        try {
            $saved = Craft::$app->getElements()->saveElement($targetEntry);
        } catch (UnsupportedSiteException $e) {
            return $this->buildUnsupportedSiteError($e, $sourceEntry, $siteHandle);
        } catch (\Throwable $e) {
            return $this->buildSaveError($e);
        }

        if (!$saved) {
            return $this->buildValidationError($targetEntry, $fields);
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

    // ── Entry resolution ────────────────────────────────────────────────

    /**
     * Resolves an entry by ID and optional site handle, including cross-site propagation.
     *
     * @return Entry|array<string, mixed> The entry or an error response array

     */
    protected function resolveEntry(int $entryId, ?string $siteHandle): Entry|array
    {
        $query = Entry::find()->id($entryId)->status(null)->drafts(null);

        if ($siteHandle) {
            $site = Craft::$app->getSites()->getSiteByHandle($siteHandle);
            $site ? $query->siteId($site->id) : $query->site('*');
        } else {
            $query->site('*');
        }

        $entry = $query->one();

        if (!$entry && $siteHandle) {
            $entry = $this->createSiteVersion($entryId, $siteHandle);
            if (is_array($entry)) {
                return $entry;
            }
        }

        if (!$entry) {
            return ['error' => "Entry #{$entryId} not found.", 'retryHint' => null];
        }

        if ($entry->getOwnerId() !== null) {
            return $this->buildNestedBlockError($entry, $entryId);
        }

        return $entry;
    }

    /**
     * Normalizes flat field arguments into a proper fields array.
     *
     * @param array<string, mixed> $arguments
     * @param array<int, string> $reservedKeys
     * @return array<string, mixed>|null Null if fields are empty/invalid
     */
    protected function normalizeFields(array $arguments, array $reservedKeys = ['entryId', 'siteHandle', '_siteHandle']): ?array
    {
        if (!isset($arguments['fields'])) {
            $flatFields = array_diff_key($arguments, array_flip($reservedKeys));

            return $flatFields !== [] ? $flatFields : null;
        }

        $fields = $arguments['fields'];

        foreach (['title', 'slug', 'enabled', 'postDate', 'expiryDate'] as $native) {
            if (array_key_exists($native, $arguments) && !array_key_exists($native, $fields)) {
                $fields[$native] = $arguments[$native];
            }
        }

        return is_array($fields) && $fields !== [] ? $fields : null;
    }

    // ── Draft handling ──────────────────────────────────────────────────

    protected function createOrResolveDraft(Entry $entry, User $user, ElementUpdateBehavior $behavior): Entry
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

            return Craft::$app->getDrafts()->createDraft($entry, $user->id, null, null, [], true);
        }

        return Craft::$app->getDrafts()->createDraft($entry, $user->id, 'CoPilot Draft');
    }

    // ── Diff & field value helpers ──────────────────────────────────────

    /**
     * @param array<string, mixed> $fields
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function buildDiff(Entry $entry, array $fields): array
    {
        $diff = [];
        foreach ($fields as $fieldHandle => $value) {
            $diff[$fieldHandle] = [
                'old' => $this->getFieldValue($entry, $fieldHandle),
                'new' => $value,
            ];
        }

        return $diff;
    }

    protected function getFieldValue(Entry $entry, string $fieldHandle): mixed
    {
        return match ($fieldHandle) {
            'title' => $entry->title,
            'slug' => $entry->slug,
            'enabled' => $entry->enabled && $entry->getEnabledForSite(),
            'postDate' => $entry->postDate?->format('c'),
            'expiryDate' => $entry->expiryDate?->format('c'),
            default => $this->getCustomFieldValue($entry, $fieldHandle),
        };
    }

    private function getCustomFieldValue(Entry $entry, string $fieldHandle): mixed
    {
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

            if ($value instanceof ElementQuery) {
                return $value->ids();
            }

            return '(complex value)';
        } catch (\Throwable) {
            return null;
        }
    }

    // ── Address siteId fix (recursive) ──────────────────────────────────

    /**
     * Fixes siteId on new Address elements after setFieldValue.
     *
     * Craft's Addresses field copies the owner entry's siteId to new addresses,
     * but Address elements only support the primary site. This method recursively
     * traverses ContentBlocks, Matrix blocks, and nested entries to fix all new addresses.
     */
    protected function fixAddressSiteIds(Entry $entry, string $fieldHandle): void
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

        $this->fixAddressSiteIdsOnValue($fieldValue, $primarySiteId);
    }

    private function fixAddressSiteIdsOnValue(mixed $value, int $primarySiteId): void
    {
        if ($value instanceof ContentBlock) {
            foreach ($value->getFieldLayout()->getCustomFields() as $field) {
                try {
                    $this->fixAddressSiteIdsOnValue($value->getFieldValue($field->handle), $primarySiteId);
                } catch (\Throwable) {
                    continue;
                }
            }

            return;
        }

        if (!$value instanceof ElementQuery) {
            return;
        }

        $cached = $value->getCachedResult();
        if ($cached === null) {
            return;
        }

        foreach ($cached as $nested) {
            if ($nested instanceof Address && !$nested->id) {
                $nested->siteId = $primarySiteId;
            }

            if ($nested instanceof Element) {
                $this->fixAddressSiteIdsOnElement($nested, $primarySiteId);
            }
        }
    }

    private function fixAddressSiteIdsOnElement(Element $element, int $primarySiteId): void
    {
        $fieldLayout = $element->getFieldLayout();
        if ($fieldLayout === null) {
            return;
        }

        foreach ($fieldLayout->getCustomFields() as $field) {
            try {
                $this->fixAddressSiteIdsOnValue($element->getFieldValue($field->handle), $primarySiteId);
            } catch (\Throwable) {
                continue;
            }
        }
    }

    // ── Error builders ──────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function buildNestedBlockError(Entry $entry, int $entryId): array
    {
        $ownerId = $entry->getOwnerId();
        $fieldHandle = $entry->fieldId
            ? (Craft::$app->getFields()->getFieldById($entry->fieldId)?->handle ?? 'unknown')
            : 'unknown';

        return [
            'error' => "Entry #{$entryId} is a nested Matrix block owned by entry #{$ownerId}. You cannot update it directly.",
            'retryHint' => "Update the parent entry #{$ownerId} instead. To modify this block, pass the block data with \"_blockId\": {$entryId} inside the \"{$fieldHandle}\" Matrix field of entry #{$ownerId}.",
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildUnsupportedSiteError(UnsupportedSiteException $e, Entry $entry, ?string $siteHandle): array
    {
        $element = $e->element;
        $debugInfo = sprintf(
            'elementType=%s, elementId=%s, siteId=%s, entrySiteId=%s, siteHandle=%s',
            get_class($element),
            $element->id ?? 'new',
            $e->siteId,
            $entry->siteId,
            $siteHandle ?? 'null',
        );
        Logger::warning("UpdateEntry UnsupportedSiteException: {$debugInfo}");

        return ['error' => "Save failed: {$e->getMessage()} ({$debugInfo})", 'retryHint' => null];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSaveError(\Throwable $e): array
    {
        $message = $e->getMessage();
        $retryHint = null;

        if (stripos($message, 'unknown property') !== false) {
            $retryHint = 'A field handle is incorrect (case-sensitive). Call describeSection to verify the exact handles and retry.';
        }

        return ['error' => "Save failed: {$message}", 'retryHint' => $retryHint];
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function buildValidationError(Entry $targetEntry, array $fields): array
    {
        $errors = $targetEntry->getFirstErrors();

        foreach ($fields as $fieldHandle => $value) {
            if (in_array($fieldHandle, self::NATIVE_FIELDS, true)) {
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

        if ($fieldValue instanceof ElementQuery) {
            $elements = $fieldValue->getCachedResult() ?? [];
            foreach ($elements as $i => $element) {
                if ($element->hasErrors()) {
                    $nestedErrors[$i] = $element->getFirstErrors();
                }
            }
        }

        if ($fieldValue instanceof ElementInterface && $fieldValue->hasErrors()) {
            $nestedErrors[] = $fieldValue->getFirstErrors();
        }

        return $nestedErrors;
    }

    // ── Cross-site propagation ──────────────────────────────────────────

    /**
     * @return Entry|array<string, mixed> The entry or an error response
     */
    protected function createSiteVersion(int $entryId, string $siteHandle): Entry|array
    {
        $site = Craft::$app->getSites()->getSiteByHandle($siteHandle);
        if (!$site) {
            return ['error' => "Site \"{$siteHandle}\" not found.", 'retryHint' => 'Call listSites to get valid site handles.'];
        }

        $sourceEntry = Entry::find()->id($entryId)->status(null)->drafts(null)->site('*')->one();
        if (!$sourceEntry) {
            return ['error' => "Entry #{$entryId} not found.", 'retryHint' => null];
        }

        if ($sourceEntry->getOwnerId() !== null) {
            return $this->buildNestedBlockError($sourceEntry, $entryId);
        }

        $section = $sourceEntry->getSection();
        if (!$section) {
            return ['error' => "Entry #{$entryId} has no section.", 'retryHint' => null];
        }

        $siteSettings = $section->getSiteSettings();
        if (!isset($siteSettings[$site->id])) {
            return ['error' => "Section \"{$section->handle}\" is not enabled for site \"{$siteHandle}\".", 'retryHint' => null];
        }

        if ($section->propagationMethod === PropagationMethod::Custom) {
            $sourceEntry->setEnabledForSite([$site->id => true]);
        }

        try {
            Craft::$app->getElements()->propagateElement($sourceEntry, $site->id);

            $entry = Entry::find()->id($entryId)->status(null)->drafts(null)->siteId($site->id)->one();

            if (!$entry) {
                return ['error' => "Entry #{$entryId} could not be created on site \"{$siteHandle}\".", 'retryHint' => null];
            }

            return $entry;
        } catch (UnsupportedSiteException) {
            $method = $section->propagationMethod->value;

            return [
                'error' => "Entry #{$entryId} cannot be propagated to site \"{$siteHandle}\". "
                    . "Section \"{$section->handle}\" uses \"{$method}\" propagation.",
                'retryHint' => "Use createEntry with siteHandle \"{$siteHandle}\" and section \"{$section->handle}\" "
                    . "to create a new translated entry on the target site. Copy the content from entry #{$entryId}.",
            ];
        } catch (\Throwable $e) {
            return ['error' => "Entry #{$entryId} could not be propagated to site \"{$siteHandle}\": {$e->getMessage()}", 'retryHint' => null];
        }
    }
}
