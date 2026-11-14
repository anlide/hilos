<?php

declare(strict_types=1);

namespace Hilos\Runtime\Snapshot;

use Hilos\Runtime\Row\RtRowRole;

/**
 * @internal A prepared Runtime participant, installed before held announcements.
 * Codecs, validation and page construction finish before install()/rollback().
 * Dropped roots enqueue fixed-size releases; physical work stays in retirement.
 * The source's ordinary FIFO is not a participant: its doors settle their reservation themselves.
 */
final class RtVersionTransaction
{
    private bool $closed = false;

    /**
     * @param RtVersionStore $store Selector to install
     * @param RtVersionDescriptor $version Already prepared installation lease and revision prefix
     * @param RtVersionClaim $claim Admitted preparation lineage
     */
    public function __construct(
        public readonly RtVersionStore $store,
        public readonly RtVersionDescriptor $version,
        public readonly RtVersionClaim $claim,
    ) {
        $claim->retain();
    }

    /** Replaced participants close their own leases; their successors keep the lineage. */
    public function __destruct()
    {
        $this->rollback();
    }

    /**
     * @internal Called only after SQL commit succeeds, before any observer.
     * Preparation has already established all preconditions; this cannot refuse.
     */
    public function install(): void
    {
        if (!$this->closed) {
            $this->closed = true;
            $this->version->root->reclassify(RtRowRole::Resident);
            $this->store->installPrepared($this->version);
            $this->claim->release();
        }
    }

    /** Drops only this participant's prepared root, including failed/cleanup paths. */
    public function rollback(): void
    {
        if (!$this->closed) {
            $this->closed = true;
            $this->version->root->release();
            $this->claim->release();
        }
    }
}
