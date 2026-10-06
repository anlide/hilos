<?php

declare(strict_types=1);

namespace Hilos\Database\Exception;

use Hilos\HilosException;

/** A journaled table has live columns the Entity cannot place safely. */
final class UnplacedJournalColumnException extends HilosException
{
    /**
     * @param list<string> $problems Table and column names with their reasons
     */
    public function __construct(public readonly array $problems)
    {
        parent::__construct('Journal column placement refused: ' . implode('; ', $problems));
    }
}
