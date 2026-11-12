<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\ValueTooLongException;
use Hilos\Hilos;
use Hilos\HilosException;

/**
 * A project's owner from its {@see AccountDataOwnerDirectory}, taken into the account erasure.
 *
 * Expected for every account once its agent type is registered with the indexing the owner
 * declares and the registered class erases its part ({@see AccountErasureProjectOwner}), rows or
 * not, because only the owner knows what it holds. Otherwise the coordinator waits for an
 * account that still has rows and expects nothing for one that has none.
 * SCAFFOLD: {@see expectationsFor()} has no live caller until the erasure coordinator opens its operation
 * (HIL-1952); the startup coverage gate reads the key and the collections.
 */
final readonly class AccountErasureProjectParticipant implements AccountErasureParticipant
{
    /**
     * @param AccountDataOwner $owner Owner as the project declared it
     */
    public function __construct(private AccountDataOwner $owner)
    {
    }

    /** @return string Participant key, the owner's key */
    public function key(): string
    {
        return $this->owner->key;
    }

    /** @return list<string> Collection keys of the project context the owner covers */
    public function collections(): array
    {
        return $this->owner->collections;
    }

    /**
     * @param int $userId Account of the erasure circle
     * @return list<AccountErasureExpectation> The owner, a wait for remaining rows, or nothing
     * @throws EmptyValueException When the owner type is empty
     * @throws ValueTooLongException When the owner type or the account id exceeds the address limit
     * @throws HilosException When the row question fails
     */
    public function expectationsFor(int $userId): array
    {
        if ($this->isOwnerRegistered()) {
            return [new AccountErasureExpectation(
                $this->owner->key,
                $userId,
                new AccountOperationOwnerAddress($this->owner->ownerType, $this->owner->perPerson ? (string)$userId : null),
            )];
        }
        if (($this->owner->hasRowsOf)($userId)) {
            return [new AccountErasureExpectation($this->owner->key, $userId, null)];
        }

        return [];
    }

    /** @return bool Whether the application registers the owner type with its indexing and a class that erases its part */
    private function isOwnerRegistered(): bool
    {
        $entry = Hilos::appClass()::AGENTS[$this->owner->ownerType] ?? null;
        if ($entry === null || AgentRegistry::requiresIndex($entry) !== $this->owner->perPerson) {
            return false;
        }
        $worker = AgentRegistry::workerClass($entry);

        return $worker !== null && is_a($worker, AccountErasureProjectOwner::class, true);
    }
}
