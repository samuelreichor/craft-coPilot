<?php

namespace samuelreichor\coPilot\services;

use craft\base\Component;
use craft\helpers\App;
use samuelreichor\coPilot\CoPilot;
use samuelreichor\coPilot\events\RegisterProvidersEvent;
use samuelreichor\coPilot\providers\AnthropicProvider;
use samuelreichor\coPilot\providers\GeminiProvider;
use samuelreichor\coPilot\providers\OpenAIProvider;
use samuelreichor\coPilot\providers\ProviderInterface;

/**
 * Manages AI provider registration and selection.
 */
class ProviderService extends Component
{
    public const EVENT_REGISTER_PROVIDERS = 'registerProviders';

    /** @var ProviderInterface[]|null */
    private ?array $providers = null;

    public function getActiveProvider(?string $handle = null): ProviderInterface
    {
        $settings = CoPilot::getInstance()->getSettings();
        $providers = $this->getProviders();
        $providerHandle = $handle ?? $settings->defaultProvider;

        return $providers[$providerHandle]
            ?? throw new \RuntimeException("Provider '{$providerHandle}' not found.");
    }

    public function getProvider(string $handle): ?ProviderInterface
    {
        return $this->getProviders()[$handle] ?? null;
    }

    /**
     * Returns providers that have an API key configured.
     *
     * @return array<string, ProviderInterface>
     */
    public function getConfiguredProviders(): array
    {
        $settings = CoPilot::getInstance()->getSettings();
        $envVarMap = [
            'openai' => $settings->openaiApiKeyEnvVar,
            'anthropic' => $settings->anthropicApiKeyEnvVar,
            'gemini' => $settings->geminiApiKeyEnvVar,
        ];

        $configured = [];
        foreach ($this->getProviders() as $handle => $provider) {
            $envVar = $envVarMap[$handle] ?? null;
            if ($envVar && App::parseEnv($envVar)) {
                $configured[$handle] = $provider;
            }
        }

        return $configured;
    }

    /**
     * Returns all registered providers keyed by handle.
     *
     * @return array<string, ProviderInterface>
     */
    public function getProviders(): array
    {
        if ($this->providers !== null) {
            return $this->providers;
        }

        $event = new RegisterProvidersEvent();
        $event->providers = [
            'openai' => new OpenAIProvider(),
            'anthropic' => new AnthropicProvider(),
            'gemini' => new GeminiProvider(),
        ];

        $this->trigger(self::EVENT_REGISTER_PROVIDERS, $event);

        $this->providers = $event->providers;

        return $this->providers;
    }
}
