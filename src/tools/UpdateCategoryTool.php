<?php

namespace samuelreichor\coPilot\tools;

use Craft;
use craft\elements\Category;
use samuelreichor\coPilot\CoPilot;
use samuelreichor\coPilot\enums\SectionAccess;

class UpdateCategoryTool implements ToolInterface
{
    public function getName(): string
    {
        return 'updateCategory';
    }

    public function getDescription(): string
    {
        return 'Updates an existing category. Can change title, slug, and custom field values.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'categoryId' => [
                    'type' => 'integer',
                    'description' => 'The Craft category ID to update',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'Optional new title',
                ],
                'slug' => [
                    'type' => 'string',
                    'description' => 'Optional new slug',
                ],
                'fields' => [
                    'type' => 'object',
                    'description' => 'Optional custom field values as key-value pairs.',
                ],
            ],
            'required' => ['categoryId'],
        ];
    }

    public function execute(array $arguments): array
    {
        $categoryId = $arguments['categoryId'];

        $category = Category::find()->id($categoryId)->status(null)->one();
        if (!$category) {
            return [
                'error' => "Category #{$categoryId} not found.",
                'retryHint' => null,
            ];
        }

        $permissionError = $this->checkPermissions($category);
        if ($permissionError !== null) {
            return ['error' => $permissionError, 'retryHint' => null];
        }

        $reserved = ['categoryId', '_siteHandle'];
        $fields = $arguments['fields'] ?? array_diff_key($arguments, array_flip($reserved));

        $diff = [];

        if (isset($fields['title'])) {
            $diff['title'] = ['old' => $category->title, 'new' => $fields['title']];
            $category->title = $fields['title'];
            unset($fields['title']);
        }

        if (isset($fields['slug'])) {
            $diff['slug'] = ['old' => $category->slug, 'new' => $fields['slug']];
            $category->slug = $fields['slug'];
            unset($fields['slug']);
        }

        foreach ($fields as $fieldHandle => $value) {
            try {
                $category->setFieldValue($fieldHandle, $value);
                $diff[$fieldHandle] = ['old' => null, 'new' => $value];
            } catch (\Throwable $e) {
                return [
                    'error' => "Invalid field handle '{$fieldHandle}': {$e->getMessage()}",
                    'retryHint' => "Remove or correct the field '{$fieldHandle}' and retry.",
                ];
            }
        }

        if ($diff === []) {
            return [
                'error' => 'No fields to update. Provide title, slug, or field values.',
                'retryHint' => null,
            ];
        }

        if (!Craft::$app->getElements()->saveElement($category)) {
            return [
                'error' => 'Failed to save category.',
                'validationErrors' => $category->getFirstErrors(),
                'retryHint' => 'Fix the fields listed in validationErrors and retry.',
            ];
        }

        return [
            'success' => true,
            'categoryId' => $category->id,
            'title' => $category->title,
            'slug' => $category->slug,
            'group' => $category->getGroup()->handle,
            'updatedFields' => array_keys($diff),
            'diff' => $diff,
            'message' => 'Category updated successfully. ' . count($diff) . ' field(s) changed.',
        ];
    }

    private function checkPermissions(Category $category): ?string
    {
        $settings = CoPilot::getInstance()->getSettings();

        if ($settings->isElementTypeBlocked(Category::class)) {
            return 'Access denied – category access is blocked by the data protection settings.';
        }

        $group = $category->getGroup();
        if ($settings->getCategoryGroupAccessLevel($group->uid) !== SectionAccess::ReadWrite) {
            return "Access denied – the category group '{$group->name}' is not writable.";
        }

        $user = Craft::$app->getUser()->getIdentity();
        if (!$user) {
            return 'Access denied – no authenticated user.';
        }

        if (!$user->can("saveCategories:{$group->uid}")) {
            return "Access denied – you lack the 'Save Categories' permission for '{$group->name}'.";
        }

        return null;
    }
}
