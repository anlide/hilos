<?php

declare(strict_types=1);

namespace Hilos\Database\Schema;

use Hilos\Database\Entity\Item\Entity;

/** Physical moment storage axes over raw information_schema rows, without queries or debt exemptions. */
final class MomentStorageAudit
{
    /** SQL current-time spellings, including their optional fractional precision. */
    private const string CURRENT_TIME = '(?:CURRENT_TIMESTAMP|NOW|LOCALTIME|LOCALTIMESTAMP)(?:\s*\(\s*\d*\s*\))?';

    /**
     * @param ?class-string<Entity> $entityClass Owning Entity, or null for a provider table
     * @param string $table Physical table name
     * @param array<string, MomentColumn> $moments Declared physical moments keyed by column
     * @param array<string, array<string, mixed>> $liveColumns Raw information_schema.COLUMNS rows keyed by column
     * @return list<EntitySchemaMismatch> Presence, type and automatic SQL-time writer findings in declaration order
     */
    public static function audit(?string $entityClass, string $table, array $moments, array $liveColumns): array
    {
        $mismatches = [];
        foreach ($moments as $moment) {
            $column = $moment->column;
            if (!isset($liveColumns[$column])) {
                $mismatches[] = new EntitySchemaMismatch(
                    EntitySchemaAxis::MOMENT_COLUMN_MISSING,
                    $entityClass,
                    $table,
                    $column,
                    'column present',
                    'missing',
                );
                continue;
            }
            if ($moment->projectStorage !== null) {
                continue;
            }

            $mysqlType = strtolower(trim((string) $liveColumns[$column][EntitySchemaAudit::COL_TYPE]));
            $mysqlType = preg_replace('/\Abigint\s*\(\s*\d+\s*\)/', 'bigint', $mysqlType);
            if ($mysqlType !== 'bigint') {
                $mismatches[] = new EntitySchemaMismatch(
                    EntitySchemaAxis::MOMENT_TYPE,
                    $entityClass,
                    $table,
                    $column,
                    'signed BIGINT (epoch milliseconds)',
                    $mysqlType,
                );
            }

            $default = $liveColumns[$column][EntitySchemaAudit::COL_DEFAULT];
            $extra = (string) $liveColumns[$column][EntitySchemaAudit::COL_EXTRA];
            $writers = [];
            if ($default !== null && preg_match('/\A\s*(?:\(\s*)*' . self::CURRENT_TIME . '(?:\s*\))*\s*\z/i', (string) $default) === 1) {
                $writers[] = 'DEFAULT ' . $default;
            }
            if (preg_match('/\bON\s+UPDATE\s+' . self::CURRENT_TIME . '(?![\w(])/i', $extra) === 1) {
                $writers[] = 'EXTRA ' . $extra;
            }
            if ($writers !== []) {
                $mismatches[] = new EntitySchemaMismatch(
                    EntitySchemaAxis::MOMENT_AUTO_WRITE,
                    $entityClass,
                    $table,
                    $column,
                    'no SQL current-time DEFAULT or ON UPDATE',
                    implode('; ', $writers),
                );
            }
        }
        return $mismatches;
    }
}
