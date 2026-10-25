<?php

declare(strict_types=1);

namespace Storyteller\Tests\Unit\Engine;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Storyteller\Engine\EffectResult;
use Storyteller\Engine\State\GameState;
use Storyteller\Engine\State\StateDiff;

#[CoversClass(EffectResult::class)]
final class EffectResultTest extends TestCase
{
    public function testIsRedirectReflectsRedirectTo(): void
    {
        $state = GameState::initial();

        $plain = new EffectResult($state, null, StateDiff::empty());
        $redirect = new EffectResult($state, 'cave', StateDiff::empty());

        self::assertFalse($plain->isRedirect());
        self::assertNull($plain->redirectTo);

        self::assertTrue($redirect->isRedirect());
        self::assertSame('cave', $redirect->redirectTo);
        self::assertSame($state, $redirect->state);
        self::assertTrue($redirect->diff->isEmpty());
    }
}
