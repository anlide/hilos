<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Rule;

use Hilos\Tests\CodeStyle\CodeStyleRule;
use Hilos\Tests\CodeStyle\Violation;

/**
 * Enforces rt-state.md: backing RT state is reachable only from files under
 * `Database/` or `Runtime/`. The caller's role does not matter — an agent, page,
 * table, or test that reaches a backing state object leaks the same way.
 *
 * Only real tokens are read, so the same names inside a comment or a string
 * literal are not hits.
 */
final class RtStateReachRule implements CodeStyleRule
{
    public const string ID = 'RT-STATE-REACH';

    private const string DOC = 'docs/agents/runtime/rt-state.md';

    /** Backing-state accessors, wherever they are called from and on whatever receiver. */
    private const array STATE_METHODS = ['getStateCollection', 'getStateItem'];

    /** Magic property that hands the backing collection out inside actions. */
    private const string STATE_PROPERTY = 'stateCollection';

    /** Path segments that own backing state and may therefore touch it. */
    private const array ALLOWED_SEGMENTS = ['Database', 'Runtime'];

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
     * The path is read relative to the scanned root, so the fixtures are judged by
     * the very same code as the real sources.
     *
     * @param string $relativePath File path relative to the scanned root
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @return iterable<Violation> One entry per reaching call or property read
     */
    public function check(string $relativePath, array $tokens): iterable
    {
        if (array_intersect(self::ALLOWED_SEGMENTS, explode('/', $relativePath)) !== []) {
            return;
        }

        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_STRING) {
                continue;
            }

            if (in_array($token[1], self::STATE_METHODS, true) && $this->isCall($tokens, $index)) {
                yield new Violation(
                    self::ID,
                    $relativePath,
                    $token[2],
                    sprintf('%s() reaches backing RT state outside Database/ and Runtime/', $token[1]),
                );
                continue;
            }

            if ($token[1] === self::STATE_PROPERTY && $this->isOwnProperty($tokens, $index)) {
                yield new Violation(
                    self::ID,
                    $relativePath,
                    $token[2],
                    '$this->stateCollection reaches backing RT state outside Database/ and Runtime/',
                );
            }
        }
    }

    /**
     * A declaration of a same-named method is not a reach, so `function` in front
     * of the name disqualifies it.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $index Index of the name token
     * @return bool True when the name is being called, not declared
     */
    private function isCall(array $tokens, int $index): bool
    {
        $previous = $this->significantToken($tokens, $index, -1);

        return $this->significantToken($tokens, $index, 1) === '('
            && !(is_array($previous) && $previous[0] === T_FUNCTION);
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $index Index of the property name token
     * @return bool True when the name is read as `$this->stateCollection`
     */
    private function isOwnProperty(array $tokens, int $index): bool
    {
        $operatorIndex = $this->significantIndex($tokens, $index, -1);
        if ($operatorIndex === null) {
            return false;
        }

        $operator = $tokens[$operatorIndex];
        if (!is_array($operator) || $operator[0] !== T_OBJECT_OPERATOR) {
            return false;
        }

        $receiver = $this->significantToken($tokens, $operatorIndex, -1);

        return is_array($receiver) && $receiver[0] === T_VARIABLE && $receiver[1] === '$this';
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $index Index to walk away from
     * @param int $step Direction to walk in
     * @return string|array{0: int, 1: string, 2: int}|null Nearest token that is not whitespace or a comment
     */
    private function significantToken(array $tokens, int $index, int $step): string|array|null
    {
        $found = $this->significantIndex($tokens, $index, $step);

        return $found === null ? null : $tokens[$found];
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $index Index to walk away from
     * @param int $step Direction to walk in
     * @return int|null Index of the nearest token that is not whitespace or a comment
     */
    private function significantIndex(array $tokens, int $index, int $step): ?int
    {
        for ($cursor = $index + $step; isset($tokens[$cursor]); $cursor += $step) {
            $token = $tokens[$cursor];
            if (!is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $cursor;
            }
        }

        return null;
    }
}
