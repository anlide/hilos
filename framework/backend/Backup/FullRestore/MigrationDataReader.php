<?php

declare(strict_types=1);

namespace Hilos\Backup\FullRestore;

use Hilos\Backup\Exception\RestoreFailedException;

/**
 * Direct row access for ordinary UP statements, without executing or evaluating expressions.
 * Scalar expressions may contain functions; subqueries, derived tables and dynamic SQL refuse.
 * Every returned table is required at this exact execution position, for reads and writes alike.
 */
final class MigrationDataReader
{
    private const array TABLE_BOUNDARIES = [
        'SET', 'WHERE', 'GROUP', 'HAVING', 'ORDER', 'LIMIT', 'JOIN', 'INNER', 'LEFT', 'RIGHT', 'CROSS', 'NATURAL', 'ON', 'USING',
    ];
    private const array QUERY_TAILS = ['WHERE', 'GROUP', 'HAVING', 'ORDER', 'LIMIT'];
    private const array OPAQUE_EXPRESSION_WORDS = [
        'SELECT', 'FROM', 'JOIN', 'UNION', 'INTERSECT', 'EXCEPT', 'WITH', 'TABLE', 'INSERT', 'REPLACE', 'UPDATE', 'DELETE',
        'CALL', 'PREPARE', 'EXECUTE', 'INTO', 'OUTFILE', 'DUMPFILE', 'PROCEDURE', 'LOAD', 'HANDLER', 'RETURNING', 'SQL_MODE',
        'NEXT', 'PREVIOUS', 'NEXTVAL', 'LASTVAL', 'SETVAL',
    ];

    /** @var list<FunctionCall> Calls in scalar expressions, excluding table lists and column declarations */
    private array $calls = [];

    /** @param string $database Actual execution schema, including for a qualified journal statement */
    private function __construct(private readonly string $database)
    {
    }

    /**
     * @param SqlTokens $cursor One lexically admitted execution unit
     * @param string $sql Exact SQL sent by the migrator
     * @param string $database Actual execution schema
     * @return MigrationOperation Complete direct requirements and scalar calls, without evaluating expressions
     * @throws RestoreFailedException When the statement's effects cannot be established
     */
    public static function read(SqlTokens $cursor, string $sql, string $database): MigrationOperation
    {
        $reader = new self($database);
        $references = $reader->references($cursor, $database);
        return new MigrationOperation(MigrationOperationKind::DATA, $sql, references: $references, calls: $reader->calls);
    }

    /**
     * @param SqlTokens $cursor One lexically admitted execution unit
     * @param string $database Actual execution schema
     * @return list<TableName> Complete direct read/write requirements; never inferred from literal text
     * @throws RestoreFailedException When the statement's table effects cannot be established
     */
    private function references(SqlTokens $cursor, string $database): array
    {
        $verb = strtoupper($cursor->take()->value);
        if ($verb === 'SELECT') {
            return $this->select($cursor, $database);
        }
        if ($verb === 'DO') {
            $this->expression($cursor->consumeRest());
            return [];
        }
        if ($verb === 'INSERT' || $verb === 'REPLACE') {
            return $this->insert($cursor, $database);
        }
        if ($verb === 'UPDATE') {
            $cursor->takeIf('LOW_PRIORITY');
            $cursor->takeIf('IGNORE');
            $tables = $this->tables($cursor, $database);
            $cursor->expect('SET');
            $this->expression($this->until($cursor, self::QUERY_TAILS));
        } else {
            $cursor->takeIf('LOW_PRIORITY');
            $cursor->takeIf('QUICK');
            $cursor->takeIf('IGNORE');
            if (!$cursor->is('FROM')) {
                // Multi-table DELETE target aliases are required to occur in the FROM/JOIN table list.
                $aliases = [$cursor->name()];
                while ($cursor->takeIf(',')) {
                    $aliases[] = $cursor->name();
                }
            }
            $cursor->expect('FROM');
            $tables = $this->tables($cursor, $database);
            foreach ($aliases ?? [] as $alias) {
                if (!isset($tables[$alias])) {
                    throw new RestoreFailedException('Unresolved migration DELETE target alias');
                }
            }
        }
        $this->tail($cursor);
        return array_values($tables);
    }

    /**
     * @param SqlTokens $cursor INSERT/REPLACE body
     * @param string $database Execution schema
     * @return list<TableName> Destination and source tables
     * @throws RestoreFailedException When row sources or suffix effects are opaque
     */
    private function insert(SqlTokens $cursor, string $database): array
    {
        foreach (['LOW_PRIORITY', 'DELAYED', 'HIGH_PRIORITY', 'IGNORE'] as $modifier) {
            $cursor->takeIf($modifier);
        }
        $cursor->takeIf('INTO');
        $tables = [$this->table($cursor, $database)];
        if ($cursor->is('(')) {
            $cursor->names();
        }
        if ($cursor->takeIf('SELECT')) {
            array_push($tables, ...$this->select($cursor, $database));
        } elseif ($cursor->takeIf('VALUES') || $cursor->takeIf('VALUE')) {
            do {
                $this->expression($cursor->group());
            } while ($cursor->takeIf(','));
            if ($cursor->takeIf('ON')) {
                $cursor->expect('DUPLICATE');
                $cursor->expect('KEY');
                $cursor->expect('UPDATE');
                $this->expression($cursor->consumeRest());
            }
        } elseif ($cursor->takeIf('SET')) {
            $this->expression($cursor->consumeRest());
        } else {
            throw new RestoreFailedException('Opaque migration INSERT row source');
        }
        if (!$cursor->ended()) {
            throw new RestoreFailedException('Opaque migration INSERT suffix');
        }
        return $tables;
    }

    /**
     * @param SqlTokens $cursor SELECT body after its keyword
     * @param string $database Execution schema
     * @return list<TableName> Every FROM/JOIN source
     * @throws RestoreFailedException When sources, subqueries or trailing execution are opaque
     */
    private function select(SqlTokens $cursor, string $database): array
    {
        $this->expression($this->until($cursor, ['FROM']));
        $tables = $cursor->takeIf('FROM') ? $this->tables($cursor, $database) : [];
        $this->tail($cursor);
        return array_values($tables);
    }

    /**
     * @param SqlTokens $cursor Direct table list with aliases and ordinary joins
     * @param string $database Default schema, independent of a qualifier's spelling
     * @return array<string, TableName> Required physical tables by SQL alias
     * @throws RestoreFailedException When a source is derived, a table function or an ambiguous alias
     */
    private function tables(SqlTokens $cursor, string $database): array
    {
        $tables = [];
        do {
            $table = $this->table($cursor, $database);
            $alias = $table->table;
            if ($cursor->takeIf('AS')) {
                $alias = $cursor->name();
            } elseif (!$cursor->ended() && !$cursor->is(',')
                && !array_any(self::TABLE_BOUNDARIES, static fn (string $word): bool => $cursor->is($word))) {
                $alias = $cursor->name();
            }
            if (isset($tables[$alias])) {
                throw new RestoreFailedException('Ambiguous migration table alias');
            }
            $tables[$alias] = $table;
            if ($cursor->takeIf('ON')) {
                $this->expression($this->until($cursor, [...self::TABLE_BOUNDARIES, ',']));
            } elseif ($cursor->takeIf('USING')) {
                $cursor->names();
            }
            if ($cursor->takeIf(',')) {
                continue;
            }
            $join = false;
            foreach (['NATURAL', 'LEFT', 'RIGHT', 'INNER', 'CROSS'] as $modifier) {
                if ($cursor->takeIf($modifier)) {
                    $join = true;
                    $cursor->takeIf('OUTER');
                    break;
                }
            }
            if ($join) {
                $cursor->expect('JOIN');
            } elseif (!$cursor->takeIf('JOIN')) {
                break;
            }
        } while (true);
        return $tables;
    }

    /**
     * @param SqlTokens $cursor Query suffix after its table list or assignment
     * @throws RestoreFailedException When any clause hides additional table access or execution
     */
    private function tail(SqlTokens $cursor): void
    {
        foreach (self::QUERY_TAILS as $keyword) {
            if (!$cursor->takeIf($keyword)) {
                continue;
            }
            if ($keyword === 'GROUP' || $keyword === 'ORDER') {
                $cursor->expect('BY');
            }
            $this->expression($this->until($cursor, self::QUERY_TAILS));
        }
        if (!$cursor->ended()) {
            throw new RestoreFailedException('Opaque migration data statement suffix');
        }
    }

    /**
     * @param SqlTokens $cursor Tokens before a top-level clause boundary
     * @param list<string> $boundaries Keywords or punctuation ending this expression
     * @return list<SqlToken> Balanced expression, without consuming the next clause
     * @throws RestoreFailedException When parentheses are incomplete
     */
    private function until(SqlTokens $cursor, array $boundaries): array
    {
        $tokens = [];
        while (!$cursor->ended() && !array_any($boundaries, static fn (string $word): bool => $cursor->is($word))) {
            if ($cursor->is('(')) {
                $tokens[] = new SqlToken(SqlToken::SYMBOL, '(', 0, 0);
                array_push($tokens, ...$cursor->group());
                $tokens[] = new SqlToken(SqlToken::SYMBOL, ')', 0, 0);
            } else {
                $tokens[] = $cursor->take();
            }
        }
        return $tokens;
    }

    /**
     * This admits scalar SQL without interpreting its values; every table-bearing construct is refused.
     * Only these expression tokens supply calls to the planner's separate server-wide proof.
     *
     * @param list<SqlToken> $tokens Nonempty expression or assignment list
     * @throws RestoreFailedException When row access or side effects are not proven by this grammar
     */
    private function expression(array $tokens): void
    {
        if ($tokens === []) {
            throw new RestoreFailedException('Empty migration data expression');
        }
        $depth = 0;
        foreach ($tokens as $token) {
            if (array_any(self::OPAQUE_EXPRESSION_WORDS, static fn (string $word): bool => $token->is($word)) || $token->is('@')) {
                throw new RestoreFailedException('Opaque migration data expression');
            }
            if ($token->is('(')) {
                $depth++;
            } elseif ($token->is(')')) {
                $depth--;
            }
            if ($depth < 0) {
                throw new RestoreFailedException('Unbalanced migration data expression');
            }
        }
        if ($depth !== 0) {
            throw new RestoreFailedException('Unbalanced migration data expression');
        }
        array_push($this->calls, ...FunctionCall::read($tokens, $this->database));
    }

    /**
     * @param SqlTokens $cursor Required physical table reference
     * @param string $database Actual connection schema
     * @return TableName Exact schema and table
     * @throws RestoreFailedException When a source is not a direct identifier
     */
    private function table(SqlTokens $cursor, string $database): TableName
    {
        $name = $cursor->name();
        return $cursor->takeIf('.') ? new TableName($name, $cursor->name()) : new TableName($database, $name);
    }
}
