<?php

declare(strict_types=1);

namespace Storyteller\Engine\Exception;

/**
 * A structural problem in an authored array: a missing key, a value of the
 * wrong PHP type, a key nobody declared, or a state envelope the Engine does
 * not understand.
 *
 * Structural validation happens at parse time; whether a literal matches a
 * variable's declared type is an evaluation-time concern and raises
 * {@see TypeMismatchException} instead.
 *
 * `$path` is a human-readable breadcrumb such as `condition.of[1]`, so an
 * author can find the offending node in a large compiled snapshot.
 */
final class MalformedNodeException extends EngineException
{
    public static function missingKey(string $path, string $key): self
    {
        return new self(sprintf('%s: required key "%s" is missing.', $path, $key));
    }

    public static function wrongType(string $path, string $key, string $expected, string $actual): self
    {
        return new self(sprintf('%s: key "%s" must be of type %s, got %s.', $path, $key, $expected, $actual));
    }

    public static function unknownKey(string $path, string $key): self
    {
        return new self(sprintf('%s: key "%s" is not allowed here.', $path, $key));
    }

    public static function notAPositiveInt(string $path, string $key): self
    {
        return new self(sprintf('%s: key "%s" must be an integer greater than or equal to 1.', $path, $key));
    }

    public static function unsupportedStateVersion(int $version, int $supported): self
    {
        return new self(sprintf('Unsupported state version %d; this engine understands version %d.', $version, $supported));
    }
}
