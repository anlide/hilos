<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation\Exception;

use Hilos\Core\Exception\ValidationException;

/** The giving owner is still waiting for the receiving account to accept its assignment. */
final class AccountMergeGiveWaitsException extends ValidationException
{
    /** Creates the safe, nonsecret wait reason. */
    public function __construct()
    {
        parent::__construct('The account merge is waiting for the receiving account to accept its assignment.');
    }
}
