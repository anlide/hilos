<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Closure;

/**
 * Who owns which collections of a project: one agent type, per person or one for everybody.
 *
 * The record says only whose the rows are; it carries no erasure and no merge of its own. A
 * project declares it in its {@see AccountDataOwnerDirectory}, and each account operation reads
 * from it what it needs to know about the owner.
 * SCAFFOLD: `$perPerson` and `$hasRowsOf` have no live caller until the erasure coordinator opens its operation
 * (HIL-1952); the startup coverage gate reads the rest.
 */
final readonly class AccountDataOwner
{
    /**
     * @param string $key Owner key, unique in the project's directory
     * @param list<string> $collections Collection keys of the project context the owner covers
     * @param string $ownerType Agent type that owns the rows
     * @param bool $perPerson Whether the owner is indexed by the account id rather than a single agent
     * @param Closure(int): bool $hasRowsOf Whether an account still has rows here
     */
    public function __construct(
        public string $key,
        public array $collections,
        public string $ownerType,
        public bool $perPerson,
        public Closure $hasRowsOf,
    ) {
    }
}
