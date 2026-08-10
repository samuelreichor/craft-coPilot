<?php

namespace samuelreichor\coPilot\helpers;

/**
 * Validates tool-call arguments against the JSON Schema subset used by tool
 * parameter definitions (type, properties, required, enum, items).
 *
 * Model-emitted arguments are untrusted: keys can be missing and scalar types
 * can be wrong (e.g. "5" instead of 5). Scalars are coerced where the intent
 * is unambiguous; everything else produces an error message the model can act
 * on. Unknown properties are allowed so harness-injected keys and forward
 * compatibility are not affected.
 */
final class SchemaValidator
{
    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $schema
     * @return array{valid: bool, arguments: array<string, mixed>, errors: array<int, string>}
     */
    public static function validate(array $arguments, array $schema): array
    {
        $errors = [];
        $validated = self::validateValue($arguments, $schema, '', $errors);

        return [
            'valid' => $errors === [],
            'arguments' => is_array($validated) ? $validated : $arguments,
            'errors' => $errors,
        ];
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<int, string> $errors
     */
    private static function validateValue(mixed $value, array $schema, string $path, array &$errors): mixed
    {
        $label = $path === '' ? 'arguments' : $path;
        $type = $schema['type'] ?? null;

        if (is_string($type)) {
            $value = self::coerce($value, $type);

            if (!self::matchesType($value, $type)) {
                $errors[] = "`{$label}` must be of type {$type}, got " . get_debug_type($value) . '.';

                return $value;
            }
        }

        if (isset($schema['enum']) && is_array($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            $errors[] = "`{$label}` must be one of: " . implode(', ', array_map(strval(...), $schema['enum'])) . '.';

            return $value;
        }

        if ($type === 'object' && is_array($value)) {
            foreach ($schema['required'] ?? [] as $requiredKey) {
                if (!array_key_exists($requiredKey, $value)) {
                    $keyLabel = $path === '' ? $requiredKey : "{$path}.{$requiredKey}";
                    $errors[] = "`{$keyLabel}` is required but missing.";
                }
            }

            if (isset($schema['properties']) && is_array($schema['properties'])) {
                $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];

                foreach ($schema['properties'] as $key => $propertySchema) {
                    if (!is_array($propertySchema) || !array_key_exists($key, $value)) {
                        continue;
                    }

                    // Models often send optional parameters as explicit null —
                    // treat that like an omitted key instead of a type error.
                    if ($value[$key] === null && !in_array($key, $required, true)) {
                        continue;
                    }

                    $childPath = $path === '' ? (string)$key : "{$path}.{$key}";
                    $value[$key] = self::validateValue($value[$key], $propertySchema, $childPath, $errors);
                }
            }
        }

        if ($type === 'array' && is_array($value) && isset($schema['items']) && is_array($schema['items'])) {
            foreach ($value as $index => $item) {
                $value[$index] = self::validateValue($item, $schema['items'], "{$label}[{$index}]", $errors);
            }
        }

        return $value;
    }

    /**
     * Coerces scalar values whose intended type is unambiguous.
     */
    private static function coerce(mixed $value, string $type): mixed
    {
        if ($type === 'integer') {
            if (is_string($value) && preg_match('/^-?\d+$/', trim($value))) {
                return (int)trim($value);
            }
            if (is_float($value) && floor($value) === $value) {
                return (int)$value;
            }
        }

        if ($type === 'number' && is_string($value) && is_numeric(trim($value))) {
            return trim($value) + 0;
        }

        if ($type === 'boolean') {
            if ($value === 'true' || $value === 1 || $value === '1') {
                return true;
            }
            if ($value === 'false' || $value === 0 || $value === '0') {
                return false;
            }
        }

        if ($type === 'string' && (is_int($value) || is_float($value))) {
            return (string)$value;
        }

        return $value;
    }

    private static function matchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'object' => is_array($value) && (!array_is_list($value) || $value === []),
            'array' => is_array($value) && array_is_list($value),
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            default => true,
        };
    }
}
