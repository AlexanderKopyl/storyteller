<?php

declare(strict_types=1);

namespace Storyteller\Tests\Unit\Engine\State;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Storyteller\Engine\State\GameState;
use Storyteller\Engine\State\StateDiff;
use Storyteller\Engine\State\VariableDefinition;
use Storyteller\Engine\State\VariableSchema;
use Storyteller\Engine\State\VariableType;

/**
 * The diff is the `EffectsApplied` payload, so its shape crosses into Play's
 * event log and is pinned here literally.
 *
 * It is a post-hoc comparison of two states, not a log of attempted writes,
 * so it records outcomes: an effect that changed nothing produces no entry at
 * all.
 */
#[CoversClass(StateDiff::class)]
final class StateDiffTest extends TestCase
{
    public function testBetweenIdenticalStatesIsEmpty(): void
    {
        $state = $this->populated();

        $diff = StateDiff::between($state, $state, $this->schema());

        self::assertTrue($diff->isEmpty());
        self::assertSame([], $diff->toArray());
    }

    public function testEmptyIsEmpty(): void
    {
        $diff = StateDiff::empty();

        self::assertTrue($diff->isEmpty());
        self::assertSame([], $diff->toArray());
        self::assertSame(['added' => [], 'removed' => []], $diff->flags);
    }

    public function testVarsDiffRecordsFromAndTo(): void
    {
        $before = $this->populated();
        $after = $before->withVar('hp', 90)->withVar('name', 'ash');

        $diff = StateDiff::between($before, $after, $this->schema());

        self::assertFalse($diff->isEmpty());
        self::assertSame(['hp' => ['from' => 95, 'to' => 90]], $diff->vars);
    }

    /**
     * A variable the before-state does not carry diffs from its **schema
     * default**, not from null: the Engine's rule for a missing variable is
     * the same everywhere, so the first `set` of a variable is still visible
     * in the log and `from` stays a scalar.
     */
    public function testAVariableAbsentBeforeDiffsFromItsSchemaDefault(): void
    {
        $diff = StateDiff::between(
            GameState::initial(),
            GameState::initial()->withVar('hp', 95)->withVar('gold', 0),
            $this->schema(),
        );

        self::assertSame(['hp' => ['from' => 100, 'to' => 95]], $diff->vars);
    }

    public function testFlagsDiffSplitsAddedAndRemoved(): void
    {
        $before = $this->populated();
        $after = $before->withFlag('wise')->withFlag('brave')->withoutFlag('met_elder');
        $schema = $this->schema();

        $diff = StateDiff::between($before, $after, $schema);

        self::assertSame(['added' => ['brave', 'wise'], 'removed' => ['met_elder']], $diff->flags);
        self::assertSame(
            ['flags' => ['added' => ['brave', 'wise'], 'removed' => ['met_elder']]],
            $diff->toArray(),
        );

        $onlyAdded = StateDiff::between($before, $before->withFlag('wise'), $schema);
        self::assertSame(['flags' => ['added' => ['wise']]], $onlyAdded->toArray());

        $onlyRemoved = StateDiff::between($before, $before->withoutFlag('met_elder'), $schema);
        self::assertSame(['flags' => ['removed' => ['met_elder']]], $onlyRemoved->toArray());
    }

    public function testInventoryDiffRecordsARemovedKeyWithToZero(): void
    {
        $before = $this->populated();
        $after = $before->withItem('torch', 0)->withItem('lantern', 1);

        $diff = StateDiff::between($before, $after, $this->schema());

        self::assertSame(
            ['lantern' => ['from' => 0, 'to' => 1], 'torch' => ['from' => 2, 'to' => 0]],
            $diff->inventory,
        );
    }

    /**
     * No effect operator writes `visited` — `Transition` does, outside both
     * effect phases — so this category is covered here directly rather than
     * through the applier.
     */
    public function testVisitedDiffIsRecorded(): void
    {
        $before = $this->populated();
        $after = $before->withVisit('cave')->withVisit('ridge');

        $diff = StateDiff::between($before, $after, $this->schema());

        self::assertSame(
            ['cave' => ['from' => 2, 'to' => 3], 'ridge' => ['from' => 0, 'to' => 1]],
            $diff->visited,
        );
    }

    public function testToArrayOmitsEmptyCategoriesInAFixedOrder(): void
    {
        $before = $this->populated();
        $after = $before
            ->withVar('hp', 90)
            ->withFlag('wise')
            ->withoutFlag('met_elder')
            ->withItem('rope', 3)
            ->withVisit('cave');

        $diff = StateDiff::between($before, $after, $this->schema());

        self::assertSame(['vars', 'flags', 'inventory', 'visited'], array_keys($diff->toArray()));
        self::assertSame(
            [
                'vars' => ['hp' => ['from' => 95, 'to' => 90]],
                'flags' => ['added' => ['wise'], 'removed' => ['met_elder']],
                'inventory' => ['rope' => ['from' => 2, 'to' => 3]],
                'visited' => ['cave' => ['from' => 2, 'to' => 3]],
            ],
            $diff->toArray(),
        );

        self::assertSame(
            ['inventory' => ['rope' => ['from' => 2, 'to' => 3]]],
            StateDiff::between($before, $before->withItem('rope', 3), $this->schema())->toArray(),
        );
    }

    private function schema(): VariableSchema
    {
        return VariableSchema::of([
            new VariableDefinition('hp', VariableType::Int, 100),
            new VariableDefinition('gold', VariableType::Int, 0),
            new VariableDefinition('name', VariableType::String, 'ash'),
        ]);
    }

    private function populated(): GameState
    {
        return GameState::create(
            ['hp' => 95, 'name' => 'ash'],
            ['met_elder'],
            ['rope' => 2, 'torch' => 2],
            ['cave' => 2],
            ['cave', 'cave'],
        );
    }
}
