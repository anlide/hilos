<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

/** Whole-batch memory admission; none of these outcomes records a receive receipt. */
enum AccountMergeAdmissionResult: string
{
    case Admitted = 'admitted';
    case AlreadyCurrent = 'already-current';
    case PendingCurrent = 'pending-current';
    case Rejected = 'rejected';
}
