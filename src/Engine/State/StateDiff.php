<?php

declare(strict_types=1);

namespace Storyteller\Engine\State;

/**
 * What one phase of effects actually changed, as a structural before/after
 * comparison.
 *
 * It is computed from two states rather than accumulated while applying, so
 * it records **outcomes, not attempts**: clearing a flag that was not set, or
 * setting a variable to the value it already had, produces no entry at all.
 * Four categories in a fixed order — `vars`, `flags`, `inventory`, `visited` —
 * and an empty category is omitted from the serialized form entirely, so an
 * unchanged turn serializes as `{}`.
 *
 * A variable absent from the before-state diffs from its **schema default**,
 * which is why the schema is a parameter: the Engine's rule for a missing
 * variable is the same here as it is in the evaluator — the declared default,
 * never null. That keeps `from` a scalar and keeps the first write of every
 * variable visible in the event log.
 */
final readonly class StateDiff
{
    /**
     * @param array<string, array{from: int|float|string|bool, to: int|float|string|bool}> $vars
     * @param array{added: list<string>, removed: list<string>}                            $flags
     * @param array<string, array{from: int, to: int}>                                     $inventory
     * @param array<string, array{from: int, to: int}>                                     $visited
     */
    private function __construct(
        public array $vars,
        public array $flags,
        public array $inventory,
        public array $visited,
    ) {
    }

    public static function between(GameState $before, GameState $after, VariableSchema $schema): self
    {
        $vars = [];

        foreach (self::unionOfKeys($before->vars, $after->vars) as $name) {
            $from = $schema->resolve($name, $before);
            $to = $schema->resolve($name, $after);

            if ($from !== $to) {
                $vars[$name] = ['from' => $from, 'to' => $to];
            }
        }

        $inventory = [];

        foreach (self::unionOfKeys($before->inventory, $after->inventory) as $item) {
            $from = $before->itemQty($item);
            $to = $after->itemQty($item);

            if ($from !== $to) {
                $inventory[$item] = ['from' => $from, 'to' => $to];
            }
        }

        $visited = [];

        foreach (self::unionOfKeys($before->visited, $after->visited) as $scene) {
            $from = $before->visitCount($scene);
            $to = $after->visitCount($scene);

            if ($from !== $to) {
                $visited[$scene] = ['from' => $from, 'to' => $to];
            }
        }

        return new self(
            $vars,
            [
                'added' => array_values(array_diff($after->flags, $before->flags)),
                'removed' => array_values(array_diff($before->flags, $after->flags)),
            ],
            $inventory,
            $visited,
        );
    }

    public static function empty(): self
    {
        return new self([], ['added' => [], 'removed' => []], [], []);
    }

    public function isEmpty(): bool
    {
        return $this->vars === []
            && $this->flags['added'] === []
            && $this->flags['removed'] === []
            && $this->inventory === []
            && $this->visited === [];
    }

    /**
     * @return array<string, mixed> non-empty categories only, in the order
     *                              vars, flags, inventory, visited
     */
    public function toArray(): array
    {
        $out = [];

        if ($this->vars !== []) {
            $out['vars'] = $this->vars;
        }

        $flags = array_filter($this->flags, static fn (array $names): bool => $names !== []);

        if ($flags !== []) {
            $out['flags'] = $flags;
        }

        if ($this->inventory !== []) {
            $out['inventory'] = $this->inventory;
        }

        if ($this->visited !== []) {
            $out['visited'] = $this->visited;
        }

        return $out;
    }

    /**
     * The two key sets merged and sorted the way `GameState` sorts its own
     * maps, so a diff is as canonical as the states it compares. Keys are cast
     * back to string because PHP stores a decimal-integer-like key as an int.
     *
     * @param  array<string, mixed> $before
     * @param  array<string, mixed> $after
     * @return list<string>
     */
    private static function unionOfKeys(array $before, array $after): array
    {
        $names = [];

        foreach ([$before, $after] as $map) {
            foreach ($map as $name => $_) {
                $names[] = (string) $name;
            }
        }

        $names = array_values(array_unique($names));

        sort($names, SORT_STRING);

        return $names;
    }
}
