<?php

namespace samuelreichor\coPilot\tools;

use Craft;
use craft\elements\Asset;
use samuelreichor\coPilot\CoPilot;
use samuelreichor\coPilot\enums\SectionAccess;

class UpdateAssetTool implements ToolInterface
{
    public function getName(): string
    {
        return 'updateAsset';
    }

    public function getDescription(): string
    {
        return 'Updates metadata on an existing asset. Can change title, alt text, and custom field values. Cannot upload or replace files.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'assetId' => [
                    'type' => 'integer',
                    'description' => 'The Craft asset ID to update',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'Optional new title',
                ],
                'alt' => [
                    'type' => 'string',
                    'description' => 'Optional new alt text (for images)',
                ],
                'fields' => [
                    'type' => 'object',
                    'description' => 'Optional custom field values as key-value pairs.',
                ],
            ],
            'required' => ['assetId'],
        ];
    }

    public function execute(array $arguments): array
    {
        $assetId = $arguments['assetId'];

        $asset = Asset::find()->id($assetId)->one();
        if (!$asset) {
            return [
                'error' => "Asset #{$assetId} not found.",
                'retryHint' => null,
            ];
        }

        $permissionError = $this->checkPermissions($asset);
        if ($permissionError !== null) {
            return ['error' => $permissionError, 'retryHint' => null];
        }

        $reserved = ['assetId', '_siteHandle'];
        $fields = $arguments['fields'] ?? array_diff_key($arguments, array_flip($reserved));

        $diff = [];

        if (isset($fields['title'])) {
            $diff['title'] = ['old' => $asset->title, 'new' => $fields['title']];
            $asset->title = $fields['title'];
            unset($fields['title']);
        }

        if (isset($fields['alt'])) {
            $diff['alt'] = ['old' => $asset->alt, 'new' => $fields['alt']];
            $asset->alt = $fields['alt'];
            unset($fields['alt']);
        }

        foreach ($fields as $fieldHandle => $value) {
            try {
                $asset->setFieldValue($fieldHandle, $value);
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
                'error' => 'No fields to update. Provide title, alt, or field values.',
                'retryHint' => null,
            ];
        }

        if (!Craft::$app->getElements()->saveElement($asset)) {
            return [
                'error' => 'Failed to save asset.',
                'validationErrors' => $asset->getFirstErrors(),
                'retryHint' => 'Fix the fields listed in validationErrors and retry.',
            ];
        }

        return [
            'success' => true,
            'assetId' => $asset->id,
            'title' => $asset->title,
            'alt' => $asset->alt,
            'filename' => $asset->filename,
            'volume' => $asset->getVolume()->handle,
            'updatedFields' => array_keys($diff),
            'diff' => $diff,
            'message' => 'Asset updated successfully. ' . count($diff) . ' field(s) changed.',
        ];
    }

    private function checkPermissions(Asset $asset): ?string
    {
        $settings = CoPilot::getInstance()->getSettings();

        if ($settings->isElementTypeBlocked(Asset::class)) {
            return 'Access denied – asset access is blocked by the data protection settings.';
        }

        $volume = $asset->getVolume();
        if ($settings->getVolumeAccessLevel($volume->uid) !== SectionAccess::ReadWrite) {
            return "Access denied – the volume '{$volume->name}' is not writable.";
        }

        $user = Craft::$app->getUser()->getIdentity();
        if (!$user) {
            return 'Access denied – no authenticated user.';
        }

        if (!$user->can("saveAssets:{$volume->uid}")) {
            return "Access denied – you lack the 'Save Assets' permission for volume '{$volume->name}'.";
        }

        return null;
    }
}
