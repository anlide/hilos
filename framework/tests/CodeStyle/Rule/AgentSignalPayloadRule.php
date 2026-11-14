<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Rule;

use Hilos\Tests\CodeStyle\CodeStyleRule;
use Hilos\Tests\CodeStyle\Violation;

/**
 * Checks lexical narrowing of the inner payload in onSignalAgent(): a whole payload passed as an argument needs an
 * earlier instanceof of it, and every instanceof of it must open the refusal.
 * This is deliberately not a control-flow or callee-type analysis.
 */
final class AgentSignalPayloadRule implements CodeStyleRule
{
    public const string ID = 'AGENT-SIGNAL-PAYLOAD';

    private const string DOC = 'docs/agents/code-style/signal-handlers.md';

    /** Exception a refusing branch throws first, read by the short name it is written with. */
    private const string REFUSAL = 'InvalidAgentSignalPayloadException';

    /** Tokens that spell a class name after instanceof. */
    private const array CLASS_NAME = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE];

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
     * @return iterable<Violation> Unguarded payload arguments and payload checks that do not refuse, in line order
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
            $hits = [];
            $outside = [];
            for ($cursor = $body + 1; $cursor < $end; $cursor++) {
                if ($tokens[$cursor][0] === T_SWITCH && ($tokens[$cursor + 1][1] ?? null) === '(') {
                    $switchBody = $this->closing($tokens, $cursor + 1) + 1;
                    if (($tokens[$switchBody][1] ?? null) === '{') {
                        array_push($hits, ...$this->checkSwitch($relativePath, $tokens, $switchBody, $wrapper));
                        $cursor = $this->closing($tokens, $switchBody);
                        continue;
                    }
                }
                // Only switches directly in the handler body are read case by case; a nested block is outside code.
                $last = $tokens[$cursor][1] === '{' ? $this->closing($tokens, $cursor) : $cursor;
                array_push($outside, ...array_slice($tokens, $cursor, $last - $cursor + 1));
                $cursor = $last;
            }
            array_push($hits, ...$this->checkRefusals($relativePath, $outside, $wrapper));
            usort($hits, static fn(Violation $left, Violation $right): int => $left->line <=> $right->line);
            yield from $hits;
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
                    $caseTokens = array_slice($tokens, $case, $cursor - $case);
                    yield from $this->checkCase($path, $caseTokens, $wrapper);
                    yield from $this->checkRefusals($path, $caseTokens, $wrapper);
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
     * An alias is one assignment from the wrapper earlier in the same tokens; nested functions are skipped, and a
     * nested switch is read as ordinary code.
     *
     * @param string $path File path relative to the scanned root
     * @param list<array{int, string, int}> $tokens One case without its keyword, or the body outside direct switches
     * @param string $wrapper AgentSignalData parameter name
     * @return iterable<Violation> One hit per instanceof of the payload or its alias that does not open the refusal
     */
    private function checkRefusals(string $path, array $tokens, string $wrapper): iterable
    {
        $aliases = [];
        $skipUntil = -1;
        foreach ($tokens as $index => $token) {
            if ($index <= $skipUntil) {
                continue;
            }
            if (in_array($token[0], [T_FUNCTION, T_FN], true)) {
                $skipUntil = $this->scopeEnd($tokens, $index);
                continue;
            }

            if ($token[0] === T_VARIABLE && ($tokens[$index + 1][1] ?? null) === '=') {
                unset($aliases[$token[1]]);
                if ($this->isInnerPayload($tokens, $index + 2, $wrapper) && ($tokens[$index + 5][1] ?? null) === ';') {
                    $aliases[$token[1]] = true;
                }
            }
            if ($token[0] !== T_INSTANCEOF) {
                continue;
            }

            if ($this->isInnerPayload($tokens, $index - 3, $wrapper)) {
                $value = $wrapper . '->data';
                $start = $index - 3;
            } elseif (($tokens[$index - 1][0] ?? null) === T_VARIABLE && isset($aliases[$tokens[$index - 1][1]])) {
                $value = $tokens[$index - 1][1];
                $start = $index - 1;
            } else {
                continue;
            }

            if (!$this->opensRefusal($tokens, $start, $index)) {
                yield new Violation(
                    self::ID,
                    $path,
                    $token[2],
                    $value . ' instanceof must refuse a mismatch with throw new ' . self::REFUSAL
                        . ' first; another exception, a return or a skip is not the refusal',
                );
            }
        }
    }

    /**
     * Two shapes refuse: a negated check whose own block throws first, and a positive check whose if/elseif chain
     * ends in an else that throws first. An elseif condition counts as an if condition.
     *
     * @param list<array{int, string, int}> $tokens Significant code tokens
     * @param int $start First token of the checked expression
     * @param int $instanceof The instanceof keyword after that expression
     * @return bool Whether this instanceof is the whole condition of a branch that leads to the refusal
     */
    private function opensRefusal(array $tokens, int $start, int $instanceof): bool
    {
        $negated = ($tokens[$start - 1][1] ?? null) === '!';
        $condition = $negated ? $start - 2 : $start - 1;
        $block = $instanceof + 3;
        if (!in_array($tokens[$condition - 1][0] ?? null, [T_IF, T_ELSEIF], true)
            || ($tokens[$condition][1] ?? null) !== '('
            || !in_array($tokens[$instanceof + 1][0] ?? null, self::CLASS_NAME, true)
            || ($tokens[$instanceof + 2][1] ?? null) !== ')'
            || ($tokens[$block][1] ?? null) !== '{'
        ) {
            return false;
        }

        return $negated ? $this->throwsRefusal($tokens, $block) : $this->elseRefuses($tokens, $block);
    }

    /**
     * @param list<array{int, string, int}> $tokens Significant code tokens
     * @param int $brace Opening brace of the positive branch
     * @return bool Whether the if/elseif chain this branch opens ends in an else that throws the refusal first
     */
    private function elseRefuses(array $tokens, int $brace): bool
    {
        $cursor = $this->closing($tokens, $brace) + 1;
        while (($tokens[$cursor][0] ?? null) === T_ELSEIF && ($tokens[$cursor + 1][1] ?? null) === '(') {
            $branch = $this->closing($tokens, $cursor + 1) + 1;
            if (($tokens[$branch][1] ?? null) !== '{') {
                return false;
            }
            $cursor = $this->closing($tokens, $branch) + 1;
        }

        return ($tokens[$cursor][0] ?? null) === T_ELSE && $this->throwsRefusal($tokens, $cursor + 1);
    }

    /**
     * @param list<array{int, string, int}> $tokens Significant code tokens
     * @param int $brace Expected opening brace of the refusing branch
     * @return bool Whether that branch is a block whose first statement throws a new refusal
     */
    private function throwsRefusal(array $tokens, int $brace): bool
    {
        return ($tokens[$brace][1] ?? null) === '{'
            && ($tokens[$brace + 1][0] ?? null) === T_THROW
            && ($tokens[$brace + 2][0] ?? null) === T_NEW
            && ($tokens[$brace + 3][0] ?? null) === T_STRING
            && $tokens[$brace + 3][1] === self::REFUSAL
            && ($tokens[$brace + 4][1] ?? null) === '(';
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
