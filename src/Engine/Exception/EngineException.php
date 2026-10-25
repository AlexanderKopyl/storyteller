<?php

declare(strict_types=1);

namespace Storyteller\Engine\Exception;

/**
 * The root of every error the Engine can raise.
 *
 * It extends `\RuntimeException` and nothing else: the Engine owns its error
 * family outright (OQ-4) so that `src/Engine` keeps zero outgoing dependencies
 * and a caller in Play or Content can catch this one type to catch the lot.
 */
abstract class EngineException extends \RuntimeException
{
}
