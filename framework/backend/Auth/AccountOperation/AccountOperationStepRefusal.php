<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

enum AccountOperationStepRefusal: string
{
    case NoParticipant = 'no_participant';
    case UnknownAssignment = 'unknown_assignment';
    case ForeignAssignment = 'foreign_assignment';
    case ClosedStage = 'closed_stage';
}
