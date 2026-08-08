<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Rule;

use Hilos\Tests\CodeStyle\CodeStyleRule;
use Hilos\Tests\CodeStyle\Violation;

/**
 * Enforces the REPEAT test of magic-values.md on the one slice a lexer can settle
 * without an argument: the same number written twice or more in one file.
 *
 * Strings are out of scope on purpose — a repeated string is far more often a wire
 * key or a fragment of SQL than a magic value, and the tokens do not tell the two
 * apart. Cross-class repetition is out of reach as well: a rule reads one file at a
 * time. Both stay human review, and automated-checks.md says so.
 *
 * Numbers inside a `const` declaration are not counted: the constant is the cure.
 * Numbers inside the value of a keyed array entry are not counted either, for the
 * same reason one step removed — the key names the entry, so the number is that
 * entry's data rather than an anonymous repeat. Numbers inside string literals
 * produce no number token at all, so they cannot be hits.
 */
final class MagicRepeatRule implements CodeStyleRule
{
    public const string ID = 'MAGIC-REPEAT';

    private const string DOC = 'docs/agents/code-style/magic-values.md';

    /**
     * Structural values — an index, an increment, a half, a "not found" — carry no
     * unit and mean the same everywhere, so repeating them hides nothing.
     *
     * The `-1` of the document is absent by nature rather than by omission: the
     * minus is a token of its own, so `-1` reaches the rule as `1`. The same split
     * cuts the other way, and the document says so: a symmetric pair such as
     * `max(-1500, min(1500, $n))` reaches the rule as one value written twice.
     */
    private const array ALLOWED_VALUES = ['0', '1', '2', '0.0', '1.0', '2.0'];

    /** Repeats start at the second occurrence, the same threshold the document states. */
    private const int REPEAT_THRESHOLD = 2;

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
     * @return iterable<Violation> One entry per occurrence of a repeated number, in file order
     */
    public function check(string $relativePath, array $tokens): iterable
    {
        $numbers = $this->countableNumbers($tokens);

        $repeats = array_count_values(array_map(static fn(array $number): string => $number['value'], $numbers));

        foreach ($numbers as $number) {
            $count = $repeats[$number['value']];
            if ($count < self::REPEAT_THRESHOLD) {
                continue;
            }

            yield new Violation(
                self::ID,
                $relativePath,
                $number['line'],
                sprintf(
                    '%s occurs %d times in this file; name it with a constant that carries the unit',
                    $number['value'],
                    $count,
                ),
            );
        }
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @return array<int, array{value: string, line: int}> Numbers this rule judges, in file order
     */
    private function countableNumbers(array $tokens): array
    {
        $significant = $this->significantTokens($tokens);
        $numbers = [];
        $insideConstant = false;
        $arrowFunctionPending = false;
        /** @var array<int, array{isArray: bool, isFnParameters: bool, inValue: bool}> $frames */
        $frames = [];

        foreach ($significant as $index => $token) {
            if ($token === ';') {
                $insideConstant = false;
                continue;
            }
            if (is_array($token) && $token[0] === T_ATTRIBUTE) {
                $frames[] = ['isArray' => false, 'isFnParameters' => false, 'inValue' => false];
                continue;
            }
            if ($token === '[' || $token === '(') {
                $frames[] = [
                    'isArray' => $this->opensArrayLiteral($significant, $index),
                    'isFnParameters' => $this->opensArrowFunctionParameters($significant, $index),
                    'inValue' => false,
                ];
                continue;
            }
            if ($token === ']' || $token === ')') {
                $closed = array_pop($frames);
                $arrowFunctionPending = $arrowFunctionPending || ($closed['isFnParameters'] ?? false);
                continue;
            }
            if (is_array($token) && $token[0] === T_DOUBLE_ARROW) {
                if ($arrowFunctionPending) {
                    $arrowFunctionPending = false;
                } elseif ($frames !== [] && $frames[array_key_last($frames)]['isArray']) {
                    $frames[array_key_last($frames)]['inValue'] = true;
                }
                continue;
            }
            if ($token === ',' && $frames !== [] && $frames[array_key_last($frames)]['isArray']) {
                $frames[array_key_last($frames)]['inValue'] = false;
                continue;
            }

            if (!is_array($token)) {
                continue;
            }
            if ($token[0] === T_CONST) {
                $insideConstant = true;
                continue;
            }
            if ($insideConstant || !in_array($token[0], [T_LNUMBER, T_DNUMBER], true)) {
                continue;
            }
            if ($this->insideEntryValue($frames)) {
                continue;
            }

            $value = $this->normalize($token[1]);
            if (!in_array($value, self::ALLOWED_VALUES, true)) {
                $numbers[] = ['value' => $value, 'line' => $token[2]];
            }
        }

        return $numbers;
    }

    /**
     * Whitespace and comments stand between tokens this rule has to read as
     * neighbours — a `=>` and the number it introduces, or the name in front of a
     * `[`. Dropping them once here keeps every later look-behind a single step.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @return array<int, string|array{0: int, 1: string, 2: int}> The same stream without the ignorable tokens
     */
    private function significantTokens(array $tokens): array
    {
        $significant = [];

        foreach ($tokens as $token) {
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $significant[] = $token;
        }

        return $significant;
    }

    /**
     * A bracket opens an array literal unless it subscripts something. `[` follows a
     * variable, a name, a string or a closing bracket when it is an index; `(` opens
     * a literal only behind the `array` keyword.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $significant Stream without ignorable tokens
     * @param int $index Position of the opening bracket in that stream
     * @return bool True when the bracket opens an array literal
     */
    private function opensArrayLiteral(array $significant, int $index): bool
    {
        $previous = $significant[$index - 1] ?? null;

        if ($significant[$index] === '(') {
            return is_array($previous) && $previous[0] === T_ARRAY;
        }

        if ($previous === ']' || $previous === ')') {
            return false;
        }

        return !is_array($previous)
            || !in_array($previous[0], [T_VARIABLE, T_STRING, T_CONSTANT_ENCAPSED_STRING], true);
    }

    /**
     * An arrow function carries a `=>` of its own, and inside an array literal that
     * arrow would otherwise read as the one separating a key from its value — which
     * would quietly exempt the rest of a keyless list. The parameter list is where
     * the two are still distinguishable.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $significant Stream without ignorable tokens
     * @param int $index Position of the opening bracket in that stream
     * @return bool True when the bracket opens the parameter list of an arrow function
     */
    private function opensArrowFunctionParameters(array $significant, int $index): bool
    {
        $previous = $significant[$index - 1] ?? null;

        return $significant[$index] === '(' && is_array($previous) && $previous[0] === T_FN;
    }

    /**
     * The whole stack is walked rather than its top: an entry value is often a call,
     * and the number sits inside that call's own frame.
     *
     * @param array<int, array{isArray: bool, inValue: bool}> $frames Open bracket frames, outermost first
     * @return bool True when the current position is the value of a keyed array entry
     */
    private function insideEntryValue(array $frames): bool
    {
        foreach ($frames as $frame) {
            if ($frame['isArray'] && $frame['inValue']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Two spellings of one number are one value: readability separators say nothing,
     * and hexadecimal case says nothing either. The base is left alone — `0755` and
     * `493` are the same quantity written for two different readers, and folding them
     * together would report a repeat nobody can see.
     *
     * @param string $literal Number exactly as it stands in the source
     * @return string Value the rule counts by
     */
    private function normalize(string $literal): string
    {
        return strtolower(str_replace('_', '', $literal));
    }
}
