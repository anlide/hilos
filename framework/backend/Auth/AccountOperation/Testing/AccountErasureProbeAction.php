<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation\Testing;

/** The closed vocabulary of the worker-local erasure test control. */
enum AccountErasureProbeAction: string
{
    case Arm = 'arm';
    case Inspect = 'inspect';
    case Release = 'release';
    case Discard = 'discard';
    case Replay = 'replay';
    case ExpireIdle = 'expire-idle';
    case Clear = 'clear';
}
