<?php

declare(strict_types=1);

namespace Hilos\Database\Schema;

use Hilos\Database\Entity\Item\Entity;

/**
 * One divergence between an Entity's ORM metadata and the live table schema.
 *
 * Produced by {@see EntitySchemaAudit::audit()} and
 * {@see EntitySchemaAudit::auditTableCoverage()}, and collected into a list so a
 * single audit reports every problem at once instead of failing on the first.
 */
final readonly class EntitySchemaMismatch
{
    /**
     * @param EntitySchemaAxis $axis Which kind of divergence this is
     * @param ?class-string<Entity> $entityClass Entity whose metadata was audited, or null for a finding about a table no Entity claims
     * @param string $table The Entity's _table
     * @param ?string $subject Column / index / foreign-key name, or null for table- or meta-level
     * @param string $expected What the Entity metadata declares
     * @param string $actual What the live schema shows
     */
    public function __construct(
        public EntitySchemaAxis $axis,
        public ?string $entityClass,
        public string $table,
        public ?string $subject,
        public string $expected,
        public string $actual,
    ) {
    }

    /**
     * One-line human description for a test failure message.
     *
     * @return string Format: `[axis] table.subject: expected <...>, got <...>`
     */
    public function describe(): string
    {
        $where = $this->subject !== null ? "{$this->table}.{$this->subject}" : $this->table;
        return "[{$this->axis->value}] {$where}: expected <{$this->expected}>, got <{$this->actual}>";
    }
}
