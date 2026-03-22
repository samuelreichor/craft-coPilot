<?php

namespace samuelreichor\coPilot\tools;

use Craft;
use craft\elements\Category;
use samuelreichor\coPilot\CoPilot;
use samuelreichor\coPilot\enums\SectionAccess;

class SearchCategoriesTool implements ToolInterface
{
    public function getName(): string
    {
        return 'searchCategories';
    }

    public function getDescription(): string
    {
        return 'Searches for categories by title. Returns category summaries with IDs that can be used in updateEntry or createEntry (pass as [categoryId] array for category relation fields).';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Search term. Omit to browse all categories.',
                ],
                'group' => [
                    'type' => 'string',
                    'description' => 'Optional: Restrict to a category group (handle)',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max number of results. Default: 20.',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments): array
    {
        $settings = CoPilot::getInstance()->getSettings();

        if ($settings->isElementTypeBlocked(Category::class)) {
            return ['total' => 0, 'results' => []];
        }

        $searchQuery = $arguments['query'] ?? null;
        $groupHandle = $arguments['group'] ?? null;
        $defaultLimit = $settings->defaultSearchLimit;
        $limit = min($arguments['limit'] ?? $defaultLimit, 50);

        $query = Category::find()->limit($limit);

        // Filter to allowed category groups
        $allowedGroupIds = $this->getAllowedGroupIds();
        if (empty($allowedGroupIds)) {
            return ['total' => 0, 'results' => []];
        }
        $query->groupId($allowedGroupIds);

        if ($searchQuery) {
            $query->search($searchQuery);
        }

        if ($groupHandle) {
            $categoryGroup = Craft::$app->getCategories()->getGroupByHandle($groupHandle);
            if (!$categoryGroup) {
                $validGroups = array_map(fn($g) => $g->handle, Craft::$app->getCategories()->getAllGroups());

                return [
                    'total' => 0,
                    'results' => [],
                    'error' => "Category group \"{$groupHandle}\" not found.",
                    'availableGroups' => $validGroups,
                    'retryHint' => !empty($validGroups)
                        ? 'Use one of the available category group handles listed above.'
                        : 'No category groups exist.',
                ];
            }
            $query->group($groupHandle);
        }

        if ($searchQuery) {
            $query->orderBy('score');
        } else {
            $query->orderBy('elements.dateCreated DESC');
        }

        $total = $query->count();
        $categories = $query->all();

        $results = array_map(fn(Category $category) => [
            'id' => $category->id,
            'title' => $category->title,
            'slug' => $category->slug,
            'group' => $category->getGroup()->handle,
            'level' => $category->level,
        ], $categories);

        return [
            'total' => $total,
            'results' => $results,
        ];
    }

    /**
     * @return int[]
     */
    private function getAllowedGroupIds(): array
    {
        $settings = CoPilot::getInstance()->getSettings();
        $groups = Craft::$app->getCategories()->getAllGroups();
        $ids = [];

        foreach ($groups as $group) {
            if ($settings->getCategoryGroupAccessLevel($group->uid) === SectionAccess::Blocked) {
                continue;
            }

            $ids[] = $group->id;
        }

        return $ids;
    }
}
