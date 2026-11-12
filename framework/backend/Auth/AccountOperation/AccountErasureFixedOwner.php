<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Closure;
use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\ValueTooLongException;
use Hilos\Hilos;
use Hilos\HilosException;

/**
 * A participant whose rows one agent type owns: the person's own agent, or one library for everybody.
 *
 * A registered owner is expected for every account, rows or not, because only the owner knows
 * what it holds. With no owner registered, the coordinator waits for an account that still has
 * rows and expects nothing for one that has none.
 * SCAFFOLD: no live caller until the erasure coordinator opens its operation (HIL-1952).
 */
final readonly class AccountErasureFixedOwner implements AccountErasureParticipant
{
    /**
     * @param string $key Participant key
     * @param list<string> $collections Collection keys the participant covers
     * @param string $ownerType Agent type that owns the rows
     * @param bool $perPerson Whether the owner is indexed by the account id rather than a single agent
     * @param Closure(int): bool $hasRowsOf Whether an account still has rows here, asked only when no owner is registered
     */
    public function __construct(
        private string $key,
        private array $collections,
        private string $ownerType,
        private bool $perPerson,
        private Closure $hasRowsOf,
    ) {
    }

    /** @return string Participant key */
    public function key(): string
    {
        return $this->key;
    }

    /** @return list<string> Collection keys the participant covers */
    public function collections(): array
    {
        return $this->collections;
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
                $this->key,
                $userId,
                new AccountOperationOwnerAddress($this->ownerType, $this->perPerson ? (string)$userId : null),
            )];
        }
        if (($this->hasRowsOf)($userId)) {
            return [new AccountErasureExpectation($this->key, $userId, null)];
        }

        return [];
    }

    /** @return bool Whether the application registers the owner type with the indexing the participant expects */
    private function isOwnerRegistered(): bool
    {
        $entry = Hilos::appClass()::AGENTS[$this->ownerType] ?? null;

        return $entry !== null && AgentRegistry::requiresIndex($entry) === $this->perPerson;
    }
}
