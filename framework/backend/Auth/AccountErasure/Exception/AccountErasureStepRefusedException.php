<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountErasure\Exception;

use Hilos\Auth\AccountErasure\AccountErasureStepRefusal;
use Hilos\Core\Exception\ValidationException;

final class AccountErasureStepRefusedException extends ValidationException
{
    /** @param AccountErasureStepRefusal $reason Domain refusal of the participant step */
    public function __construct(public readonly AccountErasureStepRefusal $reason)
    {
        parent::__construct(match ($reason) {
            AccountErasureStepRefusal::NoParticipant => 'This erasure step must be performed by its assigned participant.',
            AccountErasureStepRefusal::UnknownAssignment => 'This erasure assignment no longer exists.',
            AccountErasureStepRefusal::ForeignAssignment => 'This erasure assignment belongs to another participant.',
            AccountErasureStepRefusal::ClosedStage => 'This erasure stage is closed.',
        });
    }
}
