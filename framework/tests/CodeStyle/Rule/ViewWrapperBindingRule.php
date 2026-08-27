<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Rule;

use Hilos\Tests\CodeStyle\CodeStyleRule;
use Hilos\Tests\CodeStyle\Violation;

/**
 * Enforces collection-iteration.md: a view wrapper holds the row it was built from,
 * never the variable that row was handed over in.
 *
 * A wrapper bound with `$this->_object = &$object` follows the variable rather than
 * the row: `foreach` reuses one variable for the whole walk, so every wrapper built
 * inside it ends up showing whatever came last. Nothing crashes — the frontend is
 * simply served the wrong row.
 *
 * The two halves are one rule because they are the two ends of the same binding.
 * Half one is where it is stored, half two is how it is handed over: a parameter
 * declared `&$row` cannot be passed an expression at all, so the signature itself
 * makes the caller produce the very variable that fires the trap, and it is what an
 * author reads before copying the shape into a factory of their own.
 *
 * Half one is judged on every root, tests included: the four bindings the framework
 * had are gone, and the readonly on the two item fields refuses a fifth at runtime —
 * but a new wrapper hierarchy has no such field yet, and this is what catches it.
 *
 * Half two is judged inside the View zones only. Outside them `&` is a legitimate
 * and frequent tool — an array accumulator, a `use (&$errno)` closure, a `?string
 * &$warning` out-parameter — and a blanket ban would be false red. Inside the zones
 * there is no other use of it at all.
 *
 * Only real tokens are read, so either spelling inside a comment or a string literal
 * is not a hit, and a `use (&$captured)` clause is out of half two by construction:
 * the walk reads parameter lists, and a capture list is not one.
 */
final class ViewWrapperBindingRule implements CodeStyleRule
{
    public const string ID = 'VIEW-WRAPPER-BIND';

    private const string DOC = 'docs/agents/orm/collection-iteration.md';

    /**
     * Path prefixes half two judges, each relative to the scanned root. They are read
     * as prefixes rather than as segments anywhere in the path, because that is what
     * the zone means: the wrapper layer of a backend root, not any directory that
     * happens to repeat the name deeper down.
     *
     * @var array<int, string>
     */
    private const array ZONE_PREFIXES = [
        'Database/View/',
        'Database/Actions/',
        'Runtime/View/',
        'Runtime/View/Actions/',
    ];

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
     * The path is read relative to the scanned root, so the fixtures are judged by the
     * very same code as the real sources — a fixture standing outside the zone earns
     * the same silence a real file there would.
     *
     * @param string $relativePath File path relative to the scanned root
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @return iterable<Violation> One entry per binding, storage first
     */
    public function check(string $relativePath, array $tokens): iterable
    {
        yield from $this->checkStorage($relativePath, $tokens);

        if ($this->isInZone($relativePath)) {
            yield from $this->checkSignatures($relativePath, $tokens);
        }
    }

    /**
     * Half one: `$this-><name> = &<variable>`, which is the binding itself.
     *
     * @param string $relativePath File path relative to the scanned root
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @return iterable<Violation> One entry per property bound by reference
     */
    private function checkStorage(string $relativePath, array $tokens): iterable
    {
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_STRING || !$this->isOwnProperty($tokens, $index)) {
                continue;
            }

            $assignment = $this->significantIndex($tokens, $index, 1);
            if ($assignment === null || $tokens[$assignment] !== '=') {
                continue;
            }

            $ampersand = $this->significantIndex($tokens, $assignment, 1);
            if ($ampersand === null || !$this->isReferenceAmpersand($tokens[$ampersand])) {
                continue;
            }

            yield new Violation(
                self::ID,
                $relativePath,
                $token[2],
                '$this->' . $token[1] . ' is bound to a variable by reference; assign the value,'
                    . ' so the wrapper keeps the row it was built from',
            );
        }
    }

    /**
     * Half two: a parameter declared by reference anywhere in the zone, in a method, a
     * plain function, a closure or an arrow function alike. Only the parameter list is
     * walked, which is what leaves a closure's `use (&$captured)` alone.
     *
     * @param string $relativePath File path relative to the scanned root
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @return iterable<Violation> One entry per parameter declared by reference
     */
    private function checkSignatures(string $relativePath, array $tokens): iterable
    {
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || !in_array($token[0], [T_FUNCTION, T_FN], true)) {
                continue;
            }

            $opening = $this->parameterListStart($tokens, $index);
            if ($opening === null) {
                continue;
            }

            $closing = $this->closingBracket($tokens, $opening, '(', ')');
            if ($closing === null) {
                continue;
            }

            yield from $this->referenceParameters($relativePath, $tokens, $opening, $closing);
        }
    }

    /**
     * @param string $relativePath File path relative to the scanned root
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $opening Index of the parameter list's opening parenthesis
     * @param int $closing Index of its matching closing parenthesis
     * @return iterable<Violation> One entry per by-reference parameter in that list
     */
    private function referenceParameters(
        string $relativePath,
        array $tokens,
        int $opening,
        int $closing,
    ): iterable {
        for ($cursor = $opening + 1; $cursor < $closing; $cursor++) {
            if (!$this->isReferenceAmpersand($tokens[$cursor])) {
                continue;
            }

            $name = $this->significantIndex($tokens, $cursor, 1);
            if ($name === null) {
                continue;
            }

            $parameter = $tokens[$name];
            if (!is_array($parameter) || $parameter[0] !== T_VARIABLE) {
                continue;
            }

            yield new Violation(
                self::ID,
                $relativePath,
                $parameter[2],
                $parameter[1] . ' is declared by reference in the wrapper layer;'
                    . ' take the value, so no caller has to hand over a variable',
            );
        }
    }

    /**
     * The parenthesis a parameter list opens with. A function returning by reference
     * writes its `&` before the name, and a named function has a name at all, so the
     * walk steps over whatever stands between the keyword and the list.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $index Index of the `function` or `fn` keyword
     * @return int|null Index of the opening parenthesis, or null when the file ends first
     */
    private function parameterListStart(array $tokens, int $index): ?int
    {
        for ($cursor = $this->significantIndex($tokens, $index, 1); $cursor !== null;) {
            if ($tokens[$cursor] === '(') {
                return $cursor;
            }

            $token = $tokens[$cursor];
            $isNamePart = is_array($token)
                && in_array($token[0], [T_STRING, T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG], true);
            if (!$isNamePart) {
                return null;
            }

            $cursor = $this->significantIndex($tokens, $cursor, 1);
        }

        return null;
    }

    /**
     * @param string|array{0: int, 1: string, 2: int} $token Token to classify
     * @return bool True when the token is an `&` taking a reference rather than a bitwise and
     */
    private function isReferenceAmpersand(string|array $token): bool
    {
        return is_array($token) && $token[0] === T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG;
    }

    /**
     * @param string $relativePath File path relative to the scanned root
     * @return bool True when the file lies under one of the judged prefixes
     */
    private function isInZone(string $relativePath): bool
    {
        foreach (self::ZONE_PREFIXES as $prefix) {
            if (str_starts_with($relativePath, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $index Index of the property name token
     * @return bool True when the name is read as a property of `$this`
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
     * @param int $openIndex Index of the opening bracket
     * @param string $open Opening bracket to count
     * @param string $close Closing bracket to match
     * @return int|null Index of the matching closing bracket, or null when the file ends first
     */
    private function closingBracket(array $tokens, int $openIndex, string $open, string $close): ?int
    {
        $depth = 0;
        for ($cursor = $openIndex; isset($tokens[$cursor]); $cursor++) {
            if ($tokens[$cursor] === $open) {
                $depth++;
                continue;
            }

            if ($tokens[$cursor] === $close && --$depth === 0) {
                return $cursor;
            }
        }

        return null;
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
