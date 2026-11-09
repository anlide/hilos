<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountErasure;

enum AccountErasureStepRefusal: string
{
    case NoParticipant = 'no_participant';
    case UnknownAssignment = 'unknown_assignment';
    case ForeignAssignment = 'foreign_assignment';
    case ClosedStage = 'closed_stage';
}
