<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Rule;

use Hilos\Tests\CodeStyle\CodeStyleRule;
use Hilos\Tests\CodeStyle\Violation;

/**
 * Enforces orm/transactions.md: the framework never marks a transaction nestable.
 *
 * Database::transactionStartNestable() is the one door to a nested transaction, and it is
 * left to the projects: a framework method that needs a transaction ends it before another
 * starts, or joins the caller's with a method that neither starts nor commits. The owner's
 * words (P-416, 2026-09-26): the framework may know how to nest, and may never do it.
 *
 * Judged by root, not by kind: only `framework/backend` is read. A demo may call the mark, and
 * the framework's own suite has to, to pin the mechanism - so `demo/*\/backend` and
 * `framework/tests` are handed an empty report. The list of allowed files does not exist:
 * there is nothing a framework transaction may nest.
 *
 * Only the name in call position is read - `::transactionStartNestable(`,
 * `->transactionStartNestable(`, `?->transactionStartNestable(` - so the declaration of the
 * method itself (`function transactionStartNestable`) is not a hit, and neither is the name in
 * a string or a comment.
 */
final class NestableTransactionRule implements CodeStyleRule
{
    public const string ID = 'NESTABLE-TRANSACTION';

    private const string DOC = 'docs/agents/orm/transactions.md';

    /** The one root the rule reads; every other root is handed an empty report. */
    private const string JUDGED_ROOT = 'framework/backend';

    private const string MARK = 'transactionStartNestable';

    /**
     * Tokens before the name that make it a call on something: a class, an object, a maybe-object.
     *
     * @var array<int, int>
     */
    private const array CALL_OPERATORS = [T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR];

    /**
     * @param string $root Scanned root relative to the repository root
     */
    public function __construct(
        private readonly string $root,
    ) {
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
     * @return iterable<Violation> One entry per call of the nestable mark in the framework backend
     */
    public function check(string $relativePath, array $tokens): iterable
    {
        if ($this->root !== self::JUDGED_ROOT) {
            return;
        }

        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_STRING || $token[1] !== self::MARK) {
                continue;
            }

            if ($this->significantToken($tokens, $index, 1) !== '(') {
                continue;
            }

            $before = $this->significantToken($tokens, $index, -1);
            if (!is_array($before) || !in_array($before[0], self::CALL_OPERATORS, true)) {
                continue;
            }

            yield new Violation(
                self::ID,
                $relativePath,
                $token[2],
                self::MARK . '() marks a transaction nestable, and the framework never nests one — end the '
                    . 'transaction before starting another, or join the caller\'s with a method that neither starts '
                    . 'nor commits (docs/agents/orm/transactions.md)',
            );
        }
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $index Index to walk away from
     * @param int $step Direction to walk in
     * @return string|array{0: int, 1: string, 2: int}|null Nearest token that is not whitespace or a comment
     */
    private function significantToken(array $tokens, int $index, int $step): string|array|null
    {
        for ($cursor = $index + $step; isset($tokens[$cursor]); $cursor += $step) {
            $token = $tokens[$cursor];
            if (!is_array($token)) {
                return $token;
            }

            if ($token[0] !== T_WHITESPACE && $token[0] !== T_COMMENT && $token[0] !== T_DOC_COMMENT) {
                return $token;
            }
        }

        return null;
    }
}
