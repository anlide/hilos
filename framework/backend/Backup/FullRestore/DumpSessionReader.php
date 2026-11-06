<?php

declare(strict_types=1);

namespace Hilos\Backup\FullRestore;

use Hilos\Backup\Exception\RestoreFailedException;
use Hilos\Database\DatabaseMarker;

/** The session vocabulary emitted by mysqldump, including per-object charset and autocommit wrappers. */
final class DumpSessionReader
{
    private const array WRAPPERS = [
        '@SAVED_CS_CLIENT' => 'CHARACTER_SET_CLIENT',
        '@SAVED_CS_RESULTS' => 'CHARACTER_SET_RESULTS',
        '@SAVED_COL_CONNECTION' => 'COLLATION_CONNECTION',
    ];
    private const array SAVED_GLOBALS = [
        '@OLD_CHARACTER_SET_CLIENT' => 'CHARACTER_SET_CLIENT',
        '@OLD_CHARACTER_SET_RESULTS' => 'CHARACTER_SET_RESULTS',
        '@OLD_COLLATION_CONNECTION' => 'COLLATION_CONNECTION',
        '@OLD_TIME_ZONE' => 'TIME_ZONE',
        '@OLD_UNIQUE_CHECKS' => 'UNIQUE_CHECKS',
        '@OLD_FOREIGN_KEY_CHECKS' => 'FOREIGN_KEY_CHECKS',
        '@OLD_SQL_MODE' => 'SQL_MODE',
        '@OLD_SQL_NOTES' => 'SQL_NOTES',
        '@OLD_NOTE_VERBOSITY' => 'NOTE_VERBOSITY',
        '@MYSQLDUMP_TEMP_LOG_BIN' => 'SESSION.SQL_LOG_BIN',
    ];

    /** @var array<string, true> Per-object saved variables still awaiting restoration */
    private array $saved = [];
    /** @var list<ByteRange> Setup statements owned by the following CREATE */
    private array $setup = [];
    private ?string $owner = null;
    private bool $sqlModeRestored = false;
    private bool $sqlModeKnown = false;
    private ?string $lastObject = null;
    private ?string $transactionOwner = null;
    private bool $transactionCommitted = false;

    /**
     * @param SqlToken $family Next statement family
     * @throws RestoreFailedException When a wrapper is incomplete or a restored session mode makes the next statement unsafe
     */
    public function beforeStatement(SqlToken $family): void
    {
        if (($this->saved !== [] && !$family->is('SET') && ($this->owner !== null || !$family->is('CREATE')))
            || ($this->sqlModeRestored && !$family->is('SET'))
            || ($this->transactionCommitted && !$family->is('SET'))) {
            throw new RestoreFailedException('Incomplete dump charset wrapper or unknown restored SQL mode');
        }
    }

    /** @return bool Whether a retained SQL_MODE literal established the lexer's quote semantics */
    public function hasKnownSqlMode(): bool
    {
        return $this->sqlModeKnown;
    }

    /**
     * @param string $name Newly declared object
     * @return list<ByteRange> Marker setup statements to exclude; no ranges for another object
     * @throws RestoreFailedException When a second CREATE interrupts a charset or autocommit wrapper
     */
    public function claimObject(string $name): array
    {
        if ($this->owner !== null || $this->transactionOwner !== null) {
            throw new RestoreFailedException('Contradictory dump charset wrapper owner');
        }
        $this->lastObject = $name;
        if ($this->saved === []) {
            return [];
        }
        $this->owner = $name;
        return $name === DatabaseMarker::TABLE ? $this->setup : [];
    }

    /**
     * @param list<SqlToken> $tokens Complete SET statement
     * @param ByteRange $range Original statement byte range
     * @return ?string Marker owner for an excluded restore statement, otherwise null
     * @throws RestoreFailedException When a SET is not part of the admitted dump vocabulary
     */
    public function consume(array $tokens, ByteRange $range): ?string
    {
        // Exact unquoted forms emitted by MariaDB's per-table --no-autocommit data pass.
        $words = array_map(static fn (SqlToken $token): ?string => $token->complete
            && in_array($token->kind, [SqlToken::WORD, SqlToken::SYMBOL], true) ? strtoupper($token->value) : null, $tokens);
        if ($words === ['SET', '@', 'OLD_AUTOCOMMIT', '=', '@', '@', 'AUTOCOMMIT', ',', '@', '@', 'AUTOCOMMIT', '=', '0']) {
            if ($this->transactionOwner !== null || $this->lastObject === null || $this->saved !== []) {
                throw new RestoreFailedException('Contradictory dump autocommit setup');
            }
            $this->transactionOwner = $this->lastObject;
            return $this->transactionOwner === DatabaseMarker::TABLE ? $this->transactionOwner : null;
        }
        if ($words === ['SET', 'AUTOCOMMIT', '=', '@', 'OLD_AUTOCOMMIT']) {
            if ($this->transactionOwner === null || !$this->transactionCommitted) {
                throw new RestoreFailedException('Contradictory dump autocommit restore');
            }
            $table = $this->transactionOwner;
            $this->transactionOwner = null;
            $this->transactionCommitted = false;
            return $table === DatabaseMarker::TABLE ? $table : null;
        }
        $cursor = new SqlTokens($tokens);
        $cursor->expect('SET');
        if ($cursor->takeIf('NAMES')) {
            if ($this->saved !== [] || !in_array(strtolower($cursor->name()), ['utf8', 'utf8mb3', 'utf8mb4', 'latin1'], true)) {
                throw new RestoreFailedException('Unsupported dump connection charset');
            }
            if ($cursor->takeIf('COLLATE')) {
                $cursor->name();
            }
            if (!$cursor->ended()) {
                throw new RestoreFailedException('Unsupported SET NAMES in full dump');
            }
            return null;
        }

        $owner = $this->owner;
        $assignments = SqlTokens::split($cursor->consumeRest());
        $tableScoped = false;
        $globalScoped = false;
        foreach ($assignments as $assignment) {
            $targetTokens = [];
            foreach ($assignment as $token) {
                if ($token->is('=')) {
                    break;
                }
                $targetTokens[] = $token;
            }
            $target = self::variable($targetTokens);
            if (isset(self::WRAPPERS[$target]) || in_array($target, self::WRAPPERS, true)) {
                $tableScoped = true;
            } else {
                $globalScoped = true;
            }
        }
        if ($tableScoped && $globalScoped) {
            throw new RestoreFailedException('Unsupported mixed global and table SET in full dump');
        }
        foreach ($assignments as $assignment) {
            $equals = null;
            foreach ($assignment as $index => $token) {
                if ($token->is('=')) {
                    $equals = $index;
                    break;
                }
            }
            if ($equals === null || $equals === 0 || $equals === count($assignment) - 1) {
                throw new RestoreFailedException('Unsupported SET assignment in full dump');
            }
            $target = self::variable(array_slice($assignment, 0, $equals));
            $values = array_slice($assignment, $equals + 1);
            $quoted = count($values) === 1 && $values[0]->kind === SqlToken::STRING;
            $value = $quoted ? $values[0]->value : self::variable($values);
            if (count($values) === 1 && !$values[0]->complete) {
                throw new RestoreFailedException('Unsupported oversized SET value in full dump');
            }
            if (!$quoted && isset(self::WRAPPERS[$target]) && $value === '@@' . self::WRAPPERS[$target]) {
                if ($this->owner !== null || isset($this->saved[$target])) {
                    throw new RestoreFailedException('Contradictory dump charset save');
                }
                $this->saved[$target] = true;
                $this->setup[] = $range;
                continue;
            }
            $wrapper = array_search($target, self::WRAPPERS, true);
            if ($wrapper !== false && isset($this->saved[$wrapper])) {
                if (!$quoted && $value === $wrapper && $this->owner !== null) {
                    unset($this->saved[$wrapper]);
                    if ($this->saved === []) {
                        $this->owner = null;
                        $this->setup = [];
                    }
                    continue;
                }
                if ($this->owner === null && self::literalAllowed($target, $value)) {
                    $this->setup[] = $range;
                    continue;
                }
                throw new RestoreFailedException('Unsupported dump charset wrapper change');
            }
            if ($this->saved !== [] || $owner !== null) {
                throw new RestoreFailedException('Unsupported SET inside a dump object wrapper');
            }
            if (!$quoted && isset(self::SAVED_GLOBALS[$target]) && $value === '@@' . self::SAVED_GLOBALS[$target]) {
                continue;
            }
            $savedGlobal = array_search(ltrim($target, '@'), self::SAVED_GLOBALS, true);
            if (!$quoted && $savedGlobal !== false && $value === $savedGlobal) {
                if ($savedGlobal === '@OLD_SQL_MODE') {
                    $this->sqlModeRestored = true;
                    $this->sqlModeKnown = false;
                }
                continue;
            }
            if (!self::literalAllowed($target, $value)) {
                throw new RestoreFailedException('Unsupported session change in full dump');
            }
            if ($target === 'SQL_MODE') {
                $this->sqlModeRestored = false;
                $this->sqlModeKnown = true;
            }
        }
        return $owner === DatabaseMarker::TABLE ? $owner : null;
    }

    /**
     * @param list<SqlToken> $tokens Complete per-table COMMIT
     * @return ?string Marker owner whose transaction statement must be excluded, otherwise null
     * @throws RestoreFailedException When COMMIT is not the exact close of an admitted table transaction
     */
    public function commit(array $tokens): ?string
    {
        if (count($tokens) !== 1 || !$tokens[0]->is('COMMIT') || $this->transactionOwner === null || $this->transactionCommitted) {
            throw new RestoreFailedException('Unsupported COMMIT in full dump');
        }
        $this->transactionCommitted = true;
        return $this->transactionOwner === DatabaseMarker::TABLE ? $this->transactionOwner : null;
    }

    /**
     * @param string $table Data block target
     * @throws RestoreFailedException When a per-table transaction reaches another object's data
     */
    public function assertDataOwner(string $table): void
    {
        if ($this->transactionOwner !== null && ($this->transactionOwner !== $table || $this->transactionCommitted)) {
            throw new RestoreFailedException('Contradictory dump autocommit data owner');
        }
    }

    /**
     * @throws RestoreFailedException When an object wrapper was not closed
     */
    public function finish(): void
    {
        if ($this->saved !== [] || $this->owner !== null || $this->transactionOwner !== null) {
            throw new RestoreFailedException('Incomplete dump charset or autocommit wrapper at EOF');
        }
    }

    /**
     * @param list<SqlToken> $tokens Assignment variable or unquoted scalar
     * @return string Canonical variable spelling
     * @throws RestoreFailedException When dump syntax is incomplete, unsupported or contradictory
     */
    private static function variable(array $tokens): string
    {
        $name = '';
        foreach ($tokens as $token) {
            if (!$token->complete || ($token->kind !== SqlToken::WORD && !$token->is('@') && !$token->is('.'))) {
                throw new RestoreFailedException('Unsupported SET expression in full dump');
            }
            $name .= strtoupper($token->value);
        }
        return $name;
    }

    /**
     * @param string $target Canonical system variable
     * @param string $value Literal setting value
     * @return bool Whether the value stays within mysqldump session vocabulary
     */
    private static function literalAllowed(string $target, string $value): bool
    {
        return match ($target) {
            'CHARACTER_SET_CLIENT', 'CHARACTER_SET_RESULTS' => in_array(strtolower($value), ['utf8', 'utf8mb3', 'utf8mb4', 'latin1'], true),
            'COLLATION_CONNECTION' => preg_match('/^(?:utf8(?:mb[34])?|latin1)_[a-z0-9_]+$/iD', $value) === 1,
            'TIME_ZONE' => $value === '+00:00',
            'UNIQUE_CHECKS', 'FOREIGN_KEY_CHECKS', 'SQL_NOTES', '@@SESSION.SQL_LOG_BIN' => in_array($value, ['0', '1'], true),
            'NOTE_VERBOSITY' => $value === '0',
            'SQL_MODE' => in_array($value, ['', 'NO_AUTO_VALUE_ON_ZERO'], true),
            default => false,
        };
    }
}
