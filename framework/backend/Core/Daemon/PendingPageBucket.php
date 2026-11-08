<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

/** One original page lifetime, invalidated without visiting its pending payloads. */
final class PendingPageBucket
{
    public bool $active = true;

    public readonly SignalHoldIndex $identities;

    /** Starts a local page cohort with no pending attempts. */
    public function __construct()
    {
        $this->identities = new SignalHoldIndex();
    }
}
