<?php

namespace samuelreichor\coPilot\services;

use Craft;
use craft\base\Component;
use craft\base\FieldInterface;
use craft\elements\Entry;
use samuelreichor\coPilot\CoPilot;

/**
 * Normalizes AI-provided field values into formats Craft expects.
 * Delegates to field transformers via the TransformerRegistry.
 */
class FieldNormalizer extends Component
{
    /**
     * Available during normalizeValue() so transformers can use the layout handle
     * (which may differ from the field's original handle) for Matrix block merging.
     */
    private ?string $currentFieldHandle = null;
    public function normalize(string $fieldHandle, mixed $value, ?Entry $entry = null): mixed
    {
        $value = $this->unescapeJsonSlashes($value);
        $value = $this->stripSerializationMarkers($value);

        $field = $this->resolveField($fieldHandle, $entry);

        if ($field === null) {
            return $value;
        }

        $transformer = CoPilot::getInstance()->transformerRegistry->getTransformerForField($field);

        if ($transformer === null) {
            return $value;
        }

        $this->currentFieldHandle = $fieldHandle;

        try {
            $normalized = $transformer->normalizeValue($field, $value, $entry);

            return $normalized ?? $value;
        } finally {
            $this->currentFieldHandle = null;
        }
    }

    public function getCurrentFieldHandle(): ?string
    {
        return $this->currentFieldHandle;
    }

    /**
     * Strips _type markers added during serialization. Weak models echo them back verbatim.
     */
    private function stripSerializationMarkers(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        unset($value['_type']);

        foreach ($value as $key => $item) {
            $value[$key] = $this->stripSerializationMarkers($item);
        }

        return $value;
    }

    /**
     * Fixes escaped JSON slashes that AI models sometimes produce in HTML content (e.g. <\/p> → </p>).
     */
    private function unescapeJsonSlashes(mixed $value): mixed
    {
        if (is_string($value)) {
            return str_replace('\\/', '/', $value);
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->unescapeJsonSlashes($item);
            }
        }

        return $value;
    }

    /**
     * Checks the entry's field layout first (supports custom layout handles),
     * then falls back to the global fields service.
     */
    public function resolveField(string $fieldHandle, ?Entry $entry): ?FieldInterface
    {
        $field = $entry?->getFieldLayout()?->getFieldByHandle($fieldHandle);

        if ($field !== null) {
            return $field;
        }

        return Craft::$app->getFields()->getFieldByHandle($fieldHandle);
    }
}
