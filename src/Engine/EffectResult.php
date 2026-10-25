<?php

declare(strict_types=1);

namespace Storyteller\Engine;

use Storyteller\Engine\State\GameState;
use Storyteller\Engine\State\StateDiff;

/**
 * The outcome of applying one phase of effects: the state that came out, the
 * scene a `goto` asked for (if any), and what actually changed.
 *
 * `redirectTo` being set means the effect list ended early — everything after
 * the `goto` was deliberately not applied — so the diff describes only the
 * effects that ran before it.
 */
final readonly class EffectResult
{
    public function __construct(
        public GameState $state,
        public ?string $redirectTo,
        public StateDiff $diff,
    ) {
    }

    /**
     * Used by `Transition` at both of its redirect sites.
     *
     * The annotation is what lets it be used there: without it, static
     * analysis cannot see that a true answer means `redirectTo` is a string,
     * and the call site would need a second `!== null` check that says nothing
     * a reader did not already know. PHPStan takes the annotation on trust
     * rather than proving it against this body — it reported nothing when the
     * body was temporarily replaced by `return true` — so
     * `EffectResultTest::testIsRedirectReflectsRedirectTo` asserts both
     * directions at runtime. That test is the annotation's guard, not a
     * formality.
     *
     * @phpstan-assert-if-true !null $this->redirectTo
     */
    public function isRedirect(): bool
    {
        return $this->redirectTo !== null;
    }
}
