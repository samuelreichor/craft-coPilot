<?php

namespace samuelreichor\coPilot\tools;

use samuelreichor\coPilot\CoPilot;

class DescribeVolumeTool implements ToolInterface
{
    public function getName(): string
    {
        return 'describeVolume';
    }

    public function getDescription(): string
    {
        return 'Returns field definitions for an asset volume. Call before updateAsset to know exact field handles and accepted value formats.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'volumeHandle' => [
                    'type' => 'string',
                    'description' => 'The volume handle (from searchAssets results).',
                ],
            ],
            'required' => ['volumeHandle'],
        ];
    }

    public function execute(array $arguments): array
    {
        $volumeHandle = $arguments['volumeHandle'] ?? null;

        if (!is_string($volumeHandle) || $volumeHandle === '') {
            return ['error' => 'Missing required parameter: volumeHandle'];
        }

        return CoPilot::getInstance()->schemaService->getVolumeSchema($volumeHandle);
    }
}
