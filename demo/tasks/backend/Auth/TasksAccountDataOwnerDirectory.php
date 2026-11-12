<?php

declare(strict_types=1);

namespace Demo\Tasks\Auth;

use Demo\Tasks\Database\TasksDbContext;
use Hilos\Auth\AccountOperation\AccountDataOwnerDirectory;
use Hilos\Auth\AccountOperation\AccountErasureExemption;

/**
 * TasksAccountDataOwnerDirectory - whose the tasks demo's own collections are.
 *
 * The demo owns no personal rows of its own: its one collection, the guest names, belongs to
 * sessions without an account and has no column pointing at a person, so the erasure leaves it
 * alone.
 */
final class TasksAccountDataOwnerDirectory extends AccountDataOwnerDirectory
{
    /** @return array<string, AccountErasureExemption> The guest names, held apart from any account */
    protected static function exempt(): array
    {
        return [TasksDbContext::guests => AccountErasureExemption::OutsideAccount];
    }
}
