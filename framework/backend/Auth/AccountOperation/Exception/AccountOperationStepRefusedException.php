<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation\Exception;

use Hilos\Auth\AccountOperation\AccountOperationStepRefusal;
use Hilos\Core\Exception\ValidationException;

final class AccountOperationStepRefusedException extends ValidationException
{
    /** @param AccountOperationStepRefusal $reason Domain refusal of the participant step */
    public function __construct(public readonly AccountOperationStepRefusal $reason)
    {
        parent::__construct(match ($reason) {
            AccountOperationStepRefusal::NoParticipant => 'This account operation step must be performed by its assigned participant.',
            AccountOperationStepRefusal::UnknownAssignment => 'This account operation assignment no longer exists.',
            AccountOperationStepRefusal::ForeignAssignment => 'This account operation assignment belongs to another participant.',
            AccountOperationStepRefusal::ClosedStage => 'This account operation stage is closed.',
        });
    }
}
