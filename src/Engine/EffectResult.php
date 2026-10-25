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

    public function isRedirect(): bool
    {
        return $this->redirectTo !== null;
    }
}
