<?php

namespace samuelreichor\coPilot\tests\Unit;

use PHPUnit\Framework\TestCase;
use samuelreichor\coPilot\providers\ToolFormatter;

class ToolFormatterTest extends TestCase
{
    private function getSampleTools(): array
    {
        return [
            [
                'name' => 'readEntry',
                'description' => 'Reads an entry',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'entryId' => ['type' => 'integer'],
                    ],
                    'required' => ['entryId'],
                ],
            ],
            [
                'name' => 'listSections',
                'description' => 'Lists sections',
                'parameters' => [
                    'type' => 'object',
                    'properties' => new \stdClass(),
                ],
            ],
        ];
    }

    public function testForOpenAIFormat(): void
    {
        $tools = $this->getSampleTools();
        $formatted = ToolFormatter::forOpenAI($tools);

        $this->assertCount(2, $formatted);

        // Check OpenAI Responses API format (flat structure)
        $first = $formatted[0];
        $this->assertSame('function', $first['type']);
        $this->assertSame('readEntry', $first['name']);
        $this->assertSame('Reads an entry', $first['description']);
        $this->assertArrayHasKey('parameters', $first);
    }

    public function testForAnthropicFormat(): void
    {
        $tools = $this->getSampleTools();
        $formatted = ToolFormatter::forAnthropic($tools);

        $this->assertCount(2, $formatted);

        // Check Anthropic tool use format
        $first = $formatted[0];
        $this->assertSame('readEntry', $first['name']);
        $this->assertSame('Reads an entry', $first['description']);
        $this->assertArrayHasKey('input_schema', $first);
        // Anthropic format should NOT have 'type' => 'function'
        $this->assertArrayNotHasKey('type', $first);
    }

    public function testForGeminiFormat(): void
    {
        $tools = $this->getSampleTools();
        $formatted = ToolFormatter::forGemini($tools);

        $this->assertCount(1, $formatted);
        $this->assertArrayHasKey('functionDeclarations', $formatted[0]);
        $this->assertCount(2, $formatted[0]['functionDeclarations']);

        $first = $formatted[0]['functionDeclarations'][0];
        $this->assertSame('readEntry', $first['name']);
        $this->assertSame('Reads an entry', $first['description']);
        $this->assertArrayHasKey('parameters', $first);
    }

    public function testEmptyToolsList(): void
    {
        $this->assertSame([], ToolFormatter::forOpenAI([]));
        $this->assertSame([], ToolFormatter::forAnthropic([]));
        $this->assertSame([], ToolFormatter::forGemini([]));
    }

    public function testParametersArePassedThrough(): void
    {
        $tools = $this->getSampleTools();

        $openai = ToolFormatter::forOpenAI($tools);
        $anthropic = ToolFormatter::forAnthropic($tools);

        // Both formats should preserve the parameters/schema
        $this->assertSame(
            $tools[0]['parameters'],
            $openai[0]['parameters'],
        );
        $this->assertSame(
            $tools[0]['parameters'],
            $anthropic[0]['input_schema'],
        );
    }
}
