<?php

declare(strict_types=1);

namespace Hilos\Backup\FullRestore;

use Hilos\Backup\Exception\RestoreFailedException;

/** Conservative migration effect reader; never executes SQL or interprets literals as table references. */
final class MigrationOperationReader
{
    /**
     * Multi-command execution units, routines, executable comments and dynamic SQL stay opaque.
     * Unsupported syntax returns UNKNOWN, rather than an empty set of effects or a partial CREATE.
     * Backslash string escapes stay opaque until a planner can establish the execution SQL mode.
     *
     * @param string $sql One actual execution unit from the migrator
     * @param string $database Schema selected by the connection executing this UP file
     * @return list<MigrationOperation> Complete table, data or trigger effects in SQL order, or one opaque effect
     */
    public static function read(string $sql, string $database): array
    {
        try {
            $lexer = DumpSqlLexer::fromSql($sql);
            $tokens = [];
            while (($token = $lexer->next()) !== null) {
                if (!$token->complete || $token->is(';')) {
                    return [new MigrationOperation(MigrationOperationKind::UNKNOWN, $sql)];
                }
                $tokens[] = $token;
            }
            if ($lexer->hasExecutableComment() || $lexer->hasStringEscape()) {
                return [new MigrationOperation(MigrationOperationKind::UNKNOWN, $sql)];
            }
            return self::operations(new SqlTokens($tokens), $sql, $database);
        } catch (RestoreFailedException) {
            return [new MigrationOperation(MigrationOperationKind::UNKNOWN, $sql)];
        }
    }

    /**
     * @param SqlTokens $cursor Complete execution unit
     * @param string $sql Original SQL
     * @param string $database Execution schema
     * @return list<MigrationOperation> Complete admitted effects
     * @throws RestoreFailedException When the unit is outside the supported grammar
     */
    private static function operations(SqlTokens $cursor, string $sql, string $database): array
    {
        if ($cursor->ended()) {
            return [new MigrationOperation(MigrationOperationKind::OTHER, $sql)];
        }
        if ($cursor->takeIf('CREATE')) {
            $cursor->expect('TABLE');
            $ifNotExists = $cursor->takeIf('IF') !== null;
            if ($ifNotExists) {
                $cursor->expect('NOT');
                $cursor->expect('EXISTS');
            }
            $table = self::table($cursor, $database);
            $definition = self::definition($table->table, $cursor->consumeRest(), $sql);
            return [new MigrationOperation(
                MigrationOperationKind::CREATE_TABLE, $sql, $table, definition: $definition, ifNotExists: $ifNotExists,
                references: self::references($definition, $table->database),
            )];
        }
        if ($cursor->takeIf('DROP')) {
            if ($cursor->takeIf('TRIGGER')) {
                $ifExists = $cursor->takeIf('IF') !== null;
                if ($ifExists) {
                    $cursor->expect('EXISTS');
                }
                $trigger = self::table($cursor, $database);
                self::expectEnd($cursor);
                return [new MigrationOperation(MigrationOperationKind::DROP_TRIGGER, $sql, trigger: $trigger, ifExists: $ifExists)];
            }
            $cursor->expect('TABLE');
            if ($cursor->takeIf('IF')) {
                $cursor->expect('EXISTS');
            }
            $operations = [];
            do {
                $operations[] = new MigrationOperation(MigrationOperationKind::DROP_TABLE, $sql, self::table($cursor, $database));
            } while ($cursor->takeIf(','));
            if (!$cursor->takeIf('RESTRICT')) {
                $cursor->takeIf('CASCADE');
            }
            self::expectEnd($cursor);
            return $operations;
        }
        if ($cursor->takeIf('RENAME')) {
            $cursor->expect('TABLE');
            $operations = [];
            do {
                $table = self::table($cursor, $database);
                $cursor->expect('TO');
                $operations[] = new MigrationOperation(
                    MigrationOperationKind::RENAME_TABLE, $sql, $table, self::table($cursor, $database),
                );
            } while ($cursor->takeIf(','));
            self::expectEnd($cursor);
            return $operations;
        }
        if ($cursor->takeIf('ALTER')) {
            $cursor->expect('TABLE');
            $table = self::table($cursor, $database);
            $clauses = SqlTokens::split($cursor->consumeRest());
            $first = new SqlTokens($clauses[0]);
            if (count($clauses) === 1 && $first->takeIf('RENAME') && ($first->takeIf('TO') || $first->takeIf('AS'))) {
                $renamedTo = self::table($first, $table->database);
                self::expectEnd($first);
                return [new MigrationOperation(MigrationOperationKind::RENAME_TABLE, $sql, $table, $renamedTo)];
            }
            $references = [];
            foreach ($clauses as $clause) {
                array_push($references, ...self::alterClause(new SqlTokens($clause), $table->database));
            }
            return [new MigrationOperation(
                MigrationOperationKind::ALTER_TABLE, $sql, $table, clauses: $clauses, references: $references,
            )];
        }
        foreach (['INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'SELECT', 'DO'] as $verb) {
            if ($cursor->is($verb)) {
                return [MigrationDataReader::read($cursor, $sql, $database)];
            }
        }
        return [new MigrationOperation(MigrationOperationKind::UNKNOWN, $sql)];
    }

    /**
     * @param SqlTokens $cursor Qualified or unqualified table reference
     * @param string $database Default physical schema
     * @return TableName Exact physical name
     * @throws RestoreFailedException When an identifier is incomplete
     */
    private static function table(SqlTokens $cursor, string $database): TableName
    {
        $name = $cursor->name();
        if ($cursor->takeIf('.')) {
            return new TableName($name, $cursor->name());
        }
        return new TableName($database, $name);
    }

    /**
     * @param string $name Unqualified table name
     * @param list<SqlToken> $body Parenthesized definition and table options
     * @param string $sql Original SQL for the retained definition
     * @return DumpObject Direct CREATE admitted by the dump's structural grammar
     * @throws RestoreFailedException When CREATE is LIKE, AS SELECT or an unsupported definition
     */
    private static function definition(string $name, array $body, string $sql): DumpObject
    {
        return DumpDefinitionReader::read([
            new SqlToken(SqlToken::WORD, 'CREATE', 0, 0),
            new SqlToken(SqlToken::WORD, 'TABLE', 0, 0),
            new SqlToken(SqlToken::IDENTIFIER, $name, 0, 0),
            ...$body,
        ], $sql);
    }

    /**
     * Admits ordinary ADD/MODIFY/CHANGE definitions, DROP names, and column/index renames.
     * Placement is retained in the original clause; unsupported partition or engine changes stay opaque.
     *
     * @param SqlTokens $cursor One ALTER clause
     * @param string $database Schema of the altered table
     * @return list<TableName> Explicit references introduced by this clause
     * @throws RestoreFailedException When the clause is not understood completely
     */
    private static function alterClause(SqlTokens $cursor, string $database): array
    {
        if ($cursor->takeIf('DROP')) {
            if ($cursor->takeIf('PRIMARY')) {
                $cursor->expect('KEY');
            } else {
                if ($cursor->takeIf('FOREIGN')) {
                    $cursor->expect('KEY');
                } elseif (!$cursor->takeIf('COLUMN') && !$cursor->takeIf('INDEX')) {
                    $cursor->takeIf('KEY');
                }
                if ($cursor->takeIf('IF')) {
                    $cursor->expect('EXISTS');
                }
                $cursor->name();
            }
            self::expectEnd($cursor);
            return [];
        }
        if ($cursor->takeIf('RENAME')) {
            if (!$cursor->takeIf('COLUMN') && !$cursor->takeIf('INDEX')) {
                $cursor->expect('KEY');
            }
            $cursor->name();
            $cursor->expect('TO');
            $cursor->name();
            self::expectEnd($cursor);
            return [];
        }
        if (!$cursor->takeIf('ADD') && !$cursor->takeIf('MODIFY')) {
            $cursor->expect('CHANGE');
            $cursor->takeIf('COLUMN');
            $cursor->name();
        } else {
            $cursor->takeIf('COLUMN');
            if ($cursor->takeIf('IF')) {
                $cursor->expect('NOT');
                $cursor->expect('EXISTS');
            }
        }
        $definition = $cursor->consumeRest();
        $last = count($definition) - 1;
        if (($definition[$last] ?? null)?->is('FIRST')) {
            array_pop($definition);
        } elseif ($last > 0 && $definition[$last - 1]->is('AFTER')) {
            new SqlTokens([$definition[$last]])->name();
            array_splice($definition, -2);
        }
        // A neutral column lets the same grammar validate a standalone key or FK declaration.
        $object = self::definition('__restore_alter', [
            new SqlToken(SqlToken::SYMBOL, '(', 0, 0),
            new SqlToken(SqlToken::IDENTIFIER, '__restore_probe', 0, 0),
            new SqlToken(SqlToken::WORD, 'INT', 0, 0),
            new SqlToken(SqlToken::SYMBOL, ',', 0, 0),
            ...$definition,
            new SqlToken(SqlToken::SYMBOL, ')', 0, 0),
        ], '');
        return self::references($object, $database);
    }

    /**
     * @param DumpObject $object Parsed definition
     * @param string $database Default referenced schema
     * @return list<TableName> Physical reference targets
     */
    private static function references(DumpObject $object, string $database): array
    {
        return array_map(
            static fn (ForeignKey $key): TableName => new TableName($key->referencedDatabase ?? $database, $key->referencedTable),
            $object->foreignKeys,
        );
    }

    /**
     * @param SqlTokens $cursor Remaining SQL
     * @throws RestoreFailedException When trailing syntax has not been understood
     */
    private static function expectEnd(SqlTokens $cursor): void
    {
        if (!$cursor->ended()) {
            throw new RestoreFailedException('Unsupported migration table operation suffix');
        }
    }
}
