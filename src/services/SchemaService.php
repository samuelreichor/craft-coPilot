<?php

namespace samuelreichor\coPilot\services;

use Craft;
use craft\base\Component;
use craft\base\FieldInterface;
use craft\fieldlayoutelements\CustomField;
use craft\fields\ContentBlock as ContentBlockField;
use craft\fields\Matrix as MatrixField;
use craft\models\EntryType;
use craft\models\FieldLayout;
use samuelreichor\coPilot\constants\Constants;
use samuelreichor\coPilot\CoPilot;
use samuelreichor\coPilot\enums\SectionAccess;
use samuelreichor\coPilot\helpers\Logger;
use yii\caching\TagDependency;

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
        $cached = Craft::$app->getCache()->get($cacheKey);

        if ($cached !== false) {
            return $cached;
        }

        $schema = $this->buildSchema(slim: true);
        $dependency = new TagDependency(['tags' => [Constants::CACHE_SCHEMA_PREFIX . 'overview']]);
        Craft::$app->getCache()->set($cacheKey, $schema, 3600, $dependency);

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
        $cached = Craft::$app->getCache()->get($cacheKey);

        if ($cached !== false) {
            return $cached;
        }

        $schema = $this->buildSectionSchema($handle, slim: true);
        Craft::$app->getCache()->set($cacheKey, $schema, 3600);

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
        $cached = Craft::$app->getCache()->get($cacheKey);

        if ($cached !== false) {
            return $cached;
        }

        $schema = $this->buildEntryTypeSchema($handle);
        Craft::$app->getCache()->set($cacheKey, $schema, 3600);

        return $schema;
    }

    public function invalidateCache(): void
    {
        TagDependency::invalidate(Craft::$app->getCache(), Constants::CACHE_SCHEMA_PREFIX . 'overview');

        $sections = Craft::$app->getEntries()->getAllSections();
        foreach ($sections as $section) {
            Craft::$app->getCache()->delete(Constants::CACHE_SCHEMA_PREFIX . 'section.' . $section->handle);
        }

        foreach (Craft::$app->getEntries()->getAllEntryTypes() as $entryType) {
            Craft::$app->getCache()->delete(Constants::CACHE_SCHEMA_PREFIX . 'entryType.' . $entryType->handle);
        }
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
        $fields = [];

        if ($entryType->hasTitleField) {
            $fields[] = [
                'handle' => 'title',
                'name' => 'Title',
                'type' => 'native',
                'required' => true,
            ];
        }

        if ($entryType->showSlugField) {
            $fields[] = [
                'handle' => 'slug',
                'name' => 'Slug',
                'type' => 'native',
            ];
        }

        $fieldLayout = $entryType->getFieldLayout();
        $fields = array_merge($fields, $this->describeFieldLayoutFields($fieldLayout));

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
        $fields = [];

        if ($entryType->hasTitleField) {
            $fields[] = [
                'handle' => 'title',
                'name' => 'Title',
                'type' => 'native',
                'required' => true,
            ];
        }

        if ($entryType->showSlugField) {
            $fields[] = [
                'handle' => 'slug',
                'name' => 'Slug',
                'type' => 'native',
            ];
        }

        $fieldLayout = $entryType->getFieldLayout();
        $fields = array_merge($fields, $this->describeFieldLayoutFieldsSlim($fieldLayout));

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

        if (method_exists($fieldLayout, 'getGeneratedFields')) {
            foreach ($fieldLayout->getGeneratedFields() as $generated) {
                if (!is_array($generated) || !isset($generated['handle'])) {
                    continue;
                }

                $handle = $generated['handle'];
                if ($handle === 'title' || $handle === 'slug') {
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
            $blockFields = [];

            if ($entryType->hasTitleField) {
                $blockFields[] = [
                    'handle' => 'title',
                    'name' => 'Title',
                    'type' => 'native',
                    'required' => true,
                ];
            }

            $fieldLayout = $entryType->getFieldLayout();
            $blockFields = array_merge($blockFields, $this->describeFieldLayoutFields($fieldLayout));

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
     * @return array<int, array<string, mixed>>
     */
    private function describeFieldLayoutFields(FieldLayout $fieldLayout): array
    {
        $registry = CoPilot::getInstance()->transformerRegistry;
        $fields = [];

        foreach ($registry->resolveFieldLayoutFields($fieldLayout) as $resolved) {
            $fields[] = $this->describeCustomField($resolved['layoutElement'], $resolved['field']);
        }

        if (method_exists($fieldLayout, 'getGeneratedFields')) {
            foreach ($fieldLayout->getGeneratedFields() as $generated) {
                if (!is_array($generated) || !isset($generated['handle'])) {
                    continue;
                }

                $handle = $generated['handle'];

                if ($handle === 'title' || $handle === 'slug') {
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
