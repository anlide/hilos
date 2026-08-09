<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Rule;

use Hilos\Tests\CodeStyle\CodeStyleRule;
use Hilos\Tests\CodeStyle\Violation;

/**
 * Enforces method-contracts.md: the empty string is not a value meaning "no value".
 * Minting one at the very place a missing value should have stayed missing forces
 * every reader below to know that the empty string is not data.
 *
 * Three spellings mint it, and the rule counts occurrences, not lines: `?? ''`,
 * the branch of a ternary that hands back `''`, and `match`'s `default => ''`.
 * Only real tokens are read, so any of them inside a comment or quoted inside a
 * string literal is not a hit.
 *
 * A place where the empty string arrives from outside the process is legal, and a
 * `// external-boundary: <reason>` marker on the line directly above says so. The
 * form is the one `ErrorSuppressionRule` already uses, so the repository carries
 * one way of legalizing a single occurrence rather than two. That line has to be
 * the whole marker: a reason wrapped onto a second line leaves a plain comment
 * directly above the occurrence, and a plain comment there legalizes nothing.
 *
 * The rule never learns which root it is judging: it is handed either the segments
 * of a zone or the whole root, and the choice belongs to the caller that knows the
 * root — see `CodeStyleGuardTest`.
 */
final class EmptyStringSentinelRule implements CodeStyleRule
{
    public const string ID = 'EMPTY-STRING-SENTINEL';

    private const string DOC = 'docs/agents/code-style/method-contracts.md';

    /** Both spellings of the literal; `token_get_all()` keeps the quotes. */
    private const array EMPTY_STRING_LITERALS = ["''", '""'];

    /** The marker that legalizes one occurrence; the reason after the colon is the point of it. */
    private const string MARKER_PATTERN = '~^//\s*external-boundary:(?<reason>.*)$~';

    /** Tokens that open a bracket pair; the ternary bookkeeping is kept per depth. */
    private const array OPENING_TOKENS = ['(', '[', '{'];

    /** Their closing counterparts; `T_CURLY_OPEN` and friends close with `}` too. */
    private const array CLOSING_TOKENS = [')', ']', '}'];

    /**
     * Tokens a `?` may follow when it opens a ternary. A nullable type declaration
     * spells the same character, and it always follows a comma, a bracket, a colon
     * or a modifier keyword — never the end of an expression.
     *
     * @var array<int, int>
     */
    private const array EXPRESSION_END_TOKENS = [
        T_VARIABLE,
        T_STRING,
        T_CONSTANT_ENCAPSED_STRING,
        T_LNUMBER,
        T_DNUMBER,
        T_END_HEREDOC,
    ];

    /** Single-character tokens an expression may end with; a quote closes an interpolated string. */
    private const array EXPRESSION_END_CHARACTERS = [')', ']', '}', '"'];

    /**
     * @param array<int, string>|null $zone Path segments judged, relative to the scanned root; null judges the whole root
     */
    private function __construct(private readonly ?array $zone)
    {
    }

    /**
     * The zone grows one phase at a time: turned on across a whole root at once, the
     * baseline of that root would become a list of exceptions rather than a list of
     * owed work.
     *
     * @param array<int, string> $segments Path segments judged, relative to the scanned root
     * @return self Rule that judges those segments and nothing else
     */
    public static function forZone(array $segments): self
    {
        return new self($segments);
    }

    /**
     * For a root with no subsystems outside the mechanism — a demo, a test suite —
     * there is nothing to phase, and a segment list would only have to chase every
     * new directory.
     *
     * @return self Rule that judges every file of the scanned root
     */
    public static function forWholeRoot(): self
    {
        return new self(null);
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
     * The path is read relative to the scanned root, so the fixtures are judged by
     * the very same code as the real sources.
     *
     * @param string $relativePath File path relative to the scanned root
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @return iterable<Violation> One entry per minted empty string
     */
    public function check(string $relativePath, array $tokens): iterable
    {
        if (!$this->isInZone($relativePath)) {
            return;
        }

        $lines = $this->lineNumbers($tokens);

        foreach ($this->mintedLiterals($tokens) as $index => $message) {
            $reason = $this->markerReason($tokens, $lines, $index);
            if ($reason === null) {
                yield new Violation(self::ID, $relativePath, $lines[$index], $message);
                continue;
            }

            if (trim($reason) === '') {
                yield new Violation(
                    self::ID,
                    $relativePath,
                    $lines[$index],
                    'the `// external-boundary:` marker above the fallback names no reason',
                );
            }
        }
    }

    /**
     * Walks the file once and collects every empty string literal that is being
     * minted rather than merely written down. The ternary branch is the reason for
     * the walk: a `:` also separates a named argument, closes a `case`, opens the
     * alternative syntax and introduces a return type, so it counts only while a
     * `?` of the same bracket depth is still open.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @return array<int, string> Message keyed by the token index of the minted literal
     */
    private function mintedLiterals(array $tokens): array
    {
        $minted = [];
        $openTernaries = [0];
        $depth = 0;

        foreach ($tokens as $index => $token) {
            if ($this->isOpeningToken($token)) {
                $depth++;
                $openTernaries[$depth] = 0;
                continue;
            }

            if ($this->isClosingToken($token) && $depth > 0) {
                unset($openTernaries[$depth]);
                $depth--;
                continue;
            }

            if ($token === '?' && $this->opensTernary($tokens, $index)) {
                $openTernaries[$depth]++;
                continue;
            }

            if ($token === ':' && $openTernaries[$depth] > 0) {
                $openTernaries[$depth]--;
                $literalIndex = $this->emptyStringIndex($tokens, $index);
                if ($literalIndex !== null) {
                    $minted[$literalIndex] = 'a ternary branch hands back \'\' where the value is missing;'
                        . ' keep it null or make the field required';
                }
                continue;
            }

            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_COALESCE) {
                $literalIndex = $this->emptyStringIndex($tokens, $index);
                if ($literalIndex !== null) {
                    $minted[$literalIndex] = '?? \'\' turns a missing value into an empty string;'
                        . ' keep it null or make the field required';
                }
                continue;
            }

            if ($token[0] === T_DEFAULT) {
                $literalIndex = $this->matchDefaultIndex($tokens, $index);
                if ($literalIndex !== null) {
                    $minted[$literalIndex] = 'match falls back to \'\' where the value is missing;'
                        . ' keep it null or make the field required';
                }
            }
        }

        return $minted;
    }

    /**
     * A zone entry matches a whole path segment, wherever it sits: the fixtures
     * repeat the segments of the real zone under `Bad/` and `Good/`, and would fall
     * outside a prefix match.
     *
     * @param string $relativePath File path relative to the scanned root
     * @return bool True when this file is inside the checked zone
     */
    private function isInZone(string $relativePath): bool
    {
        if ($this->zone === null) {
            return true;
        }

        $path = '/' . $relativePath;

        foreach ($this->zone as $zone) {
            $anchored = '/' . $zone;
            if (str_ends_with($path, $anchored) || str_contains($path, $anchored . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string|array{0: int, 1: string, 2: int} $token Token to classify
     * @return bool True when the token opens a bracket pair
     */
    private function isOpeningToken(string|array $token): bool
    {
        if (is_array($token)) {
            return in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ATTRIBUTE], true);
        }

        return in_array($token, self::OPENING_TOKENS, true);
    }

    /**
     * @param string|array{0: int, 1: string, 2: int} $token Token to classify
     * @return bool True when the token closes a bracket pair
     */
    private function isClosingToken(string|array $token): bool
    {
        return !is_array($token) && in_array($token, self::CLOSING_TOKENS, true);
    }

    /**
     * Tells a ternary `?` from the `?` of a nullable type declaration by what stands
     * before it: only a ternary follows something an expression can end with.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $index Index of the `?` token
     * @return bool True when the `?` opens a ternary expression
     */
    private function opensTernary(array $tokens, int $index): bool
    {
        for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
            $token = $tokens[$cursor];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            if (is_array($token)) {
                return in_array($token[0], self::EXPRESSION_END_TOKENS, true);
            }

            return in_array($token, self::EXPRESSION_END_CHARACTERS, true);
        }

        return false;
    }

    /**
     * The `default` of a `switch` is followed by a colon and the `default` of a
     * `match` by a double arrow, so the arrow is what tells the two apart. A bare
     * arrow will not do: an ordinary array element spells `=> ''` and is no sentinel.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $index Index of the `T_DEFAULT` token
     * @return int|null Index of the empty string literal the arm hands back, or null
     */
    private function matchDefaultIndex(array $tokens, int $index): ?int
    {
        $arrowIndex = $this->significantIndex($tokens, $index);
        if ($arrowIndex === null) {
            return null;
        }

        $arrow = $tokens[$arrowIndex];

        return is_array($arrow) && $arrow[0] === T_DOUBLE_ARROW ? $this->emptyStringIndex($tokens, $arrowIndex) : null;
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $index Index to walk forward from
     * @return int|null Index of the next token when it is an empty string literal, null otherwise
     */
    private function emptyStringIndex(array $tokens, int $index): ?int
    {
        $literalIndex = $this->significantIndex($tokens, $index);
        if ($literalIndex === null) {
            return null;
        }

        $literal = $tokens[$literalIndex];
        if (!is_array($literal) || $literal[0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }

        return in_array($literal[1], self::EMPTY_STRING_LITERALS, true) ? $literalIndex : null;
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $index Index to walk forward from
     * @return int|null Index of the nearest token that is not whitespace or a comment
     */
    private function significantIndex(array $tokens, int $index): ?int
    {
        for ($cursor = $index + 1; isset($tokens[$cursor]); $cursor++) {
            $token = $tokens[$cursor];
            if (!is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $cursor;
            }
        }

        return null;
    }

    /**
     * Single-character tokens carry no line of their own, so the walk keeps the
     * line the last multi-character token ended on.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @return array<int, int> Start line of every token, single-character ones included
     */
    private function lineNumbers(array $tokens): array
    {
        $lines = [];
        $line = 1;

        foreach ($tokens as $index => $token) {
            if (!is_array($token)) {
                $lines[$index] = $line;
                continue;
            }

            $lines[$index] = $token[2];
            $line = $token[2] + substr_count($token[1], "\n");
        }

        return $lines;
    }

    /**
     * Reads the marker that legalizes one fallback. The walk gives up as soon as it
     * passes above the previous line: the marker has to sit directly above the very
     * occurrence it explains, or it stops being a classification of that place.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param array<int, int> $lines Start line of every token
     * @param int $index Index of the empty string literal
     * @return string|null Text after the colon, or null when no marker covers the fallback
     */
    private function markerReason(array $tokens, array $lines, int $index): ?string
    {
        $markerLine = $lines[$index] - 1;

        for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
            if ($lines[$cursor] < $markerLine) {
                return null;
            }

            $token = $tokens[$cursor];
            if (!is_array($token) || $token[0] !== T_COMMENT || $lines[$cursor] !== $markerLine) {
                continue;
            }

            return preg_match(self::MARKER_PATTERN, rtrim($token[1]), $found) === 1 ? $found['reason'] : null;
        }

        return null;
    }
}
