<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle;

/**
 * Reads a class declaration and its docblock out of the token_get_all() stream for the
 * code-style rules: where a class body opens, which docblock a declaration carries across
 * its modifiers and attributes, where a bracket closes, and how a docblock splits into
 * tags and type expressions.
 *
 * The token methods take the significant tokens a rule has already filtered, whitespace
 * and line comments dropped, so an adjacent token is found by index. A rule that reads the
 * same shapes takes them from here rather than keeping a copy of the walk.
 */
final class ClassDocReader
{
    /** @var list<int> Declaration modifiers between a docblock and its declaration */
    private const array MODIFIERS = [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT, T_FINAL, T_READONLY];

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Significant tokens
     * @param int $index Class keyword index
     * @return int|null Class body opening, skipping anonymous constructor arguments
     */
    public static function classOpening(array $tokens, int $index): ?int
    {
        for ($cursor = $index + 1; isset($tokens[$cursor]); $cursor++) {
            if ($tokens[$cursor] === '{') {
                return $cursor;
            }
            if ($tokens[$cursor] === ';') {
                return null;
            }
            if ($tokens[$cursor] === '(' || $tokens[$cursor] === '[') {
                $cursor = self::bracketEnd($tokens, $cursor);
            }
        }

        return null;
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Significant tokens
     * @param int $index Declaration keyword index
     * @return array{0: int, 1: string, 2: int}|null Attached docblock, across modifiers and attributes
     */
    public static function precedingDoc(array $tokens, int $index): ?array
    {
        for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
            $token = $tokens[$cursor];
            if (is_array($token) && $token[0] === T_DOC_COMMENT) {
                return $token;
            }
            if (is_array($token) && in_array($token[0], self::MODIFIERS, true)) {
                continue;
            }
            if ($token === ']') {
                $depth = 1;
                while (--$cursor >= 0) {
                    $part = $tokens[$cursor];
                    if ($part === ']') {
                        $depth++;
                    } elseif (($part === '[' || (is_array($part) && $part[0] === T_ATTRIBUTE)) && --$depth === 0) {
                        break;
                    }
                }
                if ($cursor >= 0 && is_array($tokens[$cursor]) && $tokens[$cursor][0] === T_ATTRIBUTE) {
                    continue;
                }
            }

            return null;
        }

        return null;
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Significant tokens
     * @param int $opening Opening bracket or attribute token
     * @return int Matching close, or the last token for an incomplete declaration
     */
    public static function bracketEnd(array $tokens, int $opening): int
    {
        $open = is_array($tokens[$opening]) ? '[' : $tokens[$opening];
        $close = match ($open) { '(' => ')', '[' => ']', default => '}' };
        $depth = 1;
        for ($cursor = $opening + 1; isset($tokens[$cursor]); $cursor++) {
            $token = $tokens[$cursor];
            if ($token === $open
                || ($open === '[' && is_array($token) && $token[0] === T_ATTRIBUTE)
                || ($open === '{' && is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif ($token === $close && --$depth === 0) {
                return $cursor;
            }
        }

        return count($tokens) - 1;
    }

    /**
     * @param array{0: int, 1: string, 2: int} $doc Raw doc comment token
     * @return list<array{name: string, text: string, line: int}> Tags with continuation lines folded, retaining the tag's line
     */
    public static function tags(array $doc): array
    {
        $tags = [];
        foreach (explode("\n", $doc[1]) as $offset => $line) {
            $line = trim((string)preg_replace('~^\s*(?:/\*\*|\*)\s?|\*/\s*$~', '', $line));
            if (preg_match('/^@([\w-]+)\b(.*)$/', $line, $match) === 1) {
                $tags[] = ['name' => $match[1], 'text' => trim($match[2]), 'line' => $doc[2] + $offset];
            } elseif ($tags !== []) {
                $tags[array_key_last($tags)]['text'] .= ' ' . $line;
            }
        }
        foreach ($tags as &$tag) {
            $tag['text'] = trim((string)preg_replace('/\s+/', ' ', $tag['text']));
        }

        return $tags;
    }

    /**
     * @param string $text Whitespace-normalized tag text
     * @return string Type without its trailing description or parameter name
     */
    public static function typeBeforeDescription(string $text): string
    {
        $depth = 0;
        $quote = null;
        for ($index = 0; $index < strlen($text); $index++) {
            $character = $text[$index];
            if ($quote !== null) {
                if ($character === '\\') {
                    $index++;
                } elseif ($character === $quote) {
                    $quote = null;
                }
            } elseif ($character === "'" || $character === '"') {
                $quote = $character;
            } elseif (str_contains('(<{[', $character)) {
                $depth++;
            } elseif (str_contains(')>}]', $character)) {
                $depth--;
            } elseif ($character === ' ' && $depth === 0) {
                $next = $text[$index + 1] ?? null;
                $previous = $text[$index - 1] ?? null;
                // A spaced union/intersection still belongs to the type; &$parameter does not.
                if ($previous === '|' || $previous === '&' || $next === '|'
                    || ($next === '&' && preg_match('/^&\s*(?:\.\.\.)?\$/', substr($text, $index + 1)) !== 1)) {
                    continue;
                }

                return substr($text, 0, $index);
            }
        }

        return $text;
    }

    /**
     * @param string $type Type expression
     * @param string $delimiters Separators to read only outside nested brackets and quoted literals
     * @return list<string> Top-level type fragments
     */
    public static function splitType(string $type, string $delimiters): array
    {
        $parts = [];
        $start = 0;
        $depth = 0;
        $quote = null;
        for ($index = 0; $index < strlen($type); $index++) {
            $character = $type[$index];
            if ($quote !== null) {
                if ($character === '\\') {
                    $index++;
                } elseif ($character === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($character === "'" || $character === '"') {
                $quote = $character;
            } elseif ($depth === 0 && str_contains($delimiters, $character)) {
                $parts[] = trim(substr($type, $start, $index - $start));
                $start = $index + 1;
            } elseif (str_contains('(<{[', $character)) {
                $depth++;
            } elseif (str_contains(')>}]', $character)) {
                $depth--;
            }
        }
        $parts[] = trim(substr($type, $start));

        return $parts;
    }
}
