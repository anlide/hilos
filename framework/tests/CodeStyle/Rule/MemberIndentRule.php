<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Rule;

use Hilos\Tests\CodeStyle\CodeStyleRule;
use Hilos\Tests\CodeStyle\Violation;

/**
 * Enforces indentation of PHP class member declaration lines.
 *
 * Each member declaration line directly inside a class-like body (class, interface,
 * trait, enum, or anonymous class) must be indented by exactly four spaces relative
 * to the line that opened the body with "{".
 *
 * Starters judged when at the beginning of a line:
 * visibility keywords (public, protected, private, and PHP 8.4 asymmetric set forms),
 * abstract, final, static, readonly, var, function, const, enum case, trait use,
 * attributes (#[), and docblocks.
 *
 * The baseline indentation is taken from the line containing the opening brace rather
 * than assuming four spaces per nesting depth, ensuring anonymous classes passed as
 * call arguments are judged accurately without being discarded or misaligned.
 *
 * Non-member constructs are deliberately out of scope: method and closure bodies,
 * multiline expressions, member braces, ordinary single-line and multi-line comments,
 * and top-level file declarations (such as class or global function keywords).
 *
 * Origin: HIL-959 (HOTFIX 3fe609f8b), where a mis-indented method signature in
 * OwnershipDeclaration landed unnoticed.
 */
final class MemberIndentRule implements CodeStyleRule
{
    public const string ID = 'MEMBER-INDENT';

    private const string DOC = 'docs/code-style.md';

    private const int INDENT_STEP_SPACES = 4;

    private const string INDENT_STEP = '    ';

    /**
     * Tokens that can start a member declaration line in a class-like body.
     *
     * @var array<int, int>
     */
    private const array STARTER_TOKENS = [
        T_PUBLIC,
        T_PROTECTED,
        T_PRIVATE,
        T_PUBLIC_SET,
        T_PROTECTED_SET,
        T_PRIVATE_SET,
        T_ABSTRACT,
        T_FINAL,
        T_STATIC,
        T_READONLY,
        T_VAR,
        T_FUNCTION,
        T_CONST,
        T_CASE,
        T_USE,
        T_ATTRIBUTE,
        T_DOC_COMMENT,
    ];

    /**
     * Class-like keyword tokens whose body opening is tracked.
     *
     * @var array<int, int>
     */
    private const array CLASSLIKE_TOKENS = [
        T_CLASS,
        T_INTERFACE,
        T_TRAIT,
        T_ENUM,
    ];

    /**
     * Tokens skipped when looking for adjacent significant tokens.
     *
     * @var array<int, int>
     */
    private const array INSIGNIFICANT_TOKENS = [
        T_WHITESPACE,
        T_COMMENT,
        T_DOC_COMMENT,
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
     * @param string $relativePath File path relative to the scanned root
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @return iterable<Violation> Hits found in this file
     */
    public function check(string $relativePath, array $tokens): iterable
    {
        $source = $this->sourceText($tokens);
        $totalTokens = count($tokens);
        $offset = 0;
        $lineStart = 0;
        $line = 1;
        $stack = [];
        $pending = null;

        for ($i = 0; $i < $totalTokens; $i++) {
            $token = $tokens[$i];
            $id = is_array($token) ? $token[0] : $token;
            $text = is_array($token) ? $token[1] : $token;

            $prefix = substr($source, $lineStart, $offset - $lineStart);
            $prefixLength = strlen($prefix);

            if ($id !== T_WHITESPACE
                && $offset > 0
                && strspn($prefix, " \t") === $prefixLength
                && in_array($id, self::STARTER_TOKENS, true)
                && $stack !== []
                && $stack[count($stack) - 1]['body']
            ) {
                $expected = $stack[count($stack) - 1]['indent'] . self::INDENT_STEP;
                if ($prefix !== $expected) {
                    $expectedLength = strlen($expected);
                    $message = str_contains($prefix, "\t")
                        ? sprintf('member line is indented with a tab; the class body it sits in asks for %d spaces', $expectedLength)
                        : sprintf('member line is indented %d spaces; the class body it sits in asks for %d', $prefixLength, $expectedLength);

                    yield new Violation(
                        self::ID,
                        $relativePath,
                        $line,
                        $message,
                    );
                }
            }

            if (in_array($id, self::CLASSLIKE_TOKENS, true)) {
                $prevIndex = $this->findSignificantToken($tokens, $i, -1);
                $nextIndex = $this->findSignificantToken($tokens, $i, 1);
                $prevId = $prevIndex === null
                    ? null
                    : (is_array($tokens[$prevIndex]) ? $tokens[$prevIndex][0] : $tokens[$prevIndex]);
                $nextId = $nextIndex === null
                    ? null
                    : (is_array($tokens[$nextIndex]) ? $tokens[$nextIndex][0] : $tokens[$nextIndex]);

                if ($prevId !== T_DOUBLE_COLON && $nextId !== ':') {
                    $pending = count($stack);
                }
            }

            if ($id === ';' && $pending === count($stack)) {
                $pending = null;
            }

            if ($id === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $lineRest = substr($source, $lineStart);
                $body = ($id === '{' && $pending === count($stack));
                if ($body) {
                    $pending = null;
                }
                $stack[] = [
                    'body' => $body,
                    'indent' => substr($lineRest, 0, strspn($lineRest, " \t")),
                ];
            } elseif ($id === '(' || $id === '[' || $id === T_ATTRIBUTE) {
                $stack[] = [
                    'body' => false,
                    'indent' => '',
                ];
            } elseif ($id === '}' || $id === ')' || $id === ']') {
                array_pop($stack);
                if ($pending !== null && $pending > count($stack)) {
                    $pending = null;
                }
            }

            $newlines = substr_count($text, "\n");
            if ($newlines > 0) {
                $line += $newlines;
                $lineStart = $offset + (int) strrpos($text, "\n") + 1;
            }
            $offset += strlen($text);
        }
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @return string The file as it was read, rebuilt from its tokens
     */
    private function sourceText(array $tokens): string
    {
        $source = '';

        foreach ($tokens as $token) {
            $source .= is_array($token) ? $token[1] : $token;
        }

        return $source;
    }

    /**
     * Finds the index of the nearest non-whitespace and non-comment token in the given direction.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token list
     * @param int $from Starting token index
     * @param int $step Direction step (-1 for backward, 1 for forward)
     * @return int|null Token index or null if none found
     */
    private function findSignificantToken(array $tokens, int $from, int $step): ?int
    {
        $totalTokens = count($tokens);
        for ($i = $from + $step; $i >= 0 && $i < $totalTokens; $i += $step) {
            $token = $tokens[$i];
            if (!is_array($token) || !in_array($token[0], self::INSIGNIFICANT_TOKENS, true)) {
                return $i;
            }
        }

        return null;
    }
}
