<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\HilosException;

/**
 * One owner of personal rows the account erasure has to reach: the collections it covers and
 * what it is expected to carry for one account of the erasure circle.
 * SCAFFOLD: {@see expectationsFor()} has no live caller until the erasure coordinator opens its operation
 * (HIL-1952); the startup coverage gate reads the key and the collections.
 */
interface AccountErasureParticipant
{
    /** @return string Participant key, unique in its catalog */
    public function key(): string;

    /** @return list<string> Collection keys of HilosDbContext or the project context the participant covers */
    public function collections(): array;

    /**
     * Asked only for a participant one of whose collections is activated.
     *
     * @param int $userId Account of the erasure circle
     * @return list<AccountOperationExpectation> Expected owners and waits for the account, possibly none
     * @throws HilosException When the participant cannot read its rows or name its owner
     */
    public function expectationsFor(int $userId): array;
}
