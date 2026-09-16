<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Database\Context\DbContext;
use Hilos\HilosException;

/**
 * The account block fact the framework reads over the project's DB users.
 *
 * A project binds its own users collection as the block source by implementing this
 * interface; the framework reads whether an account is blocked through it and never imports
 * the project's collection type. This is the database twin of the runtime presence source:
 * presence is live and dies with the socket, a block is an administrator's decision that
 * survives a restart, so the source is a DB collection rather than a runtime one.
 *
 * The collection implementing it must be among the project's process-wide reads
 * ({@see DbContext::processWideReadKeys()}). The framework asks whether an account is blocked
 * in whatever process happens to run the guard, and the read guard refuses a collection that
 * process does not read; a collection left out of that list would be refused in every worker
 * that runs no page and no agent of its own - or, read past the guard, answer a false that
 * stops being true at the next write.
 */
interface HilosUserBlockSource
{
    /**
     * Reports the block flag of each requested account.
     *
     * @param list<int> $userIds User ids to report on
     * @return array<int, bool> Block flag per requested id; every requested id present, a row that is gone answers false
     * @throws HilosException When the block source cannot read its database state
     */
    public function blockedAmong(array $userIds): array;
}
