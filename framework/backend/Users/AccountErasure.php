<?php

declare(strict_types=1);

namespace Hilos\Users;

use Hilos\Files\HilosFiles;

/**
 * AccountErasure - what a project erased of one person when their account went (HIL-302).
 *
 * The answer of the project's half of the erasure: the rows it deleted, per family it names -
 * in a chat, the messages and the events about the person - and the registry files those rows
 * pointed at, named by id (HIL-144). The rows are gone when this is built, inside the erasure's
 * transaction; the files are NOT, because a file removed from disk cannot come back if the
 * transaction then rolls back. Once the rows are committed, the session holder asks the files
 * library to remove them ({@see HilosFiles::remove()}); nothing is deleted past the registry.
 * This answer describes one account. Erasing a person folds the answers for all accounts
 * in that person's merge circle together (HIL-1200).
 */
final readonly class AccountErasure
{
    /**
     * @param array<string, int> $rowsErased Rows deleted, per family the project names
     * @param list<int> $fileIds Ids of the registry files the deleted rows pointed at
     */
    public function __construct(
        public array $rowsErased,
        public array $fileIds,
    ) {
    }

    /**
     * Combines the project's answers for two erased accounts.
     *
     * @param AccountErasure $other Another account's answer
     * @return self Sum of each row family and all registry files in erasure order
     */
    public function plus(AccountErasure $other): self
    {
        $rowsErased = $this->rowsErased;
        foreach ($other->rowsErased as $family => $count) {
            $rowsErased[$family] = ($rowsErased[$family] ?? 0) + $count;
        }

        return new self($rowsErased, [...$this->fileIds, ...$other->fileIds]);
    }
}
