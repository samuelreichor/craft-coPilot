<?php

namespace samuelreichor\coPilot\tools;

use Craft;
use craft\elements\Category;
use samuelreichor\coPilot\CoPilot;
use samuelreichor\coPilot\enums\SectionAccess;

class CreateCategoryTool implements ToolInterface
{
    public function getName(): string
    {
        return 'createCategory';
    }

    public function getDescription(): string
    {
        return 'Creates a new category in a given category group. Returns the category ID for use in relation fields.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'groupHandle' => [
                    'type' => 'string',
                    'description' => 'The category group handle',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'The title for the new category',
                ],
                'slug' => [
                    'type' => 'string',
                    'description' => 'Optional custom slug. If omitted, Craft generates one from the title.',
                ],
                'fields' => [
                    'type' => 'object',
                    'description' => 'Optional custom field values as key-value pairs.',
                ],
            ],
            'required' => ['groupHandle', 'title'],
        ];
    }

    public function execute(array $arguments): array
    {
        $groupHandle = $arguments['groupHandle'];
        $title = $arguments['title'];
        $slug = $arguments['slug'] ?? null;

        $reserved = ['groupHandle', 'title', 'slug', '_siteHandle'];
        $fields = $arguments['fields'] ?? array_diff_key($arguments, array_flip($reserved));

        $group = Craft::$app->getCategories()->getGroupByHandle($groupHandle);
        if (!$group) {
            $validGroups = array_map(fn($g) => $g->handle, Craft::$app->getCategories()->getAllGroups());

            return [
                'error' => "Category group '{$groupHandle}' not found.",
                'availableGroups' => $validGroups,
                'retryHint' => 'Use one of the available category group handles listed above.',
            ];
        }

        $permissionError = $this->checkPermissions($group);
        if ($permissionError !== null) {
            return ['error' => $permissionError, 'retryHint' => null];
        }

        $category = new Category();
        $category->groupId = $group->id;
        $category->title = $title;

        if ($slug !== null) {
            $category->slug = $slug;
        }

        foreach ($fields as $fieldHandle => $value) {
            try {
                $category->setFieldValue($fieldHandle, $value);
            } catch (\Throwable $e) {
                return [
                    'error' => "Invalid field handle '{$fieldHandle}': {$e->getMessage()}",
                    'retryHint' => "Remove or correct the field '{$fieldHandle}' and retry.",
                ];
            }
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
            'group' => $groupHandle,
            'message' => 'Category created successfully.',
        ];
    }

    private function checkPermissions(\craft\models\CategoryGroup $group): ?string
    {
        $settings = CoPilot::getInstance()->getSettings();

        if ($settings->isElementTypeBlocked(Category::class)) {
            return 'Access denied – category access is blocked by the data protection settings.';
        }

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
