<?php

declare(strict_types=1);

namespace Hilos\Cluster\Placement;

use Hilos\Core\EventLoop\EventLoopTimer;

/** Exact local request incarnation; equal due times do not identify the same request. */
final class PlacementTimer
{
    public ?EventLoopTimer $handle = null;

    /** @param float $at Original absolute monotonic deadline */
    public function __construct(public readonly float $at)
    {
    }
}
