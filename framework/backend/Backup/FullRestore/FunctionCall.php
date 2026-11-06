<?php

declare(strict_types=1);

namespace Hilos\Backup\FullRestore;

use Hilos\Backup\Exception\RestoreFailedException;

/** Syntactic scalar call, resolved against the migration's execution schema, never its destination table. */
final readonly class FunctionCall
{
    private const array PARENTHESIZED_OPERATORS = [
        'IN', 'NOT', 'AND', 'OR', 'XOR', 'WHEN', 'THEN', 'ELSE', 'BETWEEN', 'LIKE', 'REGEXP', 'RLIKE',
        'IS', 'DISTINCT', 'BINARY', 'INTERVAL',
    ];

    /**
     * @param string $database Explicit qualifier or actual execution schema
     * @param string $name Function identifier, independent of its case
     * @param bool $qualified Whether SQL explicitly supplied a schema
     */
    private function __construct(public string $database, public string $name, public bool $qualified)
    {
    }

    /**
     * @param list<SqlToken> $tokens Admitted scalar expression, excluding table and column lists
     * @param string $database Actual execution schema
     * @return list<self> Every nested call; strings, comments and ordinary column names have no effect
     * @throws RestoreFailedException When a call's schema qualifier cannot be resolved
     */
    public static function read(array $tokens, string $database): array
    {
        $calls = [];
        foreach ($tokens as $position => $token) {
            if (!in_array($token->kind, [SqlToken::WORD, SqlToken::IDENTIFIER], true)
                || !(($tokens[$position + 1] ?? null)?->is('('))) {
                continue;
            }
            $qualified = ($tokens[$position - 1] ?? null)?->is('.') ?? false;
            if (!$qualified && array_any(self::PARENTHESIZED_OPERATORS, static fn (string $word): bool => $token->is($word))) {
                continue;
            }
            if ($qualified && !in_array(($tokens[$position - 2] ?? null)?->kind, [SqlToken::WORD, SqlToken::IDENTIFIER], true)) {
                throw new RestoreFailedException('Unresolved scalar function qualifier');
            }
            $calls[] = new self($qualified ? $tokens[$position - 2]->value : $database, $token->value, $qualified);
        }
        return $calls;
    }
}
