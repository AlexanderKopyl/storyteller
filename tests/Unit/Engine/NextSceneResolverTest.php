<?php

declare(strict_types=1);

namespace Storyteller\Tests\Unit\Engine;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Storyteller\Engine\ConditionEvaluator;
use Storyteller\Engine\Exception\NoNextSceneException;
use Storyteller\Engine\Graph\NextRule;
use Storyteller\Engine\NextSceneResolver;
use Storyteller\Engine\State\GameState;
use Storyteller\Engine\State\VariableSchema;

/**
 * Resolution is ordered first-match and nothing more: the resolver holds no
 * graph and does not check that the scene it returns exists — that is the
 * caller's job, and keeping it out of here is what makes the rule list
 * testable on its own.
 */
#[CoversClass(NextSceneResolver::class)]
final class NextSceneResolverTest extends TestCase
{
    public function testAPlainStringResolvesToItself(): void
    {
        self::assertSame('cave', $this->resolver()->resolve('cave', $this->state(), 'crossroads'));
    }

    public function testTheFirstMatchingRuleWinsEvenWhenALaterRuleWouldAlsoMatch(): void
    {
        $rules = NextRule::listFromArray([
            ['scene' => 'ridge', 'if' => ['op' => 'flag', 'flag' => 'never_set']],
            ['scene' => 'cave', 'if' => ['op' => 'flag', 'flag' => 'brave']],
            ['scene' => 'tower', 'if' => ['op' => 'flag', 'flag' => 'brave']],
        ]);

        self::assertSame('cave', $this->resolver()->resolve($rules, $this->state(), 'crossroads'));
    }

    public function testAFallbackIsUsedOnlyWhenNoEarlierRuleMatched(): void
    {
        $resolver = $this->resolver();

        $matched = NextRule::listFromArray([
            ['scene' => 'cave', 'if' => ['op' => 'flag', 'flag' => 'brave']],
            ['scene' => 'home'],
        ]);
        $unmatched = NextRule::listFromArray([
            ['scene' => 'cave', 'if' => ['op' => 'flag', 'flag' => 'never_set']],
            ['scene' => 'home'],
        ]);

        self::assertSame('cave', $resolver->resolve($matched, $this->state(), 'crossroads'));
        self::assertSame('home', $resolver->resolve($unmatched, $this->state(), 'crossroads'));
    }

    public function testNoMatchAndNoFallbackThrows(): void
    {
        $this->expectException(NoNextSceneException::class);
        $this->expectExceptionMessage('crossroads');

        $this->resolver()->resolve(
            NextRule::listFromArray([['scene' => 'cave', 'if' => ['op' => 'flag', 'flag' => 'never_set']]]),
            $this->state(),
            'crossroads',
        );
    }

    /**
     * An empty list is how an ending or a dead end is encoded, so reaching the
     * resolver with one is an authoring error rather than a silent stop.
     */
    public function testAnEmptyRuleListThrows(): void
    {
        $this->expectException(NoNextSceneException::class);
        $this->expectExceptionMessage('the_end');

        $this->resolver()->resolve([], $this->state(), 'the_end');
    }

    /**
     * A fallback is a rule that matches unconditionally **at its position**,
     * so one written first shadows every rule after it. The resolver does not
     * warn, because it cannot tell an unreachable rule from a deliberately
     * ordered list — that is a lint for the Content compiler, and this test is
     * what says the Engine deliberately leaves it there.
     */
    public function testAFallbackPlacedFirstMakesEveryLaterRuleUnreachable(): void
    {
        $rules = NextRule::listFromArray([
            ['scene' => 'home'],
            ['scene' => 'cave', 'if' => ['op' => 'flag', 'flag' => 'brave']],
            ['scene' => 'tower'],
        ]);

        // `brave` is set, so rule 2 would match if it were ever reached.
        self::assertSame('home', $this->resolver()->resolve($rules, $this->state(), 'crossroads'));
        self::assertTrue($rules[0]->isFallback());
        self::assertFalse($rules[1]->isFallback());
    }

    /**
     * Resolution is a pure function of the rule list and the state: the same
     * resolver asked twice, and two independently built resolvers, answer the
     * same way, and the state is unchanged by having been read.
     */
    public function testResolutionIsRepeatableAndLeavesTheStateUntouched(): void
    {
        $rules = NextRule::listFromArray([
            ['scene' => 'cave', 'if' => ['op' => 'has_item', 'item' => 'rope', 'qty' => 2]],
            ['scene' => 'ridge', 'if' => ['op' => 'flag', 'flag' => 'brave']],
            ['scene' => 'home'],
        ]);

        $state = $this->state();
        $before = $state->toArray();
        $shared = $this->resolver();
        $answers = [];

        // Collected rather than asserted one by one: PHPStan proves `resolve()`
        // pure and reports a second `assertSame` on the same call as always
        // true, which is a compliment to the design and useless as a test.
        foreach ([$shared, $shared, $this->resolver()] as $resolver) {
            $answers[] = $resolver->resolve($rules, $state, 'crossroads');
        }

        self::assertSame(['ridge', 'ridge', 'ridge'], $answers);
        self::assertSame($before, $state->toArray());
    }

    private function resolver(): NextSceneResolver
    {
        return new NextSceneResolver(new ConditionEvaluator(VariableSchema::empty()));
    }

    private function state(): GameState
    {
        return GameState::create([], ['brave'], [], [], []);
    }
}
