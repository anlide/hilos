<?php

declare(strict_types=1);

namespace Hilos\Runtime;

use Hilos\Hilos;
use Hilos\Runtime\View\Context\RtContext;
use WeakReference;

/** Original runtime and mount shared by the sources a hand-over discovers over several portions. */
final readonly class RtSnapshotBasis
{
    /** @var ?WeakReference<RtContext> Original runtime; an expired reference differs from original absence */
    private ?WeakReference $runtime;

    private ?int $mountRevision;

    /** Capture the runtime and registry incarnation without enumerating a source. */
    public function __construct()
    {
        $this->runtime = Hilos::$rt === null ? null : WeakReference::create(Hilos::$rt);
        $this->mountRevision = Hilos::$rt?->snapshotMountRevision();
    }

    /** @return bool Whether the original runtime and registry incarnation still answer */
    public function isCurrent(): bool
    {
        return $this->runtime === null ? Hilos::$rt === null
            : $this->runtime->get() !== null && $this->runtime->get() === Hilos::$rt
                && Hilos::$rt->snapshotMountRevision() === $this->mountRevision;
    }
}
