<?php

namespace samuelreichor\coPilot\tools;

use Craft;
use craft\elements\Asset;
use samuelreichor\coPilot\CoPilot;
use samuelreichor\coPilot\enums\SectionAccess;

class SearchAssetsTool implements ToolInterface
{
    public function getName(): string
    {
        return 'searchAssets';
    }

    public function getDescription(): string
    {
        return 'Searches for assets (images, files) across allowed volumes. Call without a query to browse all available assets. Returns asset summaries with IDs that can be used in updateEntry or createEntry (pass as [assetId] array for asset fields).';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Search term (uses Craft Search)',
                ],
                'volume' => [
                    'type' => 'string',
                    'description' => 'Optional: Restrict to a volume (handle)',
                ],
                'kind' => [
                    'type' => 'string',
                    'enum' => ['image', 'video', 'pdf', 'json', 'text'],
                    'description' => 'Optional: Filter by asset kind',
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

        if ($settings->isElementTypeBlocked(Asset::class)) {
            return ['total' => 0, 'results' => []];
        }

        $searchQuery = $arguments['query'] ?? null;
        $volumeHandle = $arguments['volume'] ?? null;
        $kind = $arguments['kind'] ?? null;
        $defaultLimit = $settings->defaultSearchLimit;
        $limit = min($arguments['limit'] ?? $defaultLimit, 50);

        $query = Asset::find()->limit($limit);

        if ($searchQuery) {
            $query->search($searchQuery);
        }

        if ($volumeHandle) {
            $query->volume($volumeHandle);
        }

        if ($kind) {
            $query->kind($kind);
        }

        $allowedVolumeIds = $this->getAllowedVolumeIds();
        if (empty($allowedVolumeIds)) {
            return ['total' => 0, 'results' => []];
        }
        $query->volumeId($allowedVolumeIds);

        if ($searchQuery) {
            $query->orderBy('score');
        } else {
            $query->orderBy('elements.dateCreated DESC');
        }

        $total = $query->count();
        $assets = $query->all();

        $results = array_map(fn(Asset $asset) => [
            'id' => $asset->id,
            'filename' => $asset->filename,
            'url' => $asset->url,
            'alt' => $asset->alt ?? '',
            'kind' => $asset->kind,
            'volume' => $asset->getVolume()->handle,
            'width' => $asset->width,
            'height' => $asset->height,
            'size' => $asset->size,
        ], $assets);

        return [
            'total' => $total,
            'results' => $results,
        ];
    }

    /**
     * @return int[]
     */
    private function getAllowedVolumeIds(): array
    {
        $user = Craft::$app->getUser()->getIdentity();
        if (!$user) {
            return [];
        }

        $settings = CoPilot::getInstance()->getSettings();
        $volumes = Craft::$app->getVolumes()->getAllVolumes();
        $ids = [];

        foreach ($volumes as $volume) {
            if ($settings->getVolumeAccessLevel($volume->uid) === SectionAccess::Blocked) {
                continue;
            }

            if ($user->can("viewAssets:{$volume->uid}")) {
                $ids[] = $volume->id;
            }
        }

        return $ids;
    }
}
