<?php

declare(strict_types=1);

namespace Storyteller\Engine\State;

use Storyteller\Engine\Exception\MalformedNodeException;
use Storyteller\Engine\Exception\UnknownVariableException;
use Storyteller\Engine\Support\ArrayReader;

/**
 * The whole of a playthrough's mutable world, as one immutable value.
 *
 * Every `with*` method returns a new instance; nothing here is ever modified
 * in place, so a caller can keep a reference to an earlier state (the applier
 * does exactly that to compute a diff).
 *
 * **Canonical ordering** is not cosmetic. `vars`, `inventory` and `visited`
 * are key-sorted with `SORT_STRING` — not the default `SORT_REGULAR`, which
 * compares numeric-looking keys numerically and would make the order depend on
 * what the keys happen to say — and `flags` is de-duplicated and sorted.
 * `history` is the one collection that keeps insertion order, because its
 * order *is* its content. The result: two states reached by different but
 * equivalent routes serialize to identical bytes, which is what makes the
 * determinism guarantee checkable rather than merely claimed.
 *
 * The state carries no schema and therefore validates no types: `withVar()`
 * stores what it is given. Type enforcement belongs to the applier, which has
 * the schema in hand.
 *
 * That has one consequence worth naming, because JSON forces it. `json_encode`
 * writes a float with a zero fraction as `1`, and `json_decode` reads it back
 * as an `int`, so a state reloaded from JSONB can carry an `int` under a name
 * the schema declares `float`. The Engine never reads a variable out of the
 * state directly — it reads through `VariableSchema::resolve()`, which widens
 * it back — and the applier only ever writes canonical values, so the two
 * forms are indistinguishable to every operator. `var()` is the raw view and
 * reports what is actually stored.
 */
final readonly class GameState
{
    public const int VERSION = 1;

    /**
     * @param array<string, int|float|string|bool> $vars      key-sorted (SORT_STRING)
     * @param list<string>                         $flags     sorted and de-duplicated
     * @param array<string, int>                   $inventory key-sorted; the Engine's own
     *                                                        writers never keep a value below 1
     * @param array<string, int>                   $visited   key-sorted; same
     * @param list<string>                         $history   insertion order, append-only
     */
    private function __construct(
        public array $vars,
        public array $flags,
        public array $inventory,
        public array $visited,
        public array $history,
    ) {
    }

    /**
     * Five empty collections. There is exactly one notion of "default" in the
     * Engine — the schema, consulted when a variable is read — so none is
     * seeded here, and the starting scene is not marked visited.
     */
    public static function initial(): self
    {
        return new self([], [], [], [], []);
    }

    /**
     * @param array<string, int|float|string|bool> $vars
     * @param list<string>                         $flags
     * @param array<string, int>                   $inventory
     * @param array<string, int>                   $visited
     * @param list<string>                         $history
     */
    public static function create(array $vars, array $flags, array $inventory, array $visited, array $history): self
    {
        return new self(
            self::sortedScalarMap($vars),
            self::canonicalFlags($flags),
            self::sortedIntMap($inventory),
            self::sortedIntMap($visited),
            $history,
        );
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $reader = new ArrayReader($data, 'state');

        $reader->rejectUnknownKeys('v', 'vars', 'flags', 'inventory', 'visited', 'history');

        $version = $reader->requireInt('v');

        if ($version !== self::VERSION) {
            throw MalformedNodeException::unsupportedStateVersion($version, self::VERSION);
        }

        return self::create(
            $reader->requireScalarMap('vars'),
            $reader->requireStringList('flags'),
            $reader->requireIntMap('inventory'),
            $reader->requireIntMap('visited'),
            $reader->requireStringList('history'),
        );
    }

    /**
     * @return array{
     *     v: int,
     *     vars: array<string, int|float|string|bool>,
     *     flags: list<string>,
     *     inventory: array<string, int>,
     *     visited: array<string, int>,
     *     history: list<string>
     * }
     */
    public function toArray(): array
    {
        return [
            'v' => self::VERSION,
            'vars' => $this->vars,
            'flags' => $this->flags,
            'inventory' => $this->inventory,
            'visited' => $this->visited,
            'history' => $this->history,
        ];
    }

    public function hasVar(string $name): bool
    {
        return array_key_exists($name, $this->vars);
    }

    public function var(string $name): int|float|string|bool
    {
        return $this->vars[$name] ?? throw UnknownVariableException::named($name);
    }

    public function hasFlag(string $name): bool
    {
        return in_array($name, $this->flags, true);
    }

    public function itemQty(string $item): int
    {
        return $this->inventory[$item] ?? 0;
    }

    public function visitCount(string $scene): int
    {
        return $this->visited[$scene] ?? 0;
    }

    public function withVar(string $name, int|float|string|bool $value): self
    {
        $vars = $this->vars;
        $vars[$name] = $value;

        return self::create($vars, $this->flags, $this->inventory, $this->visited, $this->history);
    }

    public function withFlag(string $name): self
    {
        $flags = $this->flags;
        $flags[] = $name;

        return self::create($this->vars, $flags, $this->inventory, $this->visited, $this->history);
    }

    public function withoutFlag(string $name): self
    {
        $flags = array_values(array_filter(
            $this->flags,
            static fn (string $flag): bool => $flag !== $name,
        ));

        return self::create($this->vars, $flags, $this->inventory, $this->visited, $this->history);
    }

    /**
     * A quantity of zero or less removes the item entirely, so an inventory
     * never carries a zero entry and a quantity can never go negative.
     */
    public function withItem(string $item, int $qty): self
    {
        $inventory = $this->inventory;

        if ($qty <= 0) {
            unset($inventory[$item]);
        } else {
            $inventory[$item] = $qty;
        }

        return self::create($this->vars, $this->flags, $inventory, $this->visited, $this->history);
    }

    /**
     * The only writer of `visited` and of `history`, so the two cannot drift
     * apart: the visit count of a scene is always the number of times it
     * appears in the history.
     */
    public function withVisit(string $scene): self
    {
        $visited = $this->visited;
        $visited[$scene] = $this->visitCount($scene) + 1;

        $history = $this->history;
        $history[] = $scene;

        return self::create($this->vars, $this->flags, $this->inventory, $visited, $history);
    }

    /**
     * @param  array<string, int|float|string|bool> $map
     * @return array<string, int|float|string|bool>
     */
    private static function sortedScalarMap(array $map): array
    {
        ksort($map, SORT_STRING);

        return $map;
    }

    /**
     * @param  array<string, int> $map
     * @return array<string, int>
     */
    private static function sortedIntMap(array $map): array
    {
        ksort($map, SORT_STRING);

        return $map;
    }

    /**
     * @param  list<string> $flags
     * @return list<string>
     */
    private static function canonicalFlags(array $flags): array
    {
        $flags = array_values(array_unique($flags));

        sort($flags, SORT_STRING);

        return $flags;
    }
}
