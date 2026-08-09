<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Rule;

use Hilos\Tests\CodeStyle\CodeStyleRule;
use Hilos\Tests\CodeStyle\Violation;

/**
 * Enforces the case half of cross-layer-field-names.md: a field key that crosses
 * PHP → wire → TS is spelled camelCase, so one word survives every boundary.
 *
 * A field key is recognized by the case of the constant's NAME, not by the file it
 * sits in. A camelCase name is how this repository declares a payload key in all
 * three places keys are born — `*TableRow.php`, `*DTO.php` and the signal data
 * classes — while an UPPER_SNAKE name is a message name, a type or a catalog key,
 * and a snake name is an SQL column. The lexical test needs no registry of files
 * and grows with the tree; it also settles the trap of a catalog key that happens
 * to hold a snake value (`CATALOG_ENTRY_DEFAULT_VALUE = 'default_value'`) for free.
 *
 * Only a literal is judged. A constant whose value is a reference to another
 * constant (`public const string id = ObjectSetting::id;`) is passed over and
 * judged where the key is spelled out, so one key is reported once.
 *
 * The rule is narrower than its document: it says nothing about the words, only
 * about their case, and it cannot see a key written as a literal at the place it is
 * used. The TypeScript half of the same rule id lives in
 * `framework/frontend/codestyle/wireKeyCase.ts`.
 */
final class WireKeyCaseRule implements CodeStyleRule
{
    public const string ID = 'WIRE-KEY-CASE';

    private const string DOC = 'docs/agents/code-style/cross-layer-field-names.md';

    /**
     * How a field key declares itself on this side: a constant named in camelCase.
     * Everything else — UPPER_SNAKE, snake, a leading capital — names something the
     * rule does not own.
     */
    private const string FIELD_KEY_NAME = '/^[a-z][a-zA-Z0-9]*$/';

    /**
     * How the key itself has to be spelled to survive the crossing unchanged. Same
     * shape as the name test above and a separate constant on purpose: the two
     * answer different questions, and either could move without the other.
     */
    private const string WIRE_KEY_VALUE = '/^[a-z][a-zA-Z0-9]*$/';

    /** Brackets a value may nest in; a `=` inside one of them is not a declaration. */
    private const array OPENING_BRACKETS = ['(', '[', '{'];

    /** Counterparts of the brackets above, closing the same nesting. */
    private const array CLOSING_BRACKETS = [')', ']', '}'];

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
     * @return iterable<Violation> One entry per declared key, in file order
     */
    public function check(string $relativePath, array $tokens): iterable
    {
        $significant = $this->significantTokens($tokens);

        foreach ($significant as $index => $token) {
            if (!is_array($token) || $token[0] !== T_CONST) {
                continue;
            }

            yield from $this->checkDeclaration($relativePath, $significant, $index);
        }
    }

    /**
     * One `const` keyword can declare several constants at once, so the declaration
     * is read as a whole and every assignment inside it is judged. Nesting is
     * tracked because a `=` inside an array or a call belongs to that value, not to
     * a constant of its own.
     *
     * @param string $relativePath File path relative to the scanned root
     * @param array<int, string|array{0: int, 1: string, 2: int}> $significant Stream without ignorable tokens
     * @param int $constIndex Position of the `const` keyword in that stream
     * @return iterable<Violation> Hits of this declaration, in declaration order
     */
    private function checkDeclaration(string $relativePath, array $significant, int $constIndex): iterable
    {
        $depth = 0;

        for ($index = $constIndex + 1; isset($significant[$index]); $index++) {
            $token = $significant[$index];

            if (in_array($token, self::OPENING_BRACKETS, true)) {
                $depth++;
                continue;
            }
            if (in_array($token, self::CLOSING_BRACKETS, true)) {
                $depth--;
                continue;
            }
            if ($depth > 0) {
                continue;
            }
            if ($token === ';') {
                return;
            }
            if ($token !== '=') {
                continue;
            }

            $violation = $this->judgeAssignment($relativePath, $significant, $index);
            if ($violation !== null) {
                yield $violation;
            }
        }
    }

    /**
     * The value is judged only when it is the whole right-hand side — a lone literal
     * followed by the end of this constant. A literal that opens a concatenation is
     * a fragment rather than a key, and a fragment has no case to hold.
     *
     * The name is read by its spelling rather than by its token type. A constant may
     * be named with a reserved word — `key` and `type` already are keys here, and
     * `default` or `list` would be plausible neighbours — and the lexer hands those
     * over as `T_DEFAULT` or `T_LIST` rather than `T_STRING`. Requiring `T_STRING`
     * would let exactly those through in silence. Whatever stands directly in front
     * of a `=` at the top level of a `const` is the name being declared, so nothing
     * else can reach the test.
     *
     * @param string $relativePath File path relative to the scanned root
     * @param array<int, string|array{0: int, 1: string, 2: int}> $significant Stream without ignorable tokens
     * @param int $assignmentIndex Position of the `=` in that stream
     * @return Violation|null Hit when a field key is spelled in another case, null otherwise
     */
    private function judgeAssignment(string $relativePath, array $significant, int $assignmentIndex): ?Violation
    {
        $name = $significant[$assignmentIndex - 1] ?? null;
        $value = $significant[$assignmentIndex + 1] ?? null;
        $terminator = $significant[$assignmentIndex + 2] ?? null;

        if (!is_array($name) || preg_match(self::FIELD_KEY_NAME, $name[1]) !== 1) {
            return null;
        }
        if (!is_array($value) || $value[0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }
        if ($terminator !== ',' && $terminator !== ';') {
            return null;
        }

        $key = substr($value[1], 1, -1);
        if (preg_match(self::WIRE_KEY_VALUE, $key) === 1) {
            return null;
        }

        return new Violation(
            self::ID,
            $relativePath,
            $value[2],
            sprintf('field key \'%s\' is not camelCase; one spelling has to serve PHP, the wire and TS', $key),
        );
    }

    /**
     * A declaration is read as a sequence of neighbours — the name in front of the
     * `=`, the literal behind it, the `,` or `;` that closes it. Dropping whitespace
     * and comments once here keeps every one of those a single step away.
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
}
