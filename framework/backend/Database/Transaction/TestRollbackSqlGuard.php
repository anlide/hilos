<?php

declare(strict_types=1);

namespace Hilos\Database\Transaction;

use Hilos\Database\Exception\DatabaseRuntimeException;

/** Narrow SQL admission for a test scope, before any statement of a multi-query is sent. */
final class TestRollbackSqlGuard
{
    private const array FAMILIES = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'WITH', 'SHOW', 'EXPLAIN'];

    /**
     * Executable comments are refused outright. Database supplies the rendered query and the
     * connection's actual SQL mode before sending any of its statements.
     *
     * @param string $sql Whole query, including every statement
     * @param bool $backslashEscapes Whether the connection uses backslash escapes in strings
     * @param bool $ansiQuotes Whether double quotes delimit identifiers instead of strings
     * @throws DatabaseRuntimeException When a statement family or an ambiguous lexical boundary is unsafe
     */
    public static function check(string $sql, bool $backslashEscapes = true, bool $ansiQuotes = false): void
    {
        $length = strlen($sql);
        $needsFamily = true;
        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? null;
            if (ctype_space($char)) {
                continue;
            }
            if ($char === '#' || ($char === '-' && $next === '-'
                && ($i + 2 === $length || ctype_space($sql[$i + 2]) || ctype_cntrl($sql[$i + 2])))) {
                while ($i < $length && $sql[$i] !== "\n" && $sql[$i] !== "\r") {
                    $i++;
                }
                continue;
            }
            if ($char === '/' && $next === '*') {
                if (substr($sql, $i, 3) === '/*!' || strtoupper(substr($sql, $i, 4)) === '/*M!') {
                    throw new DatabaseRuntimeException('Executable SQL comments are refused inside a test rollback scope');
                }
                $end = strpos($sql, '*/', $i + 2);
                if ($end === false) {
                    throw new DatabaseRuntimeException('Unterminated SQL comment inside a test rollback scope');
                }
                $i = $end + 1;
                continue;
            }
            if ($char === ';') {
                $needsFamily = true;
                continue;
            }
            if ($needsFamily) {
                $start = $i;
                while ($i < $length && (ctype_alnum($sql[$i]) || $sql[$i] === '_')) {
                    $i++;
                }
                $family = strtoupper(substr($sql, $start, $i - $start));
                if (!in_array($family, self::FAMILIES, true)) {
                    throw new DatabaseRuntimeException('SQL statement family is refused inside a test rollback scope: ' . $family);
                }
                $needsFamily = false;
                $i--;
                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $string = $quote === "'" || ($quote === '"' && !$ansiQuotes);
                $closed = false;
                for ($i++; $i < $length; $i++) {
                    if ($backslashEscapes && $string && $sql[$i] === '\\') {
                        $i++;
                        continue;
                    }
                    if ($sql[$i] !== $quote) {
                        continue;
                    }
                    if (($sql[$i + 1] ?? null) === $quote) {
                        $i++;
                        continue;
                    }
                    $closed = true;
                    break;
                }
                if (!$closed) {
                    throw new DatabaseRuntimeException('Ambiguous or unterminated SQL quote inside a test rollback scope');
                }
            }
        }
    }
}
