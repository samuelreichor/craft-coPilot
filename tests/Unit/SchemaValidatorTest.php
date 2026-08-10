<?php

namespace samuelreichor\coPilot\tests\Unit;

use PHPUnit\Framework\TestCase;
use samuelreichor\coPilot\helpers\SchemaValidator;

class SchemaValidatorTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function entrySchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'entryId' => ['type' => 'integer'],
                'detail' => ['type' => 'string', 'enum' => ['summary', 'full']],
                'fields' => ['type' => 'object'],
                'ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
            ],
            'required' => ['entryId'],
        ];
    }

    public function testValidArgumentsPass(): void
    {
        $result = SchemaValidator::validate(
            ['entryId' => 5, 'detail' => 'full'],
            $this->entrySchema(),
        );

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['errors']);
    }

    public function testMissingRequiredKeyFails(): void
    {
        $result = SchemaValidator::validate(['detail' => 'full'], $this->entrySchema());

        $this->assertFalse($result['valid']);
        $this->assertSame(['`entryId` is required but missing.'], $result['errors']);
    }

    public function testNumericStringIsCoercedToInteger(): void
    {
        $result = SchemaValidator::validate(['entryId' => '42'], $this->entrySchema());

        $this->assertTrue($result['valid']);
        $this->assertSame(42, $result['arguments']['entryId']);
    }

    public function testNonNumericStringForIntegerFails(): void
    {
        $result = SchemaValidator::validate(['entryId' => 'abc'], $this->entrySchema());

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('must be of type integer', $result['errors'][0]);
    }

    public function testInvalidEnumValueFails(): void
    {
        $result = SchemaValidator::validate(
            ['entryId' => 1, 'detail' => 'everything'],
            $this->entrySchema(),
        );

        $this->assertFalse($result['valid']);
        $this->assertSame(['`detail` must be one of: summary, full.'], $result['errors']);
    }

    public function testExplicitNullForOptionalKeyIsAllowed(): void
    {
        $result = SchemaValidator::validate(
            ['entryId' => 1, 'detail' => null],
            $this->entrySchema(),
        );

        $this->assertTrue($result['valid']);
    }

    public function testExplicitNullForRequiredKeyFails(): void
    {
        $result = SchemaValidator::validate(['entryId' => null], $this->entrySchema());

        $this->assertFalse($result['valid']);
    }

    public function testArrayItemsAreValidatedAndCoerced(): void
    {
        $result = SchemaValidator::validate(
            ['entryId' => 1, 'ids' => ['7', 8]],
            $this->entrySchema(),
        );

        $this->assertTrue($result['valid']);
        $this->assertSame([7, 8], $result['arguments']['ids']);
    }

    public function testListPassedAsObjectFails(): void
    {
        $result = SchemaValidator::validate(
            ['entryId' => 1, 'fields' => ['not', 'an', 'object']],
            $this->entrySchema(),
        );

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('`fields` must be of type object', $result['errors'][0]);
    }

    public function testUnknownPropertiesAreAllowed(): void
    {
        $result = SchemaValidator::validate(
            ['entryId' => 1, 'somethingNew' => 'value'],
            $this->entrySchema(),
        );

        $this->assertTrue($result['valid']);
        $this->assertSame('value', $result['arguments']['somethingNew']);
    }

    public function testNestedObjectValidation(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'filter' => [
                    'type' => 'object',
                    'properties' => [
                        'limit' => ['type' => 'integer'],
                    ],
                    'required' => ['limit'],
                ],
            ],
            'required' => [],
        ];

        $result = SchemaValidator::validate(['filter' => ['limit' => 'x']], $schema);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('`filter.limit` must be of type integer', $result['errors'][0]);
    }

    public function testBooleanAndStringCoercion(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'enabled' => ['type' => 'boolean'],
                'slug' => ['type' => 'string'],
            ],
            'required' => [],
        ];

        $result = SchemaValidator::validate(['enabled' => 'true', 'slug' => 42], $schema);

        $this->assertTrue($result['valid']);
        $this->assertTrue($result['arguments']['enabled']);
        $this->assertSame('42', $result['arguments']['slug']);
    }
}
