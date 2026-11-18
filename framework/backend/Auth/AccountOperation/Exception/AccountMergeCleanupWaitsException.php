<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation\Exception;

use Hilos\Core\Exception\ValidationException;

/** The source cannot remove its private copy before the survivor's committed receipt. */
final class AccountMergeCleanupWaitsException extends ValidationException
{
    /** Creates the safe, nonsecret wait reason. */
    public function __construct()
    {
        parent::__construct('The account merge is waiting for its survivor receipt.');
    }
}
