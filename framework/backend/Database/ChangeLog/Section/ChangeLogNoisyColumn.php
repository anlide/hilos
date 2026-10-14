<?php

declare(strict_types=1);

namespace Hilos\Database\ChangeLog\Section;

/** One live journaled column omitted for its declared noise reason. */
final readonly class ChangeLogNoisyColumn
{
    public const string table = 'table';
    public const string column = 'column';
    public const string reason = 'reason';

    /**
     * @param string $table Live journaled table name
     * @param string $column Noisy column name
     * @param string $reason Non-empty reason validated by the journal column policy
     */
    public function __construct(
        public string $table,
        public string $column,
        public string $reason,
    ) {
    }
}
