<?php

declare(strict_types=1);

namespace Storyteller\Engine\Exception;

/**
 * The root of every error the Engine can raise.
 *
 * It extends `\RuntimeException` and nothing else: the Engine owns its error
 * family outright (OQ-4) so that `src/Engine` keeps zero outgoing dependencies
 * and a caller in Play or Content can catch this one type to catch the lot.
 *
 * **Context.** The place that detects an error is rarely the place that knows
 * where it happened: the evaluator can say `Unknown variable "hp".` and no
 * more, while `Transition` knows the scene, the choice, the phase and the
 * turn. {@see self::withContext()} is how the second hands its knowledge to
 * the first, without the exception family having to learn about transitions
 * (review M4).
 */
abstract class EngineException extends \RuntimeException
{
    /**
     * @var array<string, string|int>
     */
    private array $context = [];

    /**
     * Records where this error happened, once.
     *
     * The same object is decorated and returned, rather than a new exception
     * wrapping it: the type a caller catches is unchanged, and the stack trace
     * still begins at the line that detected the problem instead of at the
     * `catch` that annotated it. The first context wins — the innermost frame
     * that knows where it is gives the most precise answer, and an outer frame
     * must not bury it or append a second parenthesis.
     *
     * The rendered form is appended to the message because that is what ends
     * up in a log; `context()` keeps the structure for a caller that would
     * rather correlate than read.
     *
     * @param array<string, string|int> $context
     */
    public function withContext(array $context): static
    {
        if ($this->context !== [] || $context === []) {
            return $this;
        }

        $this->context = $context;
        $this->message .= ' (' . self::render($context) . ')';

        return $this;
    }

    /**
     * @return array<string, string|int> empty when nothing recorded a position
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * `scene "cave", choice "north", phase "on_enter", turn 7` — strings
     * quoted so an empty or space-bearing key is still visible, ints bare so
     * a turn number does not read as a name.
     *
     * @param non-empty-array<string, string|int> $context
     */
    private static function render(array $context): string
    {
        $parts = [];

        foreach ($context as $key => $value) {
            $parts[] = is_int($value)
                ? sprintf('%s %d', $key, $value)
                : sprintf('%s "%s"', $key, $value);
        }

        return implode(', ', $parts);
    }
}
