<?php

declare(strict_types=1);

namespace Hilos\DaemonSection;

/** Comparison cells for one key across every node in the picture. */
final readonly class ClusterEnvironmentKeyComparison
{
    /** @param list<ClusterEnvironmentCell> $cells One cell per node in id order */
    public function __construct(public string $key, public array $cells)
    {
    }
}
