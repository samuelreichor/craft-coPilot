<?php

namespace samuelreichor\coPilot\providers;

use samuelreichor\coPilot\models\AIResponse;
use samuelreichor\coPilot\models\StreamChunk;

interface ProviderInterface
{
    /**
     * Returns the unique handle for this provider (e.g. 'openai', 'langdock').
     */
    public function getHandle(): string;

    /**
     * Returns the human-readable provider name.
     */
    public function getName(): string;

    /**
     * Returns an inline SVG string for the provider icon.
     */
    public function getIcon(): string;

    /**
     * Returns the resolved API key, or null if not configured.
     */
    public function getApiKey(): ?string;

    /**
     * Returns the currently configured model identifier.
     */
    public function getModel(): string;

    /**
     * Returns available model identifiers for this provider.
     *
     * @return array<int, string>
     */
    public function getAvailableModels(): array;

    /**
     * Returns a fast, cheap model identifier used for lightweight tasks like title generation.
     */
    public function getTitleModel(): string;

    /**
     * Validates that the given API key is functional.
     */
    public function validateApiKey(string $key): bool;

    /**
     * Returns the default configuration for this provider.
     * Used to initialize settings when the provider is first registered.
     *
     * @return array<string, mixed> e.g. ['apiKeyEnvVar' => '', 'model' => 'gpt-5.4']
     */
    public function getDefaultConfig(): array;

    /**
     * Applies stored configuration to this provider instance.
     *
     * @param array<string, mixed> $config
     */
    public function setConfig(array $config): void;

    /**
     * Renders settings HTML for this provider's configuration fields.
     *
     * @param array<string, mixed> $config Current saved config
     * @param array<string, mixed> $fileConfig Config-file overrides (for disabled/warning states)
     */
    public function getSettingsHtml(array $config, array $fileConfig): string;

    /**
     * Converts normalized tool definitions to the provider's expected format.
     *
     * @param array<int, array<string, mixed>> $tools Normalized tool definitions
     * @return array<int, array<string, mixed>>
     */
    public function formatTools(array $tools): array;

    /**
     * Sends messages + tools to the provider and returns the response.
     *
     * @param string $systemPrompt
     * @param array<int, array{role: string, content: string|array}> $messages
     * @param array<int, array<string, mixed>> $tools Normalized tool definitions
     * @param string|null $model Override model selection
     */
    public function chat(
        string $systemPrompt,
        array $messages,
        array $tools,
        ?string $model = null,
    ): AIResponse;

    /**
     * Sends messages + tools to the provider with streaming response.
     *
     * @param string $systemPrompt
     * @param array<int, array{role: string, content: string|array}> $messages
     * @param array<int, array<string, mixed>> $tools Normalized tool definitions
     * @param string|null $model Override model selection
     * @param callable(StreamChunk): void $onChunk Called for each streamed chunk
     */
    public function chatStream(
        string $systemPrompt,
        array $messages,
        array $tools,
        ?string $model,
        callable $onChunk,
    ): void;
}
