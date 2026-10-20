<?php

declare(strict_types=1);

namespace Hilos\Database\Migration;

/** Call-local source plan; execution rechecks the track under its lock before using these intents. */
final readonly class FrameworkMigrationPlan
{
    /**
     * @param FrameworkMigrationProject $project Explicit target
     * @param list<FrameworkMigrationPublication> $publications Ordered infrastructure source operations
     */
    public function __construct(public FrameworkMigrationProject $project, public array $publications)
    {
    }
}
