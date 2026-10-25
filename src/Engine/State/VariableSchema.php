<?php

declare(strict_types=1);

namespace Storyteller\Engine\State;

use Storyteller\Engine\Exception\MalformedNodeException;
use Storyteller\Engine\Exception\TypeMismatchException;
use Storyteller\Engine\Exception\UnknownVariableException;
use Storyteller\Engine\Support\ArrayReader;

/**
 * The authoritative declaration of every story variable.
 *
 * A name absent from the schema is an error even when the state carries a
 * value for it, and a name present in the schema but absent from the state
 * resolves to the declared default — never to null. Keeping both rules here
 * means the evaluator and the applier share one implementation of them.
 *
 * The map is key-sorted at construction (SORT_STRING, so ordering never
 * depends on whether a name looks numeric), which makes `toArray()` canonical.
 */
final readonly class VariableSchema
{
    /**
     * @param array<string, VariableDefinition> $byName key-sorted, already canonical
     */
    private function __construct(private array $byName)
    {
    }

    /**
     * @param list<VariableDefinition> $definitions
     */
    public static function of(array $definitions): self
    {
        $byName = [];

        foreach ($definitions as $definition) {
            if (array_key_exists($definition->name, $byName)) {
                throw MalformedNodeException::duplicateKey('schema', $definition->name);
            }

            $byName[$definition->name] = $definition;
        }

        ksort($byName, SORT_STRING);

        return new self($byName);
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $reader = new ArrayReader($data, 'schema');
        $definitions = [];

        foreach ($data as $name => $_) {
            $definitions[] = VariableDefinition::fromArray((string) $name, $reader->requireArray((string) $name));
        }

        return self::of($definitions);
    }

    /**
     * @return array<string, array{type: string, default: int|float|string|bool}>
     */
    public function toArray(): array
    {
        $out = [];

        foreach ($this->byName as $name => $definition) {
            $out[$name] = $definition->toArray();
        }

        return $out;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->byName);
    }

    public function typeOf(string $name): VariableType
    {
        return $this->definition($name)->type;
    }

    /**
     * m2: {@see self::resolve()} reads the definition's default directly, so
     * nothing in the Engine calls this. Kept for `src/Content` and `src/Api`:
     * a schema is content in its own right — an authoring UI shows declared
     * defaults, and a version diff compares them — and reaching that through
     * `resolve()` against a fabricated empty state would be a worse way to ask.
     */
    public function defaultOf(string $name): int|float|string|bool
    {
        return $this->definition($name)->default;
    }

    /**
     * The single variable-resolution point in the Engine.
     *
     * A name the schema does not declare is an error even when the state
     * carries a value for it; a declared name the state says nothing about
     * resolves to the declared default. The result is never null, so no
     * operator has to decide what a missing variable means.
     *
     * The result is also canonical. `GameState` carries no schema and so
     * stores whatever it was handed — including the `int` that a float with a
     * zero fraction becomes on its way through JSONB. Widening here, at the
     * one place every operator reads a variable, is what keeps `===` an
     * honest test of equality across a save.
     *
     * @throws UnknownVariableException if the name is not declared
     */
    public function resolve(string $name, GameState $state): int|float|string|bool
    {
        $definition = $this->definition($name);

        return $definition->type->canonicalise(
            $state->hasVar($name) ? $state->var($name) : $definition->default,
        );
    }

    /**
     * Normalises a value against its declaration: an `int` destined for a
     * `float` variable becomes a real `float`, and nothing else changes.
     *
     * Call it on anything about to be compared with, or written into, a
     * declared variable — a condition's literal, an effect's value, an
     * arithmetic result.
     *
     * @throws UnknownVariableException if the name is not declared
     */
    public function canonicalise(string $name, int|float|string|bool $value): int|float|string|bool
    {
        return $this->typeOf($name)->canonicalise($value);
    }

    /**
     * @throws UnknownVariableException if the name is not declared
     * @throws TypeMismatchException    if the value does not match the declaration
     */
    public function assertAccepts(string $name, int|float|string|bool $value): void
    {
        $type = $this->typeOf($name);

        if (!$type->accepts($value)) {
            throw TypeMismatchException::value($name, $type, get_debug_type($value));
        }
    }

    /**
     * Validates a whole loaded state against this schema — the one check a
     * caller makes after reading a session out of its JSONB column, before
     * handing the state to a `Transition`.
     *
     * The Engine cannot make this check for itself: `GameState` carries no
     * schema by design, so `GameState::fromArray()` accepts any scalar the
     * column holds. Without a boundary check, one disagreeing value is
     * answered differently by every operator that meets it — `eq` reports
     * `false`, `gt` reports `true`, `add` throws and `set` overwrites — four
     * code paths, three policies, no error (review r3 / B1-b). One loud
     * failure per load is worth more than four quiet answers per turn.
     *
     * Acceptance is the canonicalising one, so an integral `float` that JSON
     * wrote as `1` and handed back as an `int` passes: that is the B1 rule,
     * and tightening it here would brick every saved session carrying a float.
     * A declared variable the state says nothing about is not a disagreement
     * either — it resolves to its default.
     *
     * Undeclared names are **rejected**, unlike {@see StateDiff::between()},
     * which skips them. The two run at different moments and the policies
     * follow from that: the diff runs on every phase of every turn and must
     * not brick a playable session over a variable a newer story version
     * retired, while this runs once, at load, where the caller can still
     * refuse the session or migrate it.
     *
     * @throws UnknownVariableException if the state carries a name the schema does not declare
     * @throws TypeMismatchException    if a value does not match its declaration; the state's
     *                                  `vars` are key-sorted, so the name reported is the first
     *                                  disagreement in canonical order and never varies
     */
    public function assertState(GameState $state): void
    {
        foreach ($state->vars as $name => $value) {
            $this->assertAccepts($name, $value);
        }
    }

    /**
     * m2: no production call site in the Engine, which only ever resolves
     * names an AST node gave it. Kept for `src/Content`: enumerating a
     * version's declared variables is how a schema is diffed against its
     * predecessor and how an authoring UI lists what a story has — and
     * `toArray()`, the alternative, hands back the whole declaration when only
     * the names were wanted.
     *
     * @return list<string> sorted
     */
    public function names(): array
    {
        return array_keys($this->byName);
    }

    private function definition(string $name): VariableDefinition
    {
        return $this->byName[$name] ?? throw UnknownVariableException::named($name);
    }
}
