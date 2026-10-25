<?php

declare(strict_types=1);

namespace Storyteller\Engine\Random;

use Random\Engine\Xoshiro256StarStar;
use Random\IntervalBoundary;
use Random\Randomizer;

/**
 * The reference `Rng`: a pure function of its seed.
 *
 * Each draw derives its own 32-byte engine seed from
 * `sha256(seed | turn | index)` and runs a fresh `Xoshiro256StarStar` over it.
 * That is what makes the class `readonly` and index-addressed at the same
 * time — there is no shared engine to advance, so two draws cannot influence
 * one another and no ordering of calls can change an answer.
 *
 * The cost is one hash and one engine construction per draw, which is
 * irrelevant at the rate a story rolls dice and buys a property worth far
 * more: the same `(seed, turn, index)` yields the same value in a different
 * process, on a different machine, a year later. Nothing here reads a clock, a
 * PID or the OS entropy pool — `Random\Engine\Secure` in particular is never
 * used, because it is exactly the one engine that cannot be reproduced.
 *
 * **Unused by design.** No condition, effect or service in this feature takes
 * an `Rng`; see the interface docblock, and `SeededRngTest` asserts the claim.
 */
final readonly class SeededRng implements Rng
{
    public function __construct(private string $seed)
    {
    }

    /**
     * For callers that keep a numeric seed — a story version id, say. Any
     * string is a valid seed, so this is a convenience, not a constraint.
     */
    public static function fromInt(int $seed): self
    {
        return new self((string) $seed);
    }

    public function intBetween(int $turn, int $index, int $min, int $max): int
    {
        return $this->randomizerFor($turn, $index)->getInt($min, $max);
    }

    public function fraction(int $turn, int $index): float
    {
        return $this->randomizerFor($turn, $index)
            ->getFloat(0.0, 1.0, IntervalBoundary::ClosedOpen);
    }

    /**
     * The whole of the derivation, in one place: a binary SHA-256 digest is
     * exactly the 32 bytes `Xoshiro256StarStar` wants, and the separator keeps
     * `(turn 1, index 23)` from colliding with `(turn 12, index 3)`.
     */
    private function randomizerFor(int $turn, int $index): Randomizer
    {
        return new Randomizer(new Xoshiro256StarStar(
            hash('sha256', $this->seed . '|' . $turn . '|' . $index, true),
        ));
    }
}
