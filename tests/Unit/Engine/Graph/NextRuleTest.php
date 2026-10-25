<?php

declare(strict_types=1);

namespace Storyteller\Tests\Unit\Engine\Graph;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Storyteller\Engine\Ast\Condition;
use Storyteller\Engine\Ast\FlagCheck;
use Storyteller\Engine\Exception\MalformedNodeException;
use Storyteller\Engine\Graph\NextRule;

/**
 * One entry of a scene's `next` list. The array shape is part of the compiled
 * snapshot Content produces, so it is parsed and round-tripped literally.
 */
#[CoversClass(NextRule::class)]
final class NextRuleTest extends TestCase
{
    public function testFallbackIsARuleWithoutACondition(): void
    {
        $guarded = NextRule::fromArray(['scene' => 'cave', 'if' => ['op' => 'flag', 'flag' => 'brave']]);
        $fallback = NextRule::fromArray(['scene' => 'home']);

        self::assertFalse($guarded->isFallback());
        self::assertInstanceOf(FlagCheck::class, $guarded->if);
        self::assertSame('cave', $guarded->scene);

        self::assertTrue($fallback->isFallback());
        self::assertNull($fallback->if);
        self::assertSame('home', $fallback->scene);
    }

    public function testRoundTripsWithAndWithoutACondition(): void
    {
        $guarded = NextRule::fromArray(['scene' => 'cave', 'if' => ['op' => 'flag', 'flag' => 'brave']]);
        $fallback = NextRule::fromArray(['scene' => 'home']);

        self::assertSame(
            ['scene' => 'cave', 'if' => ['op' => 'flag', 'flag' => 'brave']],
            $guarded->toArray(),
        );
        self::assertSame(['scene' => 'home'], $fallback->toArray());

        self::assertEquals($guarded, NextRule::fromArray($guarded->toArray()));
        self::assertEquals($fallback, NextRule::fromArray($fallback->toArray()));
    }

    public function testListFromArrayPreservesOrder(): void
    {
        $rules = NextRule::listFromArray([
            ['scene' => 'cave', 'if' => ['op' => 'flag', 'flag' => 'brave']],
            ['scene' => 'ridge', 'if' => ['op' => 'flag', 'flag' => 'wise']],
            ['scene' => 'home'],
        ]);

        self::assertSame(['cave', 'ridge', 'home'], array_map(
            static fn (NextRule $rule): string => $rule->scene,
            $rules,
        ));
        self::assertSame([], NextRule::listFromArray([]));
    }

    public function testRejectsAMissingScene(): void
    {
        $this->expectException(MalformedNodeException::class);
        $this->expectExceptionMessage('scene');

        NextRule::fromArray(['if' => ['op' => 'flag', 'flag' => 'brave']]);
    }

    public function testRejectsAnUnknownKey(): void
    {
        $this->expectException(MalformedNodeException::class);
        $this->expectExceptionMessage('then');

        NextRule::fromArray(['scene' => 'cave', 'then' => 'ridge']);
    }

    public function testTheErrorPathNamesThePositionInTheList(): void
    {
        $this->expectException(MalformedNodeException::class);
        $this->expectExceptionMessage('next[1].if');

        NextRule::listFromArray([
            ['scene' => 'cave'],
            ['scene' => 'ridge', 'if' => ['op' => 'flag']],
        ]);
    }

    public function testACallerSuppliedPathIsUsed(): void
    {
        $this->expectException(MalformedNodeException::class);
        $this->expectExceptionMessage('scene.next');

        NextRule::fromArray([], 'scene.next');
    }

    public function testCanBeBuiltDirectly(): void
    {
        $rule = new NextRule('cave', Condition::fromArray(['op' => 'flag', 'flag' => 'brave']));

        self::assertSame('cave', $rule->scene);
        self::assertFalse($rule->isFallback());
    }
}
