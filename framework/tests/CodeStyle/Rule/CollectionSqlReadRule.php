<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Rule;

use Hilos\Tests\CodeStyle\CodeStyleRule;
use Hilos\Tests\CodeStyle\Violation;

/**
 * Reads direct entityClass() SQL chains in production Object collection paths.
 * Aliases, arbitrary Entity names, raw SQL and inter-file helpers are outside this
 * lexical check. Token streams and comment maps stay arrays at the tooling boundary.
 */
final class CollectionSqlReadRule implements CodeStyleRule
{
    public const string ID = 'COLLECTION-SQL-READ';

    private const string DOC = 'docs/agents/orm/object.md';

    /**
     * @param string $root Scanned root relative to the repository root
     */
    public function __construct(private readonly string $root)
    {
    }

    /**
     * @return string Rule id
     */
    public function id(): string
    {
        return self::ID;
    }

    /**
     * @return string Owning document
     */
    public function doc(): string
    {
        return self::DOC;
    }

    /**
     * @param string $relativePath File path relative to the scanned root
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @return iterable<Violation> One hit per direct get/count chain without a local reason
     */
    public function check(string $relativePath, array $tokens): iterable
    {
        if (preg_match('~^(?:framework/backend|demo/[^/]+/backend)$~D', $this->root) !== 1
            || preg_match('~(?:^|/)Database/Object/Collection/~', $relativePath) !== 1) {
            return;
        }

        $reasons = [];
        $code = [];
        $literalEnd = null;
        foreach ($tokens as $token) {
            if ($literalEnd !== null) {
                if ($token === $literalEnd || (is_array($token) && $token[0] === $literalEnd)) {
                    $literalEnd = null;
                }
                continue;
            }
            if ($token === '"' || $token === '`' || (is_array($token) && $token[0] === T_START_HEREDOC)) {
                $literalEnd = is_array($token) ? T_END_HEREDOC : $token;
                // Keep a boundary so code on either side of a literal never joins into a chain.
                $code[] = $token;
                continue;
            }
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                if ($token[0] === T_COMMENT && preg_match('/^\/\/[\t ]*fresh-sql:(.*)$/D', $token[1], $match) === 1) {
                    $reasons[$token[2]] = trim($match[1]);
                }
                continue;
            }
            $code[] = $token;
        }

        foreach ($code as $index => $token) {
            if (!is_array($token) || !in_array($token[0], [T_STATIC, T_STRING], true)
                || !in_array(strtolower($token[1]), ['static', 'self', 'parent'], true)) {
                continue;
            }
            $method = $this->calledRead($code, $index);
            if ($method === null || (isset($reasons[$token[2]]) && $reasons[$token[2]] !== '')) {
                continue;
            }

            $message = 'entityClass()::' . $method . '() reads SQL directly; use Objects::loadByColumn/countByColumn '
                . 'or another shared collection read';
            if (isset($reasons[$token[2]])) {
                $message .= '; fresh-sql marker has no reason';
            }
            yield new Violation(
                self::ID,
                $relativePath,
                $token[2],
                $message,
            );
        }
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Tokens with whitespace and comments removed
     * @param int $index Start of a possible static/self/parent chain
     * @return string|null Direct SQL method, or null for a different expression
     */
    private function calledRead(array $tokens, int $index): ?string
    {
        $previous = $tokens[$index - 1] ?? null;
        if (is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
            return null;
        }
        $chain = array_map(
            static fn(string|array $token): string => is_array($token) ? strtolower($token[1]) : $token,
            array_slice($tokens, $index + 1, 7),
        );
        if ($chain === ['::', 'entityclass', '(', ')', '::', 'get', '(']) {
            return 'get';
        }
        if ($chain === ['::', 'entityclass', '(', ')', '::', 'count', '(']) {
            return 'count';
        }

        return null;
    }
}
