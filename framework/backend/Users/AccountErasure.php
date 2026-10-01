<?php

declare(strict_types=1);

namespace Hilos\Users;

/**
 * AccountErasure - what a project erased of one person when their account went (HIL-302).
 *
 * The answer of the project's half of the erasure: the rows it deleted, per family it names -
 * in a chat, the messages and the events about the person - and the files those rows pointed
 * at. The rows are gone when this is built, inside the erasure's transaction; the files are
 * NOT, because a file removed from disk cannot come back if the transaction then rolls back.
 * The session holder removes them once the rows are committed.
 * This answer describes one account. Erasing a person folds the answers for all accounts
 * in that person's merge circle together (HIL-1200).
 */
final readonly class AccountErasure
{
    /**
     * @param array<string, int> $rowsErased Rows deleted, per family the project names
     * @param list<string> $publishedFiles Names in the files directory the deleted rows pointed at
     */
    public function __construct(
        public array $rowsErased,
        public array $publishedFiles,
    ) {
    }

    /**
     * Combines the project's answers for two erased accounts.
     *
     * @param AccountErasure $other Another account's answer
     * @return self Sum of each row family and all published files in erasure order
     */
    public function plus(AccountErasure $other): self
    {
        $rowsErased = $this->rowsErased;
        foreach ($other->rowsErased as $family => $count) {
            $rowsErased[$family] = ($rowsErased[$family] ?? 0) + $count;
        }

        return new self($rowsErased, [...$this->publishedFiles, ...$other->publishedFiles]);
    }
}
