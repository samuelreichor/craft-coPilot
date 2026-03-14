<?php

namespace samuelreichor\coPilot\commands;

/**
 * Interface for slash commands that can be registered via events.
 *
 * Commands provide a predefined prompt that is sent to the AI
 * when triggered from the chat input.
 */
interface CommandInterface
{
    /**
     * Unique command name (used as identifier, e.g. 'proofread').
     */
    public function getName(): string;

    /**
     * Human-readable description shown in the command menu.
     */
    public function getDescription(): string;

    /**
     * The prompt to send to the AI when this command is triggered.
     *
     * Use `{paramName}` placeholders to inject resolved parameter values.
     */
    public function getPrompt(): string;

    /**
     * Optional parameter the command requires before execution.
     *
     * Supported types: 'entry', 'asset', 'file', 'text'.
     * Return null if the command needs no parameter.
     *
     * @return array{type: string, label: string}|null
     */
    public function getParam(): ?array;
}
