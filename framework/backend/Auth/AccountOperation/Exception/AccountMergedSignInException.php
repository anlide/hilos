<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation\Exception;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Core\Exception\ValidationException;

/** A session bind found that the proven account had already been folded. */
final class AccountMergedSignInException extends ValidationException
{
    public function __construct()
    {
        parent::__construct(AbstractSessionsLibraryAgent::MERGED_ACCOUNT_REFUSED_MESSAGE);
    }
}
