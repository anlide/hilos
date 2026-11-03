<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Rule;

use Hilos\Tests\CodeStyle\CodeStyleRule;
use Hilos\Tests\CodeStyle\Violation;

/**
 * Checks lexical narrowing before a whole inner payload is passed as an argument.
 * This is deliberately not a control-flow or callee-type analysis.
 */
final class AgentSignalPayloadRule implements CodeStyleRule
{
    public const string ID = 'AGENT-SIGNAL-PAYLOAD';

    private const string DOC = 'docs/agents/code-style/signal-handlers.md';

    /** @return string Rule id */
    public function id(): string
    {
        return self::ID;
    }

    /** @return string Owning document */
    public function doc(): string
    {
        return self::DOC;
    }

    /**
     * @param string $relativePath File path relative to the scanned root
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @return iterable<Violation> One hit per unguarded payload argument
     */
    public function check(string $relativePath, array $tokens): iterable
    {
        $tokens = $this->codeTokens($tokens);
        foreach ($tokens as $index => $token) {
            if ($token[0] !== T_FUNCTION
                || ($tokens[$index + 1][0] ?? null) !== T_STRING
                || strtolower($tokens[$index + 1][1]) !== 'onsignalagent'
            ) {
                continue;
            }

            $parameters = $index + 2;
            if (($tokens[$parameters][1] ?? null) !== '(') {
                continue;
            }
            $parametersEnd = $this->closing($tokens, $parameters);
            $wrapper = null;
            for ($cursor = $parameters + 1; $cursor < $parametersEnd; $cursor++) {
                if ($tokens[$cursor][1] === 'AgentSignalData' && ($tokens[$cursor + 1][0] ?? null) === T_VARIABLE) {
                    $wrapper = $tokens[$cursor + 1][1];
                }
            }
            if ($wrapper === null) {
                continue;
            }

            $body = $parametersEnd + 1;
            while (isset($tokens[$body]) && !in_array($tokens[$body][1], ['{', ';'], true)) {
                $body++;
            }
            if (($tokens[$body][1] ?? null) !== '{') {
                continue;
            }

            $end = $this->closing($tokens, $body);
            for ($cursor = $body + 1; $cursor < $end; $cursor++) {
                if ($tokens[$cursor][0] === T_SWITCH && ($tokens[$cursor + 1][1] ?? null) === '(') {
                    $switchBody = $this->closing($tokens, $cursor + 1) + 1;
                    if (($tokens[$switchBody][1] ?? null) === '{') {
                        yield from $this->checkSwitch($relativePath, $tokens, $switchBody, $wrapper);
                        $cursor = $this->closing($tokens, $switchBody);
                    }
                } elseif ($tokens[$cursor][1] === '{') {
                    // Only switches directly in the handler body belong to this small check.
                    $cursor = $this->closing($tokens, $cursor);
                }
            }
        }
    }

    /**
     * Each case starts fresh; consecutive labels therefore share their following body.
     *
     * @param string $path File path relative to the scanned root
     * @param list<array{int, string, int}> $tokens Significant code tokens
     * @param int $start Opening switch brace
     * @param string $wrapper AgentSignalData parameter name
     * @return iterable<Violation> Hits in the direct cases of this switch
     */
    private function checkSwitch(string $path, array $tokens, int $start, string $wrapper): iterable
    {
        $end = $this->closing($tokens, $start);
        $case = null;
        for ($cursor = $start + 1; $cursor <= $end; $cursor++) {
            if ($cursor === $end || in_array($tokens[$cursor][0], [T_CASE, T_DEFAULT], true)) {
                if ($case !== null) {
                    yield from $this->checkCase($path, array_slice($tokens, $case, $cursor - $case), $wrapper);
                }
                $case = $cursor + 1;
            } elseif ($tokens[$cursor][1] === '{') {
                $cursor = $this->closing($tokens, $cursor);
            }
        }
    }

    /**
     * @param string $path File path relative to the scanned root
     * @param list<array{int, string, int}> $tokens One case, excluding its keyword
     * @param string $wrapper AgentSignalData parameter name
     * @return iterable<Violation> Hits before an instanceof of the same expression
     */
    private function checkCase(string $path, array $tokens, string $wrapper): iterable
    {
        $aliases = [];
        $narrowed = [];
        $parentheses = [];
        $skipUntil = -1;
        foreach ($tokens as $index => $token) {
            if ($index <= $skipUntil) {
                continue;
            }
            if (in_array($token[0], [T_FUNCTION, T_FN, T_SWITCH], true)) {
                $skipUntil = $this->scopeEnd($tokens, $index);
                continue;
            }

            if ($token[1] === '(') {
                $parentheses[] = in_array(
                    $tokens[$index - 1][0] ?? null,
                    [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_VARIABLE],
                    true,
                );
            } elseif ($token[1] === '[') {
                $parentheses[] = false;
            } elseif (in_array($token[1], [')', ']'], true)) {
                array_pop($parentheses);
            }

            if ($token[0] !== T_VARIABLE) {
                continue;
            }

            if (($tokens[$index + 1][1] ?? null) === '=') {
                unset($aliases[$token[1]], $narrowed[$token[1]]);
                if ($this->isInnerPayload($tokens, $index + 2, $wrapper) && ($tokens[$index + 5][1] ?? null) === ';') {
                    $aliases[$token[1]] = true;
                    $narrowed[$token[1]] = $narrowed[$wrapper . '->data'] ?? false;
                }
            }

            if ($this->isInnerPayload($tokens, $index, $wrapper)) {
                $value = $wrapper . '->data';
                $after = $index + 3;
            } elseif (isset($aliases[$token[1]])) {
                $value = $token[1];
                $after = $index + 1;
            } else {
                continue;
            }

            if (($tokens[$after][0] ?? null) === T_INSTANCEOF) {
                $narrowed[$value] = true;
                continue;
            }

            if (end($parentheses) === true
                && in_array($tokens[$index - 1][1] ?? null, ['(', ','], true)
                && in_array($tokens[$after][1] ?? null, [')', ','], true)
                && !($narrowed[$value] ?? false)
            ) {
                yield new Violation(
                    self::ID,
                    $path,
                    $token[2],
                    $value . ' is passed without a preceding instanceof in this case; narrow the inner payload first',
                );
            }
        }
    }

    /**
     * @param list<array{int, string, int}> $tokens Significant code tokens
     * @param int $index Possible beginning of the expression
     * @param string $wrapper AgentSignalData parameter name
     * @return bool Whether this is the literal wrapper->data expression
     */
    private function isInnerPayload(array $tokens, int $index, string $wrapper): bool
    {
        return ($tokens[$index][0] ?? null) === T_VARIABLE
            && $tokens[$index][1] === $wrapper
            && ($tokens[$index + 1][0] ?? null) === T_OBJECT_OPERATOR
            && ($tokens[$index + 2][1] ?? null) === 'data';
    }

    /**
     * Ignores nested functions and switches so their guards cannot narrow an outer case.
     * Arrow bodies stop at the first enclosing delimiter, skipping balanced expressions.
     *
     * @param list<array{int, string, int}> $tokens Significant code tokens
     * @param int $start Function, arrow function or switch keyword
     * @return int Last token belonging to that nested scope
     */
    private function scopeEnd(array $tokens, int $start): int
    {
        $arrow = $tokens[$start][0] === T_FN;
        for ($cursor = $start + 1; isset($tokens[$cursor]); $cursor++) {
            $text = $tokens[$cursor][1];
            if ($text === '{' && !$arrow) {
                return $this->closing($tokens, $cursor);
            }
            if (in_array($text, ['(', '[', '{'], true)) {
                $cursor = $this->closing($tokens, $cursor);
            } elseif (in_array($text, [';', ',', ')', ']', '}'], true)) {
                return $cursor - 1;
            }
        }

        return count($tokens) - 1;
    }

    /**
     * @param list<array{int, string, int}> $tokens Significant code tokens
     * @param int $start Opening parenthesis, bracket or brace
     * @return int Matching closing delimiter, or the last token for incomplete input
     */
    private function closing(array $tokens, int $start): int
    {
        $open = $tokens[$start][1];
        $close = match ($open) {
            '(' => ')',
            '[' => ']',
            '{' => '}',
        };
        $depth = 1;
        for ($cursor = $start + 1; isset($tokens[$cursor]); $cursor++) {
            if ($tokens[$cursor][0] !== 0) {
                continue;
            }
            if ($tokens[$cursor][1] === $open) {
                $depth++;
            } elseif ($tokens[$cursor][1] === $close && --$depth === 0) {
                return $cursor;
            }
        }

        return count($tokens) - 1;
    }

    /**
     * Comments and whitespace disappear; interpolated strings become one inert token.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @return list<array{int, string, int}> Uniform significant tokens with source lines
     */
    private function codeTokens(array $tokens): array
    {
        $code = [];
        $line = 1;
        $stringEnd = null;
        foreach ($tokens as $token) {
            $part = is_array($token) ? $token : [0, $token, $line];
            $line = $part[2] + substr_count($part[1], "\n");
            if ($stringEnd !== null) {
                if ($part[0] === T_END_HEREDOC || ($part[0] === 0 && $part[1] === $stringEnd)) {
                    $stringEnd = null;
                }
                continue;
            }
            if ($part[0] === T_START_HEREDOC || ($part[0] === 0 && in_array($part[1], ['"', '`'], true))) {
                $stringEnd = $part[1];
                $code[] = [T_CONSTANT_ENCAPSED_STRING, $part[1], $part[2]];
            } elseif (!in_array($part[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $code[] = $part;
            }
        }

        return $code;
    }
}
