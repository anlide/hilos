<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation\Exception;

use Hilos\Core\Exception\ValidationException;

/** The receiving owner is still waiting for the current transferred set in memory. */
final class AccountMergeReceiveWaitsException extends ValidationException
{
    /** Creates the safe, nonsecret wait reason. */
    public function __construct()
    {
        parent::__construct('The account merge is waiting for its current transferred rows.');
    }
}
