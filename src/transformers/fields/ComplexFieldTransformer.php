<?php

namespace samuelreichor\coPilot\transformers\fields;

use Craft;
use craft\base\FieldInterface;
use craft\elements\ContentBlock as ContentBlockElement;
use craft\elements\Entry;
use craft\fields\ContentBlock as ContentBlockField;
use craft\fields\data\JsonData;
use craft\fields\data\LinkData;
use craft\fields\Json as JsonField;
use craft\fields\Link as LinkField;
use craft\fields\Matrix as MatrixField;
use craft\fields\Money as MoneyField;
use craft\fields\Table as TableField;
use samuelreichor\coPilot\CoPilot;
use samuelreichor\coPilot\transformers\SerializeFallbackTrait;

/**
 * Handles complex field types: Matrix, ContentBlock, Link, Money, JSON, Table.
 */
class ComplexFieldTransformer implements FieldTransformerInterface
{
    use SerializeFallbackTrait;
    public function getSupportedFieldClasses(): array
    {
        return [
            MatrixField::class,
            ContentBlockField::class,
            LinkField::class,
            MoneyField::class,
            JsonField::class,
            TableField::class,
        ];
    }

    public function matchesField(FieldInterface $field): ?bool
    {
        return null;
    }

    public function describeField(FieldInterface $field, array $fieldInfo): array
    {
        return match (true) {
            $field instanceof TableField => $this->describeTable($field, $fieldInfo),
            $field instanceof LinkField => $this->describeLink($field, $fieldInfo),
            $field instanceof MoneyField => $this->describeMoney($field, $fieldInfo),
            $field instanceof JsonField => $this->describeWithHint($fieldInfo, 'JSON object, array, or null', 'Send any valid JSON value. To clear, set to null.'),
            $field instanceof MatrixField => $this->describeMatrix($field, $fieldInfo),
            $field instanceof ContentBlockField => $this->describeWithHint($fieldInfo, 'object with sub-field handles as keys', 'Send {"subFieldHandle": value, ...}. To clear all sub-fields at once, set this field to null. Never include title or slug keys.'),
            default => $fieldInfo,
        };
    }

    public function serializeValue(FieldInterface $field, mixed $value, int $depth): mixed
    {
        return match (true) {
            $field instanceof ContentBlockField && $value instanceof ContentBlockElement => $this->serializeContentBlock($field, $value, $depth),
            $field instanceof MatrixField && $depth <= 0 => ['_truncated' => true, '_count' => $value->count()],
            $field instanceof MatrixField => $this->serializeMatrixBlocks($value, $depth - 1),
            $value instanceof LinkData => $this->serializeLinkData($value),
            $value instanceof \Money\Money => $this->serializeMoneyData($value),
            $value instanceof JsonData => $value->getValue(),
            default => $value,
        };
    }

    public function normalizeValue(FieldInterface $field, mixed $value, ?Entry $entry = null): mixed
    {
        return match (true) {
            $field instanceof ContentBlockField && $value === null => $this->buildEmptyContentBlockFields($field),
            $field instanceof ContentBlockField && is_array($value) && empty($value) => $this->buildEmptyContentBlockFields($field),
            $field instanceof ContentBlockField && is_array($value) => $this->normalizeContentBlockValue($value, $field, $entry),
            $field instanceof LinkField && (is_int($value) || (is_string($value) && ctype_digit($value))) => $this->normalizeLinkValue(['type' => 'entry', 'value' => (int) $value], $entry),
            $field instanceof LinkField && is_array($value) => $this->normalizeLinkValue($value, $entry),
            $field instanceof MoneyField && is_array($value) && isset($value['amount']) => (int) $value['amount'],
            $field instanceof MoneyField && ($value === 0 || $value === '0') => 0,
            $field instanceof TableField && $value === null => [],
            $field instanceof JsonField && is_array($value) && empty($value) => [],
            $field instanceof JsonField && is_string($value) => $this->normalizeJsonString($value),
            $field instanceof MatrixField && is_array($value) => $this->normalizeMatrixFieldInput($field, $value, $entry),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $fieldInfo
     * @return array<string, mixed>
     */
    private function describeWithHint(array $fieldInfo, string $format, string $hint): array
    {
        $fieldInfo['valueFormat'] = $format;
        $fieldInfo['hint'] = $hint;

        return $fieldInfo;
    }

    /**
     * @param array<string, mixed> $fieldInfo
     * @return array<string, mixed>
     */
    private function describeTable(TableField $field, array $fieldInfo): array
    {
        $fieldInfo['valueFormat'] = 'array of row objects';
        $fieldInfo['hint'] = 'Each row keyed by column key.';
        $fieldInfo['columns'] = [];
        foreach ($field->columns as $colKey => $col) {
            $fieldInfo['columns'][] = [
                'key' => $colKey,
                'heading' => $col['heading'],
                'type' => $col['type'],
            ];
        }

        if ($field->minRows) {
            $fieldInfo['minRows'] = $field->minRows;
        }
        if ($field->maxRows) {
            $fieldInfo['maxRows'] = $field->maxRows;
        }

        return $fieldInfo;
    }

    /**
     * @param array<string, mixed> $fieldInfo
     * @return array<string, mixed>
     */
    private function describeLink(LinkField $field, array $fieldInfo): array
    {
        $fieldInfo['valueFormat'] = 'link object';
        $fieldInfo['allowedTypes'] = $field->types;
        $types = implode(', ', $field->types);

        $fieldInfo['hint'] = match (true) {
            count($field->types) === 1 && $field->types[0] === 'entry' => 'Type: entry. Value must be an entry ID. Use searchEntries to find valid IDs. Example: {"type": "entry", "value": 123, "label": "My Entry"}.',
            count($field->types) === 1 && $field->types[0] === 'asset' => 'Type: asset. Value must be an asset ID. Use searchAssets to find valid IDs. Example: {"type": "asset", "value": 456, "label": "My Asset"}.',
            default => 'Allowed types: ' . $types . '. Example: {"type": "url", "value": "https://example.com", "label": "Example"}. For entry/asset types, use the element ID as value.',
        };

        return $fieldInfo;
    }

    /**
     * @param array<string, mixed> $fieldInfo
     * @return array<string, mixed>
     */
    private function describeMoney(MoneyField $field, array $fieldInfo): array
    {
        $fieldInfo['valueFormat'] = 'integer (minor units) or null';
        $fieldInfo['hint'] = '1990 = 19.90 ' . ($field->currency ?? 'USD') . '. To clear, set to null (not 0).';
        $fieldInfo['currency'] = $field->currency;

        return $fieldInfo;
    }

    /**
     * @param array<string, mixed> $fieldInfo
     * @return array<string, mixed>
     */
    private function describeMatrix(MatrixField $field, array $fieldInfo): array
    {
        $fieldInfo['valueFormat'] = 'array of block objects';
        $fieldInfo['hint'] = 'Each block: {"type": "<blockTypeHandle>", "fields": {"<fieldHandle>": <value>}}. '
            . 'To ADD blocks to existing ones: {"blocks": [...]}. '
            . 'To REPLACE ALL existing blocks (destructive!): {"_replace": true, "blocks": [...]}. '
            . 'To clear: []. '
            . 'IMPORTANT: Only use _replace when explicitly asked to replace all content. Default is append.';

        if ($field->minEntries) {
            $fieldInfo['minEntries'] = $field->minEntries;
        }
        if ($field->maxEntries) {
            $fieldInfo['maxEntries'] = $field->maxEntries;
        }

        return $fieldInfo;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeContentBlock(ContentBlockField $field, ContentBlockElement $block, int $depth): array
    {
        $data = ['_type' => 'contentBlock'];
        $registry = CoPilot::getInstance()->transformerRegistry;

        foreach ($registry->resolveFieldLayoutFields($field->getFieldLayout()) as $resolved) {
            $handle = $resolved['handle'];
            $subField = $resolved['field'];
            $value = $block->getFieldValue($handle);
            $transformer = $registry->getTransformerForField($subField);

            if ($transformer !== null) {
                $data[$handle] = $transformer->serializeValue($subField, $value, $depth);
            } else {
                $data[$handle] = $this->serializeFallback($value);
            }
        }

        return $data;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function serializeMatrixBlocks(mixed $query, int $depth): array
    {
        $blocks = [];
        $position = 0;
        $registry = CoPilot::getInstance()->transformerRegistry;

        foreach ($query->all() as $block) {
            $blockData = [
                '_blockId' => $block->id,
                '_blockType' => $block->getType()->handle,
                '_blockDescription' => $block->getType()->name,
                '_position' => $position,
            ];

            if ($block->getType()->hasTitleField) {
                $blockData['title'] = $block->title;
            }

            $fieldLayout = $block->getFieldLayout();
            if ($fieldLayout) {
                foreach ($registry->resolveFieldLayoutFields($fieldLayout) as $resolved) {
                    $handle = $resolved['handle'];
                    $subField = $resolved['field'];
                    $value = $block->getFieldValue($handle);
                    $transformer = $registry->getTransformerForField($subField);

                    if ($transformer !== null) {
                        $blockData[$handle] = $transformer->serializeValue($subField, $value, $depth);
                    } else {
                        $blockData[$handle] = $this->serializeFallback($value);
                    }
                }
            }

            $blocks[] = $blockData;
            $position++;
        }

        return $blocks;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeLinkData(LinkData $value): array
    {
        return [
            '_type' => 'link',
            'url' => $value->getUrl(),
            'label' => $value->getLabel(),
            'type' => $value->getType(),
            'target' => $value->target,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeMoneyData(\Money\Money $value): array
    {
        return [
            '_type' => 'money',
            'amount' => $value->getAmount(),
            'currency' => $value->getCurrency()->getCode(),
        ];
    }

    /**
     * Builds a "clear all sub-fields" payload for ContentBlock.
     *
     * @return array{fields: array<string, null|array{}>}
     */
    private function buildEmptyContentBlockFields(ContentBlockField $field): array
    {
        $fields = [];
        $registry = CoPilot::getInstance()->transformerRegistry;

        foreach ($registry->resolveFieldLayoutFields($field->getFieldLayout()) as $resolved) {
            $subField = $resolved['field'];
            $handle = $resolved['handle'];

            $fields[$handle] = match (true) {
                $subField instanceof MatrixField,
                $subField instanceof \craft\fields\Assets,
                $subField instanceof \craft\fields\Entries,
                $subField instanceof \craft\fields\Tags,
                $subField instanceof \craft\fields\Users,
                $subField instanceof \craft\fields\Addresses,
                $subField instanceof TableField => [],
                default => null,
            };
        }

        return ['fields' => $fields];
    }

    /**
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private function normalizeContentBlockValue(array $value, ContentBlockField $contentBlockField, ?Entry $entry = null): array
    {
        if (isset($value['fields']) && is_array($value['fields'])) {
            unset($value['fields']['title'], $value['fields']['slug']);
            $value['fields'] = $this->normalizeContentBlockSubFields($value['fields'], $contentBlockField, $entry);

            return $value;
        }

        $nativeAttributes = ['title', 'slug'];
        $fields = array_diff_key($value, array_flip($nativeAttributes));
        $fields = $this->normalizeContentBlockSubFields($fields, $contentBlockField, $entry);

        return ['fields' => $fields];
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function normalizeContentBlockSubFields(array $fields, ContentBlockField $contentBlockField, ?Entry $entry = null): array
    {
        $fieldLayout = $contentBlockField->getFieldLayout();
        $registry = CoPilot::getInstance()->transformerRegistry;

        foreach ($fields as $handle => $value) {
            if (!is_array($value)) {
                continue;
            }

            $field = $fieldLayout->getFieldByHandle($handle);

            if ($field === null) {
                continue;
            }

            $transformer = $registry->getTransformerForField($field);

            if ($transformer === null) {
                continue;
            }

            $normalized = $transformer->normalizeValue($field, $value, $entry);

            if ($normalized !== null) {
                $fields[$handle] = $normalized;
            }
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private function normalizeLinkValue(array $value, ?Entry $entry = null): array
    {
        $keyMappings = [
            'url' => 'url',
            'entryId' => 'entry',
            'assetId' => 'asset',
            'categoryId' => 'category',
            'email' => 'email',
            'phone' => 'tel',
        ];

        foreach ($keyMappings as $aiKey => $impliedType) {
            if (isset($value[$aiKey]) && !isset($value['value'])) {
                $value['value'] = $value[$aiKey];
                unset($value[$aiKey]);

                if ($impliedType !== null && !isset($value['type'])) {
                    $value['type'] = $impliedType;
                }
            }
        }

        if (!isset($value['type']) && isset($value['value'])) {
            $value['type'] = $this->detectLinkType($value['value']);
        }

        if (!isset($value['type'])) {
            $value['type'] = 'url';
        }

        if (isset($value['value'])) {
            $value['value'] = $this->prefixLinkValue((string) $value['type'], $value['value'], $entry);
        }

        return $value;
    }

    private function normalizeJsonString(string $value): mixed
    {
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            // json_decode("null") returns null — convert to empty array so
            // the FieldNormalizer doesn't treat it as "no normalization needed"
            return $decoded ?? [];
        }

        return null;
    }

    /**
     * @param array<int|string, mixed> $value
     * @return array<string, mixed>
     */
    private function normalizeMatrixFieldInput(MatrixField $field, array $value, ?Entry $entry): array
    {
        // Use the AI-provided handle (may be a custom layout handle) for Matrix merging
        $handle = CoPilot::getInstance()->fieldNormalizer->getCurrentFieldHandle() ?? $field->handle;

        return $this->normalizeMatrixValue($value, $entry, $handle, $field);
    }

    private function detectLinkType(mixed $value): string
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return 'entry';
        }

        $value = (string) $value;

        if (str_starts_with($value, 'mailto:') || filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return 'email';
        }

        if (str_starts_with($value, 'tel:')) {
            return 'tel';
        }

        if (str_starts_with($value, 'sms:')) {
            return 'sms';
        }

        return 'url';
    }

    private function prefixLinkValue(string $type, mixed $value, ?Entry $entry = null): mixed
    {
        // Convert numeric element IDs to Craft reference tags for entry/asset/category links.
        // This ensures the correct siteId is used (from the entry being edited) instead of
        // relying on Craft's getCurrentSite() which may differ in API/CLI contexts.
        $elementTypes = ['entry', 'asset', 'category'];
        if (in_array($type, $elementTypes, true) && (is_int($value) || (is_string($value) && ctype_digit($value)))) {
            $siteId = $entry?->siteId ?? Craft::$app->getSites()->getCurrentSite()->id;

            return sprintf('{%s:%s@%s:url}', $type, $value, $siteId);
        }

        if (!is_string($value)) {
            return $value;
        }

        return match ($type) {
            'email' => !str_starts_with($value, 'mailto:') && filter_var($value, FILTER_VALIDATE_EMAIL)
                ? 'mailto:' . $value
                : $value,
            'tel' => !str_starts_with($value, 'tel:')
                ? 'tel:' . $value
                : $value,
            'sms' => !str_starts_with($value, 'sms:')
                ? 'sms:' . $value
                : $value,
            default => $value,
        };
    }

    /**
     * @param array<int|string, mixed> $value
     * @return array<string, mixed>
     */
    private function normalizeMatrixValue(array $value, ?Entry $entry, string $fieldHandle, ?MatrixField $matrixField = null): array
    {
        if (isset($value['entries'])) {
            return $value;
        }

        if ($value === []) {
            return [
                'entries' => [],
                'sortOrder' => [],
            ];
        }

        $replaceMode = false;
        if (isset($value['_replace']) && $value['_replace'] === true) {
            $replaceMode = true;
        }

        if (isset($value['blocks']) && is_array($value['blocks'])) {
            $value = $value['blocks'];
        }

        $firstKey = array_key_first($value);
        if (is_string($firstKey) && str_starts_with($firstKey, 'new')) {
            return $value;
        }

        $blocks = array_values(array_filter($value, 'is_array'));

        // Try to match blocks without _blockId to existing blocks by type+position.
        // This is critical for cross-site updates where the agent sends translated
        // content without preserving block IDs.
        if ($entry !== null && !$replaceMode) {
            $blocks = $this->matchBlocksByPosition($blocks, $entry, $fieldHandle);
        }

        $newEntries = [];
        $existingEntries = [];
        $newSortOrder = [];
        $existingUpdateIds = [];
        $newIndex = 1;

        foreach ($blocks as $block) {
            $blockId = $block['_blockId'] ?? null;
            $block = $this->normalizeMatrixBlock($block, $matrixField, $entry);

            if ($blockId !== null) {
                $key = (string) $blockId;
                $existingUpdateIds[] = $key;
                $existingEntries[$key] = $block;
            } else {
                $key = 'new' . $newIndex++;
                $newSortOrder[] = $key;
                $newEntries[$key] = $block;
            }
        }

        if ($replaceMode || $entry === null) {
            return [
                'entries' => array_merge($existingEntries, $newEntries),
                'sortOrder' => array_merge($existingUpdateIds, $newSortOrder),
            ];
        }

        return $this->mergeWithExistingBlocks($entry, $fieldHandle, $newEntries, $newSortOrder, $existingEntries, $existingUpdateIds);
    }

    /**
     * Normalizes a single Matrix block from AI format to Craft format.
     * Handles: _blockType→type fallback, stripping serialization markers,
     * restructuring flat blocks into {type, title, fields} format,
     * and normalizing sub-fields (ContentBlock, nested Matrix, etc.) via their transformers.
     *
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private function normalizeMatrixBlock(array $block, ?MatrixField $matrixField = null, ?Entry $entry = null): array
    {
        if (!isset($block['type']) && isset($block['_blockType'])) {
            $block['type'] = $block['_blockType'];
        }

        foreach (array_keys($block) as $key) {
            if (str_starts_with((string) $key, '_')) {
                unset($block[$key]);
            }
        }

        if (!isset($block['fields'])) {
            $reserved = ['type', 'title', 'slug'];
            $fields = array_diff_key($block, array_flip($reserved));
            $block = array_intersect_key($block, array_flip($reserved));
            if ($fields !== []) {
                $block['fields'] = $fields;
            }
        }

        // AI sometimes puts title/slug inside fields, move them up
        if (isset($block['fields']) && is_array($block['fields'])) {
            foreach (['title', 'slug'] as $native) {
                if (isset($block['fields'][$native]) && !isset($block[$native])) {
                    $block[$native] = $block['fields'][$native];
                }
                unset($block['fields'][$native]);
            }

            if ($matrixField !== null && isset($block['type'])) {
                $block['fields'] = $this->normalizeBlockSubFields($block['fields'], $matrixField, $block['type'], $entry);
            } else {
                $block['fields'] = $this->normalizeNestedFieldsByPattern($block['fields']);
            }
        }

        return $block;
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function normalizeBlockSubFields(array $fields, MatrixField $matrixField, string $blockTypeHandle, ?Entry $entry = null): array
    {
        $fieldLayout = $this->resolveBlockFieldLayout($matrixField, $blockTypeHandle);

        if ($fieldLayout === null) {
            return $this->normalizeNestedFieldsByPattern($fields);
        }

        $registry = CoPilot::getInstance()->transformerRegistry;

        foreach ($fields as $handle => $value) {
            if (!is_array($value)) {
                continue;
            }

            $field = $fieldLayout->getFieldByHandle($handle);

            if ($field === null) {
                continue;
            }

            $transformer = $registry->getTransformerForField($field);

            if ($transformer === null) {
                continue;
            }

            $normalized = $transformer->normalizeValue($field, $value, $entry);

            if ($normalized !== null) {
                $fields[$handle] = $normalized;
            }
        }

        return $fields;
    }

    private function resolveBlockFieldLayout(MatrixField $matrixField, string $blockTypeHandle): ?\craft\models\FieldLayout
    {
        foreach ($matrixField->getEntryTypes() as $entryType) {
            if ($entryType->handle === $blockTypeHandle) {
                return $entryType->getFieldLayout();
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function normalizeNestedFieldsByPattern(array $fields): array
    {
        foreach ($fields as $key => $value) {
            if (!is_array($value)) {
                continue;
            }

            if (isset($value['blocks']) && is_array($value['blocks'])) {
                $fields[$key] = $this->normalizeMatrixValue($value, null, $key);

                continue;
            }

            if (array_is_list($value)) {
                $fields[$key] = array_map(function($item) {
                    if (is_array($item) && (isset($item['type']) || isset($item['_blockType']))) {
                        return $this->normalizeMatrixBlock($item);
                    }

                    return $item;
                }, $value);
            }
        }

        return $fields;
    }

    /**
     * Matches incoming blocks without _blockId to existing blocks by type and position.
     *
     * When the agent reads an entry on one site and sends translated content to another,
     * block IDs are often omitted. This method maps unidentified blocks to their existing
     * counterparts so they update in place instead of being duplicated.
     *
     * @param array<int, array<string, mixed>> $blocks
     * @return array<int, array<string, mixed>>
     */
    private function matchBlocksByPosition(array $blocks, Entry $entry, string $fieldHandle): array
    {
        // Skip if all blocks already have _blockId
        $hasUnidentified = false;
        foreach ($blocks as $block) {
            if (!isset($block['_blockId'])) {
                $hasUnidentified = true;
                break;
            }
        }

        if (!$hasUnidentified) {
            return $blocks;
        }

        try {
            $existingBlocks = $entry->getFieldValue($fieldHandle)->all();
        } catch (\Throwable) {
            return $blocks;
        }

        if ($existingBlocks === []) {
            return $blocks;
        }

        // Build a list of existing blocks with their type and ID
        $existingByPosition = [];
        foreach ($existingBlocks as $existing) {
            $existingByPosition[] = [
                'id' => $existing->id,
                'type' => $existing->getType()->handle,
            ];
        }

        $matched = [];
        $existingIndex = 0;

        foreach ($blocks as $block) {
            // Block already identified — keep as-is
            if (isset($block['_blockId'])) {
                $matched[] = $block;
                continue;
            }

            $blockType = $block['_blockType'] ?? $block['type'] ?? null;

            // Try to match to the next existing block at the same position with matching type
            if ($existingIndex < count($existingByPosition)) {
                $candidate = $existingByPosition[$existingIndex];

                if ($blockType === null || $candidate['type'] === $blockType) {
                    $block['_blockId'] = $candidate['id'];
                    $existingIndex++;
                    $matched[] = $block;
                    continue;
                }
            }

            // No positional match — scan remaining existing blocks for a type match
            for ($i = $existingIndex; $i < count($existingByPosition); $i++) {
                if ($existingByPosition[$i]['type'] === $blockType) {
                    $block['_blockId'] = $existingByPosition[$i]['id'];
                    // Remove matched block so it's not matched again
                    array_splice($existingByPosition, $i, 1);
                    $matched[] = $block;
                    continue 2;
                }
            }

            // No match found — treat as genuinely new
            $matched[] = $block;
        }

        return $matched;
    }

    /**
     * @param array<string, mixed> $newEntries
     * @param array<string> $newSortOrder
     * @param array<string, mixed> $existingEntries Blocks with _blockId that should update in place
     * @param array<string> $existingUpdateIds IDs of blocks being updated
     * @return array<string, mixed>
     */
    private function mergeWithExistingBlocks(
        Entry $entry,
        string $fieldHandle,
        array $newEntries,
        array $newSortOrder,
        array $existingEntries = [],
        array $existingUpdateIds = [],
    ): array {
        $sortOrder = [];

        try {
            $existingBlocks = $entry->getFieldValue($fieldHandle)->all();
        } catch (\Throwable) {
            $existingBlocks = [];
        }

        foreach ($existingBlocks as $block) {
            $blockId = (string) $block->id;
            $sortOrder[] = $blockId;
        }

        $entries = $existingEntries;
        foreach ($newSortOrder as $key) {
            $sortOrder[] = $key;
            $entries[$key] = $newEntries[$key];
        }

        return [
            'entries' => $entries,
            'sortOrder' => $sortOrder,
        ];
    }
}
