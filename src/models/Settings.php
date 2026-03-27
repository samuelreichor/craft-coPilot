<?php

namespace samuelreichor\coPilot\models;

use craft\base\Model;
use samuelreichor\coPilot\enums\AgentExecutionMode;
use samuelreichor\coPilot\enums\ElementCreationBehavior;
use samuelreichor\coPilot\enums\ElementUpdateBehavior;
use samuelreichor\coPilot\enums\SectionAccess;

/**
 * Plugin settings model.
 */
class Settings extends Model
{
    // Provider settings
    public string $defaultProvider = 'openai';

    /**
     * Generic provider configuration, keyed by provider handle.
     * Each entry contains the provider's own config (e.g. apiKeyEnvVar, model).
     *
     * @var array<string, array<string, mixed>>
     */
    public array $providerSettings = [];

    /**
     * Section access configuration.
     * Maps section UIDs to SectionAccess enum values.
     *
     * @var array<string, string>
     */
    public array $sectionAccess = [];

    /**
     * Volume access configuration.
     * Maps volume UIDs to SectionAccess enum values (reused for blocked/readOnly/readWrite).
     *
     * @var array<string, string>
     */
    public array $volumeAccess = [];

    /**
     * Category group access configuration.
     * Maps category group UIDs to SectionAccess enum values.
     *
     * @var array<string, string>
     */
    public array $categoryGroupAccess = [];

    /**
     * Element type blocklist.
     * List of blocked element type classes.
     *
     * @var array<int, string>
     */
    public array $blockedElementTypes = [
        'craft\commerce\elements\Order',
    ];

    // Brand voice settings
    public string $brandVoice = '';
    public string $glossary = '';
    public string $forbiddenWords = '';

    /**
     * Language-specific instructions.
     *
     * @var array<string, string>
     */
    public array $languageInstructions = [];

    // Web Search
    public bool $webSearchEnabled = false;

    // Debugging
    public bool $debug = false;

    // Agent behavior
    public string $agentExecutionMode = 'supervised';
    public int $maxAgentIterations = 20;
    public int $defaultSerializationDepth = 3;
    public int $maxSerializationDepth = 4;
    public int $maxContextTokens = 100000;
    public int $defaultSearchLimit = 20;

    // Element persistence behavior
    public string $elementUpdateBehavior = 'provisionalDraft';
    public string $elementCreationBehavior = 'draft';

    // Appearance
    public string $pluginName = 'CoPilot';

    // Data retention
    public int $auditLogRetentionDays = 30;

    public function defineRules(): array
    {
        return [
            [['defaultProvider', 'pluginName'], 'required'],
            ['pluginName', 'string', 'max' => 50],
            ['defaultProvider', 'string'],
            ['maxAgentIterations', 'integer', 'min' => 1, 'max' => 50],
            ['defaultSerializationDepth', 'integer', 'min' => 1, 'max' => 10],
            ['maxSerializationDepth', 'integer', 'min' => 1, 'max' => 10],
            ['maxContextTokens', 'integer', 'min' => 1000, 'max' => 128000],
            ['defaultSearchLimit', 'integer', 'min' => 1, 'max' => 100],
            ['auditLogRetentionDays', 'integer', 'min' => 1, 'max' => 365],
            [['webSearchEnabled', 'debug'], 'boolean'],
            ['agentExecutionMode', 'in', 'range' => array_column(AgentExecutionMode::cases(), 'value')],
            ['elementUpdateBehavior', 'in', 'range' => array_column(ElementUpdateBehavior::cases(), 'value')],
            ['elementCreationBehavior', 'in', 'range' => array_column(ElementCreationBehavior::cases(), 'value')],
        ];
    }

    /**
     * Returns the configuration for a specific provider.
     *
     * @return array<string, mixed>
     */
    public function getProviderConfig(string $handle): array
    {
        return $this->providerSettings[$handle] ?? [];
    }

    /**
     * Returns the access level for a given section UID.
     */
    public function getSectionAccessLevel(string $sectionUid): SectionAccess
    {
        $value = $this->sectionAccess[$sectionUid] ?? SectionAccess::ReadWrite->value;

        return SectionAccess::tryFrom($value) ?? SectionAccess::ReadWrite;
    }

    /**
     * Returns the access level for a given volume UID.
     */
    public function getVolumeAccessLevel(string $volumeUid): SectionAccess
    {
        $value = $this->volumeAccess[$volumeUid] ?? SectionAccess::ReadWrite->value;

        return SectionAccess::tryFrom($value) ?? SectionAccess::ReadWrite;
    }

    /**
     * Returns the access level for a given category group UID.
     */
    public function getCategoryGroupAccessLevel(string $groupUid): SectionAccess
    {
        $value = $this->categoryGroupAccess[$groupUid] ?? SectionAccess::ReadWrite->value;

        return SectionAccess::tryFrom($value) ?? SectionAccess::ReadWrite;
    }

    /**
     * Checks if an element type class is blocked.
     */
    public function isElementTypeBlocked(string $elementTypeClass): bool
    {
        return in_array($elementTypeClass, $this->blockedElementTypes, true);
    }
}
