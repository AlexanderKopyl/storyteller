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

    private function resolver(): NextSceneResolver
    {
        return new NextSceneResolver(new ConditionEvaluator(VariableSchema::empty()));
    }

    private function state(): GameState
    {
        return GameState::create([], ['brave'], [], [], []);
    }
}
