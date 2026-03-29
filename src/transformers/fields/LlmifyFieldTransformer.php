<?php

namespace samuelreichor\coPilot\transformers\fields;

use craft\base\Element;
use craft\base\FieldInterface;

/**
 * Handles samuelreichor LLMify settings fields.
 *
 * The field stores LLM metadata configuration (title, description, front matter)
 * at the entry level.
 */
class LlmifyFieldTransformer implements FieldTransformerInterface
{
    public function getSupportedFieldClasses(): array
    {
        return [];
    }

    public function matchesField(FieldInterface $field): ?bool
    {
        if (get_class($field) === 'samuelreichor\llmify\fields\LlmifySettingsField') {
            return true;
        }

        return null;
    }

    public function describeField(FieldInterface $field, array $fieldInfo): array
    {
        $fieldInfo['hint'] = 'LLMify settings. Controls how this entry is exposed to LLMs. '
            . 'Object with these groups of keys:\n'
            . '**Title**: Set "overrideTitleSettings": true to enable entry-level overrides. '
            . 'Then set "llmTitleSource" to a field handle (e.g. "title", "headline") to pull the title from that field, '
            . 'OR set "llmTitleSource": "custom" AND provide "llmTitle": "Your custom title". '
            . '"llmTitle" is ONLY used when "llmTitleSource" is "custom".\n'
            . '**Description**: Set "overrideDescriptionSettings": true to enable entry-level overrides. '
            . 'Then set "llmDescriptionSource" to a field handle OR set "llmDescriptionSource": "custom" AND provide "llmDescription": "Your text". '
            . '"llmDescription" is ONLY used when "llmDescriptionSource" is "custom".\n'
            . '**Front Matter**: Set "overrideFrontMatterSettings": true, then provide "frontMatterFields": [{handle, enabled, label}, ...].';

        return $fieldInfo;
    }

    public function serializeValue(FieldInterface $field, mixed $value, int $depth): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $data = [];

        $keys = [
            'overrideTitleSettings',
            'llmTitleSource',
            'llmTitle',
            'overrideDescriptionSettings',
            'llmDescriptionSource',
            'llmDescription',
            'overrideFrontMatterSettings',
            'frontMatterFields',
        ];

        foreach ($keys as $key) {
            if (array_key_exists($key, $value) && $value[$key] !== null && $value[$key] !== '') {
                $data[$key] = $value[$key];
            }
        }

        return $data !== [] ? $data : null;
    }

    public function normalizeValue(FieldInterface $field, mixed $value, ?Element $element = null): mixed
    {
        if (!is_array($value)) {
            return null;
        }

        // Merge with existing values so partial updates don't clear other keys
        if ($element !== null) {
            $handle = $field->handle;
            $existing = $element->getFieldValue($handle);

            if (is_array($existing)) {
                $value = array_merge($existing, $value);
            }
        }

        return $value;
    }
}
