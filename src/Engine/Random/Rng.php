<?php

declare(strict_types=1);

namespace Storyteller\Engine\Random;

/**
 * A deterministic source of randomness — and, for now, a deliberate forward
 * contract: **nothing in this feature calls it.**
 *
 * It exists because the operators that will need it are already foreseeable (a
 * `chance` condition, a `roll` effect) and because `Transition::run()` already
 * threads `int $turn` for exactly this reason. Introducing the interface now
 * costs one file; introducing it later would change a constructor signature in
 * the one class every bounded context above the Engine depends on.
 *
 * **It is index-addressed, not sequential.** There is no `next()` and no
 * internal cursor, because a cursor is mutable state and `src/Engine` holds
 * none. A draw is a pure function of `(seed, turn, index)`: the same triple
 * always gives the same value, in any order, in any process, no matter what
 * was drawn before it. The caller owns the index — typically the position of
 * the operator inside the turn it belongs to.
 */
interface Rng
{
    /**
     * A value in `[$min, $max]`, both inclusive.
     *
     * The caller's contract is `$min <= $max`; the Engine trusts its inputs
     * (CLAUDE.md standing rule 5), so an implementation need not guard it.
     */
    public function intBetween(int $turn, int $index, int $min, int $max): int;

    /**
     * @return float in `[0.0, 1.0)` — zero is possible, one is not
     */
    public function fraction(int $turn, int $index): float;
}
