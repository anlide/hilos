<?php

declare(strict_types=1);

namespace Hilos\Core\Table\Exception;

/**
 * Exception: a second bulk run was asked for on a table that already has one going.
 *
 * One place holds one progress bar, so a second run would take the bar of the first and leave
 * it working blind. The answer is a refusal and not a queue: a person whose button did nothing
 * presses it again, while a person whose action went into an invisible queue is promised work
 * they will never be told about.
 *
 * It refuses through {@see TableActionException}, and that is the whole reason it has a class:
 * the sentence is addressed to the person who pressed the button and has to reach them, and
 * only the ValidationException family crosses the wire with its own words.
 */
final class TableBulkRunBusyException extends TableActionException
{
    /** What the person who pressed the button is told. */
    public const string REASON = 'A bulk action is already running here';

    /**
     * Refuses a bulk action over a table that is already running one.
     *
     * @param string $tableKey Table that is already busy, named in the log rather than to the client
     */
    public function __construct(public readonly string $tableKey)
    {
        parent::__construct(self::REASON);
    }
}
