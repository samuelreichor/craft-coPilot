<?php

namespace samuelreichor\coPilot\services;

use Craft;
use craft\base\Component;
use craft\base\FieldInterface;
use craft\fieldlayoutelements\BaseField;
use craft\fieldlayoutelements\CustomField;
use craft\fields\ContentBlock as ContentBlockField;
use craft\fields\Matrix as MatrixField;
use craft\models\EntryType;
use craft\models\FieldLayout;
use samuelreichor\coPilot\constants\Constants;
use samuelreichor\coPilot\CoPilot;
use samuelreichor\coPilot\enums\SectionAccess;
use samuelreichor\coPilot\helpers\CacheHelper;
use samuelreichor\coPilot\helpers\Logger;

/**
 * Builds Craft schema descriptions for the AI.
 */
class SchemaService extends Component
{
    /**
     * @return array<string, mixed>
     */
    public function getAccessibleSchema(): array
    {
        if (Craft::$app->getConfig()->getGeneral()->devMode) {
            return $this->buildSchema(slim: true);
        }

        $userId = Craft::$app->getUser()->getId() ?? 0;
        $cacheKey = Constants::CACHE_SCHEMA_PREFIX . 'overview.' . $userId;
        $cached = CacheHelper::get($cacheKey);

        if ($cached !== false) {
            return $cached;
        }

        $schema = $this->buildSchema(slim: true);
        CacheHelper::set($cacheKey, $schema);

        return $schema;
    }

    /**
     * Returns detailed schema for a single section including all field definitions.
     *
     * @return array<string, mixed>
     */
    public function getSectionSchema(string $handle): array
    {
        if (Craft::$app->getConfig()->getGeneral()->devMode) {
            return $this->buildSectionSchema($handle, slim: true);
        }

        $cacheKey = Constants::CACHE_SCHEMA_PREFIX . 'section.' . $handle;
        $cached = CacheHelper::get($cacheKey);

        if ($cached !== false) {
            return $cached;
        }

        $schema = $this->buildSectionSchema($handle, slim: true);
        CacheHelper::set($cacheKey, $schema);

        return $schema;
    }

    /**
     * Returns detailed schema for a single entry type including all field definitions.
     *
     * @return array<string, mixed>
     */
    public function getEntryTypeSchema(string $handle): array
    {
        if (Craft::$app->getConfig()->getGeneral()->devMode) {
            return $this->buildEntryTypeSchema($handle);
        }

        $cacheKey = Constants::CACHE_SCHEMA_PREFIX . 'entryType.' . $handle;
        $cached = CacheHelper::get($cacheKey);

        if ($cached !== false) {
            return $cached;
        }

        $schema = $this->buildEntryTypeSchema($handle);
        CacheHelper::set($cacheKey, $schema);

        return $schema;
    }

    /**
     * Returns field definitions for a category group.
     *
     * @return array<string, mixed>
     */
    public function getCategoryGroupSchema(string $handle): array
    {
        $group = Craft::$app->getCategories()->getGroupByHandle($handle);
        if (!$group) {
            return ['error' => "Category group '{$handle}' not found."];
        }

        $settings = CoPilot::getInstance()->getSettings();
        if ($settings->getCategoryGroupAccessLevel($group->uid) === SectionAccess::Blocked) {
            return ['error' => "Category group '{$handle}' is blocked."];
        }

        $fieldLayout = $group->getFieldLayout();
        $fields = $this->describeFieldLayoutFields($fieldLayout);

        return [
            'handle' => $group->handle,
            'name' => $group->name,
            'fields' => $fields,
        ];
    }

    /**
     * Returns field definitions for an asset volume.
     *
     * @return array<string, mixed>
     */
    public function getVolumeSchema(string $handle): array
    {
        $volume = Craft::$app->getVolumes()->getVolumeByHandle($handle);
        if (!$volume) {
            return ['error' => "Volume '{$handle}' not found."];
        }

        $settings = CoPilot::getInstance()->getSettings();
        if ($settings->getVolumeAccessLevel($volume->uid) === SectionAccess::Blocked) {
            return ['error' => "Volume '{$handle}' is blocked."];
        }

        $fieldLayout = $volume->getFieldLayout();
        $fields = $this->describeFieldLayoutFields($fieldLayout);

        return [
            'handle' => $volume->handle,
            'name' => $volume->name,
            'fields' => $fields,
        ];
    }

    public function invalidateCache(): void
    {
        CacheHelper::invalidateAll();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSchema(bool $slim = false): array
    {
        $settings = CoPilot::getInstance()->getSettings();
        $permissionGuard = CoPilot::getInstance()->permissionGuard;
        $sections = Craft::$app->getEntries()->getAllSections();
        $result = ['sections' => []];

        Logger::info('buildSchema: found ' . count($sections) . ' total sections, slim=' . ($slim ? 'true' : 'false'));

        foreach ($sections as $section) {
            $access = $settings->getSectionAccessLevel($section->uid);

            if ($access === SectionAccess::Blocked) {
                Logger::info("buildSchema: section '{$section->handle}' skipped — blocked");

                continue;
            }

            $guardCheck = $permissionGuard->canReadSection($section->uid);
            if (!$guardCheck['allowed']) {
                Logger::info("buildSchema: section '{$section->handle}' skipped — {$guardCheck['reason']}");

                continue;
            }

            $permissions = ['read'];
            if ($access === SectionAccess::ReadWrite) {
                $permissions[] = 'write';
            }

            if ($slim) {
                $entryTypes = [];
                $typeLabels = [];
                foreach ($section->getEntryTypes() as $et) {
                    $entryTypes[] = ['handle' => $et->handle, 'name' => $et->name];
                    $typeLabels[] = "{$et->handle} ({$et->name})";
                }

                $writable = $access === SectionAccess::ReadWrite ? 'read/write' : 'read-only';
                $result['sections'][] = [
                    'handle' => $section->handle,
                    'name' => $section->name,
                    'type' => $section->type,
                    'access' => $writable,
                    'entryTypes' => $entryTypes,
                    'summary' => "{$section->name} ({$section->type}, {$writable}) — Entry types: " . implode(', ', $typeLabels),
                ];

                continue;
            }

            $entryTypes = [];
            foreach ($section->getEntryTypes() as $entryType) {
                $entryTypes[] = $this->describeEntryType($entryType);
            }

            $result['sections'][] = [
                'handle' => $section->handle,
                'name' => $section->name,
                'type' => $section->type,
                'permissions' => $permissions,
                'entryTypes' => $entryTypes,
            ];
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSectionSchema(string $handle, bool $slim = false): array
    {
        $section = Craft::$app->getEntries()->getSectionByHandle($handle);

        if ($section === null) {
            return ['error' => "Section '{$handle}' not found."];
        }

        $settings = CoPilot::getInstance()->getSettings();
        $permissionGuard = CoPilot::getInstance()->permissionGuard;

        $access = $settings->getSectionAccessLevel($section->uid);

        if ($access === SectionAccess::Blocked) {
            return ['error' => "Section '{$handle}' is blocked."];
        }

        $guardCheck = $permissionGuard->canReadSection($section->uid);
        if (!$guardCheck['allowed']) {
            return ['error' => "Access denied: {$guardCheck['reason']}"];
        }

        $permissions = ['read'];
        if ($access === SectionAccess::ReadWrite) {
            $permissions[] = 'write';
        }

        $entryTypes = [];
        foreach ($section->getEntryTypes() as $entryType) {
            $entryTypes[] = $slim
                ? $this->describeEntryTypeSlim($entryType)
                : $this->describeEntryType($entryType);
        }

        return [
            'handle' => $section->handle,
            'name' => $section->name,
            'type' => $section->type,
            'permissions' => $permissions,
            'entryTypes' => $entryTypes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildEntryTypeSchema(string $handle): array
    {
        foreach (Craft::$app->getEntries()->getAllEntryTypes() as $entryType) {
            if ($entryType->handle === $handle) {
                return $this->describeEntryType($entryType);
            }
        }

        return ['error' => "Entry type '{$handle}' not found."];
    }

    /**
     * @return array<string, mixed>
     */
    private function describeEntryType(EntryType $entryType): array
    {
        $fieldLayout = $entryType->getFieldLayout();
        $fields = $this->describeFieldLayoutFields($fieldLayout);

        $info = [
            'handle' => $entryType->handle,
            'name' => $entryType->name,
        ];

        if ($entryType->description) {
            $info['description'] = $entryType->description;
        }

        $info['fields'] = $fields;

        return $info;
    }

    /**
     * Slim version of describeEntryType: Matrix fields only list block type handles.
     *
     * @return array<string, mixed>
     */
    private function describeEntryTypeSlim(EntryType $entryType): array
    {
        $fieldLayout = $entryType->getFieldLayout();
        $fields = $this->describeFieldLayoutFieldsSlim($fieldLayout);

        $info = [
            'handle' => $entryType->handle,
            'name' => $entryType->name,
        ];

        if ($entryType->description) {
            $info['description'] = $entryType->description;
        }

        $info['fields'] = $fields;

        return $info;
    }

    /**
     * Slim version: Matrix fields only list block type handles/names with a hint.
     * ContentBlock fields are still fully described.
     *
     * @return array<int, array<string, mixed>>
     */
    private function describeFieldLayoutFieldsSlim(FieldLayout $fieldLayout): array
    {
        $registry = CoPilot::getInstance()->transformerRegistry;
        $fields = [];

        // Native fields from layout
        $nativeElements = $fieldLayout->getElementsByType(BaseField::class);
        foreach ($nativeElements as $element) {
            if ($element instanceof CustomField) {
                continue;
            }

            $handle = $element->attribute();
            $fieldInfo = [
                'handle' => $handle,
                'name' => $element->label() ?? ucfirst($handle),
                'type' => 'native',
            ];

            if ($element->required) {
                $fieldInfo['required'] = true;
            }

            $fields[] = $fieldInfo;
        }

        // Custom fields — slim: Matrix fields only list block type handles
        foreach ($registry->resolveFieldLayoutFields($fieldLayout) as $resolved) {
            $layoutElement = $resolved['layoutElement'];
            $field = $resolved['field'];

            if ($field instanceof MatrixField) {
                $blockTypes = [];
                foreach ($field->getEntryTypes() as $blockEntryType) {
                    $blockTypes[] = [
                        'handle' => $blockEntryType->handle,
                        'name' => $blockEntryType->name,
                    ];
                }

                $fieldInfo = [
                    'handle' => $layoutElement->attribute(),
                    'name' => $field->name,
                    'type' => 'Matrix',
                    'blockTypes' => $blockTypes,
                    'hint' => 'Call describeEntryType(handle) for full field definitions of each block type.',
                ];

                if (property_exists($field, 'required') && $field->required) {
                    $fieldInfo['required'] = true;
                }

                $fields[] = $fieldInfo;
            } else {
                $fields[] = $this->describeCustomField($layoutElement, $field);
            }
        }

        // Generated fields
        $nativeHandles = array_column($fields, 'handle');
        if (method_exists($fieldLayout, 'getGeneratedFields')) {
            foreach ($fieldLayout->getGeneratedFields() as $generated) {
                if (!is_array($generated) || !isset($generated['handle'])) {
                    continue;
                }

                $handle = $generated['handle'];
                if (in_array($handle, $nativeHandles, true)) {
                    continue;
                }

                $fields[] = [
                    'handle' => $handle,
                    'name' => $handle,
                    'type' => 'generated',
                ];
            }
        }

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    private function describeCustomField(CustomField $layoutElement, FieldInterface $field): array
    {
        $handle = $layoutElement->attribute();

        $fieldInfo = [
            'handle' => $handle,
            'name' => $field->name,
            'type' => $this->getFieldTypeName($field),
        ];

        if (property_exists($field, 'required') && $field->required) {
            $fieldInfo['required'] = true;
        }

        if (property_exists($field, 'charLimit') && $field->charLimit) {
            $fieldInfo['maxLength'] = $field->charLimit;
        }

        if (property_exists($field, 'instructions') && $field->instructions) {
            $fieldInfo['instructions'] = $field->instructions;
        }

        $fieldInfo = $this->describeFieldMetadata($field, $fieldInfo);

        if ($field instanceof ContentBlockField) {
            $fieldInfo['fields'] = $this->describeContentBlockFields($field);
        }

        if ($field instanceof MatrixField) {
            $fieldInfo['blockTypes'] = $this->describeMatrixBlockTypes($field);
        }

        return $fieldInfo;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function describeContentBlockFields(ContentBlockField $field): array
    {
        return $this->describeFieldLayoutFields($field->getFieldLayout());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function describeMatrixBlockTypes(MatrixField $field): array
    {
        $blockTypes = [];

        foreach ($field->getEntryTypes() as $entryType) {
            $fieldLayout = $entryType->getFieldLayout();
            $blockFields = $this->describeFieldLayoutFields($fieldLayout);

            $blockType = [
                'handle' => $entryType->handle,
                'name' => $entryType->name,
            ];

            if ($entryType->description) {
                $blockType['description'] = $entryType->description;
            }

            $blockType['fields'] = $blockFields;

            $blockTypes[] = $blockType;
        }

        return $blockTypes;
    }

    /**
     * Describes all fields in a layout: native (title, slug, alt, …), custom, and generated.
     *
     * @return array<int, array<string, mixed>>
     */
    private function describeFieldLayoutFields(FieldLayout $fieldLayout): array
    {
        $registry = CoPilot::getInstance()->transformerRegistry;
        $fields = [];

        // Native fields (title, slug, alt, etc.) from the layout
        $nativeElements = $fieldLayout->getElementsByType(BaseField::class);
        foreach ($nativeElements as $element) {
            // CustomField extends BaseField — skip here, handled below
            if ($element instanceof CustomField) {
                continue;
            }

            $handle = $element->attribute();
            $fieldInfo = [
                'handle' => $handle,
                'name' => $element->label() ?? ucfirst($handle),
                'type' => 'native',
            ];

            if ($element->required) {
                $fieldInfo['required'] = true;
            }

            $fields[] = $fieldInfo;
        }

        // Custom fields
        foreach ($registry->resolveFieldLayoutFields($fieldLayout) as $resolved) {
            $fields[] = $this->describeCustomField($resolved['layoutElement'], $resolved['field']);
        }

        // Generated fields
        $nativeHandles = array_column($fields, 'handle');
        if (method_exists($fieldLayout, 'getGeneratedFields')) {
            foreach ($fieldLayout->getGeneratedFields() as $generated) {
                if (!is_array($generated) || !isset($generated['handle'])) {
                    continue;
                }

                $handle = $generated['handle'];

                // Skip if already covered by native or custom fields
                if (in_array($handle, $nativeHandles, true)) {
                    continue;
                }

                $fields[] = [
                    'handle' => $handle,
                    'name' => $handle,
                    'type' => 'generated',
                ];
            }
        }

        return $fields;
    }

    /**
     * Falls back to displayName() when getShortName() is too generic (e.g. craft\ckeditor\Field).
     */
    private function getFieldTypeName(FieldInterface $field): string
    {
        $shortName = (new \ReflectionClass($field))->getShortName();

        if ($shortName === 'Field') {
            return $field::displayName();
        }

        return $shortName;
    }

    /**
     * @param array<string, mixed> $fieldInfo
     * @return array<string, mixed>
     */
    private function describeFieldMetadata(FieldInterface $field, array $fieldInfo): array
    {
        $transformer = CoPilot::getInstance()->transformerRegistry->getTransformerForField($field);

        if ($transformer !== null) {
            return $transformer->describeField($field, $fieldInfo);
        }

        return $fieldInfo;
    }
}
