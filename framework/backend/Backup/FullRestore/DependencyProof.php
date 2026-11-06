<?php

declare(strict_types=1);

namespace Hilos\Backup\FullRestore;

use Hilos\Backup\Exception\RestoreFailedException;

/**
 * A stored routine has no enforced table binding and is not removed by DROP TABLE.
 * Automatic-object dependencies retain their global cleanup proof; future replay refuses stored/native opaque calls.
 * This invocation-local traversal refuses dynamic execution and recursive/hidden definitions.
 */
final class DependencyProof
{
    private const string SUPPORTED_FUNCTION_NAME = '/^[A-Za-z0-9_$]+$/D';

    /** @var array<string, bool> Definitions on the current recursion path */
    private array $visiting = [];

    /**
     * @param list<TableName> $tables Cleanup candidates on one physical server
     * @param list<array<string, mixed>> $routines Complete server routine catalog
     * @param list<string> $functions Names of opaque native UDFs
     */
    public function __construct(
        private readonly array $tables,
        private readonly array $routines,
        private readonly array $functions = [],
    ) {
    }

    /**
     * Future replay preserves retained and bootstrap rows even when cleanup is empty.
     * Routine effects are unsupported here; declared NO SQL/READS SQL DATA is not enforced by the server.
     * The complete, rechecked catalog distinguishes native expressions from stored routines and opaque UDFs.
     *
     * @param MigrationOperation $operation Parsed future execution unit, with calls from scalar expressions
     * @param string $sqlMode Actual execution mode of the source connection
     * @param ServerMetadata $metadata Complete physical server catalog, shared by primary and journal
     * @param string $context Migration source and connection
     * @throws RestoreFailedException When any call's effects on preserved objects cannot be established
     */
    public static function assertReplayCallsSupported(
        MigrationOperation $operation,
        string $sqlMode,
        ServerMetadata $metadata,
        string $context,
    ): void {
        self::tokens($operation->sql, $sqlMode, $context);
        if ($operation->calls === []) {
            return;
        }
        if (!$metadata->completeVisibility) {
            throw RestoreFailedException::beforeDestructive($context . ': routine/UDF visibility is not proven');
        }
        foreach ($operation->calls as $call) {
            if (preg_match(self::SUPPORTED_FUNCTION_NAME, $call->name) !== 1) {
                throw RestoreFailedException::beforeDestructive($context . ': function name comparison is not proven: ' . $call->name);
            }
            foreach ($metadata->functions as $function) {
                if (preg_match(self::SUPPORTED_FUNCTION_NAME, (string)$function['name']) !== 1
                    || strcasecmp($call->name, (string)$function['name']) === 0) {
                    throw RestoreFailedException::beforeDestructive($context . ': native function effects are opaque: ' . $call->name);
                }
            }
            foreach ($metadata->routines as $routine) {
                if ($call->database === $routine['ROUTINE_SCHEMA']
                    && (preg_match(self::SUPPORTED_FUNCTION_NAME, (string)$routine['ROUTINE_NAME']) !== 1
                        || strcasecmp($call->name, (string)$routine['ROUTINE_NAME']) === 0)) {
                    throw RestoreFailedException::beforeDestructive($context . ': stored function effects on preserved objects are not proven: '
                        . $routine['ROUTINE_SCHEMA'] . '.' . $routine['ROUTINE_NAME']);
                }
            }
            if ($call->qualified) {
                throw RestoreFailedException::beforeDestructive($context . ': qualified function effects are not proven: '
                    . $call->database . '.' . $call->name);
            }
        }
    }

    /**
     * @param mixed $sql View, trigger, event or reachable routine definition
     * @param string $sqlMode Proven lexical mode of the definition
     * @param string $context Object and connection to report
     * @param bool $executes Whether the object is automatic/reachable, rather than an uncalled routine's stored bindings
     * @throws RestoreFailedException When references, dynamic execution or uncertainty can affect cleanup
     */
    public function assertIndependent(mixed $sql, string $sqlMode, string $context, bool $executes = true): void
    {
        $tokens = self::tokens($sql, $sqlMode, $context, $executes);
        foreach ($tokens as $token) {
            if (in_array($token->kind, [SqlToken::WORD, SqlToken::IDENTIFIER], true)) {
                foreach ($this->tables as $table) {
                    if (strcasecmp($token->value, $table->table) === 0) {
                        throw RestoreFailedException::beforeDestructive($context . ': possible dependency on '
                            . $table->database . '.' . $table->table);
                    }
                }
            }
        }
        if ($executes) {
            $this->calls($tokens, $context);
        }
    }

    /**
     * Direct references to recreated tables are valid within UP; indirect calls still need proof.
     *
     * @param string $sql Actual pending execution unit
     * @param string $sqlMode Prepared execution mode
     * @param string $context Migration source
     * @throws RestoreFailedException When a reachable routine may depend on cleanup tables
     */
    public function assertCallsIndependent(string $sql, string $sqlMode, string $context): void
    {
        $this->calls(self::tokens($sql, $sqlMode, $context), $context);
    }

    /**
     * @param mixed $sql Catalog definition, possibly hidden
     * @param string $sqlMode Mode used to interpret quoted strings
     * @param string $context Source to report
     * @param bool $executes Whether procedural/dynamic execution must be ruled out
     * @return list<SqlToken> Complete definition, including compound-statement delimiters
     * @throws RestoreFailedException When the definition is hidden or ambiguous
     */
    private static function tokens(mixed $sql, string $sqlMode, string $context, bool $executes = true): array
    {
        if (!is_string($sql) || $sql === '') {
            throw RestoreFailedException::beforeDestructive($context . ': dependency definition is not visible');
        }
        try {
            $lexer = DumpSqlLexer::fromSql($sql);
            $tokens = [];
            while (($token = $lexer->next()) !== null) {
                // The lexer consumes an entire long literal; its retained prefix is irrelevant to static bindings.
                if ((!$token->complete && $token->kind !== SqlToken::STRING)
                    || ($executes && ($token->is('PREPARE') || $token->is('EXECUTE') || $token->is('CALL')))) {
                    throw new RestoreFailedException('Dynamic or procedural dependency');
                }
                $tokens[] = $token;
            }
            $modes = explode(',', strtoupper($sqlMode));
            if ($lexer->hasExecutableComment() || in_array('ANSI_QUOTES', $modes, true)
                || ($lexer->hasStringEscape() && in_array('NO_BACKSLASH_ESCAPES', $modes, true))) {
                throw new RestoreFailedException('Ambiguous dependency lexical mode');
            }
            return $tokens;
        } catch (RestoreFailedException $failure) {
            throw RestoreFailedException::beforeDestructive($context . ': dependency definition is opaque', $failure);
        }
    }

    /**
     * All same-named routines are considered when qualification is uncertain; false positives refuse.
     *
     * @param list<SqlToken> $tokens One complete lexical unit
     * @param string $context Calling object
     * @throws RestoreFailedException When a called routine is recursive, hidden or dependent
     */
    private function calls(array $tokens, string $context): void
    {
        foreach ($tokens as $position => $token) {
            if (!in_array($token->kind, [SqlToken::WORD, SqlToken::IDENTIFIER], true)
                || !(($tokens[$position + 1] ?? null)?->is('('))
                || (($tokens[$position - 1] ?? null)?->is('FUNCTION'))
                || (($tokens[$position - 1] ?? null)?->is('PROCEDURE'))) {
                continue;
            }
            foreach ($this->functions as $function) {
                if (strcasecmp($token->value, $function) === 0) {
                    throw RestoreFailedException::beforeDestructive($context . ': native function dependency is opaque: ' . $function);
                }
            }
            foreach ($this->routines as $routine) {
                if (strcasecmp($token->value, (string)$routine['ROUTINE_NAME']) !== 0) {
                    continue;
                }
                $name = $routine['ROUTINE_SCHEMA'] . '.' . $routine['ROUTINE_NAME'];
                if (isset($this->visiting[$name])) {
                    throw RestoreFailedException::beforeDestructive($context . ': recursive routine dependency ' . $name);
                }
                $this->visiting[$name] = true;
                try {
                    $this->assertIndependent($routine[ServerMetadata::CREATE_SQL], (string)$routine['SQL_MODE'], $context . ' via ' . $name);
                } finally {
                    unset($this->visiting[$name]);
                }
            }
        }
    }
}
