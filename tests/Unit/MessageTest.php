<?php

namespace samuelreichor\coPilot\tests\Unit;

use PHPUnit\Framework\TestCase;
use samuelreichor\coPilot\enums\MessageRole;
use samuelreichor\coPilot\models\Message;

class MessageTest extends TestCase
{
    public function testCreateUserMessage(): void
    {
        $message = new Message(
            role: MessageRole::User,
            content: 'Hello',
        );

        $this->assertSame(MessageRole::User, $message->role);
        $this->assertSame('Hello', $message->content);
        $this->assertNull($message->toolCallId);
        $this->assertNull($message->toolName);
    }

    public function testCreateToolMessage(): void
    {
        $message = new Message(
            role: MessageRole::Tool,
            content: ['result' => 'data'],
            toolCallId: 'tc_123',
            toolName: 'readEntry',
        );

        $this->assertSame(MessageRole::Tool, $message->role);
        $this->assertSame(['result' => 'data'], $message->content);
        $this->assertSame('tc_123', $message->toolCallId);
        $this->assertSame('readEntry', $message->toolName);
    }

    public function testToArray(): void
    {
        $message = new Message(
            role: MessageRole::Assistant,
            content: 'AI response',
        );

        $array = $message->toArray();

        $this->assertSame('assistant', $array['role']);
        $this->assertSame('AI response', $array['content']);
        $this->assertNull($array['toolCallId']);
        $this->assertNull($array['toolName']);
    }

    public function testFromArray(): void
    {
        $data = [
            'role' => 'user',
            'content' => 'Test message',
            'toolCallId' => null,
            'toolName' => null,
        ];

        $message = Message::fromArray($data);

        $this->assertSame(MessageRole::User, $message->role);
        $this->assertSame('Test message', $message->content);
    }

    public function testFromArrayWithToolData(): void
    {
        $data = [
            'role' => 'tool',
            'content' => '{"id": 1}',
            'toolCallId' => 'tc_456',
            'toolName' => 'searchEntries',
        ];

        $message = Message::fromArray($data);

        $this->assertSame(MessageRole::Tool, $message->role);
        $this->assertSame('tc_456', $message->toolCallId);
        $this->assertSame('searchEntries', $message->toolName);
    }

    public function testFromArrayThrowsOnMissingRole(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Message data must contain a role.');

        Message::fromArray(['content' => 'no role']);
    }

    public function testFromArrayAllowsMissingContent(): void
    {
        $message = Message::fromArray(['role' => 'user']);

        $this->assertNull($message->content);
    }

    public function testFromArrayThrowsOnInvalidRole(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid message role: invalid');

        Message::fromArray(['role' => 'invalid', 'content' => 'test']);
    }

    public function testRoundTrip(): void
    {
        $original = new Message(
            role: MessageRole::Assistant,
            content: 'Round-trip test',
            toolCallId: null,
            toolName: null,
        );

        $restored = Message::fromArray($original->toArray());

        $this->assertSame($original->role, $restored->role);
        $this->assertSame($original->content, $restored->content);
        $this->assertSame($original->toolCallId, $restored->toolCallId);
    }
}
