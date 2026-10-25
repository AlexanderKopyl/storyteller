<?php

declare(strict_types=1);

namespace Storyteller\Tests\Unit\Engine\State;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Storyteller\Engine\Exception\MalformedNodeException;
use Storyteller\Engine\Exception\TypeMismatchException;
use Storyteller\Engine\Exception\UnknownVariableException;
use Storyteller\Engine\State\GameState;
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

    public function testResolveReturnsTheStateValueWhenPresent(): void
    {
        $state = GameState::initial()->withVar('hp', 42);

        self::assertSame(42, self::schema()->resolve('hp', $state));
    }

    public function testResolveReturnsTheSchemaDefaultWhenTheStateHasNoValue(): void
    {
        self::assertSame(
            100,
            self::schema()->resolve('hp', GameState::initial()),
            'Resolution never yields null; the schema default stands in.',
        );
    }

    public function testResolveThrowsForANameNotInTheSchemaEvenWhenTheStateCarriesIt(): void
    {
        $state = GameState::initial()->withVar('mana', 5);

        $this->expectException(UnknownVariableException::class);
        $this->expectExceptionMessage('mana');

        self::schema()->resolve('mana', $state);
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

    /**
     * B1: `json_encode(1.0)` is the string `"1"` and `json_decode("1")` is an
     * `int`, so a schema whose declared default is an integral float only
     * survives the `story_version` snapshot if the Engine widens it back.
     */
    public function testASchemaWhoseDefaultIsAnIntegralFloatSurvivesAJsonRoundTrip(): void
    {
        $schema = VariableSchema::of([new VariableDefinition('ratio', VariableType::Float, 1.0)]);

        $json = json_encode($schema->toArray(), JSON_THROW_ON_ERROR);

        self::assertSame('{"ratio":{"type":"float","default":1}}', $json);

        // No `assertIsArray()` here: the encoded literal is precise enough
        // that static analysis already proves the decoded shape, and asserting
        // it again would be an assertion that can never run.
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $reloaded = VariableSchema::fromArray($decoded);

        self::assertSame(1.0, $reloaded->defaultOf('ratio'));
        self::assertEquals($schema, $reloaded);
    }

    public function testCanonicaliseWidensAnIntForAFloatDeclaredVariableOnly(): void
    {
        $schema = self::schema();

        self::assertSame(1.0, $schema->canonicalise('ratio', 1));
        self::assertSame(2.5, $schema->canonicalise('ratio', 2.5));
        self::assertSame(7, $schema->canonicalise('hp', 7));
        self::assertSame('ash', $schema->canonicalise('name', 'ash'));
    }

    public function testCanonicaliseRejectsAnUndeclaredName(): void
    {
        $this->expectException(UnknownVariableException::class);

        self::schema()->canonicalise('mana', 1);
    }

    /**
     * B1 again, on the read side: a state that came back from JSONB carries a
     * PHP `int` under a float-declared name, and `resolve()` is the one place
     * every operator reads a variable, so widening there is what keeps `===`
     * meaningful.
     */
    public function testResolveWidensAnIntCarriedByTheStateForAFloatDeclaredVariable(): void
    {
        $schema = self::schema();

        self::assertSame(1.0, $schema->resolve('ratio', GameState::create(['ratio' => 1], [], [], [], [])));
        self::assertSame(0.5, $schema->resolve('ratio', GameState::create(['ratio' => 0.5], [], [], [], [])));
        self::assertSame(0.5, $schema->resolve('ratio', GameState::initial()));
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
