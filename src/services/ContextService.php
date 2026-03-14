<?php

namespace samuelreichor\coPilot\services;

use craft\base\Component;
use craft\elements\Asset;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use LanguageDetection\Language;
use samuelreichor\coPilot\CoPilot;

/**
 * Serializes Craft elements for AI context.
 * Delegates to element and field handlers via the registries.
 */
class ContextService extends Component
{
    public const EVENT_BEFORE_SERIALIZE_ENTRY = 'beforeSerializeEntry';

    /**
     * @param string[]|null $fieldHandles
     * @return array<string, mixed>|null Returns null if cancelled by event
     */
    public function serializeEntry(Entry $entry, int $depth = 2, ?array $fieldHandles = null): ?array
    {
        $transformer = CoPilot::getInstance()->transformerRegistry->getTransformerForElement($entry);

        if ($transformer !== null) {
            return $transformer->serializeElement($entry, $depth, $fieldHandles);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function summarizeEntry(Entry $entry): array
    {
        $registry = CoPilot::getInstance()->transformerRegistry;
        $fieldLayout = $entry->getFieldLayout();

        $filledFields = [];
        $emptyFields = [];
        $textParts = [];

        if ($fieldLayout) {
            foreach ($registry->resolveFieldLayoutFields($fieldLayout) as $resolved) {
                $handle = $resolved['handle'];
                $value = $entry->getFieldValue($handle);

                if ($this->isFieldEmpty($value)) {
                    $emptyFields[] = $handle;
                } else {
                    $filledFields[] = $handle;
                    $this->collectText($value, $textParts);
                }
            }
        }

        $collectedText = implode(' ', $textParts);
        $summary = $collectedText !== '' ? mb_substr($collectedText, 0, 300) : '';
        $detectedLanguage = $this->detectLanguage($collectedText);

        $siteLanguage = strtolower(substr($entry->getSite()->language, 0, 2));
        $matchesSiteLanguage = $detectedLanguage === null || $detectedLanguage === $siteLanguage;

        return [
            'entryId' => $entry->id,
            'title' => $entry->title ?: $entry->getSection()?->name ?? '(untitled)',
            'slug' => $entry->slug,
            'section' => $entry->getSection()?->handle,
            'type' => $entry->getType()->handle,
            'status' => $entry->getStatus(),
            'url' => $entry->url,
            'summary' => $summary,
            'contentLanguage' => $detectedLanguage,
            'matchesSiteLanguage' => $matchesSiteLanguage,
            'filledFields' => $filledFields,
            'emptyFields' => $emptyFields,
        ];
    }

    public function isFieldEmpty(mixed $value): bool
    {
        if ($value === null || $value === '' || $value === []) {
            return true;
        }

        if ($value instanceof ElementQueryInterface) {
            return $value->count() === 0;
        }

        if (is_string($value)) {
            return trim(strip_tags($value)) === '';
        }

        return false;
    }

    /**
     * @param string[] $parts
     */
    private function collectText(mixed $value, array &$parts): void
    {
        if (is_string($value)) {
            $clean = trim(strip_tags($value));
            if ($clean !== '') {
                $parts[] = $clean;
            }

            return;
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            $str = (string) $value;
            $clean = trim(strip_tags($str));
            if ($clean !== '') {
                $parts[] = $clean;
            }
        }
    }

    /**
     * Detects content language using N-gram analysis.
     */
    private function detectLanguage(string $text): ?string
    {
        if (mb_strlen(trim($text)) < 20) {
            return null;
        }

        $detector = new Language();
        $results = $detector->detect($text)->close();

        if ($results === []) {
            return null;
        }

        $bestLang = array_key_first($results);
        $bestScore = $results[$bestLang];

        // Require minimum confidence
        if ($bestScore < 0.1) {
            return null;
        }

        return $bestLang;
    }

    /**
     * @return array<array<string, mixed>>
     */
    public function getExampleEntries(?string $sectionHandle, string $entryTypeHandle, int $limit = 2, ?string $siteHandle = null): array
    {
        $query = Entry::find()
            ->type($entryTypeHandle)
            ->status('live')
            ->orderBy(['dateCreated' => SORT_DESC])
            ->limit($limit);

        if ($sectionHandle !== null) {
            $query->section($sectionHandle);
        }

        if ($siteHandle !== null) {
            $query->site($siteHandle);
        }

        $entries = $query->all();
        $examples = [];
        foreach ($entries as $entry) {
            $examples[] = $this->summarizeEntry($entry);
        }

        return $examples;
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeAsset(Asset $asset): array
    {
        $transformer = CoPilot::getInstance()->transformerRegistry->getTransformerForElement($asset);

        if ($transformer !== null) {
            $result = $transformer->serializeElement($asset);
            if ($result !== null) {
                return $result;
            }
        }

        // Fallback (should not happen with built-in handler)
        return [
            '_type' => 'asset',
            'id' => $asset->id,
            'filename' => $asset->filename,
            'url' => $asset->url,
            'alt' => $asset->alt ?? '',
            'kind' => $asset->kind,
            'size' => $asset->size,
            'width' => $asset->width,
            'height' => $asset->height,
        ];
    }
}
