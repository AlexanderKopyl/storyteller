<?php

declare(strict_types=1);

namespace Storyteller\Engine\Event;

/**
 * Which of a turn's two effect phases produced a diff: the chosen choice's
 * own effects, or the target scene's `on_enter`.
 */
enum EffectPhase: string
{
    case Choice = 'choice';
    case OnEnter = 'on_enter';
}
