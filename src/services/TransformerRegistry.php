<?php

namespace samuelreichor\coPilot\services;

use craft\base\Component;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\fieldlayoutelements\CustomField;
use craft\models\FieldLayout;
use samuelreichor\coPilot\constants\Constants;
use samuelreichor\coPilot\events\RegisterElementTransformersEvent;
use samuelreichor\coPilot\events\RegisterFieldTransformersEvent;
use samuelreichor\coPilot\helpers\PluginHelper;
use samuelreichor\coPilot\transformers\elements\AssetTransformer;
use samuelreichor\coPilot\transformers\elements\ElementTransformerInterface;
use samuelreichor\coPilot\transformers\elements\EntryTransformer;
use samuelreichor\coPilot\transformers\fields\ComplexFieldTransformer;
use samuelreichor\coPilot\transformers\fields\FieldTransformerInterface;
use samuelreichor\coPilot\transformers\fields\LlmifyFieldTransformer;
use samuelreichor\coPilot\transformers\fields\OptionsFieldTransformer;
use samuelreichor\coPilot\transformers\fields\RelationalFieldTransformer;
use samuelreichor\coPilot\transformers\fields\RichTextFieldTransformer;
use samuelreichor\coPilot\transformers\fields\ScalarFieldTransformer;

/**
 * Unified registry for field and element transformers.
 * Resolves the appropriate transformer for a given field or element.
 */
class TransformerRegistry extends Component
{
    public const EVENT_REGISTER_FIELD_TRANSFORMERS = 'registerFieldTransformers';
    public const EVENT_REGISTER_ELEMENT_TRANSFORMERS = 'registerElementTransformers';

    /** @var FieldTransformerInterface[]|null */
    private ?array $fieldTransformers = null;

    /** @var ElementTransformerInterface[]|null */
    private ?array $elementTransformers = null;

    public function getTransformerForField(FieldInterface $field): ?FieldTransformerInterface
    {
        foreach ($this->getFieldTransformers() as $transformer) {
            $customMatch = $transformer->matchesField($field);

            if ($customMatch === true) {
                return $transformer;
            }

            if ($customMatch === false) {
                continue;
            }

            foreach ($transformer->getSupportedFieldClasses() as $className) {
                if ($field instanceof $className) {
                    return $transformer;
                }
            }
        }

        return null;
    }

    /**
     * @return FieldTransformerInterface[]
     */
    public function getFieldTransformers(): array
    {
        if ($this->fieldTransformers !== null) {
            return $this->fieldTransformers;
        }

        $event = new RegisterFieldTransformersEvent();
        $this->trigger(self::EVENT_REGISTER_FIELD_TRANSFORMERS, $event);

        $this->fieldTransformers = array_merge($event->transformers, $this->getBuiltInFieldTransformers());

        return $this->fieldTransformers;
    }

    public function getTransformerForElement(ElementInterface $element): ?ElementTransformerInterface
    {
        foreach ($this->getElementTransformers() as $transformer) {
            foreach ($transformer->getSupportedElementClasses() as $className) {
                if ($element instanceof $className) {
                    return $transformer;
                }
            }
        }

        return null;
    }

    /**
     * @return ElementTransformerInterface[]
     */
    public function getElementTransformers(): array
    {
        if ($this->elementTransformers !== null) {
            return $this->elementTransformers;
        }

        $event = new RegisterElementTransformersEvent();
        $this->trigger(self::EVENT_REGISTER_ELEMENT_TRANSFORMERS, $event);

        $this->elementTransformers = array_merge($event->transformers, $this->getBuiltInElementTransformers());

        return $this->elementTransformers;
    }

    /**
     * Resolves custom fields from a layout using layout handles (custom overrides).
     *
     * @return array<int, array{layoutElement: CustomField, field: FieldInterface, handle: string}>
     */
    public function resolveFieldLayoutFields(FieldLayout $fieldLayout): array
    {
        $resolved = [];

        foreach ($fieldLayout->getCustomFieldElements() as $layoutElement) {
            try {
                $field = $layoutElement->getField();

                if ($this->isExcludedField($field)) {
                    continue;
                }

                $resolved[] = [
                    'layoutElement' => $layoutElement,
                    'field' => $field,
                    'handle' => $layoutElement->attribute(),
                ];
            } catch (\Throwable) {
                continue;
            }
        }

        return $resolved;
    }

    private function isExcludedField(FieldInterface $field): bool
    {
        $fieldClass = get_class($field);

        foreach (Constants::EXCLUDED_FIELD_CLASSES as $excludedClass) {
            if ($fieldClass === $excludedClass || is_subclass_of($field, $excludedClass)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return FieldTransformerInterface[]
     */
    private function getBuiltInFieldTransformers(): array
    {
        $transformers = [];

        if (PluginHelper::isPluginInstalledAndEnabled('llmify')) {
            $transformers[] = new LlmifyFieldTransformer();
        }

        $transformers[] = new ScalarFieldTransformer();
        $transformers[] = new OptionsFieldTransformer();
        $transformers[] = new RichTextFieldTransformer();
        $transformers[] = new RelationalFieldTransformer();
        $transformers[] = new ComplexFieldTransformer();

        return $transformers;
    }

    /**
     * @return ElementTransformerInterface[]
     */
    private function getBuiltInElementTransformers(): array
    {
        return [
            new EntryTransformer(),
            new AssetTransformer(),
        ];
    }
}
