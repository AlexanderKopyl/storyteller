<?php

declare(strict_types=1);

namespace Storyteller\Engine\Support;

use Storyteller\Engine\Exception\MalformedNodeException;

/**
 * The single place in the Engine that narrows `mixed`.
 *
 * Every `fromArray()` in `src/Engine` reads through a reader rather than
 * touching a decoded array directly, which is what keeps `mixed` out of all
 * other signatures and makes static analysis at level 8 provable without a
 * single suppression. Each method either returns a precisely typed value or
 * throws {@see MalformedNodeException} naming the path and the key.
 *
 * `$path` is a human-readable breadcrumb such as `condition.of[1]`, extended
 * by the AST dispatchers as they descend, so an error points at a position in
 * the authored document rather than at a PHP type.
 *
 * Narrowing is done with `is_*()` and element-by-element copies into a fresh
 * array: PHP's own type juggling never leaks through — a numeric string is not
 * an int, and a bool is neither an int nor a number.
 */
final readonly class ArrayReader
{
    /**
     * @param array<array-key, mixed> $data
     */
    public function __construct(private array $data, private string $path)
    {
    }

    public function requireString(string $key): string
    {
        $value = $this->data[$key] ?? throw MalformedNodeException::missingKey($this->path, $key);

        if (!is_string($value)) {
            throw MalformedNodeException::wrongType($this->path, $key, 'string', get_debug_type($value));
        }

        return $value;
    }

    public function requireInt(string $key): int
    {
        $value = $this->data[$key] ?? throw MalformedNodeException::missingKey($this->path, $key);

        if (!is_int($value)) {
            throw MalformedNodeException::wrongType($this->path, $key, 'int', get_debug_type($value));
        }

        return $value;
    }

    public function requirePositiveInt(string $key): int
    {
        $value = $this->requireInt($key);

        if ($value < 1) {
            throw MalformedNodeException::notAPositiveInt($this->path, $key);
        }

        return $value;
    }

    public function optionalPositiveInt(string $key, int $default): int
    {
        if (!$this->has($key)) {
            return $default;
        }

        return $this->requirePositiveInt($key);
    }

    public function requireScalar(string $key): int|float|string|bool
    {
        $value = $this->data[$key] ?? throw MalformedNodeException::missingKey($this->path, $key);

        if (!is_int($value) && !is_float($value) && !is_string($value) && !is_bool($value)) {
            throw MalformedNodeException::wrongType($this->path, $key, 'scalar', get_debug_type($value));
        }

        return $value;
    }

    public function requireNumber(string $key): int|float
    {
        $value = $this->data[$key] ?? throw MalformedNodeException::missingKey($this->path, $key);

        if (!is_int($value) && !is_float($value)) {
            throw MalformedNodeException::wrongType($this->path, $key, 'number', get_debug_type($value));
        }

        return $value;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function requireArray(string $key): array
    {
        $value = $this->data[$key] ?? throw MalformedNodeException::missingKey($this->path, $key);

        if (!is_array($value)) {
            throw MalformedNodeException::wrongType($this->path, $key, 'array', get_debug_type($value));
        }

        return $value;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    public function optionalArray(string $key): ?array
    {
        if (!$this->has($key)) {
            return null;
        }

        return $this->requireArray($key);
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    public function requireArrayList(string $key): array
    {
        $raw = $this->requireArray($key);

        if (!array_is_list($raw)) {
            throw MalformedNodeException::wrongType($this->path, $key, 'list', 'map');
        }

        $out = [];

        foreach ($raw as $index => $item) {
            if (!is_array($item)) {
                throw MalformedNodeException::wrongType(
                    $this->path . '.' . $key,
                    (string) $index,
                    'array',
                    get_debug_type($item),
                );
            }

            $out[] = $item;
        }

        return $out;
    }

    /**
     * @param  list<array<array-key, mixed>> $default
     * @return list<array<array-key, mixed>>
     */
    public function optionalArrayList(string $key, array $default = []): array
    {
        if (!$this->has($key)) {
            return $default;
        }

        return $this->requireArrayList($key);
    }

    /**
     * @return list<string>
     */
    public function requireStringList(string $key): array
    {
        $raw = $this->requireArray($key);

        if (!array_is_list($raw)) {
            throw MalformedNodeException::wrongType($this->path, $key, 'list', 'map');
        }

        $out = [];

        foreach ($raw as $index => $item) {
            if (!is_string($item)) {
                throw MalformedNodeException::wrongType(
                    $this->path . '.' . $key,
                    (string) $index,
                    'string',
                    get_debug_type($item),
                );
            }

            $out[] = $item;
        }

        return $out;
    }

    /**
     * @return array<string, int>
     */
    public function requireIntMap(string $key): array
    {
        $raw = $this->requireArray($key);
        $out = [];

        foreach ($raw as $name => $value) {
            if (!is_int($value)) {
                throw MalformedNodeException::wrongType(
                    $this->path . '.' . $key,
                    (string) $name,
                    'int',
                    get_debug_type($value),
                );
            }

            $out[(string) $name] = $value;
        }

        return $out;
    }

    /**
     * @return array<string, int|float|string|bool>
     */
    public function requireScalarMap(string $key): array
    {
        $raw = $this->requireArray($key);
        $out = [];

        foreach ($raw as $name => $value) {
            if (!is_int($value) && !is_float($value) && !is_string($value) && !is_bool($value)) {
                throw MalformedNodeException::wrongType(
                    $this->path . '.' . $key,
                    (string) $name,
                    'scalar',
                    get_debug_type($value),
                );
            }

            $out[(string) $name] = $value;
        }

        return $out;
    }

    /**
     * Present *and* not null — the same reading of "provided" the `??`
     * narrowing above uses, so `has()` and `require*()` can never disagree.
     */
    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }

    /**
     * Refuses a key that is present and explicitly `null`.
     *
     * {@see self::has()} cannot tell the two apart, and for an optional key
     * whose absence carries meaning that difference is the whole story: an
     * **omitted** `condition` means "no guard", while an **explicit null** —
     * a YAML `condition:` line with nothing after it, or a compiler emitting
     * `"condition": null` — is malformed content. Reading the second as the
     * first would delete an authored gate instead of reporting an authoring
     * error, which is the one kind of silent failure that changes who can
     * reach what.
     *
     * @throws MalformedNodeException if the key is present with a null value
     */
    public function rejectNull(string $key, string $expected): void
    {
        if (array_key_exists($key, $this->data) && $this->data[$key] === null) {
            throw MalformedNodeException::wrongType($this->path, $key, $expected, 'null');
        }
    }

    /**
     * Whether the key holds a string.
     *
     * The format has exactly one authored union — a scene's `next`, which is
     * either a scene name or a rule list — and this is what lets its reader
     * pick a branch without touching the raw array itself. Narrowing stays in
     * this one class.
     */
    public function isString(string $key): bool
    {
        return is_string($this->data[$key] ?? null);
    }

    /**
     * Rejects any key outside the allowed set, so a typo in authored content
     * fails when the story is compiled instead of silently doing nothing when
     * it is played.
     */
    public function rejectUnknownKeys(string ...$allowed): void
    {
        foreach ($this->data as $key => $_) {
            if (!in_array((string) $key, $allowed, true)) {
                throw MalformedNodeException::unknownKey($this->path, (string) $key);
            }
        }
    }
}
