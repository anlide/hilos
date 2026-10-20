<?php

declare(strict_types=1);

namespace Hilos\Database\Migration;

use Throwable;

/** What this call actually published; a failed target can still have published one or more files. */
final readonly class FrameworkMigrationProjectResult
{
    /**
     * @param FrameworkMigrationProject $project Target of this outcome
     * @param list<string> $publishedPaths Files created by this call, including a completed first half
     * @param ?Throwable $failure Refusal or I/O failure, absent on completion
     * @param bool $attempted Whether execution reached this target after the batch preflight
     */
    public function __construct(
        public FrameworkMigrationProject $project,
        public array $publishedPaths = [],
        public ?Throwable $failure = null,
        public bool $attempted = false,
    ) {
    }
}
