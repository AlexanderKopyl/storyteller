<?php

declare(strict_types=1);

namespace Storyteller\Tests\Unit\Engine\State;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Storyteller\Engine\Exception\MalformedNodeException;
use Storyteller\Engine\Exception\UnknownVariableException;
use Storyteller\Engine\State\GameState;
use Storyteller\Engine\State\VariableDefinition;
use Storyteller\Engine\State\VariableSchema;
use Storyteller\Engine\State\VariableType;

/**
 * The state is immutable and canonically ordered, because it is the value that
 * will be persisted as JSONB and compared across runs: two states reached by
 * different but equivalent routes must serialize to the same bytes.
 */
#[CoversClass(GameState::class)]
final class GameStateTest extends TestCase
{
    public function testInitialIsEmptyAndDoesNotMarkAnyScene(): void
    {
        $state = GameState::initial();

        self::assertSame(
            [
                'v' => 1,
                'vars' => [],
                'flags' => [],
                'inventory' => [],
                'visited' => [],
                'history' => [],
            ],
            $state->toArray(),
        );
        self::assertSame(0, $state->visitCount('start'));
    }

    public function testVarsInventoryAndVisitedAreKeySorted(): void
    {
        $state = GameState::initial()
            ->withVar('hp', 10)
            ->withVar('gold', 2)
            ->withVar('agility', 3)
            ->withItem('torch', 1)
            ->withItem('rope', 2)
            ->withVisit('zoo')
            ->withVisit('attic');

        self::assertSame(['agility', 'gold', 'hp'], array_keys($state->toArray()['vars']));
        self::assertSame(['rope', 'torch'], array_keys($state->toArray()['inventory']));
        self::assertSame(['attic', 'zoo'], array_keys($state->toArray()['visited']));
    }

    public function testFlagsAreSortedAndDeduplicated(): void
    {
        $state = GameState::initial()->withFlag('b')->withFlag('a')->withFlag('b');

        self::assertSame(['a', 'b'], $state->toArray()['flags']);
        self::assertTrue($state->hasFlag('a'));
        self::assertFalse($state->hasFlag('c'));
        self::assertSame([], $state->withoutFlag('a')->withoutFlag('b')->toArray()['flags']);
    }

    public function testHistoryPreservesInsertionOrderAndIsUnbounded(): void
    {
        $state = GameState::initial();
        $expected = [];

        for ($i = 0; $i < 50; $i++) {
            $scene = $i % 2 === 0 ? 'hub' : 'room' . $i;
            $expected[] = $scene;
            $state = $state->withVisit($scene);
        }

        self::assertSame($expected, $state->toArray()['history']);
        self::assertCount(50, $state->toArray()['history']);
        self::assertSame(25, $state->visitCount('hub'));
    }

    public function testWithVisitIncrementsVisitedAndAppendsHistoryTogether(): void
    {
        $state = GameState::initial()
            ->withVisit('cave')
            ->withVisit('crossroads')
            ->withVisit('cave')
            ->withVisit('cave');

        $history = $state->toArray()['history'];

        self::assertSame(['cave', 'crossroads', 'cave', 'cave'], $history);

        foreach (['cave', 'crossroads'] as $scene) {
            self::assertSame(
                count(array_filter($history, static fn (string $entry): bool => $entry === $scene)),
                $state->visitCount($scene),
                sprintf('visited and history disagree about "%s".', $scene),
            );
        }
    }

    /**
     * @param Closure(GameState): GameState $mutate
     */
    #[DataProvider('withMethodProvider')]
    public function testEveryWithMethodReturnsANewInstanceAndLeavesTheReceiverUnchanged(Closure $mutate): void
    {
        $original = GameState::initial()
            ->withVar('hp', 100)
            ->withFlag('brave')
            ->withItem('rope', 1)
            ->withVisit('cave');
        $before = $original->toArray();

        $changed = $mutate($original);

        self::assertNotSame($original, $changed);
        self::assertSame($before, $original->toArray(), 'The receiver was mutated.');
        self::assertNotSame($before, $changed->toArray());
    }

    public function testWithItemRemovesTheKeyAtZeroOrBelow(): void
    {
        $state = GameState::initial()->withItem('rope', 2);

        self::assertSame(2, $state->itemQty('rope'));
        self::assertArrayNotHasKey('rope', $state->withItem('rope', 0)->toArray()['inventory']);
        self::assertArrayNotHasKey('rope', $state->withItem('rope', -5)->toArray()['inventory']);
        self::assertSame(0, $state->withItem('rope', 0)->itemQty('rope'));
        self::assertArrayNotHasKey('torch', $state->withItem('torch', 0)->toArray()['inventory']);
    }

    public function testVarAccessors(): void
    {
        $state = GameState::initial()->withVar('hp', 95);

        self::assertTrue($state->hasVar('hp'));
        self::assertFalse($state->hasVar('gold'));
        self::assertSame(95, $state->var('hp'));
    }

    public function testVarThrowsForAnAbsentVariable(): void
    {
        $this->expectException(UnknownVariableException::class);
        $this->expectExceptionMessage('gold');

        GameState::initial()->var('gold');
    }

    public function testToArrayCarriesVersionOne(): void
    {
        self::assertSame(1, GameState::VERSION);
        self::assertSame(
            ['v', 'vars', 'flags', 'inventory', 'visited', 'history'],
            array_keys(GameState::initial()->toArray()),
        );
        self::assertSame(1, GameState::initial()->toArray()['v']);
    }

    public function testFromArrayRejectsAnUnknownVersion(): void
    {
        $this->expectException(MalformedNodeException::class);
        $this->expectExceptionMessage('version');

        GameState::fromArray(['v' => 2] + self::fullArray());
    }

    #[DataProvider('stateKeyProvider')]
    public function testFromArrayRejectsAMissingKey(string $key): void
    {
        $data = self::fullArray();

        unset($data[$key]);

        $this->expectException(MalformedNodeException::class);
        $this->expectExceptionMessage($key);

        GameState::fromArray($data);
    }

    public function testFromArrayRejectsAnUnknownKey(): void
    {
        $this->expectException(MalformedNodeException::class);
        $this->expectExceptionMessage('score');

        GameState::fromArray(self::fullArray() + ['score' => 1]);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    #[DataProvider('roundTripProvider')]
    public function testRoundTripsForAFullStateAnEmptyStateAndAFloatVariable(array $data): void
    {
        $state = GameState::fromArray($data);

        self::assertSame($data, $state->toArray());
        self::assertEquals($state, GameState::fromArray($state->toArray()));
    }

    public function testRoundTripsAnItemKeyThatLooksLikeAnInteger(): void
    {
        $state = GameState::initial()->withItem('10', 3)->withItem('rope', 1);

        self::assertSame(3, $state->itemQty('10'));

        $json = json_encode($state->toArray(), JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertSame($state->toArray(), GameState::fromArray($decoded)->toArray());
    }

    /**
     * B1: the full `play_session` cycle. `json_encode(1.0)` writes `1`, so the
     * reloaded state carries a PHP `int` under `ratio`. The envelope must come
     * back equal, re-encode to identical bytes, and — read through the schema,
     * which is the only way the Engine ever reads a variable — hand back a
     * real `float` again.
     */
    public function testAFullStateWithAnIntegralFloatSurvivesAJsonEncodeDecodeCycle(): void
    {
        $schema = VariableSchema::of([new VariableDefinition('ratio', VariableType::Float, 0.0)]);

        $state = GameState::initial()
            ->withVar('ratio', 1.0)
            ->withFlag('brave')
            ->withItem('rope', 2)
            ->withVisit('cave');

        $json = json_encode($state->toArray(), JSON_THROW_ON_ERROR);

        self::assertStringContainsString('"ratio":1', $json);

        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);

        $reloaded = GameState::fromArray($decoded);

        self::assertEquals($state, $reloaded);
        self::assertSame($json, json_encode($reloaded->toArray(), JSON_THROW_ON_ERROR));
        self::assertSame(1.0, $schema->resolve('ratio', $reloaded));
        self::assertSame(
            $schema->resolve('ratio', $state),
            $schema->resolve('ratio', $reloaded),
        );
    }

    public function testStateIsIndependentOfTheOrderWithMethodsAreApplied(): void
    {
        $a = GameState::initial()
            ->withVar('hp', 95)
            ->withFlag('wise')
            ->withItem('rope', 1)
            ->withVar('gold', 7)
            ->withFlag('brave');

        $b = GameState::initial()
            ->withFlag('brave')
            ->withVar('gold', 7)
            ->withItem('rope', 1)
            ->withFlag('wise')
            ->withVar('hp', 95);

        self::assertNotSame($a, $b);
        self::assertSame($a->toArray(), $b->toArray());
        self::assertSame(
            json_encode($a->toArray(), JSON_THROW_ON_ERROR),
            json_encode($b->toArray(), JSON_THROW_ON_ERROR),
        );
    }

    public function testCreateCanonicalisesTheArraysItIsGiven(): void
    {
        $state = GameState::create(
            ['hp' => 1, 'gold' => 2],
            ['b', 'a', 'b'],
            ['torch' => 1, 'rope' => 2],
            ['zoo' => 1, 'attic' => 2],
            ['zoo', 'attic', 'zoo'],
        );

        self::assertSame(['gold', 'hp'], array_keys($state->toArray()['vars']));
        self::assertSame(['a', 'b'], $state->toArray()['flags']);
        self::assertSame(['rope', 'torch'], array_keys($state->toArray()['inventory']));
        self::assertSame(['attic', 'zoo'], array_keys($state->toArray()['visited']));
        self::assertSame(['zoo', 'attic', 'zoo'], $state->toArray()['history']);
    }

    /**
     * @return iterable<string, array{0: Closure(GameState): GameState}>
     */
    public static function withMethodProvider(): iterable
    {
        yield 'withVar' => [static fn (GameState $s): GameState => $s->withVar('hp', 1)];
        yield 'withFlag' => [static fn (GameState $s): GameState => $s->withFlag('wise')];
        yield 'withoutFlag' => [static fn (GameState $s): GameState => $s->withoutFlag('brave')];
        yield 'withItem' => [static fn (GameState $s): GameState => $s->withItem('torch', 2)];
        yield 'withVisit' => [static fn (GameState $s): GameState => $s->withVisit('cave')];
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function stateKeyProvider(): iterable
    {
        foreach (['v', 'vars', 'flags', 'inventory', 'visited', 'history'] as $key) {
            yield $key => [$key];
        }
    }

    /**
     * @return iterable<string, array{0: array<array-key, mixed>}>
     */
    public static function roundTripProvider(): iterable
    {
        yield 'full state' => [self::fullArray()];

        yield 'empty state' => [[
            'v' => 1,
            'vars' => [],
            'flags' => [],
            'inventory' => [],
            'visited' => [],
            'history' => [],
        ]];

        yield 'float and bool variables' => [[
            'v' => 1,
            'vars' => ['alive' => true, 'ratio' => 0.25],
            'flags' => [],
            'inventory' => [],
            'visited' => [],
            'history' => [],
        ]];
    }

    /**
     * @return array{v: int, vars: array<string, int|float|string|bool>, flags: list<string>, inventory: array<string, int>, visited: array<string, int>, history: list<string>}
     */
    private static function fullArray(): array
    {
        return [
            'v' => 1,
            'vars' => ['gold' => 10, 'hp' => 95],
            'flags' => ['brave', 'wise'],
            'inventory' => ['rope' => 1, 'torch' => 2],
            'visited' => ['cave' => 1, 'crossroads' => 2],
            'history' => ['crossroads', 'cave', 'crossroads'],
        ];
    }
}
