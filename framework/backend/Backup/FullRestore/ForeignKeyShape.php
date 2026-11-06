<?php

declare(strict_types=1);

namespace Hilos\Backup\FullRestore;

final readonly class ForeignKeyShape
{
    /**
     * @param list<string> $columns Ordered child columns
     * @param TableName $parent Physical parent, preserving case-sensitive table names
     * @param list<string> $parentColumns Ordered parent columns
     * @param string $deleteRule Effective ON DELETE rule
     * @param string $updateRule Effective ON UPDATE rule
     */
    public function __construct(
        public array $columns,
        public TableName $parent,
        public array $parentColumns,
        public string $deleteRule,
        public string $updateRule,
    ) {
    }
}
