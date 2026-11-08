<?php

declare(strict_types=1);

namespace Hilos\TruthSource;

use Hilos\Runtime\RtSnapshotBasis;
use WeakReference;

/** Receipt ordering for pending confirmations of one origin in one master process. */
final class RtReplicaConfirmation
{
    public ?float $lostAt = null;

    private readonly RtSnapshotBasis $basis;

    /** @var WeakReference<RtReplicaOriginMap> Exact replica bookkeeping owner */
    private readonly WeakReference $origins;

    /** @var WeakReference<RtNodeSourceMap> Exact ownership source */
    private readonly WeakReference $sources;

    /**
     * @param RtReplicaOriginMap $origins Original replica bookkeeping owner
     * @param RtNodeSourceMap $sources Original ownership source
     */
    public function __construct(RtReplicaOriginMap $origins, RtNodeSourceMap $sources)
    {
        $this->basis = new RtSnapshotBasis();
        $this->origins = WeakReference::create($origins);
        $this->sources = WeakReference::create($sources);
    }

    /**
     * @param RtReplicaOriginMap $origins Current replica bookkeeping owner
     * @param RtNodeSourceMap $sources Current ownership source
     * @return bool Whether the original runtime, mount and owners still answer
     */
    public function isCurrent(RtReplicaOriginMap $origins, RtNodeSourceMap $sources): bool
    {
        return $this->basis->isCurrent() && $this->origins->get() === $origins && $this->sources->get() === $sources;
    }
}
