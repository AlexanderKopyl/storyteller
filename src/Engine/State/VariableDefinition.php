<?php

declare(strict_types=1);

namespace Storyteller\Engine\State;

use Storyteller\Engine\Exception\MalformedNodeException;
use Storyteller\Engine\Exception\TypeMismatchException;
use Storyteller\Engine\Support\ArrayReader;

/**
 * One declared story variable: its name, its type and the value it resolves to
 * while the state carries nothing for it.
 *
 * The default is canonicalised at construction, so a `float` variable declared
 * with `1.0` still holds a real `float` after the snapshot has been through
 * JSON and the literal came back as `1`.
 */
final readonly class VariableDefinition
{
    public int|float|string|bool $default;

    /**
     * @throws TypeMismatchException if the type does not accept the default
     */
    public function __construct(
        public string $name,
        public VariableType $type,
        int|float|string|bool $default,
    ) {
        if (!$type->accepts($default)) {
            throw TypeMismatchException::value($name, $type, get_debug_type($default));
        }

        $this->default = $type->canonicalise($default);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(string $name, array $data): self
    {
        $path = 'schema.' . $name;
        $reader = new ArrayReader($data, $path);

        $reader->rejectUnknownKeys('type', 'default');

        $rawType = $reader->requireString('type');
        $type = VariableType::tryFrom($rawType)
            ?? throw MalformedNodeException::wrongType($path, 'type', 'int|float|string|bool', $rawType);

        return new self($name, $type, $reader->requireScalar('default'));
    }

    /**
     * @return array{type: string, default: int|float|string|bool}
     */
    public function toArray(): array
    {
        return ['type' => $this->type->value, 'default' => $this->default];
    }
}
