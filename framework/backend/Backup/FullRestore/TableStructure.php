<?php

declare(strict_types=1);

namespace Hilos\Backup\FullRestore;

use Hilos\Backup\Exception\RestoreFailedException;

/**
 * Semantic, conservative replay of direct CREATE and ordinary ALTER. Private to this invocation.
 * Unknown attributes never disappear in normalization: unsupported forms refuse the proof.
 */
final class TableStructure
{
    private const array INTEGER_TYPES = ['TINYINT', 'SMALLINT', 'MEDIUMINT', 'INT', 'BIGINT'];
    private const array TEXT_TYPES = ['CHAR', 'VARCHAR', 'TINYTEXT', 'TEXT', 'MEDIUMTEXT', 'LONGTEXT', 'ENUM', 'SET'];
    private const array TYPES = [
        ...self::INTEGER_TYPES, ...self::TEXT_TYPES, 'BIT', 'DECIMAL', 'FLOAT', 'DOUBLE',
        'DATE', 'DATETIME', 'TIMESTAMP', 'TIME', 'YEAR', 'BINARY', 'VARBINARY', 'TINYBLOB', 'BLOB', 'MEDIUMBLOB', 'LONGBLOB',
    ];
    private const string PRIMARY = 'PRIMARY';
    private const string INDEX = 'INDEX';
    private const string UNIQUE = 'UNIQUE';
    private const string BTREE = 'BTREE';
    private const string RESTRICT = 'RESTRICT';
    private const string CHARSET = 'charset';
    private const string COLLATION = 'collation';
    private const string CHECK = 'check';

    /** @var array<string, ColumnShape> Columns in physical order */
    public array $columns = [];
    /** @var array<string, KeyShape> Keys by case-insensitive name */
    public array $keys = [];
    /** @var array<string, ForeignKeyShape> Foreign keys by case-insensitive name */
    public array $foreignKeys = [];
    /** @var array<string, string> Effective table properties, excluding the next row id */
    public array $options = [];

    /**
     * @param string $database Selected physical schema
     * @param StructureDefaults $defaults Execution defaults and server character catalog
     */
    private function __construct(private readonly string $database, private readonly StructureDefaults $defaults)
    {
    }

    /**
     * @param string $sql Direct CREATE TABLE from a source or SHOW CREATE
     * @param string $database Execution schema
     * @param StructureDefaults $defaults Server and schema defaults
     * @return self Proven essential structure
     * @throws RestoreFailedException When any part of the structure cannot be modeled exactly
     */
    public static function read(string $sql, string $database, StructureDefaults $defaults): self
    {
        $cursor = new SqlTokens(self::tokens($sql));
        $cursor->expect('CREATE');
        $cursor->expect('TABLE');
        if ($cursor->takeIf('IF')) {
            $cursor->expect('NOT');
            $cursor->expect('EXISTS');
        }
        $cursor->name();
        if ($cursor->takeIf('.')) {
            $cursor->name();
        }
        $definitions = SqlTokens::split($cursor->group());
        $structure = new self($database, $defaults);
        $structure->tableOptions($cursor);
        foreach ($definitions as $definition) {
            $structure->add(new SqlTokens($definition));
        }
        $structure->finish();
        return $structure;
    }

    /**
     * @return string Canonical structure including physical column and composite-key order
     */
    public function fingerprint(): string
    {
        return json_encode([$this->columns, $this->keys, $this->foreignKeys, $this->options]);
    }

    /**
     * @param TableName $old Renamed parent
     * @param TableName $new New physical address
     */
    public function renameReferences(TableName $old, TableName $new): void
    {
        foreach ($this->foreignKeys as $name => $key) {
            if ($key->parent == $old) {
                $this->foreignKeys[$name] = new ForeignKeyShape($key->columns, $new, $key->parentColumns, $key->deleteRule, $key->updateRule);
            }
        }
    }

    /**
     * @param MigrationOperation $operation Already admitted ALTER, in execution order
     * @throws RestoreFailedException When the ALTER cannot be replayed without guessing
     */
    public function alter(MigrationOperation $operation): void
    {
        foreach ($operation->clauses as $clause) {
            $cursor = new SqlTokens($clause);
            if ($cursor->takeIf('DROP')) {
                if ($cursor->takeIf('PRIMARY')) {
                    $cursor->expect('KEY');
                    $this->remove($this->keys, self::PRIMARY);
                } elseif ($cursor->takeIf('FOREIGN')) {
                    $cursor->expect('KEY');
                    $this->remove($this->foreignKeys, strtolower($cursor->name()));
                } elseif ($cursor->takeIf('INDEX') || $cursor->takeIf('KEY')) {
                    $this->remove($this->keys, strtolower($cursor->name()));
                } else {
                    $cursor->takeIf('COLUMN');
                    $name = strtolower($cursor->name());
                    $this->refuseCheckedColumnChange($name);
                    $this->remove($this->columns, $name);
                }
            } elseif ($cursor->takeIf('RENAME')) {
                $column = $cursor->takeIf('COLUMN') !== null;
                if (!$column && !$cursor->takeIf('INDEX')) {
                    $cursor->expect('KEY');
                }
                $old = strtolower($cursor->name());
                $cursor->expect('TO');
                $new = strtolower($cursor->name());
                if ($column) {
                    $this->renameColumn($old, $new);
                } else {
                    $key = $this->keys[$old] ?? null;
                    $this->remove($this->keys, $old);
                    $this->insert($this->keys, $new, $key);
                }
            } elseif ($cursor->takeIf('ADD')) {
                $cursor->takeIf('COLUMN');
                $this->add($cursor);
            } else {
                $change = $cursor->takeIf('CHANGE') !== null;
                if (!$change) {
                    $cursor->expect('MODIFY');
                }
                $cursor->takeIf('COLUMN');
                $old = strtolower($cursor->name());
                $new = $change ? strtolower($cursor->name()) : $old;
                $this->refuseCheckedColumnChange($old);
                if ($new !== $old) {
                    $this->renameColumn($old, $new);
                }
                if (!isset($this->columns[$new])) {
                    throw new RestoreFailedException("ALTER changes absent column {$new}");
                }
                $this->columns[$new] = $this->column($cursor, $new);
                $this->placement($cursor, $new);
            }
            self::end($cursor);
        }
        $this->finish();
    }

    /**
     * @param string $sql Bounded declaration
     * @return list<SqlToken> Complete tokens without a trailing statement delimiter
     * @throws RestoreFailedException When lexical interpretation is ambiguous
     */
    public static function tokens(string $sql): array
    {
        $lexer = DumpSqlLexer::fromSql($sql);
        $tokens = [];
        while (($token = $lexer->next()) !== null) {
            if ($token->is(';') && $lexer->next() === null) {
                break;
            }
            if (!$token->complete || $token->is(';')) {
                throw new RestoreFailedException('Opaque or incomplete structure declaration');
            }
            $tokens[] = $token;
        }
        if ($lexer->hasExecutableComment() || $lexer->hasStringEscape()) {
            throw new RestoreFailedException('Structure uses executable comments or ambiguous string escaping');
        }
        return $tokens;
    }

    /**
     * @param SqlTokens $cursor Table properties following the definition list
     * @throws RestoreFailedException When an option or its effective value is unknown
     */
    private function tableOptions(SqlTokens $cursor): void
    {
        $charset = null;
        $collation = null;
        $this->options['engine'] = strtolower($this->defaults->engine);
        while (!$cursor->ended()) {
            $cursor->takeIf('DEFAULT');
            $option = strtoupper($cursor->name());
            if ($option === 'CHARACTER') {
                $cursor->expect('SET');
                $option = 'CHARSET';
            }
            $cursor->takeIf('=');
            $value = $cursor->take();
            if ($option === 'AUTO_INCREMENT') {
                if (preg_match('/^\d+$/D', $value->value) !== 1) {
                    throw new RestoreFailedException('Unknown AUTO_INCREMENT table option');
                }
                continue;
            }
            if ($option === 'CHARSET') {
                $charset = strtolower($value->value);
            } elseif ($option === 'COLLATE') {
                $collation = strtolower($value->value);
            } elseif (in_array($option, ['ENGINE', 'ROW_FORMAT', 'KEY_BLOCK_SIZE', 'COMMENT'], true)) {
                if ($option !== 'COMMENT' || $value->value !== '') {
                    $this->options[strtolower($option)] = $option === 'COMMENT' ? $value->value : strtolower($value->value);
                }
            } else {
                throw new RestoreFailedException("Unsupported structural table option {$option}");
            }
        }
        $this->options += $this->characterOptions($charset, $collation, $this->defaults->charset, $this->defaults->collation);
        if ($this->options['engine'] !== 'innodb') {
            throw new RestoreFailedException('Structural provenance currently requires InnoDB');
        }
        if (!isset($this->options['row_format']) || $this->options['row_format'] === 'default') {
            $this->options['row_format'] = strtolower($this->defaults->rowFormat);
        }
        ksort($this->options);
    }

    /**
     * @param SqlTokens $cursor One definition or ADD body
     * @throws RestoreFailedException When a definition is unsupported or duplicated
     */
    private function add(SqlTokens $cursor): void
    {
        $constraint = null;
        if ($cursor->takeIf('CONSTRAINT')) {
            $constraint = strtolower($cursor->name());
        }
        if ($cursor->is('FOREIGN')) {
            if ($constraint === null) {
                throw new RestoreFailedException('An unnamed foreign key cannot establish exact structural provenance');
            }
            $this->insert($this->foreignKeys, $constraint, $this->foreignKey($cursor));
        } elseif ($cursor->is('PRIMARY') || $cursor->is('UNIQUE') || $cursor->is('KEY') || $cursor->is('INDEX')) {
            $kind = self::INDEX;
            $name = $constraint;
            if ($cursor->takeIf('PRIMARY')) {
                $kind = self::PRIMARY;
                $name = self::PRIMARY;
                $cursor->expect('KEY');
            } else {
                if ($cursor->takeIf('UNIQUE')) {
                    $kind = self::UNIQUE;
                }
                if (!$cursor->takeIf('KEY')) {
                    $cursor->expect('INDEX');
                }
                if (!$cursor->is('(') && !$cursor->is('USING')) {
                    $name = strtolower($cursor->name());
                }
            }
            $algorithm = self::BTREE;
            if ($cursor->takeIf('USING')) {
                $algorithm = strtoupper($cursor->name());
            }
            $columns = [];
            foreach (SqlTokens::split($cursor->group()) as $part) {
                $column = new SqlTokens($part);
                $columnName = strtolower($column->name());
                $prefix = $column->is('(') ? self::signature($column->group()) : null;
                $direction = $column->takeIf('DESC') ? 'DESC' : 'ASC';
                $column->takeIf('ASC');
                self::end($column);
                $this->insert($columns, $columnName, json_encode([$prefix, $direction]));
            }
            if ($cursor->takeIf('USING')) {
                $algorithm = strtoupper($cursor->name());
            }
            $cursor->takeIf('VISIBLE');
            $name ??= array_key_first($columns);
            $this->insert($this->keys, $name, new KeyShape($kind, $columns, $algorithm));
        } else {
            if ($constraint !== null) {
                throw new RestoreFailedException('Unsupported named constraint');
            }
            $name = strtolower($cursor->name());
            $this->insert($this->columns, $name, $this->column($cursor, $name));
            $this->placement($cursor, $name);
        }
        self::end($cursor);
    }

    /**
     * @param SqlTokens $cursor Column type and attributes
     * @param string $name Case-insensitive column name
     * @return ColumnShape Effective type, nullability, default and remaining attributes
     * @throws RestoreFailedException When a column attribute cannot be modeled
     */
    private function column(SqlTokens $cursor, string $name): ColumnShape
    {
        $type = strtoupper($cursor->name());
        $boolean = in_array($type, ['BOOL', 'BOOLEAN'], true);
        $type = match ($type) { 'BOOL', 'BOOLEAN' => 'TINYINT', 'INTEGER' => 'INT', 'NUMERIC', 'FIXED' => 'DECIMAL', default => $type };
        if (!in_array($type, self::TYPES, true)) {
            throw new RestoreFailedException("Unsupported structural column type {$type}");
        }
        $arguments = $cursor->is('(') ? $cursor->group() : [];
        if ($boolean) {
            if ($arguments !== []) {
                throw new RestoreFailedException('Unsupported BOOLEAN type arguments');
            }
            $arguments = [new SqlToken(SqlToken::WORD, '1', 0, 0)];
        }
        $nullable = true;
        $default = null;
        $attributes = [];
        $charset = null;
        $collation = null;
        $unsigned = false;
        $zerofill = false;
        while (!$cursor->ended() && !$cursor->is('FIRST') && !$cursor->is('AFTER')) {
            if ($cursor->takeIf('NOT')) {
                $cursor->expect('NULL');
                $nullable = false;
            } elseif ($cursor->takeIf('NULL')) {
                $nullable = true;
            } elseif ($cursor->takeIf('UNSIGNED')) {
                $unsigned = true;
            } elseif ($cursor->takeIf('SIGNED')) {
                $unsigned = false;
            } elseif ($cursor->takeIf('ZEROFILL')) {
                $zerofill = true;
                $unsigned = true;
            } elseif ($cursor->takeIf('DEFAULT')) {
                $default = self::value($cursor);
            } elseif ($cursor->takeIf('AUTO_INCREMENT')) {
                $attributes['auto_increment'] = 'true';
            } elseif ($cursor->takeIf('ON')) {
                $cursor->expect('UPDATE');
                $attributes['on_update'] = self::value($cursor);
            } elseif ($cursor->takeIf('COMMENT')) {
                $comment = $cursor->take();
                if ($comment->kind !== SqlToken::STRING) {
                    throw new RestoreFailedException('Unsupported column comment');
                }
                if ($comment->value !== '') {
                    $attributes['comment'] = $comment->value;
                }
            } elseif ($cursor->takeIf('PRIMARY')) {
                $cursor->expect('KEY');
                $this->insert($this->keys, self::PRIMARY, new KeyShape(self::PRIMARY, [$name => json_encode([null, 'ASC'])], self::BTREE));
                $nullable = false;
            } elseif ($cursor->takeIf('UNIQUE')) {
                $cursor->takeIf('KEY');
                $this->insert($this->keys, $name, new KeyShape(self::UNIQUE, [$name => json_encode([null, 'ASC'])], self::BTREE));
            } elseif ($cursor->takeIf('CHARACTER')) {
                $cursor->expect('SET');
                $charset = strtolower($cursor->name());
            } elseif ($cursor->takeIf('COLLATE')) {
                $collation = strtolower($cursor->name());
            } elseif ($cursor->takeIf('CHECK')) {
                $tokens = $cursor->group();
                $check = new SqlTokens($tokens);
                $check->expect('JSON_VALID');
                if (array_map('strtolower', $check->names()) !== [$name]) {
                    throw new RestoreFailedException('Structural JSON CHECK must reference only its own column');
                }
                self::end($check);
                $this->insert($attributes, self::CHECK, self::signature($tokens));
            } else {
                throw new RestoreFailedException('Unsupported structural column attribute');
            }
        }
        if (in_array($type, self::INTEGER_TYPES, true) && !$zerofill) {
            $arguments = [];
        }
        if (in_array($type, self::TEXT_TYPES, true)) {
            $attributes += $this->characterOptions($charset, $collation, $this->options[self::CHARSET], $this->options[self::COLLATION]);
        } elseif ($charset !== null || $collation !== null) {
            throw new RestoreFailedException('Character options on a non-text column cannot establish structural provenance');
        }
        ksort($attributes);
        $default ??= $nullable ? self::signature([new SqlToken(SqlToken::WORD, 'NULL', 0, 0)]) : null;
        $type .= self::signature($arguments);
        if ($unsigned) {
            $type .= ' UNSIGNED';
        }
        if ($zerofill) {
            $type .= ' ZEROFILL';
        }
        return new ColumnShape($type, $nullable, $default, $attributes);
    }

    /**
     * @param ?string $charset Explicit character set, independent of option order
     * @param ?string $collation Explicit collation whose charset must be proven
     * @param string $inheritedCharset Parent character set for an omitted pair
     * @param string $inheritedCollation Parent collation for an omitted pair
     * @return array<string, string> Effective charset/collation attributes at this metadata boundary
     * @throws RestoreFailedException When a collation is unknown, ambiguous or incompatible with an explicit charset
     */
    private function characterOptions(?string $charset, ?string $collation, string $inheritedCharset, string $inheritedCollation): array
    {
        if ($collation !== null) {
            $associated = $this->defaults->charsetByCollation[$collation] ?? null;
            if ($associated === null) {
                // CHARACTER_SETS also proves its default aliases, including those absent from a base COLLATIONS catalog.
                $candidates = array_keys($this->defaults->collations, $collation, true);
                if (count($candidates) !== 1) {
                    throw new RestoreFailedException('The collation character set cannot be established');
                }
                $associated = $candidates[0];
            }
            if ($charset !== null && $charset !== $associated) {
                throw new RestoreFailedException('The explicit character set and collation are incompatible');
            }
            $charset = $associated;
        } elseif ($charset !== null) {
            $collation = $this->defaults->collations[$charset] ?? null;
            if ($collation === null) {
                throw new RestoreFailedException('The character set default collation cannot be established');
            }
        } else {
            $charset = $inheritedCharset;
            $collation = $inheritedCollation;
        }
        return [self::CHARSET => strtolower($charset), self::COLLATION => strtolower($collation)];
    }

    /**
     * @param SqlTokens $cursor FOREIGN KEY definition
     * @return ForeignKeyShape Fully qualified dependency with both actions
     * @throws RestoreFailedException When syntax or reference actions are unsupported
     */
    private function foreignKey(SqlTokens $cursor): ForeignKeyShape
    {
        $cursor->expect('FOREIGN');
        $cursor->expect('KEY');
        if (!$cursor->is('(')) {
            $cursor->name();
        }
        $columns = array_map('strtolower', $cursor->names());
        $cursor->expect('REFERENCES');
        $database = $this->database;
        $table = $cursor->name();
        if ($cursor->takeIf('.')) {
            $database = $table;
            $table = $cursor->name();
        }
        $parentColumns = array_map('strtolower', $cursor->names());
        $delete = self::RESTRICT;
        $update = self::RESTRICT;
        while ($cursor->takeIf('ON')) {
            $event = strtoupper($cursor->name());
            $rule = strtoupper($cursor->name());
            if ($rule === 'SET' || $rule === 'NO') {
                $rule .= ' ' . strtoupper($cursor->name());
            }
            if (!in_array($rule, [self::RESTRICT, 'CASCADE', 'SET NULL', 'NO ACTION'], true)
                || !in_array($event, ['DELETE', 'UPDATE'], true)) {
                throw new RestoreFailedException('Unsupported structural FK action');
            }
            $rule = $rule === 'NO ACTION' ? self::RESTRICT : $rule;
            if ($event === 'DELETE') {
                $delete = $rule;
            } else {
                $update = $rule;
            }
        }
        return new ForeignKeyShape($columns, new TableName($database, $table), $parentColumns, $delete, $update);
    }

    /**
     * @param SqlTokens $cursor Optional FIRST/AFTER placement
     * @param string $name Column to move, already present
     * @throws RestoreFailedException When the position names an absent column
     */
    private function placement(SqlTokens $cursor, string $name): void
    {
        if (!$cursor->is('FIRST') && !$cursor->is('AFTER')) {
            return;
        }
        $first = $cursor->takeIf('FIRST') !== null;
        $after = null;
        if (!$first) {
            $cursor->expect('AFTER');
            $after = strtolower($cursor->name());
            if (!isset($this->columns[$after]) || $after === $name) {
                throw new RestoreFailedException('ALTER column position is not established');
            }
        }
        $column = $this->columns[$name];
        unset($this->columns[$name]);
        $ordered = $first ? [$name => $column] : [];
        foreach ($this->columns as $key => $shape) {
            $ordered[$key] = $shape;
            if ($key === $after) {
                $ordered[$name] = $column;
            }
        }
        $this->columns = $ordered;
    }

    /**
     * Column replacement, erasure and rename may implicitly retain or rewrite a constraint; this proof does not guess.
     *
     * @param string $name Column affected by an ALTER
     * @throws RestoreFailedException When the column carries a modeled inline CHECK
     */
    private function refuseCheckedColumnChange(string $name): void
    {
        if (isset($this->columns[$name]->attributes[self::CHECK])) {
            throw new RestoreFailedException('ALTER of a checked column requires explicit constraint lifecycle evidence');
        }
    }

    /**
     * Refuse rather than guessing implicit changes to keys or references during a rename.
     *
     * @param string $old Existing column
     * @param string $new New column
     * @throws RestoreFailedException When references or keys make the rename ambiguous
     */
    private function renameColumn(string $old, string $new): void
    {
        $this->refuseCheckedColumnChange($old);
        if (!isset($this->columns[$old]) || isset($this->columns[$new]) || $this->foreignKeys !== []) {
            throw new RestoreFailedException('Column rename has ambiguous structural effects');
        }
        foreach ($this->keys as $key) {
            if (isset($key->columns[$old])) {
                throw new RestoreFailedException('A keyed column rename needs explicit structural evidence');
            }
        }
        $renamed = [];
        foreach ($this->columns as $name => $shape) {
            $renamed[$name === $old ? $new : $name] = $shape;
        }
        $this->columns = $renamed;
    }

    /**
     * Resolves primary nullability and indexes generated by explicit foreign keys.
     *
     * @throws RestoreFailedException When the final structure has dangling columns or ambiguous implicit keys
     */
    private function finish(): void
    {
        foreach ($this->keys as $key) {
            foreach ($key->columns as $name => $part) {
                if (!isset($this->columns[$name])) {
                    throw new RestoreFailedException("Key names absent column {$name}");
                }
                if ($key->kind === self::PRIMARY) {
                    $column = $this->columns[$name];
                    $default = $column->default === self::signature([new SqlToken(SqlToken::WORD, 'NULL', 0, 0)]) ? null : $column->default;
                    $this->columns[$name] = new ColumnShape($column->type, false, $default, $column->attributes);
                }
            }
        }
        foreach ($this->foreignKeys as $name => $key) {
            if (count($key->columns) !== count($key->parentColumns) || array_diff($key->columns, array_keys($this->columns)) !== []) {
                throw new RestoreFailedException('Foreign key columns are not established');
            }
            foreach ($key->columns as $column) {
                if (($key->deleteRule === 'SET NULL' || $key->updateRule === 'SET NULL') && !$this->columns[$column]->nullable) {
                    throw new RestoreFailedException('SET NULL foreign key requires nullable child columns');
                }
            }
            $covered = false;
            foreach ($this->keys as $index) {
                if ($index->algorithm === self::BTREE && array_slice(array_keys($index->columns), 0, count($key->columns)) === $key->columns
                    && array_all(array_slice($index->columns, 0, count($key->columns)),
                        static fn (string $part): bool => json_decode($part, true)[0] === null)) {
                    $covered = true;
                }
            }
            if (!$covered) {
                $columns = array_fill_keys($key->columns, json_encode([null, 'ASC']));
                $this->insert($this->keys, $name, new KeyShape(self::INDEX, $columns, self::BTREE));
            }
        }
        ksort($this->keys);
        ksort($this->foreignKeys);
    }

    /**
     * @param SqlTokens $cursor One scalar or timestamp default
     * @return string Stable expression retaining literal kinds and string case
     * @throws RestoreFailedException When an expression cannot be modeled exactly
     */
    private static function value(SqlTokens $cursor): string
    {
        $tokens = [];
        if ($cursor->takeIf('-')) {
            $tokens[] = new SqlToken(SqlToken::SYMBOL, '-', 0, 0);
        }
        $token = $cursor->take();
        if ($token->is('TRUE') || $token->is('FALSE')) {
            $token = new SqlToken(SqlToken::WORD, $token->is('TRUE') ? '1' : '0', $token->start, $token->end);
        }
        $tokens[] = $token;
        if ($token->is('CURRENT_TIMESTAMP')) {
            if ($cursor->is('(')) {
                $arguments = $cursor->group();
                if ($arguments !== [] && !(count($arguments) === 1 && $arguments[0]->is('0'))) {
                    array_push($tokens, new SqlToken(SqlToken::SYMBOL, '(', 0, 0), ...$arguments);
                    $tokens[] = new SqlToken(SqlToken::SYMBOL, ')', 0, 0);
                }
            }
        } elseif ($token->kind !== SqlToken::STRING && !$token->is('NULL')
            && preg_match('/^\d+$/D', $token->value) !== 1) {
            throw new RestoreFailedException('Unsupported structural default expression');
        }
        if ($cursor->takeIf('.')) {
            $tokens[] = new SqlToken(SqlToken::SYMBOL, '.', 0, 0);
            $tokens[] = $cursor->take();
        }
        return self::signature($tokens);
    }

    /**
     * @param list<SqlToken> $tokens Structural tokens; column/key names are case-insensitive
     * @return string Unambiguous encoding preserving string values
     */
    private static function signature(array $tokens): string
    {
        return json_encode(array_map(static fn (SqlToken $token): array => [
            $token->kind === SqlToken::STRING ? SqlToken::STRING : SqlToken::WORD,
            $token->kind === SqlToken::STRING ? $token->value : strtolower($token->value),
        ], $tokens));
    }

    /**
     * @param array<string, mixed> $map Invocation-local structural map
     * @param string $name Name to remove
     * @throws RestoreFailedException When the declared object does not exist
     */
    private function remove(array &$map, string $name): void
    {
        if (!array_key_exists($name, $map)) {
            throw new RestoreFailedException("Structural operation names absent object {$name}");
        }
        unset($map[$name]);
    }

    /**
     * @param array<string, mixed> $map Invocation-local structural map
     * @param string $name New name
     * @param mixed $shape Structural value
     * @throws RestoreFailedException When an object is declared twice
     */
    private function insert(array &$map, string $name, mixed $shape): void
    {
        if (array_key_exists($name, $map)) {
            throw new RestoreFailedException("Ambiguous repeated structural declaration {$name}");
        }
        $map[$name] = $shape;
    }

    /**
     * @param SqlTokens $cursor Remainder after a supported operation
     * @throws RestoreFailedException When some structural syntax remains unexplained
     */
    private static function end(SqlTokens $cursor): void
    {
        if (!$cursor->ended()) {
            throw new RestoreFailedException('Unexplained structural declaration suffix');
        }
    }
}
