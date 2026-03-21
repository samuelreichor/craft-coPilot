<?php

namespace samuelreichor\coPilot\providers;

use samuelreichor\coPilot\CoPilot;

/**
 * Normalizes tool definitions to provider-specific formats.
 */
class ToolFormatter
{
    /**
     * Converts internal tool format to OpenAI Responses API format.
     *
     * @param array<int, array<string, mixed>> $tools
     * @return array<int, array<string, mixed>>
     */
    public static function forOpenAI(array $tools): array
    {
        $formatted = array_map(fn(array $tool) => [
            'type' => 'function',
            'name' => $tool['name'],
            'description' => $tool['description'],
            'parameters' => $tool['parameters'],
        ], $tools);

        try {
            $plugin = CoPilot::getInstance();
            if ($plugin !== null && $plugin->getSettings()->webSearchEnabled) {
                $formatted[] = ['type' => 'web_search'];
            }
        } catch (\Throwable) {
            // Plugin not bootstrapped (e.g. unit tests)
        }

        return $formatted;
    }

    /**
     * Converts internal tool format to Anthropic tool use format.
     *
     * @param array<int, array<string, mixed>> $tools
     * @return array<int, array<string, mixed>>
     */
    public static function forAnthropic(array $tools): array
    {
        return array_map(fn(array $tool) => [
            'name' => $tool['name'],
            'description' => $tool['description'],
            'input_schema' => $tool['parameters'],
        ], $tools);
    }

    /**
     * Converts internal tool format to Gemini function declarations format.
     *
     * @param array<int, array<string, mixed>> $tools
     * @return array<int, array<string, mixed>>
     */
    public static function forGemini(array $tools): array
    {
        if (empty($tools)) {
            return [];
        }

        return [
            [
                'functionDeclarations' => array_map(function(array $tool) {
                    $declaration = [
                        'name' => $tool['name'],
                        'description' => $tool['description'],
                    ];

                    // Gemini rejects parameterless tools if parameters key is present
                    if (self::hasProperties($tool['parameters'])) {
                        $declaration['parameters'] = self::sanitizeSchemaForGemini($tool['parameters']);
                    }

                    return $declaration;
                }, $tools),
            ],
        ];
    }

    /**
     * Checks whether a tool parameter schema has actual properties defined.
     *
     * @param array<string, mixed> $parameters
     */
    private static function hasProperties(array $parameters): bool
    {
        $properties = $parameters['properties'] ?? null;

        if ($properties instanceof \stdClass) {
            return (array)$properties !== [];
        }

        return is_array($properties) && $properties !== [];
    }

    /**
     * Ensures all properties in a schema have a `type` field.
     * Gemini rejects schemas with typeless properties (returns 400).
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private static function sanitizeSchemaForGemini(array $schema): array
    {
        if (!isset($schema['properties']) || !is_array($schema['properties'])) {
            return $schema;
        }

        foreach ($schema['properties'] as $key => $prop) {
            if (!is_array($prop)) {
                continue;
            }

            if (!isset($prop['type'])) {
                $schema['properties'][$key]['type'] = 'string';
            }

            // Recurse into nested object properties
            if (($prop['type'] ?? $schema['properties'][$key]['type'] ?? '') === 'object' && isset($prop['properties'])) {
                $schema['properties'][$key] = self::sanitizeSchemaForGemini($prop);
            }
        }

        return $schema;
    }
}
