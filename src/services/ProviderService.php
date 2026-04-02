<?php

namespace samuelreichor\coPilot\services;

use craft\base\Component;
use InvalidArgumentException;
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
        $providerHandle = $handle ?? $settings->defaultProvider;

        if ($providerHandle !== '' && $providerHandle !== null) {
            $providers = $this->getProviders();
            if (isset($providers[$providerHandle])) {
                return $providers[$providerHandle];
            }
        }

        // Fall back to the first configured provider
        $configured = $this->getConfiguredProviders();
        if ($configured !== []) {
            return reset($configured);
        }

        throw new \RuntimeException('No AI provider with a valid API key is configured.');
    }

    public function getProvider(string $handle): ?ProviderInterface
    {
        return $this->getProviders()[$handle] ?? null;
    }

    /**
     * Returns providers that have an API key configured and available models.
     *
     * @return array<string, ProviderInterface>
     */
    public function getConfiguredProviders(): array
    {
        $configured = [];
        foreach ($this->getProviders() as $handle => $provider) {
            if ($provider->getApiKey() !== null && $provider->getAvailableModels() !== []) {
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

        // Apply stored configuration to each provider
        $settings = CoPilot::getInstance()->getSettings();
        foreach ($event->providers as $handle => $provider) {
            if (!$provider instanceof ProviderInterface) {
                throw new InvalidArgumentException(
                    "Provider '{$handle}' must implement " . ProviderInterface::class
                );
            }
            $config = $settings->getProviderConfig($handle);
            if (!empty($config)) {
                $provider->setConfig($config);
            }
        }

        $this->providers = $event->providers;

        return $this->providers;
    }
}
