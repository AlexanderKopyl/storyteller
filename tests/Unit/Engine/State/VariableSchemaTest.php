<?php

declare(strict_types=1);

namespace Storyteller\Tests\Unit\Engine\State;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Storyteller\Engine\Exception\MalformedNodeException;
use Storyteller\Engine\Exception\TypeMismatchException;
use Storyteller\Engine\Exception\UnknownVariableException;
use Storyteller\Engine\State\VariableDefinition;
use Storyteller\Engine\State\VariableSchema;
use Storyteller\Engine\State\VariableType;

/**
 * The schema is authoritative for everything about a variable: whether it
 * exists, what type it has and what it defaults to.
 */
#[CoversClass(VariableSchema::class)]
final class VariableSchemaTest extends TestCase
{
    public function testOfKeySortsDefinitions(): void
    {
        $schema = VariableSchema::of([
            new VariableDefinition('hp', VariableType::Int, 100),
            new VariableDefinition('gold', VariableType::Int, 0),
            new VariableDefinition('Name', VariableType::String, ''),
        ]);

        self::assertSame(['Name', 'gold', 'hp'], $schema->names());
        self::assertSame(['Name', 'gold', 'hp'], array_keys($schema->toArray()));
    }

    public function testOfRejectsDuplicateNames(): void
    {
        $this->expectException(MalformedNodeException::class);
        $this->expectExceptionMessage('hp');

        VariableSchema::of([
            new VariableDefinition('hp', VariableType::Int, 100),
            new VariableDefinition('hp', VariableType::Int, 50),
        ]);
    }

    public function testHasReportsMembership(): void
    {
        $schema = self::schema();

        self::assertTrue($schema->has('hp'));
        self::assertFalse($schema->has('mana'));
    }

    public function testTypeOfAndDefaultOfReturnTheDeclaration(): void
    {
        $schema = self::schema();

        self::assertSame(VariableType::Int, $schema->typeOf('hp'));
        self::assertSame(100, $schema->defaultOf('hp'));
        self::assertSame(VariableType::String, $schema->typeOf('name'));
        self::assertSame('ash', $schema->defaultOf('name'));
    }

    public function testTypeOfThrowsForAnUnknownVariable(): void
    {
        $this->expectException(UnknownVariableException::class);
        $this->expectExceptionMessage('mana');

        self::schema()->typeOf('mana');
    }

    public function testDefaultOfThrowsForAnUnknownVariable(): void
    {
        $this->expectException(UnknownVariableException::class);
        $this->expectExceptionMessage('mana');

        self::schema()->defaultOf('mana');
    }

    public function testAssertAcceptsPassesForAValueOfTheDeclaredType(): void
    {
        $schema = self::schema();

        $schema->assertAccepts('hp', 7);

        self::assertTrue($schema->has('hp'));
    }

    public function testAssertAcceptsThrowsTypeMismatchForAWrongTypedValue(): void
    {
        $this->expectException(TypeMismatchException::class);
        $this->expectExceptionMessage('hp');

        self::schema()->assertAccepts('hp', '7');
    }

    public function testAssertAcceptsThrowsForAnUnknownVariable(): void
    {
        $this->expectException(UnknownVariableException::class);

        self::schema()->assertAccepts('mana', 7);
    }

    public function testEmptySchemaHasNoNames(): void
    {
        $schema = VariableSchema::empty();

        self::assertSame([], $schema->names());
        self::assertSame([], $schema->toArray());
        self::assertFalse($schema->has('hp'));
    }

    public function testRoundTripsThroughToArray(): void
    {
        $schema = self::schema();
        $array = $schema->toArray();

        self::assertSame(
            [
                'hp' => ['type' => 'int', 'default' => 100],
                'name' => ['type' => 'string', 'default' => 'ash'],
                'ratio' => ['type' => 'float', 'default' => 0.5],
            ],
            $array,
        );
        self::assertEquals($schema, VariableSchema::fromArray($array));
    }

    public function testFromArrayRejectsANonObjectDefinition(): void
    {
        $this->expectException(MalformedNodeException::class);
        $this->expectExceptionMessage('hp');

        VariableSchema::fromArray(['hp' => 'int']);
    }

    private static function schema(): VariableSchema
    {
        return VariableSchema::of([
            new VariableDefinition('hp', VariableType::Int, 100),
            new VariableDefinition('ratio', VariableType::Float, 0.5),
            new VariableDefinition('name', VariableType::String, 'ash'),
        ]);
    }
}
