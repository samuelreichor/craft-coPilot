<?php

namespace samuelreichor\coPilot\transformers\elements;

use craft\base\ElementInterface;
use craft\elements\Entry;
use samuelreichor\coPilot\CoPilot;
use samuelreichor\coPilot\events\SerializeEntryEvent;
use samuelreichor\coPilot\services\ContextService;
use samuelreichor\coPilot\transformers\SerializeFallbackTrait;

/**
 * Handles serialization of Entry elements for AI context.
 */
class EntryTransformer implements ElementTransformerInterface
{
    use SerializeFallbackTrait;
    public function getSupportedElementClasses(): array
    {
        return [
            Entry::class,
        ];
    }

    public function serializeElement(ElementInterface $element, int $depth = 2, ?array $fieldHandles = null): ?array
    {
        if (!$element instanceof Entry) {
            return null;
        }

        $contextService = CoPilot::getInstance()->contextService;

        $event = new SerializeEntryEvent();
        $event->entry = $element;
        $event->fields = $fieldHandles ?? $this->getFieldHandles($element);
        $contextService->trigger(ContextService::EVENT_BEFORE_SERIALIZE_ENTRY, $event);

        if ($event->cancel) {
            return null;
        }

        $data = [
            'id' => $element->id,
            'title' => $element->title ?: $element->getSection()?->name,
            'slug' => $element->slug,
            'section' => $element->getSection()?->handle,
            'type' => $element->getType()->handle,
            'status' => $element->getStatus(),
            'dateCreated' => $element->dateCreated?->format('c'),
            'dateUpdated' => $element->dateUpdated?->format('c'),
            'url' => $element->url,
            'fields' => [],
        ];

        $author = $element->getAuthor();
        if ($author) {
            $data['author'] = $author->fullName ?? $author->username;
        }

        $fieldLayout = $element->getFieldLayout();
        if (!$fieldLayout) {
            return $data;
        }

        $registry = CoPilot::getInstance()->transformerRegistry;

        foreach ($registry->resolveFieldLayoutFields($fieldLayout) as $resolved) {
            $handle = $resolved['handle'];
            $field = $resolved['field'];

            if (!in_array($handle, $event->fields, true)) {
                continue;
            }

            $value = $element->getFieldValue($handle);
            $transformer = $registry->getTransformerForField($field);

            if ($transformer !== null) {
                $data['fields'][$handle] = $transformer->serializeValue($field, $value, $depth);
            } else {
                $data['fields'][$handle] = $this->serializeFallback($value);
            }
        }

        return $data;
    }

    public function getElementTypeLabel(): string
    {
        return 'Entry';
    }

    /**
     * @return string[]
     */
    private function getFieldHandles(Entry $entry): array
    {
        $fieldLayout = $entry->getFieldLayout();
        if (!$fieldLayout) {
            return [];
        }

        $registry = CoPilot::getInstance()->transformerRegistry;

        return array_map(
            fn($resolved) => $resolved['handle'],
            $registry->resolveFieldLayoutFields($fieldLayout),
        );
    }
}
