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
     * @throws UnknownVariableException if the name is not declared
     */
    public function resolve(string $name, GameState $state): int|float|string|bool
    {
        $definition = $this->definition($name);

        return $state->hasVar($name) ? $state->var($name) : $definition->default;
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
