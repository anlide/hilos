<?php

declare(strict_types=1);

namespace Hilos\Runtime\Snapshot;

/**
 * @internal One preparation lineage; unrelated DB scopes cannot overwrite its base.
 * SCAFFOLD: process-local admission of the isolated store, not application write admission.
 */
final class RtVersionClaim
{
    private int $holders = 0;

    /**
     * @param RtVersionStore $store Store whose generation is reserved
     */
    public function __construct(private readonly RtVersionStore $store)
    {
    }

    /** Adds one fixed-size batch/participant holder. */
    public function retain(): void
    {
        ++$this->holders;
    }

    /** Clears only this lineage when its last preparation or participant closes. */
    public function release(): void
    {
        if (--$this->holders === 0) {
            $this->store->releaseClaim($this);
        }
    }
}
