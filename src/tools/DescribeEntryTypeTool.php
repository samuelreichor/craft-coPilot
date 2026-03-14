<?php

namespace samuelreichor\coPilot\tools;

use samuelreichor\coPilot\CoPilot;

class DescribeEntryTypeTool implements ToolInterface
{
    public function getName(): string
    {
        return 'describeEntryType';
    }

    public function getDescription(): string
    {
        return 'Returns full field definitions for a specific entry type or Matrix block type. Use after describeSection to get details for a specific block type.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'handle' => [
                    'type' => 'string',
                    'description' => 'The entry type handle (from describeSection block type list).',
                ],
            ],
            'required' => ['handle'],
        ];
    }

    public function execute(array $arguments): array
    {
        $handle = $arguments['handle'] ?? null;

        if (!is_string($handle) || $handle === '') {
            return ['error' => 'Missing required parameter: handle'];
        }

        $result = CoPilot::getInstance()->schemaService->getEntryTypeSchema($handle);

        if (isset($result['error'])) {
            return $result;
        }

        $siteHandle = $arguments['_siteHandle'] ?? null;
        $examples = CoPilot::getInstance()->contextService->getExampleEntries(null, $handle, 2, $siteHandle);

        if ($examples !== []) {
            $result['examples'] = $examples;
        }

        return $result;
    }
}
