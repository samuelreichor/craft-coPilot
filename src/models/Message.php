<?php

namespace samuelreichor\coPilot\models;

use samuelreichor\coPilot\enums\MessageRole;

class Message
{
    public MessageRole $role;
    public string|array|null $content;
    public ?string $toolCallId;
    public ?string $toolName;

    /** @var array<int, array<string, mixed>>|null Tool call definitions (on assistant messages that invoke tools) */
    public ?array $toolCalls;

    /** @var array<int, array<string, mixed>>|null Raw model parts (e.g. Gemini thought signatures, must be circulated verbatim) */
    public ?array $rawModelParts;

    /** @var bool|null Whether this tool result is an error */
    public ?bool $isError;

    /**
     * @param array<int, array<string, mixed>>|null $toolCalls
     * @param array<int, array<string, mixed>>|null $rawModelParts
     */
    public function __construct(
        MessageRole $role,
        string|array|null $content,
        ?string $toolCallId = null,
        ?string $toolName = null,
        ?array $toolCalls = null,
        ?array $rawModelParts = null,
        ?bool $isError = null,
    ) {
        $this->role = $role;
        $this->content = $content;
        $this->toolCallId = $toolCallId;
        $this->toolName = $toolName;
        $this->toolCalls = $toolCalls;
        $this->rawModelParts = $rawModelParts;
        $this->isError = $isError;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'role' => $this->role->value,
            'content' => $this->content,
            'toolCallId' => $this->toolCallId,
            'toolName' => $this->toolName,
        ];

        if ($this->toolCalls !== null) {
            $data['toolCalls'] = $this->toolCalls;
        }

        if ($this->rawModelParts !== null) {
            $data['rawModelParts'] = $this->rawModelParts;
        }

        if ($this->isError !== null) {
            $data['isError'] = $this->isError;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['role'])) {
            throw new \InvalidArgumentException('Message data must contain a role.');
        }

        $role = MessageRole::tryFrom($data['role']);
        if ($role === null) {
            throw new \InvalidArgumentException("Invalid message role: {$data['role']}");
        }

        return new self(
            role: $role,
            content: $data['content'] ?? null,
            toolCallId: $data['toolCallId'] ?? null,
            toolName: $data['toolName'] ?? null,
            toolCalls: $data['toolCalls'] ?? null,
            rawModelParts: $data['rawModelParts'] ?? null,
            isError: $data['isError'] ?? null,
        );
    }
}
