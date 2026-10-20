<?php

declare(strict_types=1);

namespace Hilos\Database\Migration;

/** Per-target outcomes for honest CLI output and a nonzero exit after any refusal. */
final readonly class FrameworkMigrationResult
{
    /** @param list<FrameworkMigrationProjectResult> $projects Infrastructure outcomes in target order */
    public function __construct(public array $projects)
    {
    }

    /** @return bool Whether preflight or execution failed for any target */
    public function hasFailures(): bool
    {
        foreach ($this->projects as $project) {
            if ($project->failure !== null) {
                return true;
            }
        }

        return false;
    }
}
