<?php

declare(strict_types=1);

namespace Hilos\Backup;

use Hilos\Backup\Exception\RestoreFailedException;
use Hilos\Backup\FullRestore\LiveObject;
use Hilos\Backup\FullRestore\PreparedTarget;
use Hilos\Backup\FullRestore\SchemaMetadata;
use Hilos\Backup\FullRestore\ServerMetadata;
use Hilos\Backup\FullRestore\StructureDefaults;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;

/**
 * Invocation-local physical catalog evidence. Hilos::$db has no physical-schema collection;
 * these reads extend the metadata approach used by LiveSchemaReader and EntitySchemaAudit.
 * No transaction, initialization, trial DDL or data write is performed here.
 */
final readonly class FullRestoreSchemaSnapshot
{
    private const int ADDRESS_PARTS = 4;
    private const int SERVER_PARTS = 3;
    private const string BASE_TABLE = 'BASE TABLE';
    private const string VIEW = 'VIEW';
    private const array VISIBILITY_PRIVILEGES = ['SELECT', 'SHOW VIEW', 'TRIGGER', 'EVENT'];

    /**
     * @param array<int, SchemaMetadata> $schemas Catalog by configured target index
     * @param array<string, ServerMetadata> $servers Dependency catalog by physical server
     */
    public function __construct(public array $schemas, public array $servers)
    {
    }

    /**
     * @param list<PreparedTarget> $targets Prepared physical destinations
     * @return self Complete visible evidence, with an explicit visibility verdict
     * @throws RestoreFailedException When any catalog cannot be read reliably
     * @throws DatabaseException When the caller's connection cannot be restored
     */
    public static function read(array $targets): self
    {
        $callerIndex = Database::getCurrentIndex();
        $index = null;
        try {
            $schemas = [];
            $servers = [];
            foreach ($targets as $target) {
                $index = $target->connection->index;
                Database::useConnection($index);
                $address = json_decode($target->address, true);
                if (!is_array($address) || !array_is_list($address) || count($address) !== self::ADDRESS_PARTS) {
                    throw new RestoreFailedException('The prepared target has no physical server identity');
                }
                $server = json_encode(array_slice($address, 0, self::SERVER_PARTS));
                $schemas[$index] = self::schema($server);
                $servers[$server] ??= self::server();
            }
            return new self($schemas, $servers);
        } catch (DatabaseException | RestoreFailedException $failure) {
            throw RestoreFailedException::beforeDestructive(
                'Full restore metadata, connection ' . ($index ?? 'all') . ': ' . $failure->getMessage(), $failure,
            );
        } finally {
            Database::useConnection($callerIndex);
        }
    }

    /**
     * @param list<PreparedTarget> $targets Same invocation's physical bindings
     * @throws RestoreFailedException When any visible schema, dependency, privilege or definition changed
     * @throws DatabaseException When the caller's connection cannot be restored
     */
    public function assertUnchanged(array $targets): void
    {
        if (self::read($targets) != $this) {
            throw RestoreFailedException::beforeDestructive('The physical schema or its dependencies changed after full restore preparation');
        }
    }

    /**
     * @param string $server Physical server identity
     * @return SchemaMetadata Selected schema without volatile row counts or statistics
     * @throws DatabaseException When catalog or SHOW CREATE reads fail
     * @throws RestoreFailedException When a declaration or schema default is unavailable
     */
    private static function schema(string $server): SchemaMetadata
    {
        $objects = [];
        foreach (Database::sql(
            'SELECT TABLE_NAME, TABLE_TYPE, ROW_FORMAT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME',
        )->rows() as $row) {
            $name = (string)$row['TABLE_NAME'];
            $type = (string)$row['TABLE_TYPE'];
            if (!in_array($type, [self::BASE_TABLE, self::VIEW], true)) {
                throw new RestoreFailedException("Object {$name}: unsupported physical type {$type}");
            }
            $declaration = Database::sql('SHOW CREATE ' . ($type === self::VIEW ? 'VIEW ' : 'TABLE ')
                . self::identifier($name))->row();
            $sql = $declaration[$type === self::VIEW ? 'Create View' : 'Create Table'] ?? null;
            if (!is_string($sql) || $sql === '') {
                throw new RestoreFailedException("Object {$name}: the full declaration is not visible");
            }
            $objects[$name] = new LiveObject($type, $sql, $row['ROW_FORMAT'] === null ? null : (string)$row['ROW_FORMAT']);
        }
        $defaults = Database::sql(
            'SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME, @@default_storage_engine AS storage_engine,'
            . ' @@sql_mode AS sql_mode, @@lower_case_table_names AS lower_case_table_names, @@innodb_default_row_format AS row_format'
            . ' FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = DATABASE()',
        )->rows();
        if (count($defaults) !== 1) {
            throw new RestoreFailedException('The selected schema defaults are not visible');
        }
        $grants = self::grants();
        $database = Database::getConnectionConfig(Database::getCurrentIndex())->database;
        if (!self::hasSchemaPrivilege($grants, $database, 'SELECT')) {
            throw new RestoreFailedException('The complete destination object set is not visible by direct schema or global SELECT grants');
        }
        $collations = [];
        // MariaDB's aliased UCA defaults can be absent from COLLATIONS' IS_DEFAULT rows.
        foreach (Database::sql('SELECT CHARACTER_SET_NAME, DEFAULT_COLLATE_NAME FROM information_schema.CHARACTER_SETS'
            . ' ORDER BY CHARACTER_SET_NAME')->rows() as $row) {
            $collations[(string)$row['CHARACTER_SET_NAME']] = (string)$row['DEFAULT_COLLATE_NAME'];
        }
        $charsetByCollation = [];
        // MariaDB exposes charset-qualified UCA aliases in FULL_COLLATION_NAME; older catalogs use COLLATION_NAME.
        foreach (Database::sql('SELECT * FROM information_schema.COLLATION_CHARACTER_SET_APPLICABILITY'
            . ' ORDER BY CHARACTER_SET_NAME, COLLATION_NAME')->rows() as $row) {
            $name = (string)($row['FULL_COLLATION_NAME'] ?? $row['COLLATION_NAME']);
            $charset = (string)$row['CHARACTER_SET_NAME'];
            if (isset($charsetByCollation[$name]) && $charsetByCollation[$name] !== $charset) {
                throw new RestoreFailedException('The server collation character set is ambiguous');
            }
            $charsetByCollation[$name] = $charset;
        }
        return new SchemaMetadata(
            $server, $objects, $defaults,
            Database::sql('SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()'
                . ' ORDER BY TABLE_NAME, ORDINAL_POSITION')->rows(),
            Database::sql('SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, COLLATION,'
                . ' SUB_PART, PACKED, NULLABLE, INDEX_TYPE, COMMENT, INDEX_COMMENT'
                . ' FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()'
                . ' ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX')->rows(),
            new StructureDefaults((string)$defaults[0]['storage_engine'], (string)$defaults[0]['DEFAULT_CHARACTER_SET_NAME'],
                (string)$defaults[0]['DEFAULT_COLLATION_NAME'], $collations, (string)$defaults[0]['sql_mode'],
                (string)$defaults[0]['row_format'], $charsetByCollation),
            $grants,
            self::hasSchemaPrivilege($grants, $database, 'TRIGGER'),
            Database::sql('SELECT TRIGGER_SCHEMA, TRIGGER_NAME, EVENT_OBJECT_SCHEMA, EVENT_OBJECT_TABLE'
                . ' FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY TRIGGER_NAME')->rows(),
        );
    }

    /**
     * @return ServerMetadata All visible dependency types, including cross-schema and cascading FKs
     * @throws DatabaseException When catalog or grant reads fail
     */
    private static function server(): ServerMetadata
    {
        $grants = self::grants();
        $privileges = [];
        foreach ($grants as $grant) {
            if (preg_match('/^GRANT (.+) ON \*\.\* TO /D', $grant, $match) !== 1) {
                continue;
            }
            foreach (explode(', ', $match[1]) as $privilege) {
                $privileges[$privilege] = true;
            }
        }
        $complete = isset($privileges['ALL PRIVILEGES'])
            || array_diff(self::VISIBILITY_PRIVILEGES, array_keys($privileges)) === [];
        return new ServerMetadata(
            $complete, $grants,
            Database::sql('SELECT k.TABLE_SCHEMA, k.TABLE_NAME, k.CONSTRAINT_NAME, k.COLUMN_NAME, k.ORDINAL_POSITION,'
                . ' k.REFERENCED_TABLE_SCHEMA, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.UPDATE_RULE, r.DELETE_RULE'
                . ' FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r'
                . ' ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.TABLE_NAME = k.TABLE_NAME'
                . ' AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME WHERE k.REFERENCED_TABLE_NAME IS NOT NULL'
                . ' ORDER BY k.TABLE_SCHEMA, k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION')->rows(),
            self::definitions('SELECT TABLE_SCHEMA, TABLE_NAME, VIEW_DEFINITION, CHECK_OPTION, IS_UPDATABLE, DEFINER, SECURITY_TYPE'
                . ' FROM information_schema.VIEWS ORDER BY TABLE_SCHEMA, TABLE_NAME',
                'VIEW', 'TABLE_SCHEMA', 'TABLE_NAME', 'Create View', $complete),
            self::definitions('SELECT TRIGGER_SCHEMA, TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_SCHEMA, EVENT_OBJECT_TABLE,'
                . ' ACTION_ORDER, ACTION_STATEMENT, ACTION_ORIENTATION, ACTION_TIMING, SQL_MODE, DEFINER'
                . ' FROM information_schema.TRIGGERS ORDER BY TRIGGER_SCHEMA, TRIGGER_NAME',
                'TRIGGER', 'TRIGGER_SCHEMA', 'TRIGGER_NAME', 'SQL Original Statement', $complete),
            self::definitions('SELECT ROUTINE_SCHEMA, ROUTINE_NAME, ROUTINE_TYPE, ROUTINE_DEFINITION, SQL_DATA_ACCESS,'
                . ' SECURITY_TYPE, SQL_MODE, DEFINER FROM information_schema.ROUTINES ORDER BY ROUTINE_SCHEMA, ROUTINE_NAME',
                'ROUTINE', 'ROUTINE_SCHEMA', 'ROUTINE_NAME', 'Create ', $complete),
            self::definitions('SELECT EVENT_SCHEMA, EVENT_NAME, EVENT_DEFINITION, EVENT_TYPE, STATUS, SQL_MODE, DEFINER'
                . ' FROM information_schema.EVENTS ORDER BY EVENT_SCHEMA, EVENT_NAME',
                'EVENT', 'EVENT_SCHEMA', 'EVENT_NAME', 'Create Event', $complete),
            $complete ? Database::sql('SELECT name, ret, dl, type FROM mysql.func ORDER BY name')->rows() : [],
        );
    }

    /**
     * INFORMATION_SCHEMA can deparse a routine's quoted literals without SQL escaping.
     * SHOW CREATE retains the executable spelling and is the source of lexical dependency proof.
     *
     * @param string $query Ordered catalog query
     * @param string $kind SHOW CREATE family; ROUTINE resolves per row
     * @param string $schemaColumn Catalog schema field
     * @param string $nameColumn Catalog object field
     * @param string $declarationColumn SHOW CREATE SQL field or the routine field prefix
     * @param bool $complete Whether grants establish the right to read every definition
     * @return list<array<string, mixed>> Catalog rows with complete executable declarations or explicit absence
     * @throws DatabaseException When an admitted declaration cannot be read
     */
    private static function definitions(
        string $query,
        string $kind,
        string $schemaColumn,
        string $nameColumn,
        string $declarationColumn,
        bool $complete,
    ): array {
        $rows = Database::sql($query)->rows();
        foreach ($rows as &$row) {
            $row[ServerMetadata::CREATE_SQL] = null;
            if (!$complete) {
                continue;
            }
            $type = $kind === 'ROUTINE' ? (string)$row['ROUTINE_TYPE'] : $kind;
            $column = $kind === 'ROUTINE' ? $declarationColumn . ucfirst(strtolower($type)) : $declarationColumn;
            $declaration = Database::sql('SHOW CREATE ' . $type . ' '
                . self::identifier((string)$row[$schemaColumn]) . '.'
                . self::identifier((string)$row[$nameColumn]))->row();
            $row[ServerMetadata::CREATE_SQL] = $declaration[$column] ?? null;
        }
        unset($row);
        return $rows;
    }

    /**
     * Physical metadata can name dollars, spaces or backticks; the journal database validator cannot quote those objects.
     *
     * @param string $name Server-reported schema or object name
     * @return string Safely quoted SQL identifier
     */
    private static function identifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /**
     * @return list<string> Stable direct grant evidence for this account
     * @throws DatabaseException When grants cannot be read
     */
    private static function grants(): array
    {
        $grants = array_map(static fn (array $row): string => (string)reset($row), Database::sql('SHOW GRANTS')->rows());
        sort($grants);
        return $grants;
    }

    /**
     * @param list<string> $grants Account's direct grants; role and wildcard-only proofs are deliberately not inferred
     * @param string $database Configured physical schema
     * @param string $privilege Required direct schema or global privilege
     * @return bool Whether a global or exact schema grant establishes the privilege
     */
    private static function hasSchemaPrivilege(array $grants, string $database, string $privilege): bool
    {
        foreach ($grants as $grant) {
            if (preg_match('/^GRANT ([A-Z ]+(?:, [A-Z ]+)*) ON (\*|`(?:``|[^`])+`|[a-zA-Z0-9$]+)\.\* TO /D', $grant, $match) !== 1) {
                continue;
            }
            $privileges = explode(', ', $match[1]);
            $schema = str_starts_with($match[2], '`') ? str_replace('``', '`', substr($match[2], 1, -1)) : $match[2];
            // SHOW GRANTS quotes identifiers, but preserves the database pattern's backslashes.
            // Exact escaped names avoid MariaDB's precedence between overlapping wildcard grants.
            $exact = str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $database);
            if (($match[2] === '*' || $schema === $exact)
                && (in_array('ALL PRIVILEGES', $privileges, true) || in_array($privilege, $privileges, true))) {
                return true;
            }
        }
        return false;
    }
}
