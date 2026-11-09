<?php

declare(strict_types=1);

namespace Hilos\Backup;

use DateTimeImmutable;
use DateTimeZone;
use Hilos\Constants\TimeConstants;
use Hilos\Backup\Exception\RestoreFailedException;
use Hilos\Backup\FullRestore\ByteRange;
use Hilos\Backup\FullRestore\Dump;
use Hilos\Backup\FullRestore\DumpDefinitionReader;
use Hilos\Backup\FullRestore\DumpObject;
use Hilos\Backup\FullRestore\DumpObjectType;
use Hilos\Backup\FullRestore\DumpServerVersion;
use Hilos\Backup\FullRestore\DumpSessionReader;
use Hilos\Backup\FullRestore\DumpSqlLexer;
use Hilos\Backup\FullRestore\MigrationHistory;
use Hilos\Backup\FullRestore\MigrationHistoryState;
use Hilos\Backup\FullRestore\MigrationRow;
use Hilos\Backup\FullRestore\SqlToken;
use Hilos\Backup\FullRestore\SqlTokens;
use Hilos\Database\DatabaseMarker;
use Hilos\Fs\FsException;
use Hilos\Fs\FsPath;

/**
 * FULL restore preflight and primary staging, used by FullRestorePlan before any import.
 * No statement is executed here.
 *
 * Only mysqldump's table/view, literal data and session grammar is admitted. Unknown or
 * contradictory input refuses the whole read; absence requires mysqldump's completion comment
 * followed by a complete EOF, including when the connection contains no tables.
 * The parser keeps schema/history and marker byte ranges, never a whole data statement.
 */
final class FullRestoreDumpReader
{
    private const int MAX_DEFINITION_BYTES = 1048576;
    private const int MAX_DEFINITION_TOKENS = 32768;
    private const array MARKER_COLUMNS = ['id', 'marker', 'written_by', 'written_at'];

    /** @var array<string, DumpObject> Final declarations, not text matches */
    private array $declaredObjects = [];
    /** @var array<string, list<DumpObjectType>> Drops awaiting a matching declaration */
    private array $pendingDrops = [];
    /** @var array<string, true> Tables that already received data */
    private array $dataTables = [];
    /** @var array<int, MigrationRow> History keyed by explicit index to detect duplicates */
    private array $migrationRows = [];
    /** @var list<ByteRange> Statements and session wrappers omitted from primary */
    private array $excluded = [];

    private DumpSqlLexer $lexer;
    private DumpSessionReader $session;
    private ?SqlToken $token = null;
    private int $statementStart = 0;
    private int $statementEnd = 0;
    private ?string $lockedTable = null;
    private ?string $disabledTable = null;
    private bool $markerRowSeen = false;

    /**
     * Parsing state belongs to one pinned file and one call, with no reader in another process.
     *
     * @param resource $handle Extracted dump handle owned by FsPath
     * @param DumpServerVersion $server Destination's executable comment grammar
     */
    private function __construct(private readonly mixed $handle, DumpServerVersion $server)
    {
        $this->lexer = new DumpSqlLexer($handle, $server);
        $this->session = new DumpSessionReader();
    }

    /**
     * @param string $path Extracted FULL dump file
     * @param DumpServerVersion $server Version of the server that will import this file
     * @return Dump Admitted objects, DDL, foreign keys and explicit migration history
     * @throws RestoreFailedException When the file is unreadable, incomplete, unsupported or contradictory
     */
    public static function read(string $path, DumpServerVersion $server): Dump
    {
        try {
            return FsPath::readWith($path, static fn ($handle): Dump => new self($handle, $server)->parse());
        } catch (FsException $failure) {
            throw RestoreFailedException::beforeDestructive('Cannot read the full restore dump', $failure);
        }
    }

    /**
     * Validates the entire extracted copy before writing a staging file. The destination must
     * not exist; even a destination naming the source (or its hard link) is never replaced.
     * On refusal no staging file is published. All other bytes retain their original order.
     *
     * @param string $path Extracted primary FULL dump, never a published archive or sidecar
     * @param string $stagingPath New file to receive the admitted stream without the marker block
     * @param DumpServerVersion $server Version of the server that will import this file
     * @return Dump Metadata from the same parse that selected the excluded byte ranges
     * @throws RestoreFailedException When admission or staging fails, before any database write
     */
    public static function stagePrimary(string $path, string $stagingPath, DumpServerVersion $server): Dump
    {
        try {
            return FsPath::readWith($path, static function ($handle) use ($stagingPath, $server): Dump {
                $reader = new self($handle, $server);
                $dump = $reader->parse();
                $reader->writeStaging($stagingPath);
                return $dump;
            });
        } catch (FsException $failure) {
            throw RestoreFailedException::beforeDestructive('Cannot prepare the full restore staging file', $failure);
        }
    }

    /**
     * @return Dump Metadata only after the complete file has been admitted
     * @throws RestoreFailedException When any part of the dump cannot be admitted
     */
    private function parse(): Dump
    {
        $this->advance();
        while ($this->token !== null) {
            $family = $this->token;
            $this->session->beforeStatement($family);
            if ($family->is('INSERT')) {
                $table = $this->insert();
            } else {
                $tokens = $this->statement();
                $cursor = new SqlTokens($tokens);
                $table = match (strtoupper($family->value)) {
                    'CREATE' => $this->create($tokens),
                    'DROP' => $this->drop($cursor),
                    'ALTER' => $this->alter($cursor),
                    'LOCK' => $this->lock($cursor),
                    'UNLOCK' => $this->unlock($cursor),
                    'SET' => $this->session->consume($tokens, $this->range()),
                    'COMMIT' => $this->session->commit($tokens),
                    default => throw new RestoreFailedException('Unsupported full dump statement family'),
                };
                if ($family->kind !== SqlToken::WORD) {
                    throw new RestoreFailedException('Unsupported quoted statement family in full dump');
                }
            }
            if ($table === DatabaseMarker::TABLE) {
                $this->excluded[] = $this->range();
            }
            $this->statementStart = $this->statementEnd;
            $this->lexer->setSqlModeKnown($this->session->hasKnownSqlMode());
            $this->advance();
        }
        if (!$this->lexer->dumpCompleted()) {
            throw new RestoreFailedException('Incomplete full dump: the mysqldump completion comment is missing');
        }
        if ($this->pendingDrops !== [] || $this->lockedTable !== null || $this->disabledTable !== null) {
            throw new RestoreFailedException('Incomplete or contradictory full dump object block');
        }
        $this->session->finish();
        $state = !isset($this->declaredObjects[MigrationHistory::TABLE])
            ? MigrationHistoryState::ABSENT
            : ($this->migrationRows === [] ? MigrationHistoryState::EMPTY : MigrationHistoryState::RECORDED);

        return new Dump($this->declaredObjects, new MigrationHistory($state, array_values($this->migrationRows)));
    }

    /**
     * @param list<SqlToken> $tokens Bounded CREATE statement
     * @return string Created object name
     * @throws RestoreFailedException When dump syntax is incomplete, unsupported or contradictory
     */
    private function create(array $tokens): string
    {
        $object = DumpDefinitionReader::read($tokens, $this->sourceBytes($this->statementStart, $this->statementEnd));
        if ($this->lockedTable !== null || $this->disabledTable !== null) {
            throw new RestoreFailedException('Contradictory CREATE inside a dump data block');
        }
        foreach ($this->declaredObjects as $declared) {
            if ($declared->name !== $object->name && strcasecmp($declared->name, $object->name) === 0) {
                throw new RestoreFailedException('Contradictory object identifier casing in full dump');
            }
        }
        if (isset($this->declaredObjects[$object->name])) {
            // mysqldump may declare a temporary view (or a table-shaped placeholder) before
            // its final view. Ordinary table redeclarations are never that placeholder.
            if ($object->type !== DumpObjectType::VIEW || isset($this->dataTables[$object->name])
                || !in_array($this->declaredObjects[$object->name]->type, $this->pendingDrops[$object->name] ?? [], true)) {
                throw new RestoreFailedException("Contradictory duplicate object declaration: {$object->name}");
            }
        }
        if (strcasecmp($object->name, MigrationHistory::TABLE) === 0
            && ($object->name !== MigrationHistory::TABLE || $object->type !== DumpObjectType::TABLE || count($object->columns) !== 2
                || !in_array('index', $object->columns, true) || !in_array('failed', $object->columns, true))) {
            throw new RestoreFailedException('Unsupported migration table shape in full dump');
        }
        if (strcasecmp($object->name, DatabaseMarker::TABLE) === 0
            && ($object->name !== DatabaseMarker::TABLE || $object->type !== DumpObjectType::TABLE
                || $object->columns !== self::MARKER_COLUMNS || $object->foreignKeys !== [])) {
            throw new RestoreFailedException('Unsupported database marker declaration in full dump');
        }
        $this->declaredObjects[$object->name] = $object;
        unset($this->pendingDrops[$object->name]);
        foreach ($this->session->claimObject($object->name) as $range) {
            $this->excluded[] = $range;
        }
        return $object->name;
    }

    /**
     * @param SqlTokens $cursor Complete DROP statement
     * @return string Dropped object name
     * @throws RestoreFailedException When dump syntax is incomplete, unsupported or contradictory
     */
    private function drop(SqlTokens $cursor): string
    {
        $cursor->expect('DROP');
        if ($cursor->takeIf('TABLE')) {
            $type = DumpObjectType::TABLE;
        } else {
            $cursor->expect('VIEW');
            $type = DumpObjectType::VIEW;
        }
        $cursor->expect('IF');
        $cursor->expect('EXISTS');
        $table = $cursor->name();
        if (!$cursor->ended() || $this->lockedTable !== null || $this->disabledTable !== null
            || in_array($type, $this->pendingDrops[$table] ?? [], true)
            || (strcasecmp($table, DatabaseMarker::TABLE) === 0 && ($table !== DatabaseMarker::TABLE
                || $type !== DumpObjectType::TABLE || isset($this->declaredObjects[$table])))) {
            throw new RestoreFailedException('Unsupported or contradictory DROP in full dump');
        }
        $this->pendingDrops[$table][] = $type;
        return $table;
    }

    /**
     * @param SqlTokens $cursor Complete ALTER statement
     * @return string Table whose key wrapper changed
     * @throws RestoreFailedException When dump syntax is incomplete, unsupported or contradictory
     */
    private function alter(SqlTokens $cursor): string
    {
        $cursor->expect('ALTER');
        $cursor->expect('TABLE');
        $table = $cursor->name();
        $this->dataObject($table);
        if ($cursor->takeIf('DISABLE')) {
            if ($this->disabledTable !== null) {
                throw new RestoreFailedException('Contradictory DISABLE KEYS in full dump');
            }
            $this->disabledTable = $table;
        } else {
            $cursor->expect('ENABLE');
            if ($this->disabledTable !== $table) {
                throw new RestoreFailedException('Contradictory ENABLE KEYS in full dump');
            }
            $this->disabledTable = null;
        }
        $cursor->expect('KEYS');
        if (!$cursor->ended()) {
            throw new RestoreFailedException('Unsupported ALTER TABLE in full dump');
        }
        return $table;
    }

    /**
     * @param SqlTokens $cursor Complete LOCK statement
     * @return string Locked data table
     * @throws RestoreFailedException When dump syntax is incomplete, unsupported or contradictory
     */
    private function lock(SqlTokens $cursor): string
    {
        $cursor->expect('LOCK');
        $cursor->expect('TABLES');
        $table = $cursor->name();
        $this->dataObject($table);
        $cursor->expect('WRITE');
        if (!$cursor->ended() || $this->lockedTable !== null) {
            throw new RestoreFailedException('Unsupported or contradictory LOCK TABLES in full dump');
        }
        $this->lockedTable = $table;
        return $table;
    }

    /**
     * @param SqlTokens $cursor Complete UNLOCK statement
     * @return string Previously locked data table
     * @throws RestoreFailedException When dump syntax is incomplete, unsupported or contradictory
     */
    private function unlock(SqlTokens $cursor): string
    {
        $cursor->expect('UNLOCK');
        $cursor->expect('TABLES');
        if (!$cursor->ended() || $this->lockedTable === null || $this->disabledTable !== null) {
            throw new RestoreFailedException('Contradictory UNLOCK TABLES in full dump');
        }
        $table = $this->lockedTable;
        $this->lockedTable = null;
        return $table;
    }

    /**
     * @return string Table whose literal data has been consumed without buffering its INSERT
     * @throws RestoreFailedException When dump syntax is incomplete, unsupported or contradictory
     */
    private function insert(): string
    {
        $this->expect('INSERT');
        $this->expect('INTO');
        $table = $this->name();
        $this->dataObject($table);
        $columns = $this->declaredObjects[$table]->columns;
        if ($this->is('(')) {
            $this->advance();
            $columns = [$this->name()];
            while ($this->is(',')) {
                $this->advance();
                $columns[] = $this->name();
            }
            $this->expect(')');
            if (count($columns) !== count(array_unique($columns))
                || array_diff($columns, $this->declaredObjects[$table]->columns) !== []) {
                throw new RestoreFailedException("Contradictory INSERT columns for {$table}");
            }
        }
        if (($table === MigrationHistory::TABLE || $table === DatabaseMarker::TABLE)
            && count($columns) !== count($this->declaredObjects[$table]->columns)) {
            throw new RestoreFailedException("Incomplete service-table INSERT for {$table}");
        }
        $this->expect('VALUES');
        do {
            $this->expect('(');
            $values = [];
            $count = 0;
            do {
                if ($count >= count($columns)) {
                    throw new RestoreFailedException("Contradictory INSERT value count for {$table}");
                }
                $literal = $this->literal();
                if ($table === MigrationHistory::TABLE || $table === DatabaseMarker::TABLE) {
                    $values[] = $literal;
                }
                $count++;
                if (!$this->is(',')) {
                    break;
                }
                $this->advance();
            } while (true);
            $this->expect(')');
            if ($count !== count($columns)) {
                throw new RestoreFailedException("Contradictory INSERT value count for {$table}");
            }
            if ($table === MigrationHistory::TABLE) {
                $this->recordMigration($columns, $values);
            } elseif ($table === DatabaseMarker::TABLE) {
                $this->recordMarker($columns, $values);
            }
            if (!$this->is(',')) {
                break;
            }
            $this->advance();
        } while (true);
        $this->delimiter();
        $this->dataTables[$table] = true;
        return $table;
    }

    /**
     * @return SqlToken Scalar literal, with introduced strings distinct from integer history values
     * @throws RestoreFailedException When dump syntax is incomplete, unsupported or contradictory
     */
    private function literal(): SqlToken
    {
        $literal = $this->requiredToken();
        $this->advance();
        if ($literal->kind === SqlToken::STRING) {
            return $literal;
        }
        if ($literal->kind === SqlToken::WORD && (str_starts_with($literal->value, '_')
            || $literal->is('X') || $literal->is('B'))) {
            $value = $this->requiredToken();
            if (!$literal->isIntroducerFor($value)) {
                throw new RestoreFailedException('Unsupported introduced literal in full dump');
            }
            $this->advance();
            // Introduced strings are valid data, but are not integer migration values.
            return new SqlToken(SqlToken::SYMBOL, $literal->value, $literal->start, $value->end);
        }
        if ($literal->is('NULL')) {
            return $literal;
        }
        $value = $literal->value;
        if ($literal->is('-') || $literal->is('+')) {
            $number = $this->requiredToken();
            if ($number->kind !== SqlToken::WORD || !$number->complete) {
                throw new RestoreFailedException('Unsupported signed dump literal');
            }
            $value .= $number->value;
            $this->advance();
        }
        if ($this->is('.')) {
            $this->advance();
            $fraction = $this->requiredToken();
            if ($fraction->kind !== SqlToken::WORD || !$fraction->complete) {
                throw new RestoreFailedException('Unsupported fractional dump literal');
            }
            $value .= '.' . $fraction->value;
            $this->advance();
        }
        if (str_ends_with(strtolower($value), 'e') && ($this->is('+') || $this->is('-'))) {
            $value .= $this->requiredToken()->value;
            $this->advance();
            $value .= $this->requiredToken()->value;
            $this->advance();
        }
        if (!$literal->complete || !preg_match('/^(?:[+-]?\d+(?:\.\d+)?(?:e[+-]?\d+)?|0x[0-9a-f]+)$/iD', $value)) {
            throw new RestoreFailedException('Unsupported data expression in full dump');
        }
        return new SqlToken(SqlToken::WORD, $value, $literal->start, $literal->end);
    }

    /**
     * @param list<string> $columns Explicit or storage-order columns
     * @param list<SqlToken> $values One complete row
     * @throws RestoreFailedException When dump syntax is incomplete, unsupported or contradictory
     */
    private function recordMigration(array $columns, array $values): void
    {
        $index = $this->unsignedInteger($values[array_search('index', $columns, true)]);
        $failed = $this->unsignedInteger($values[array_search('failed', $columns, true)]);
        if ($failed > 1 || isset($this->migrationRows[$index])) {
            throw new RestoreFailedException('Contradictory migration history in full dump');
        }
        $this->migrationRows[$index] = new MigrationRow($index, $failed === 1);
    }

    /**
     * @param list<string> $columns Explicit or storage-order columns
     * @param list<SqlToken> $values One complete marker row
     * @throws RestoreFailedException When dump syntax is incomplete, unsupported or contradictory
     */
    private function recordMarker(array $columns, array $values): void
    {
        if ($this->markerRowSeen || $this->unsignedInteger($values[array_search('id', $columns, true)]) !== DatabaseMarker::ROW_ID) {
            throw new RestoreFailedException('Contradictory database marker data in full dump');
        }
        foreach (['marker', 'written_by'] as $column) {
            if ($values[array_search($column, $columns, true)]->kind !== SqlToken::STRING) {
                throw new RestoreFailedException('Unsupported database marker data in full dump');
            }
        }
        $moment = $values[array_search('written_at', $columns, true)];
        if ($moment->kind === SqlToken::STRING) {
            if (str_contains($moment->value, "\0")) {
                throw new RestoreFailedException('Invalid legacy database marker moment in full dump');
            }
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $moment->value, new DateTimeZone(TimeConstants::UTC_TIME_ZONE));
            if ($parsed === false || $parsed->format('Y-m-d H:i:s') !== $moment->value) {
                throw new RestoreFailedException('Invalid legacy database marker moment in full dump');
            }
        } else {
            $this->signedMarkerInteger($moment);
        }
        $this->markerRowSeen = true;
    }

    /**
     * @param SqlToken $token Explicit signed decimal epoch-millisecond literal
     * @return int Admitted int64 value, checked before conversion
     * @throws RestoreFailedException When the marker moment is not a signed integer or exceeds int64
     */
    private function signedMarkerInteger(SqlToken $token): int
    {
        if (!$token->complete || $token->kind !== SqlToken::WORD || preg_match('/^[+-]?[0-9]+$/D', $token->value) !== 1) {
            throw new RestoreFailedException('Unsupported database marker moment syntax in full dump');
        }
        $digits = ltrim(ltrim($token->value, '+-'), '0');
        $limit = str_starts_with($token->value, '-') ? substr((string)PHP_INT_MIN, 1) : (string)PHP_INT_MAX;
        if (strlen($digits) > strlen($limit) || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) {
            throw new RestoreFailedException('Unsupported database marker moment range in full dump');
        }

        return (int)$token->value;
    }

    /**
     * @param SqlToken $token Explicit unsigned integer literal
     * @return int Admitted integer without overflow or a missing-value fallback
     * @throws RestoreFailedException When dump syntax is incomplete, unsupported or contradictory
     */
    private function unsignedInteger(SqlToken $token): int
    {
        if (!$token->complete || !in_array($token->kind, [SqlToken::WORD, SqlToken::STRING], true)
            || preg_match('/^\d+$/D', $token->value) !== 1) {
            throw new RestoreFailedException('Unknown migration or marker integer syntax in full dump');
        }
        $digits = ltrim($token->value, '0');
        if (strlen($digits) > strlen((string)PHP_INT_MAX)
            || (strlen($digits) === strlen((string)PHP_INT_MAX) && strcmp($digits, (string)PHP_INT_MAX) > 0)) {
            throw new RestoreFailedException('Unsupported migration or marker integer range in full dump');
        }
        return (int)$token->value;
    }

    /**
     * @param string $table Required table target in the current data block
     * @throws RestoreFailedException When dump syntax is incomplete, unsupported or contradictory
     */
    private function dataObject(string $table): void
    {
        $this->session->assertDataOwner($table);
        if (!isset($this->declaredObjects[$table]) || $this->declaredObjects[$table]->type !== DumpObjectType::TABLE
            || isset($this->pendingDrops[$table])
            || ($this->lockedTable !== null && $this->lockedTable !== $table)
            || ($this->disabledTable !== null && $this->disabledTable !== $table)) {
            throw new RestoreFailedException("Contradictory or undeclared dump data target: {$table}");
        }
    }

    /**
     * @return list<SqlToken> Bounded statement tokens, with the delimiter consumed
     * @throws RestoreFailedException When dump syntax is incomplete, unsupported or contradictory
     */
    private function statement(): array
    {
        $tokens = [];
        while ($this->token !== null && !$this->is(';')) {
            if ($this->token->end - $this->statementStart > self::MAX_DEFINITION_BYTES
                || count($tokens) >= self::MAX_DEFINITION_TOKENS) {
                throw new RestoreFailedException('Unsupported oversized dump definition');
            }
            $tokens[] = $this->token;
            $this->advance();
        }
        $this->delimiter();
        return $tokens;
    }

    /**
     * @throws RestoreFailedException When dump syntax is incomplete, unsupported or contradictory
     */
    private function delimiter(): void
    {
        if (!$this->is(';')) {
            throw new RestoreFailedException('Incomplete full dump statement: missing delimiter');
        }
        $this->statementEnd = $this->token->end;
    }

    /**
     * @throws RestoreFailedException When the next token cannot be read or decoded
     */
    private function advance(): void
    {
        $this->token = $this->lexer->next();
    }

    /**
     * @param string $text Keyword or punctuation
     * @return bool Whether the current unquoted token matches
     */
    private function is(string $text): bool
    {
        return $this->token?->is($text) ?? false;
    }

    /**
     * @param string $text Required keyword or punctuation
     * @throws RestoreFailedException When dump syntax is incomplete, unsupported or contradictory
     */
    private function expect(string $text): void
    {
        if (!$this->is($text)) {
            throw new RestoreFailedException("Unsupported full dump syntax: expected {$text}");
        }
        $this->advance();
    }

    /**
     * @return SqlToken Required current token
     * @throws RestoreFailedException When dump syntax is incomplete, unsupported or contradictory
     */
    private function requiredToken(): SqlToken
    {
        if ($this->token === null) {
            throw new RestoreFailedException('Incomplete full dump statement');
        }
        return $this->token;
    }

    /**
     * @return string Required identifier without advancing past an incomplete name
     * @throws RestoreFailedException When dump syntax is incomplete, unsupported or contradictory
     */
    private function name(): string
    {
        $name = new SqlTokens([$this->requiredToken()])->name();
        $this->advance();
        return $name;
    }

    /**
     * @return ByteRange Current complete statement including its leading trivia
     */
    private function range(): ByteRange
    {
        return new ByteRange($this->statementStart, $this->statementEnd);
    }

    /**
     * @param int $start Inclusive statement offset
     * @param int $end Exclusive statement offset
     * @return string Original bounded DDL bytes
     * @throws RestoreFailedException When the pinned source cannot be sought or read
     */
    private function sourceBytes(int $start, int $end): string
    {
        $position = ftell($this->handle);
        if ($position === false || fseek($this->handle, $start) !== 0) {
            throw new RestoreFailedException('Cannot seek the full restore dump');
        }
        $sql = '';
        while (strlen($sql) < $end - $start) {
            $chunk = fread($this->handle, $end - $start - strlen($sql));
            if ($chunk === false || $chunk === '') {
                throw new RestoreFailedException('Cannot read full restore DDL');
            }
            $sql .= $chunk;
        }
        if (fseek($this->handle, $position) !== 0) {
            throw new RestoreFailedException('Cannot resume reading the full restore dump');
        }
        return $sql;
    }

    /**
     * @param string $path New destination, published only when its copy is complete
     * @throws FsException When temporary staging or publication fails
     * @throws RestoreFailedException When the pinned source cannot be copied
     */
    private function writeStaging(string $path): void
    {
        usort($this->excluded, static fn (ByteRange $a, ByteRange $b): int => $a->start <=> $b->start);
        $temporary = $path . '.preparing';
        if (!FsPath::createExclusive($temporary, '')) {
            throw new RestoreFailedException('Full restore staging preparation already exists');
        }
        try {
            FsPath::chmod($temporary, 0600);
            $offset = 0;
            foreach ($this->excluded as $range) {
                if ($range->start > $offset) {
                    $this->copyRange($temporary, $offset, $range->start);
                }
                $offset = max($offset, $range->end);
            }
            $this->copyRange($temporary, $offset, $this->lexer->offset());
            FsPath::publishExclusive($temporary, $path);
        } finally {
            FsPath::delete($temporary);
        }
    }

    /**
     * @param string $temporary Unpublished staging file
     * @param int $start First retained source byte
     * @param int $end Exclusive end of the retained range
     * @throws FsException When a staging append fails
     * @throws RestoreFailedException When the source cannot be read
     */
    private function copyRange(string $temporary, int $start, int $end): void
    {
        if ($end < $start || fseek($this->handle, $start) !== 0) {
            throw new RestoreFailedException('Cannot seek a full restore staging range');
        }
        while ($start < $end) {
            $chunk = fread($this->handle, min(DumpSqlLexer::CHUNK_BYTES, $end - $start));
            if ($chunk === false || $chunk === '') {
                throw new RestoreFailedException('Cannot read a full restore staging range');
            }
            FsPath::append($temporary, $chunk);
            $start += strlen($chunk);
        }
    }
}
