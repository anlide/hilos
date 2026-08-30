<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Rule;

use Hilos\Tests\CodeStyle\CodeStyleRule;
use Hilos\Tests\CodeStyle\Violation;

/**
 * Enforces method-contracts.md: reading a payload does not mint a stub for a
 * field that did not arrive. A payload field has two roles and no third one —
 * required, so an absent or mistyped value is refused, or legitimately absent,
 * so it arrives as null — and `'' `, `0` and `0.0` are the three values that
 * quietly pretend to be a third one.
 *
 * The rule judges the body of eight readers by name and nothing else — the frame
 * readers `fromArray()` and `fromJson()`, and the runtime-row readers
 * `fromRow()`, `hydrateBase()`, `hydrateOwn()`, `applyDiff()`, `applyBaseDiff()`
 * and `applyOwnDiff()`. That is what tells minting from ordinary defaulting: the
 * same `?? 0` in a constructor or a getter is a decision about this object's own
 * state, while in a payload reader it is a decision about a row somebody else
 * sent. The name is judged wherever it stands, because the two families the
 * runtime declares are `final` and only delegate, so the reading itself lives in
 * the helpers named beside them.
 *
 * The group a name falls into is its {@see PayloadReaderKind}, and it decides
 * what the report names as the cure: a whole row is refused or its field made
 * nullable, while a diff reads the field with its `patch*` twin.
 *
 * Three spellings mint the stub, and the rule counts occurrences, not lines:
 * `??`, the branch of a ternary that hands the literal back, and `match`'s
 * `default =>`. `?? null` and `?? []` are neither: null is how a legitimately
 * absent field arrives, and an empty section is what an omitted section of a
 * payload means. Only real tokens are read, so any of these inside a comment or
 * quoted inside a string literal is not a hit.
 *
 * A diff body carries a second finding, and it is about reading rather than
 * minting: an `optional*` reader answers `null` to a key the diff does not
 * carry, which clears a field the diff never mentioned. It is matched by the
 * name's prefix rather than by a list, because the `optional*` family grows on
 * demand and one added tomorrow would slip out from under the guard the very way
 * the runtime row did.
 *
 * A value that genuinely arrives from outside as the literal is legal, and a
 * `// external-boundary: <reason>` marker on the line directly above says so.
 * The form is the one `ErrorSuppressionRule` and `EmptyStringSentinelRule`
 * already use, so the repository carries one way of legalizing a single
 * occurrence rather than three. That line has to be the whole marker: a reason
 * wrapped onto a second line leaves a plain comment directly above the
 * occurrence, and a plain comment there legalizes nothing. The marker covers a
 * minted stub only: the misread diff key has no legitimate case to name, since a
 * field a diff does clear arrives as a key holding `null` and is read by the
 * `patch*` twin.
 */
final class PayloadSentinelRule implements CodeStyleRule
{
    public const string ID = 'PAYLOAD-SENTINEL';

    private const string DOC = 'docs/agents/code-style/method-contracts.md';

    /** Readers handed a whole row or frame; a method name is matched case-insensitively, as PHP resolves it. */
    private const array FULL_ROW_READERS = ['fromarray', 'fromjson', 'fromrow', 'hydratebase', 'hydrateown'];

    /** Readers handed a partial update, matched the same way. */
    private const array DIFF_READERS = ['applydiff', 'applybasediff', 'applyowndiff'];

    /** Both spellings of the empty string; `token_get_all()` keeps the quotes. */
    private const array EMPTY_STRING_LITERALS = ["''", '""'];

    /** The marker that legalizes one occurrence; the reason after the colon is the point of it. */
    private const string MARKER_PATTERN = '~^//\s*external-boundary:(?<reason>.*)$~';

    /** What a report about a whole row or frame ends with, whichever spelling minted the stub. */
    private const string CURE = ' the payload field is missing; refuse the payload or let the field be null';

    /** What the same report ends with in a diff, where an absent key is not a missing field. */
    private const string DIFF_CURE = ' the diff does not carry the key; an absent key means the field did not '
        . 'change, so read it with patch*';

    /** One message per spelling that mints a stub; the literal as it is written, then the cure of the kind. */
    private const string COALESCE_MESSAGE = '?? %s mints a stub where%s';

    private const string TERNARY_MESSAGE = 'a ternary branch hands back %s where%s';

    private const string MATCH_MESSAGE = 'match falls back to %s where%s';

    /** The family that reads an absent key as null; matched by prefix, since it grows on demand. */
    private const string OPTIONAL_READER_PREFIX = 'optional';

    /** The second finding, reported in a diff body only; `%s` takes the called name as it is written. */
    private const string OPTIONAL_IN_DIFF_MESSAGE = '%s() answers null to a key the diff does not carry and '
        . 'clears a field it never touched; read it with its patch* twin';

    /** Tokens that open a bracket pair; the ternary bookkeeping is kept per depth. */
    private const array OPENING_TOKENS = ['(', '[', '{'];

    /** Their closing counterparts; `T_CURLY_OPEN` and friends close with `}` too. */
    private const array CLOSING_TOKENS = [')', ']', '}'];

    /** Tokens that open a curly pair without spelling `{`; a body ends when its own pair closes. */
    private const array CURLY_OPEN_TOKENS = [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES];

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
     * @return iterable<Violation> One entry per finding, left to right
     */
    public function check(string $relativePath, array $tokens): iterable
    {
        $bodies = $this->payloadReaderBodies($tokens);
        if ($bodies === []) {
            return;
        }

        $lines = $this->lineNumbers($tokens);

        foreach ($this->findings($tokens, $bodies) as $index => [$message, $markerApplies]) {
            if (!$markerApplies) {
                yield new Violation(self::ID, $relativePath, $lines[$index], $message);
                continue;
            }

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
     * Finds the token span of every payload reader's body. An abstract or
     * interface declaration ends at a semicolon and has no body to judge.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @return array<int, array{0: int, 1: int, 2: PayloadReaderKind}> Opening brace, closing brace and kind of each body
     */
    private function payloadReaderBodies(array $tokens): array
    {
        $bodies = [];

        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_FUNCTION) {
                continue;
            }

            $nameIndex = $this->significantIndex($tokens, $index);
            if ($nameIndex === null) {
                continue;
            }

            $kind = $this->readerKind($tokens[$nameIndex]);
            if ($kind === null) {
                continue;
            }

            $bodyStart = $this->bodyStart($tokens, $nameIndex);
            if ($bodyStart !== null) {
                $bodies[] = [$bodyStart, $this->bodyEnd($tokens, $bodyStart), $kind];
            }
        }

        return $bodies;
    }

    /**
     * @param string|array{0: int, 1: string, 2: int} $token Token following the `function` keyword
     * @return PayloadReaderKind|null What the reader is handed, or null when the name is not judged
     */
    private function readerKind(string|array $token): ?PayloadReaderKind
    {
        if (!is_array($token) || $token[0] !== T_STRING) {
            return null;
        }

        $name = strtolower($token[1]);
        if (in_array($name, self::FULL_ROW_READERS, true)) {
            return PayloadReaderKind::FullRow;
        }

        return in_array($name, self::DIFF_READERS, true) ? PayloadReaderKind::Diff : null;
    }

    /**
     * Walks past the parameter list and the return type to the brace that opens
     * the body. A parenthesis depth is kept because a default value or an
     * attribute may spell anything the walk is looking for.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $nameIndex Index of the method name
     * @return int|null Index of the opening brace, or null when the declaration carries no body
     */
    private function bodyStart(array $tokens, int $nameIndex): ?int
    {
        $depth = 0;

        for ($cursor = $nameIndex + 1; isset($tokens[$cursor]); $cursor++) {
            $token = $tokens[$cursor];
            if ($token === '(') {
                $depth++;
                continue;
            }

            if ($token === ')') {
                $depth--;
                continue;
            }

            if ($depth > 0) {
                continue;
            }

            if ($token === '{') {
                return $cursor;
            }

            if ($token === ';') {
                return null;
            }
        }

        return null;
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $bodyStart Index of the brace that opens the body
     * @return int Index of the brace that closes it, or the last index when the file ends first
     */
    private function bodyEnd(array $tokens, int $bodyStart): int
    {
        $depth = 0;

        for ($cursor = $bodyStart; isset($tokens[$cursor]); $cursor++) {
            $token = $tokens[$cursor];
            if ($token === '{' || (is_array($token) && in_array($token[0], self::CURLY_OPEN_TOKENS, true))) {
                $depth++;
                continue;
            }

            if ($token === '}') {
                $depth--;
                if ($depth === 0) {
                    return $cursor;
                }
            }
        }

        return array_key_last($tokens) ?? $bodyStart;
    }

    /**
     * Walks the file once and collects both findings, so the report stays
     * positional — left to right, the order the token index already gives. The
     * ternary branch is the reason for the walk: a `:` also separates a named
     * argument, closes a `case`, opens the alternative syntax and introduces a
     * return type, so it counts only while a `?` of the same bracket depth is
     * still open.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param array<int, array{0: int, 1: int, 2: PayloadReaderKind}> $bodies Spans of the judged reader bodies
     * @return array<int, array{0: string, 1: bool}> Message and whether a marker may legalize it, by token index
     */
    private function findings(array $tokens, array $bodies): array
    {
        $found = [];
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
                $this->recordStub($found, $tokens, $bodies, $this->stubIndex($tokens, $index), self::TERNARY_MESSAGE);
                continue;
            }

            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_COALESCE) {
                $this->recordStub($found, $tokens, $bodies, $this->stubIndex($tokens, $index), self::COALESCE_MESSAGE);
                continue;
            }

            if ($token[0] === T_DEFAULT) {
                $this->recordStub($found, $tokens, $bodies, $this->matchDefaultIndex($tokens, $index), self::MATCH_MESSAGE);
                continue;
            }

            if ($token[0] === T_STRING) {
                $this->recordMisreadDiffKey($found, $tokens, $bodies, $index);
            }
        }

        return $found;
    }

    /**
     * @param array<int, array{0: string, 1: bool}> $found Findings collected so far, keyed by token index
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param array<int, array{0: int, 1: int, 2: PayloadReaderKind}> $bodies Spans of the judged reader bodies
     * @param int|null $literalIndex Index of the stub literal, or null when the spelling hands back something else
     * @param string $message Message of the spelling that minted it, taking the literal and then the cure
     */
    private function recordStub(array &$found, array $tokens, array $bodies, ?int $literalIndex, string $message): void
    {
        if ($literalIndex === null) {
            return;
        }

        $kind = $this->bodyKind($bodies, $literalIndex);
        if ($kind === null) {
            return;
        }

        /** @var array{0: int, 1: string, 2: int} $literal */
        $literal = $tokens[$literalIndex];
        $cure = match ($kind) {
            PayloadReaderKind::FullRow => self::CURE,
            PayloadReaderKind::Diff => self::DIFF_CURE,
        };

        $found[$literalIndex] = [sprintf($message, $literal[1], $cure), true];
    }

    /**
     * Records a call of the `optional*` family made inside a diff body. What is
     * judged is the reading, so the name counts only where it is being called: a
     * bare mention reads nothing, and a `function` of that name declared inside
     * the body — which PHP allows — declares a reader rather than calling one.
     * The body a full row is read with needs no exclusion here; it is another
     * body, and a token of it is placed by its own kind.
     *
     * @param array<int, array{0: string, 1: bool}> $found Findings collected so far, keyed by token index
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param array<int, array{0: int, 1: int, 2: PayloadReaderKind}> $bodies Spans of the judged reader bodies
     * @param int $index Index of the name token
     */
    private function recordMisreadDiffKey(array &$found, array $tokens, array $bodies, int $index): void
    {
        /** @var array{0: int, 1: string, 2: int} $name */
        $name = $tokens[$index];
        if (!str_starts_with(strtolower($name[1]), self::OPTIONAL_READER_PREFIX)) {
            return;
        }

        if ($this->bodyKind($bodies, $index) !== PayloadReaderKind::Diff || !$this->isCall($tokens, $index)) {
            return;
        }

        $found[$index] = [sprintf(self::OPTIONAL_IN_DIFF_MESSAGE, $name[1]), false];
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $index Index of the name token
     * @return bool True when the name is being called rather than declared
     */
    private function isCall(array $tokens, int $index): bool
    {
        $openIndex = $this->significantIndex($tokens, $index);
        if ($openIndex === null || $tokens[$openIndex] !== '(') {
            return false;
        }

        $previousIndex = $this->previousSignificantIndex($tokens, $index);
        if ($previousIndex === null) {
            return true;
        }

        $previous = $tokens[$previousIndex];

        return !is_array($previous) || $previous[0] !== T_FUNCTION;
    }

    /**
     * @param array<int, array{0: int, 1: int, 2: PayloadReaderKind}> $bodies Spans of the judged reader bodies
     * @param int $index Token index to place
     * @return PayloadReaderKind|null Kind of the body holding the token, or null when it sits in none
     */
    private function bodyKind(array $bodies, int $index): ?PayloadReaderKind
    {
        foreach ($bodies as [$start, $end, $kind]) {
            if ($index > $start && $index < $end) {
                return $kind;
            }
        }

        return null;
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
     * arrow will not do: an ordinary array element spells `=> 0` and is no stub.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $index Index of the `T_DEFAULT` token
     * @return int|null Index of the stub literal the arm hands back, or null
     */
    private function matchDefaultIndex(array $tokens, int $index): ?int
    {
        $arrowIndex = $this->significantIndex($tokens, $index);
        if ($arrowIndex === null) {
            return null;
        }

        $arrow = $tokens[$arrowIndex];

        return is_array($arrow) && $arrow[0] === T_DOUBLE_ARROW ? $this->stubIndex($tokens, $arrowIndex) : null;
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $index Index to walk forward from
     * @return int|null Index of the next token when it is a stub literal, null otherwise
     */
    private function stubIndex(array $tokens, int $index): ?int
    {
        $literalIndex = $this->significantIndex($tokens, $index);
        if ($literalIndex === null) {
            return null;
        }

        $literal = $tokens[$literalIndex];
        if (!is_array($literal)) {
            return null;
        }

        return $this->isStubLiteral($literal) ? $literalIndex : null;
    }

    /**
     * The zero is read by value and not by spelling, so `0.00` is the same stub
     * `0.0` is. A non-zero number, `null` and `[]` are not stubs at all: the first
     * is a value the payload carries, and the other two are how absence is spelt.
     *
     * @param array{0: int, 1: string, 2: int} $literal Literal token to classify
     * @return bool True when the literal is one of the three stubs
     */
    private function isStubLiteral(array $literal): bool
    {
        return match ($literal[0]) {
            T_CONSTANT_ENCAPSED_STRING => in_array($literal[1], self::EMPTY_STRING_LITERALS, true),
            T_LNUMBER, T_DNUMBER => (float)$literal[1] === 0.0,
            default => false,
        };
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
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $index Index to walk back from
     * @return int|null Index of the nearest preceding token that is not whitespace or a comment
     */
    private function previousSignificantIndex(array $tokens, int $index): ?int
    {
        for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
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
     * @param int $index Index of the stub literal
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
