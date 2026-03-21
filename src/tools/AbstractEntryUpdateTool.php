<?php

namespace samuelreichor\coPilot\tools;

use Craft;
use craft\elements\Entry;
use samuelreichor\coPilot\CoPilot;
use samuelreichor\coPilot\enums\ElementUpdateBehavior;

abstract class AbstractEntryUpdateTool implements ToolInterface
{
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
            return [
                'error' => $guard['reason'],
                'retryHint' => null,
            ];
        }

        $entry = $this->resolveEntry($entryId, $siteHandle);
        if (is_array($entry)) {
            return $entry; // error response
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
                    $entry->setEnabledForSite((bool) $value);
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

        $updateBehavior = $behaviorOverride
            ?? ElementUpdateBehavior::tryFrom($plugin->getSettings()->elementUpdateBehavior)
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
            if ($site) {
                $query->siteId($site->id);
            } else {
                $query->site('*');
            }
        } else {
            $query->site('*');
        }

        $entry = $query->one();

        // Entry not found on target site — try to create the site version
        if (!$entry && $siteHandle) {
            $entry = $this->createSiteVersion($entryId, $siteHandle);
            if (is_array($entry)) {
                return $entry; // error response
            }
        }

        if (!$entry) {
            return [
                'error' => "Entry #{$entryId} not found.",
                'retryHint' => null,
            ];
        }

        // Prevent direct updates to nested Matrix block entries, they must be
        // updated via their parent entry's Matrix field.
        if ($entry->getOwnerId() !== null) {
            $ownerId = $entry->getOwnerId();
            $fieldHandle = $entry->fieldId
                ? (Craft::$app->getFields()->getFieldById($entry->fieldId)?->handle ?? 'unknown')
                : 'unknown';

            return [
                'error' => "Entry #{$entryId} is a nested Matrix block owned by entry #{$ownerId}. You cannot update it directly.",
                'retryHint' => "Update the parent entry #{$ownerId} instead. To modify this block, pass the block data with \"_blockId\": {$entryId} inside the \"{$fieldHandle}\" Matrix field of entry #{$ownerId}.",
            ];
        }

        return $entry;
    }

    /**
     * Normalizes flat field arguments into a proper fields array.
     *
     * AI models sometimes send field values as top-level arguments instead of
     * wrapping them in a "fields" object.
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

        // Models may send native fields at top level alongside a "fields" object.
        // Merge them in so they aren't silently dropped.
        foreach (['title', 'slug', 'enabled', 'postDate', 'expiryDate'] as $native) {
            if (array_key_exists($native, $arguments) && !array_key_exists($native, $fields)) {
                $fields[$native] = $arguments[$native];
            }
        }

        return is_array($fields) && $fields !== [] ? $fields : null;
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
                    $target->setEnabledForSite((bool) $value);
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

        // ContentBlock fields are not ElementQuery but ContentBlock elements
        if ($fieldValue instanceof \craft\elements\ContentBlock) {
            $this->fixContentBlockAddressSiteIds($fieldValue, $primarySiteId);

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
     * Fixes Address siteIds inside ContentBlock sub-fields.
     */
    private function fixContentBlockAddressSiteIds(\craft\elements\ContentBlock $contentBlock, int $primarySiteId): void
    {
        $fieldLayout = $contentBlock->getFieldLayout();

        foreach ($fieldLayout->getCustomFields() as $field) {
            try {
                $value = $contentBlock->getFieldValue($field->handle);
            } catch (\Throwable) {
                continue;
            }

            if ($value instanceof \craft\elements\db\ElementQuery) {
                $cached = $value->getCachedResult();
                if ($cached === null) {
                    continue;
                }

                foreach ($cached as $nested) {
                    if ($nested instanceof \craft\elements\Address && !$nested->id) {
                        $nested->siteId = $primarySiteId;
                    }

                    if ($nested instanceof Entry) {
                        $this->fixAddressSiteIdsRecursive($nested, $primarySiteId);
                    }
                }
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

            // Also handle ContentBlock sub-fields within Matrix blocks
            if ($value instanceof \craft\elements\ContentBlock) {
                $this->fixContentBlockAddressSiteIds($value, $primarySiteId);

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

    protected function getFieldValue(Entry $entry, string $fieldHandle): mixed
    {
        if ($fieldHandle === 'title') {
            return $entry->title;
        }

        if ($fieldHandle === 'slug') {
            return $entry->slug;
        }

        if ($fieldHandle === 'enabled') {
            return $entry->enabled && $entry->getEnabledForSite();
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

    /**
     * Creates an entry's site version using Craft's built-in propagation.
     *
     * For Custom propagation, setEnabledForSite() makes the target site appear
     * in getSupportedSites() with propagate=true, so propagateElement() works.
     *
     * For All, propagateElement() works directly (all sites are supported).
     *
     * For None/Language/SiteGroup, the target site may not be in
     * getSupportedSites() at all (e.g. different language). Craft's data model
     * requires a separate entry per site/language/group in these cases. The
     * agent must use createEntry instead.
     *
     * @return Entry|array<string, mixed> The entry or an error response
     */
    private function createSiteVersion(int $entryId, string $siteHandle): Entry|array
    {
        $site = Craft::$app->getSites()->getSiteByHandle($siteHandle);
        if (!$site) {
            return [
                'error' => "Site \"{$siteHandle}\" not found.",
                'retryHint' => 'Call listSites to get valid site handles.',
            ];
        }

        $sourceEntry = Entry::find()->id($entryId)->status(null)->drafts(null)->site('*')->one();
        if (!$sourceEntry) {
            return [
                'error' => "Entry #{$entryId} not found.",
                'retryHint' => null,
            ];
        }

        $section = $sourceEntry->getSection();
        if (!$section) {
            return [
                'error' => "Entry #{$entryId} has no section.",
                'retryHint' => null,
            ];
        }

        $siteSettings = $section->getSiteSettings();
        if (!isset($siteSettings[$site->id])) {
            return [
                'error' => "Section \"{$section->handle}\" is not enabled for site \"{$siteHandle}\".",
                'retryHint' => null,
            ];
        }

        // For Custom propagation, mark the entry as enabled for the target site
        // so getSupportedSites() includes it with propagate=true.
        if ($section->propagationMethod === \craft\enums\PropagationMethod::Custom) {
            $sourceEntry->setEnabledForSite([$site->id => true]);
        }

        try {
            Craft::$app->getElements()->propagateElement($sourceEntry, $site->id);

            $entry = Entry::find()->id($entryId)->status(null)->drafts(null)->siteId($site->id)->one();

            if (!$entry) {
                return [
                    'error' => "Entry #{$entryId} could not be created on site \"{$siteHandle}\".",
                    'retryHint' => null,
                ];
            }

            return $entry;
        } catch (\craft\errors\UnsupportedSiteException) {
            $method = $section->propagationMethod->value;

            return [
                'error' => "Entry #{$entryId} cannot be propagated to site \"{$siteHandle}\". "
                    . "Section \"{$section->handle}\" uses \"{$method}\" propagation, "
                    . "so entries on different sites/languages/groups are independent.",
                'retryHint' => "Use createEntry with siteHandle \"{$siteHandle}\" and section \"{$section->handle}\" "
                    . "to create a new translated entry on the target site. Copy the content from entry #{$entryId}.",
            ];
        } catch (\Throwable $e) {
            return [
                'error' => "Entry #{$entryId} could not be propagated to site \"{$siteHandle}\": {$e->getMessage()}",
                'retryHint' => null,
            ];
        }
    }
}
