<?php

declare(strict_types=1);

namespace Hilos\Backup\Anonymization;

/**
 * RestrictingForeignKey - one incoming foreign key that forbids deleting a parent row.
 *
 * Read out of `information_schema` by {@see LiveSchemaReader} and carried by
 * {@see LiveTableSchema} for the one question the compatibility gate asks of it: whether a
 * table declared {@see AnonymizationStrategy::PURGE} may be emptied at all. `RESTRICT` and
 * `NO ACTION` are the two rules that answer no - InnoDB checks both immediately - and they
 * are the only ones read, so a value of this class always means a refusal.
 *
 * Every field is here because the refusal prints it: the developer who declared the purge
 * closes the finding by looking at the named key, and a name is what turns a complaint
 * into one edit.
 */
final class RestrictingForeignKey
{
    /**
     * @param string $constraint Key name, as `information_schema` spells it
     * @param string $childTable Table holding the key; qualified with its schema
     *     (`schema.table`) only when the key comes from another database of the same server
     * @param list<string> $childColumns Columns of the child table, in `ORDINAL_POSITION`
     *     order
     * @param string $deleteRule `RESTRICT` or `NO ACTION`, exactly as `information_schema`
     *     writes it
     */
    public function __construct(
        public readonly string $constraint,
        public readonly string $childTable,
        public readonly array $childColumns,
        public readonly string $deleteRule,
    ) {
    }
}
