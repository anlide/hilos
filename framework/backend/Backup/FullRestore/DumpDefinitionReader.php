<?php

declare(strict_types=1);

namespace Hilos\Backup\FullRestore;

use Hilos\Backup\Exception\RestoreFailedException;
use Hilos\Database\DatabaseMarker;

/** Structural DDL admission, not a SQL executor or a migration analyzer. */
final class DumpDefinitionReader
{
    private const array COLUMN_TYPES = [
        'BIT', 'TINYINT', 'SMALLINT', 'MEDIUMINT', 'INT', 'INTEGER', 'BIGINT', 'BOOL', 'BOOLEAN',
        'DECIMAL', 'NUMERIC', 'FIXED', 'FLOAT', 'DOUBLE', 'REAL', 'DATE', 'DATETIME', 'TIMESTAMP', 'TIME', 'YEAR',
        'CHAR', 'VARCHAR', 'BINARY', 'VARBINARY', 'TINYBLOB', 'BLOB', 'MEDIUMBLOB', 'LONGBLOB',
        'TINYTEXT', 'TEXT', 'MEDIUMTEXT', 'LONGTEXT', 'ENUM', 'SET', 'JSON',
        'GEOMETRY', 'POINT', 'LINESTRING', 'POLYGON', 'MULTIPOINT', 'MULTILINESTRING', 'MULTIPOLYGON', 'GEOMETRYCOLLECTION',
    ];

    private const array TABLE_OPTIONS = [
        'ENGINE', 'AUTO_INCREMENT', 'CHARSET', 'COLLATE', 'COMMENT', 'ROW_FORMAT', 'KEY_BLOCK_SIZE',
        'MAX_ROWS', 'MIN_ROWS', 'AVG_ROW_LENGTH', 'PACK_KEYS', 'CHECKSUM', 'DELAY_KEY_WRITE',
        'STATS_AUTO_RECALC', 'STATS_PERSISTENT', 'STATS_SAMPLE_PAGES', 'PAGE_CHECKSUM', 'TRANSACTIONAL',
    ];

    private const array EXPRESSION_FUNCTIONS = [
        'COALESCE', 'IFNULL', 'NULLIF', 'IF', 'ABS', 'CAST', 'CONVERT', 'CONCAT', 'CONCAT_WS', 'LOWER', 'UPPER',
        'LENGTH', 'CHAR_LENGTH', 'OCTET_LENGTH', 'SUBSTRING', 'SUBSTR', 'JSON_VALID', 'JSON_EXTRACT', 'JSON_UNQUOTE',
        'JSON_VALUE', 'YEAR', 'MONTH', 'DAY', 'DATE', 'TO_DAYS', 'TO_SECONDS', 'UNIX_TIMESTAMP', 'FLOOR', 'CEIL', 'ROUND',
        'CURRENT_TIMESTAMP', 'NOW', 'UUID', 'UUID_SHORT',
    ];

    /**
     * @param list<SqlToken> $tokens Complete bounded CREATE statement
     * @param string $sql Original statement bytes
     * @return DumpObject Admitted table or view and its structural dependencies
     * @throws RestoreFailedException When DDL is incomplete or outside the supported dump grammar
     */
    public static function read(array $tokens, string $sql): DumpObject
    {
        $cursor = new SqlTokens($tokens);
        $cursor->expect('CREATE');
        if (!$cursor->takeIf('TABLE')) {
            return self::view($cursor, $sql);
        }
        if ($cursor->takeIf('IF')) {
            $cursor->expect('NOT');
            $cursor->expect('EXISTS');
        }
        $name = $cursor->name();
        $columns = [];
        $foreignKeys = [];
        foreach (SqlTokens::split($cursor->group()) as $definition) {
            $part = new SqlTokens($definition);
            $constraint = null;
            if ($part->takeIf('CONSTRAINT')) {
                if (!$part->is('FOREIGN') && !$part->is('CHECK') && !$part->is('UNIQUE')) {
                    $constraint = $part->name();
                }
            }
            if ($part->is('FOREIGN')) {
                $foreignKeys[] = self::foreignKey($part, $constraint);
            } elseif ($part->is('CHECK')) {
                self::check($part);
            } elseif ($part->is('PRIMARY') || $part->is('UNIQUE') || $part->is('KEY') || $part->is('INDEX')
                || $part->is('FULLTEXT') || $part->is('SPATIAL')) {
                self::index($part);
            } else {
                if ($constraint !== null) {
                    throw new RestoreFailedException('Unsupported named table constraint');
                }
                $column = $part->name();
                if (in_array($column, $columns, true)) {
                    throw new RestoreFailedException("Unsupported or contradictory column definition in {$name}");
                }
                $columns[] = $column;
                self::column($part, $name, $column);
            }
        }
        if ($columns === []) {
            throw new RestoreFailedException("Incomplete table definition for {$name}");
        }
        self::tableOptions($cursor);

        return new DumpObject($name, DumpObjectType::TABLE, $sql, $columns, $foreignKeys);
    }

    /**
     * @param SqlTokens $cursor Column definition after its name
     * @param string $table Declaring table
     * @param string $column Declared column name
     * @throws RestoreFailedException When dump syntax is incomplete, unsupported or contradictory
     */
    private static function column(SqlTokens $cursor, string $table, string $column): void
    {
        $type = $cursor->take();
        if ($type->kind !== SqlToken::WORD || !in_array(strtoupper($type->value), self::COLUMN_TYPES, true)) {
            throw new RestoreFailedException('Unsupported dump column type');
        }
        if ($table === MigrationHistory::TABLE && !in_array(strtoupper($type->value), ['INT', 'INTEGER', 'TINYINT'], true)) {
            throw new RestoreFailedException('Unsupported migration column type');
        }
        if ($table === DatabaseMarker::TABLE && !in_array(strtoupper($type->value), match ($column) {
            'id' => ['TINYINT'], 'marker' => ['CHAR'], 'written_by' => ['VARCHAR'],
            'written_at' => ['DATETIME', 'BIGINT'], default => [],
        }, true)) {
            throw new RestoreFailedException('Unsupported database marker column type');
        }
        if ($cursor->is('(')) {
            foreach (SqlTokens::split($cursor->group()) as $argument) {
                if (count($argument) !== 1 || ($argument[0]->kind !== SqlToken::STRING
                    && ($argument[0]->kind !== SqlToken::WORD || preg_match('/^\d+$/D', $argument[0]->value) !== 1))) {
                    throw new RestoreFailedException('Unsupported dump column type argument');
                }
            }
        }
        if ($type->is('DOUBLE')) {
            $cursor->takeIf('PRECISION');
        }
        while (!$cursor->ended()) {
            if ($table === DatabaseMarker::TABLE && $column === 'written_at'
                && ($cursor->is('UNSIGNED') || $cursor->is('ZEROFILL'))) {
                throw new RestoreFailedException('Database marker moment must be signed');
            }
            if ($cursor->takeIf('UNSIGNED') || $cursor->takeIf('SIGNED') || $cursor->takeIf('ZEROFILL')
                || $cursor->takeIf('NULL') || $cursor->takeIf('AUTO_INCREMENT') || $cursor->takeIf('BINARY')
                || $cursor->takeIf('INVISIBLE') || $cursor->takeIf('VISIBLE')) {
                continue;
            }
            if ($cursor->takeIf('NOT')) {
                $cursor->expect('NULL');
            } elseif ($cursor->takeIf('CHARACTER')) {
                $cursor->expect('SET');
                $cursor->name();
            } elseif ($cursor->takeIf('COLLATE') || $cursor->takeIf('CHARSET')) {
                $cursor->name();
            } elseif ($cursor->takeIf('DEFAULT')) {
                self::defaultValue($cursor);
            } elseif ($cursor->takeIf('ON')) {
                $cursor->expect('UPDATE');
                self::timestamp($cursor);
            } elseif ($cursor->takeIf('COMMENT')) {
                if ($cursor->take()->kind !== SqlToken::STRING) {
                    throw new RestoreFailedException('Unsupported dump column comment');
                }
            } elseif ($cursor->takeIf('PRIMARY')) {
                $cursor->expect('KEY');
            } elseif ($cursor->takeIf('UNIQUE')) {
                $cursor->takeIf('KEY');
            } elseif ($cursor->is('GENERATED') || $cursor->is('AS')) {
                if ($cursor->takeIf('GENERATED')) {
                    $cursor->expect('ALWAYS');
                }
                $cursor->expect('AS');
                if ($table === DatabaseMarker::TABLE || $table === MigrationHistory::TABLE) {
                    throw new RestoreFailedException('Unsupported generated service-table column');
                }
                self::expression($cursor->group());
                if (!$cursor->takeIf('VIRTUAL') && !$cursor->takeIf('STORED')) {
                    $cursor->takeIf('PERSISTENT');
                }
            } elseif ($cursor->is('CHECK')) {
                self::check($cursor, false);
            } else {
                throw new RestoreFailedException('Unsupported dump column attribute');
            }
        }
    }

    /**
     * @param SqlTokens $cursor Column definition after DEFAULT
     * @throws RestoreFailedException When dump syntax is incomplete, unsupported or contradictory
     */
    private static function defaultValue(SqlTokens $cursor): void
    {
        if ($cursor->is('(')) {
            self::expression($cursor->group());
            return;
        }
        if ($cursor->is('CURRENT_TIMESTAMP')) {
            self::timestamp($cursor);
            return;
        }
        $cursor->takeIf('-');
        $value = $cursor->take();
        if ($value->kind !== SqlToken::STRING && !$value->is('NULL') && !$value->is('TRUE') && !$value->is('FALSE')
            && ($value->kind !== SqlToken::WORD || preg_match('/^(?:\d+(?:e[+-]?\d+)?|0x[0-9a-f]+)$/iD', $value->value) !== 1)) {
            if (($value->is('B') || $value->is('X') || str_starts_with($value->value, '_'))
                && $value->isIntroducerFor($cursor->take())) {
                return;
            }
            throw new RestoreFailedException('Unsupported dump default expression');
        }
        if ($cursor->takeIf('.')) {
            if (preg_match('/^\d+(?:e\d+)?$/iD', $cursor->take()->value) !== 1) {
                throw new RestoreFailedException('Unsupported dump decimal default');
            }
        }
    }

    /**
     * @param SqlTokens $cursor Timestamp expression with optional precision
     * @throws RestoreFailedException When dump syntax is incomplete, unsupported or contradictory
     */
    private static function timestamp(SqlTokens $cursor): void
    {
        $cursor->expect('CURRENT_TIMESTAMP');
        if ($cursor->takeIf('(')) {
            if (!$cursor->is(')') && preg_match('/^\d+$/D', $cursor->take()->value) !== 1) {
                throw new RestoreFailedException('Unsupported dump timestamp precision');
            }
            $cursor->expect(')');
        }
    }

    /**
     * @param SqlTokens $cursor Complete table index definition
     * @throws RestoreFailedException When dump syntax is incomplete, unsupported or contradictory
     */
    private static function index(SqlTokens $cursor): void
    {
        $primary = $cursor->takeIf('PRIMARY');
        if ($primary) {
            $cursor->expect('KEY');
        } else {
            if (!$cursor->takeIf('UNIQUE') && !$cursor->takeIf('FULLTEXT')) {
                $cursor->takeIf('SPATIAL');
            }
            if (!$cursor->takeIf('KEY')) {
                $cursor->expect('INDEX');
            }
            if (!$cursor->is('(') && !$cursor->is('USING')) {
                $cursor->name();
            }
        }
        self::indexUsing($cursor);
        foreach (SqlTokens::split($cursor->group()) as $column) {
            $part = new SqlTokens($column);
            $part->name();
            if ($part->takeIf('(')) {
                if (preg_match('/^\d+$/D', $part->take()->value) !== 1) {
                    throw new RestoreFailedException('Unsupported dump index prefix');
                }
                $part->expect(')');
            }
            if (!$part->takeIf('ASC')) {
                $part->takeIf('DESC');
            }
            if (!$part->ended()) {
                throw new RestoreFailedException('Unsupported dump index column');
            }
        }
        self::indexUsing($cursor);
        while (!$cursor->ended()) {
            if ($cursor->takeIf('COMMENT')) {
                if ($cursor->take()->kind !== SqlToken::STRING) {
                    throw new RestoreFailedException('Unsupported dump index comment');
                }
            } elseif (!$cursor->takeIf('VISIBLE') && !$cursor->takeIf('INVISIBLE') && !$cursor->takeIf('IGNORED')) {
                throw new RestoreFailedException('Unsupported dump index suffix');
            }
        }
    }

    /**
     * @param SqlTokens $cursor Optional index algorithm clause
     * @throws RestoreFailedException When dump syntax is incomplete, unsupported or contradictory
     */
    private static function indexUsing(SqlTokens $cursor): void
    {
        if ($cursor->takeIf('USING') && !in_array(strtoupper($cursor->name()), ['BTREE', 'HASH', 'RTREE'], true)) {
            throw new RestoreFailedException('Unsupported dump index algorithm');
        }
    }

    /**
     * @param SqlTokens $cursor CHECK definition
     * @param bool $whole Whether no column attributes may follow
     * @throws RestoreFailedException When dump syntax is incomplete, unsupported or contradictory
     */
    private static function check(SqlTokens $cursor, bool $whole = true): void
    {
        $cursor->expect('CHECK');
        self::expression($cursor->group());
        if ($cursor->takeIf('NOT')) {
            $cursor->expect('ENFORCED');
        } else {
            $cursor->takeIf('ENFORCED');
        }
        if ($whole && !$cursor->ended()) {
            throw new RestoreFailedException('Unsupported CHECK suffix in dump');
        }
    }

    /**
     * @param list<SqlToken> $tokens Balanced expression within a DDL definition
     * @throws RestoreFailedException When dump syntax is incomplete, unsupported or contradictory
     */
    private static function expression(array $tokens): void
    {
        if ($tokens === []) {
            throw new RestoreFailedException('Incomplete dump DDL expression');
        }
        self::refuseHiddenReference($tokens);
        foreach ($tokens as $index => $token) {
            if ($token->is('SELECT') || (in_array($token->kind, [SqlToken::WORD, SqlToken::IDENTIFIER], true)
                && ($tokens[$index + 1] ?? null)?->is('(')
                && ($token->kind !== SqlToken::WORD || ($tokens[$index - 1] ?? null)?->is('.')
                    || !in_array(strtoupper($token->value), self::EXPRESSION_FUNCTIONS, true)))) {
                throw new RestoreFailedException('Unsupported function or query in dump DDL expression');
            }
        }
    }

    /**
     * @param SqlTokens $cursor CREATE statement after its first keyword
     * @param string $sql Original statement bytes
     * @return DumpObject Final view declaration
     * @throws RestoreFailedException When dump syntax is incomplete, unsupported or contradictory
     */
    private static function view(SqlTokens $cursor, string $sql): DumpObject
    {
        if ($cursor->takeIf('ALGORITHM')) {
            $cursor->expect('=');
            if (!in_array(strtoupper($cursor->name()), ['UNDEFINED', 'MERGE', 'TEMPTABLE'], true)) {
                throw new RestoreFailedException('Unsupported dump view algorithm');
            }
        }
        if ($cursor->takeIf('DEFINER')) {
            $cursor->expect('=');
            self::viewAccountPart($cursor->take());
            $cursor->expect('@');
            self::viewAccountPart($cursor->take());
        }
        if ($cursor->takeIf('SQL')) {
            $cursor->expect('SECURITY');
            if (!$cursor->takeIf('DEFINER')) {
                $cursor->expect('INVOKER');
            }
        }
        $cursor->expect('VIEW');
        $name = $cursor->name();
        if ($cursor->is('(')) {
            $cursor->names();
        }
        $cursor->expect('AS');
        $cursor->expect('SELECT');
        if ($cursor->ended()) {
            throw new RestoreFailedException("Incomplete view definition for {$name}");
        }
        // Keep the SELECT text for the planner. It does not declare additional objects.
        SqlTokens::split($cursor->consumeRest());
        return new DumpObject($name, DumpObjectType::VIEW, $sql, [], []);
    }

    /**
     * @param SqlToken $token One part of a view definer account
     * @throws RestoreFailedException When dump syntax is incomplete, unsupported or contradictory
     */
    private static function viewAccountPart(SqlToken $token): void
    {
        if (!$token->complete || !in_array($token->kind, [SqlToken::WORD, SqlToken::IDENTIFIER, SqlToken::STRING], true)) {
            throw new RestoreFailedException('Unsupported view definer in dump');
        }
    }

    /**
     * @param SqlTokens $cursor FOREIGN KEY definition
     * @param ?string $name Explicit constraint name, when present
     * @return ForeignKey Declared dependency without live resolution
     * @throws RestoreFailedException When dump syntax is incomplete, unsupported or contradictory
     */
    private static function foreignKey(SqlTokens $cursor, ?string $name): ForeignKey
    {
        $cursor->expect('FOREIGN');
        $cursor->expect('KEY');
        if (!$cursor->is('(')) {
            $cursor->name();
        }
        $columns = $cursor->names();
        $cursor->expect('REFERENCES');
        $table = $cursor->name();
        $database = null;
        if ($cursor->takeIf('.')) {
            $database = $table;
            $table = $cursor->name();
        }
        $referencedColumns = $cursor->names();
        if (count($columns) !== count($referencedColumns)) {
            throw new RestoreFailedException('Contradictory foreign key column counts in dump');
        }
        if ($cursor->takeIf('MATCH')) {
            if (!in_array(strtoupper($cursor->name()), ['FULL', 'PARTIAL', 'SIMPLE'], true)) {
                throw new RestoreFailedException('Unsupported foreign key MATCH in dump');
            }
        }
        $events = [];
        while ($cursor->takeIf('ON')) {
            $event = strtoupper($cursor->name());
            if (!in_array($event, ['DELETE', 'UPDATE'], true) || in_array($event, $events, true)) {
                throw new RestoreFailedException('Contradictory foreign key action in dump');
            }
            $events[] = $event;
            if ($cursor->takeIf('SET')) {
                if (!$cursor->takeIf('NULL')) {
                    $cursor->expect('DEFAULT');
                }
            } elseif ($cursor->takeIf('NO')) {
                $cursor->expect('ACTION');
            } elseif (!$cursor->takeIf('CASCADE')) {
                $cursor->expect('RESTRICT');
            }
        }
        if (!$cursor->ended()) {
            throw new RestoreFailedException('Unsupported foreign key suffix in dump');
        }
        return new ForeignKey($name, $columns, $database, $table, $referencedColumns);
    }

    /**
     * @param list<SqlToken> $tokens Non-FK definition tokens
     * @throws RestoreFailedException When dump syntax is incomplete, unsupported or contradictory
     */
    private static function refuseHiddenReference(array $tokens): void
    {
        foreach ($tokens as $token) {
            if ($token->is('REFERENCES') || $token->is('FOREIGN')) {
                throw new RestoreFailedException('Unsupported inline or hidden foreign key in dump');
            }
        }
    }

    /**
     * @param SqlTokens $cursor Remaining table options or partition definition
     * @throws RestoreFailedException When dump syntax is incomplete, unsupported or contradictory
     */
    private static function tableOptions(SqlTokens $cursor): void
    {
        while (!$cursor->ended()) {
            if ($cursor->is('PARTITION')) {
                self::partitions($cursor);
                return;
            }
            $cursor->takeIf('DEFAULT');
            if ($cursor->takeIf('CHARACTER')) {
                $cursor->expect('SET');
            } elseif (!in_array(strtoupper($cursor->name()), self::TABLE_OPTIONS, true)) {
                throw new RestoreFailedException('Unsupported table option in dump');
            }
            $cursor->takeIf('=');
            $value = $cursor->take();
            if (!in_array($value->kind, [SqlToken::WORD, SqlToken::STRING], true)) {
                throw new RestoreFailedException('Unsupported table option value in dump');
            }
        }
    }

    /**
     * @param SqlTokens $cursor RANGE COLUMNS partition definition
     * @throws RestoreFailedException When dump syntax is incomplete, unsupported or contradictory
     */
    private static function partitions(SqlTokens $cursor): void
    {
        $cursor->expect('PARTITION');
        $cursor->expect('BY');
        $cursor->expect('RANGE');
        $cursor->expect('COLUMNS');
        $cursor->names();
        foreach (SqlTokens::split($cursor->group()) as $definition) {
            $part = new SqlTokens($definition);
            $part->expect('PARTITION');
            $part->name();
            $part->expect('VALUES');
            $part->expect('LESS');
            $part->expect('THAN');
            foreach (SqlTokens::split($part->group()) as $bound) {
                if (count($bound) !== 1 || ($bound[0]->kind !== SqlToken::STRING && !$bound[0]->is('MAXVALUE')
                    && preg_match('/^\d+$/D', $bound[0]->value) !== 1)) {
                    throw new RestoreFailedException('Unsupported dump partition bound');
                }
            }
            self::tableOptions($part);
        }
        if (!$cursor->ended()) {
            throw new RestoreFailedException('Unsupported dump partition suffix');
        }
    }
}
