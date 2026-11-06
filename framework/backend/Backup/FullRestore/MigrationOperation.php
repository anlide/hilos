<?php

declare(strict_types=1);

namespace Hilos\Backup\FullRestore;

final readonly class MigrationOperation
{
    /**
     * @param MigrationOperationKind $kind Admitted table/data/trigger operation, no effects, or opaque execution
     * @param string $sql Exact SQL sent by the common migration reader
     * @param ?TableName $table Resolved target, when known
     * @param ?TableName $renamedTo Resolved new name for a table rename
     * @param ?DumpObject $definition Admitted direct CREATE definition; never LIKE or AS SELECT
     * @param bool $ifNotExists Whether the direct CREATE is conditional
     * @param list<list<SqlToken>> $clauses Ordered ALTER clauses for structural replay by the planner
     * @param list<TableName> $references Explicit foreign-key references or every direct DML read/write table
     * @param ?TableName $trigger Physical trigger name, separate from its table owner
     * @param bool $ifExists Whether a trigger drop admits an absent trigger
     * @param list<FunctionCall> $calls Scalar calls within admitted data expressions
     */
    public function __construct(
        public MigrationOperationKind $kind,
        public string $sql,
        public ?TableName $table = null,
        public ?TableName $renamedTo = null,
        public ?DumpObject $definition = null,
        public bool $ifNotExists = false,
        public array $clauses = [],
        public array $references = [],
        public ?TableName $trigger = null,
        public bool $ifExists = false,
        public array $calls = [],
    ) {
    }

    /**
     * This is source evidence only; applied history and live structure must still be established.
     *
     * @return bool Whether this direct CREATE may be considered for table provenance
     */
    public function isProvenanceSource(): bool
    {
        return $this->kind === MigrationOperationKind::CREATE_TABLE && $this->table !== null && !$this->table->isBootstrap();
    }
}
