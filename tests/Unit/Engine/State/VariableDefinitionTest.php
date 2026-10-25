<?php

declare(strict_types=1);

namespace Storyteller\Tests\Unit\Engine\State;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Storyteller\Engine\Exception\MalformedNodeException;
use Storyteller\Engine\Exception\TypeMismatchException;
use Storyteller\Engine\State\VariableDefinition;
use Storyteller\Engine\State\VariableType;

#[CoversClass(VariableDefinition::class)]
final class VariableDefinitionTest extends TestCase
{
    public function testRejectsADefaultTheTypeDoesNotAccept(): void
    {
        $this->expectException(TypeMismatchException::class);
        $this->expectExceptionMessage('hp');

        new VariableDefinition('hp', VariableType::Int, 1.5);
    }

    /**
     * @param array{type: string, default: int|float|string|bool} $expectedArray
     */
    #[DataProvider('definitionProvider')]
    public function testRoundTripsThroughToArrayAndFromArray(
        VariableDefinition $definition,
        array $expectedArray,
    ): void {
        self::assertSame($expectedArray, $definition->toArray());
        self::assertEquals($definition, VariableDefinition::fromArray($definition->name, $definition->toArray()));
    }

    public function testRejectsAnUnknownTypeName(): void
    {
        $this->expectException(MalformedNodeException::class);
        $this->expectExceptionMessage('decimal');

        VariableDefinition::fromArray('hp', ['type' => 'decimal', 'default' => 1]);
    }

    public function testRejectsAnUnknownKey(): void
    {
        $this->expectException(MalformedNodeException::class);
        $this->expectExceptionMessage('min');

        VariableDefinition::fromArray('hp', ['type' => 'int', 'default' => 1, 'min' => 0]);
    }

    public function testRejectsAMissingDefault(): void
    {
        $this->expectException(MalformedNodeException::class);
        $this->expectExceptionMessage('default');

        VariableDefinition::fromArray('hp', ['type' => 'int']);
    }

    /**
     * @return iterable<string, array{0: VariableDefinition, 1: array{type: string, default: int|float|string|bool}}>
     */
    public static function definitionProvider(): iterable
    {
        yield 'int' => [
            new VariableDefinition('hp', VariableType::Int, 100),
            ['type' => 'int', 'default' => 100],
        ];

        yield 'float' => [
            new VariableDefinition('ratio', VariableType::Float, 0.5),
            ['type' => 'float', 'default' => 0.5],
        ];

        yield 'string' => [
            new VariableDefinition('name', VariableType::String, ''),
            ['type' => 'string', 'default' => ''],
        ];

        yield 'bool' => [
            new VariableDefinition('alive', VariableType::Bool, true),
            ['type' => 'bool', 'default' => true],
        ];
    }
}
