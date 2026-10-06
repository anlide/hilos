<?php

declare(strict_types=1);

namespace Hilos\Database\Schema;

use Hilos\Backup\Anonymization\LiveTableSchema;
use Hilos\Backup\Anonymization\PiiRegistry;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Exception\UnplacedJournalColumnException;

/** Places every column of a journaled table using its Entity and the live SQL schema. */
final class JournalColumnPolicy
{
    private const array BINARY_TYPES = ['binary', 'varbinary', 'blob', 'tinyblob', 'mediumblob', 'longblob', 'bit'];

    /**
     * @param class-string<Entity> $entityClass Mounted Entity declaring this table
     * @param LiveTableSchema $schema The live table, including DB-only columns
     * @param PiiRegistry $pii Normalized personal-data verdicts
     * @return array<string, JournalColumnPlacement> Placement by live column, in schema order
     * @throws UnplacedJournalColumnException When a column or declaration cannot be placed safely
     * @throws InvalidArgumentException When a placement cannot carry its reason
     */
    public static function forTable(string $entityClass, LiveTableSchema $schema, PiiRegistry $pii): array
    {
        $table = $schema->table;
        if ($entityClass::_table !== $table) {
            throw new UnplacedJournalColumnException([
                "{$table}._table: {$entityClass} declares a different table",
            ]);
        }

        $index = DatabaseConnectionDefaults::PRIMARY_INDEX;
        $personal = $pii->strategiesFor($index, $table);
        $notPersonal = $pii->notPersonalColumns($index, $table);
        $purged = $pii->isPurged($index, $table);
        $secrets = $entityClass::_journalSecrets;
        $noise = $entityClass::_journalNoise;
        $problems = [];

        if ($personal === null) {
            $problems[] = "{$table}._pii: no personal-data verdict";
        }

        foreach ($secrets as $column) {
            if (!is_string($column)) {
                $problems[] = "{$table}._journalSecrets: column name is not a string";
                continue;
            }
            if (!$schema->hasColumn($column)) {
                $problems[] = "{$table}.{$column}: secret declaration names no live column";
            }
        }
        foreach ($noise as $column => $reason) {
            if (!is_string($column) || !$schema->hasColumn($column)) {
                $problems[] = "{$table}.{$column}: noise declaration names no live column";
            }
            if (!is_string($reason) || trim($reason) === '') {
                $problems[] = "{$table}.{$column}: noise reason is empty";
            }
            if (in_array($column, $secrets, true)) {
                $problems[] = "{$table}.{$column}: both secret and noise";
            }
        }

        if ($schema->primaryKey === []) {
            $problems[] = "{$table}._primary: live table has no primary key";
        }

        $placements = [];
        foreach (array_keys($schema->columns) as $column) {
            $isSecret = in_array($column, $secrets, true);
            $isNoise = array_key_exists($column, $noise);
            $isPersonal = isset($personal[$column]);
            if (in_array($column, $schema->primaryKey, true)) {
                if ($isSecret || $isNoise || $isPersonal) {
                    $problems[] = "{$table}.{$column}: record key is personal, secret, or noise";
                    continue;
                }
                $placements[$column] = new JournalColumnPlacement(JournalColumnMode::RECORD_KEY);
            } elseif ($isSecret) {
                $placements[$column] = new JournalColumnPlacement(JournalColumnMode::SECRET);
            } elseif ($isNoise) {
                if (is_string($noise[$column]) && trim($noise[$column]) !== '') {
                    $placements[$column] = new JournalColumnPlacement(JournalColumnMode::NOISE, $noise[$column]);
                }
            } elseif ($purged || $isPersonal) {
                $placements[$column] = new JournalColumnPlacement(JournalColumnMode::PERSONAL);
            } elseif (in_array($column, $notPersonal ?? [], true)) {
                $mode = in_array($schema->typeOf($column), self::BINARY_TYPES, true)
                    ? JournalColumnMode::BINARY : JournalColumnMode::VALUE;
                $placements[$column] = new JournalColumnPlacement($mode);
            } else {
                $problems[] = "{$table}.{$column}: no personal-data verdict or journal placement";
            }
        }

        if ($problems !== []) {
            throw new UnplacedJournalColumnException($problems);
        }

        return $placements;
    }
}
