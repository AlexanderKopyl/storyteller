<?php

declare(strict_types=1);

namespace Storyteller\Engine\Exception;

/**
 * Why a choice could not be taken.
 *
 * The two cases look alike from inside the Engine and are entirely different
 * from outside it. `NotFound` is a client or content bug — the scene never
 * offered that key, so retrying the same request will never work. The caller's
 * HTTP answer is 404/422. `ConditionFailed` is a state conflict — the choice
 * exists, the player simply may not take it in this state, which is
 * legitimately racy when two turns are submitted concurrently. The answer
 * there is 409, and the player's view is stale rather than wrong.
 *
 * The backing values are the wire form: `src/Api` will map them onto status
 * codes, so renaming one is a breaking change, not a tidy-up.
 */
enum ChoiceUnavailableReason: string
{
    case NotFound = 'not_found';
    case ConditionFailed = 'condition_failed';
}
