<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Rule;

use Hilos\Tests\CodeStyle\CodeStyleRule;
use Hilos\Tests\CodeStyle\Violation;

/**
 * Enforces wiring-refusals.md: a broad catch around a read of `Hilos::$db` or `Hilos::$rt`
 * turns a wiring defect into an ordinary answer.
 *
 * The read guard refuses a collection nothing in this process declared it reads, and the
 * refusal is not something the caller can retry or work around — it says the wiring is wrong.
 * A `catch (Throwable)` over it hands the caller whatever the fallback was written to mean:
 * `false` from a permission check, an empty list from a lookup, null from a field. The incident
 * that produced this rule was exactly that — an admin with rights answered 403, and nothing in
 * the journal.
 *
 * PHP cannot forbid it: `catch (Throwable)` catches a marker-carrying exception like any other,
 * and a project may write whatever catch it likes over its own read. So the guard is what stands
 * in the way, and it names three ways out, all of them cheap:
 *
 * - narrow the catch to the species actually expected there;
 * - put `catch (WiringRefusal) { throw $e; }` above the broad one, keeping its behavior;
 * - write `// read-refusal-swallowed: <reason>` on the line directly above the catch.
 *
 * Broad means `Throwable`, `Exception` and `HilosException` — the three that stand above both
 * refusal species. A narrower family that happens to contain one of them, such as
 * `RtBaseException`, is not judged here: the rule pins the reflex of catching everything, not
 * every possible route a refusal could take.
 */
final class WiringRefusalSwallowedRule implements CodeStyleRule
{
    public const string ID = 'WIRING-REFUSAL-SWALLOWED';

    private const string DOC = 'docs/agents/code-style/wiring-refusals.md';

    /** The marker that legalizes one broad catch; the reason after the colon is the point of it. */
    private const string MARKER_PATTERN = '~^//\s*read-refusal-swallowed:(?<reason>.*)$~';

    /** Facade properties whose `->` read reaches the read guard. */
    private const array GUARDED_PROPERTIES = ['$db', '$rt'];

    /** The one method of a context that is a read: the application's way into the object layer. */
    private const string GUARDED_METHOD = 'getObjectCollection';

    /** Catch types that stand above both refusal species and therefore swallow them. */
    private const array BROAD_TYPES = ['Throwable', 'Exception', 'HilosException'];

    /** The marker interface both refusal species carry. */
    private const string REFUSAL_TYPE = 'WiringRefusal';

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
     * @return iterable<Violation> One entry per broad catch that swallows a guarded read
     */
    public function check(string $relativePath, array $tokens): iterable
    {
        $lines = $this->lineNumbers($tokens);

        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_TRY) {
                continue;
            }

            $body = $this->blockAfter($tokens, $index);
            if ($body === null || !$this->readsGuardedProperty($tokens, $body[0], $body[1])) {
                continue;
            }

            yield from $this->judgeCatches($relativePath, $tokens, $lines, $body[1]);
        }
    }

    /**
     * Reports the broad catches of one `try`, unless an earlier one already rethrows the refusal.
     *
     * The order matters and is the whole reason a rethrow counts as a way out: a
     * `catch (WiringRefusal)` written after the broad clause never runs, so a rule that only
     * looked for the clause anywhere would approve code that still swallows.
     *
     * @param string $relativePath File path relative to the scanned root
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param array<int, int> $lines Start line of every token
     * @param int $blockEnd Index of the `}` closing the try body
     * @return iterable<Violation> Hits found among this try's catches
     */
    private function judgeCatches(string $relativePath, array $tokens, array $lines, int $blockEnd): iterable
    {
        $cursor = $blockEnd;

        while (true) {
            $catch = $this->nextMeaningful($tokens, $cursor + 1);
            if ($catch === null || !is_array($tokens[$catch]) || $tokens[$catch][0] !== T_CATCH) {
                return;
            }

            $types = $this->caughtTypes($tokens, $catch);
            $body = $this->blockAfter($tokens, $catch);
            if ($body === null) {
                return;
            }

            $cursor = $body[1];

            if (in_array(self::REFUSAL_TYPE, $types, true)) {
                return;
            }

            if (array_intersect($types, self::BROAD_TYPES) === []) {
                continue;
            }

            if ($this->rethrows($tokens, $body[0], $body[1])) {
                return;
            }

            $reason = $this->markerReason($tokens, $lines, $catch);
            if ($reason === null) {
                yield new Violation(
                    self::ID,
                    $relativePath,
                    $lines[$catch],
                    sprintf(
                        'catch (%s) swallows a read of Hilos::$db / Hilos::$rt; narrow it, rethrow %s above '
                            . 'it, or mark it with a reason',
                        implode('|', $types),
                        self::REFUSAL_TYPE,
                    ),
                );

                return;
            }

            if (trim($reason) === '') {
                yield new Violation(
                    self::ID,
                    $relativePath,
                    $lines[$catch],
                    'the `// read-refusal-swallowed:` marker above the catch names no reason',
                );
            }

            return;
        }
    }

    /**
     * Whether a catch body always leaves by a throw, which is what "swallows" means here.
     *
     * A broad catch is not automatically a swallow: the commonest honest one wraps a
     * transaction, rolls it back and raises the same failure onward. It catches everything and
     * keeps nothing, so the refusal reaches the caller exactly as the rethrow exit arranges —
     * more of it, in fact, since it lets every other species past too.
     *
     * Only a throw at the body's own brace depth counts. One inside an `if`, a loop or a nested
     * try is conditional, and the clause it sits in may still fall through with the failure
     * absorbed.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $from Index just after the opening brace
     * @param int $to Index of the closing brace
     * @return bool True when the clause cannot fall through
     */
    private function rethrows(array $tokens, int $from, int $to): bool
    {
        $depth = 0;

        for ($index = $from; $index < $to; $index++) {
            $token = $tokens[$index];
            if ($this->opensBrace($token)) {
                $depth++;
                continue;
            }
            if ($token === '}') {
                $depth--;
                continue;
            }
            if ($depth === 0 && is_array($token) && $token[0] === T_THROW) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the token range reads a guarded facade property through `->` or `?->`.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $from Index just after the opening brace
     * @param int $to Index of the closing brace
     * @return bool True when the range holds at least one guarded read
     */
    private function readsGuardedProperty(array $tokens, int $from, int $to): bool
    {
        for ($index = $from; $index < $to; $index++) {
            $token = $tokens[$index];
            if (!is_array($token) || $token[0] !== T_VARIABLE) {
                continue;
            }
            if (!in_array($token[1], self::GUARDED_PROPERTIES, true)) {
                continue;
            }

            $before = $this->previousMeaningful($tokens, $index - 1);
            $after = $this->nextMeaningful($tokens, $index + 1);
            if ($before === null || $after === null) {
                continue;
            }
            if (!is_array($tokens[$before]) || $tokens[$before][0] !== T_DOUBLE_COLON) {
                continue;
            }

            $reader = $tokens[$after];
            if (!is_array($reader) || ($reader[0] !== T_OBJECT_OPERATOR && $reader[0] !== T_NULLSAFE_OBJECT_OPERATOR)) {
                continue;
            }

            if ($this->readsCollection($tokens, $after)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether what follows the arrow is a collection read rather than a call on the context.
     *
     * A named collection goes through the context's `__get()`, which is where the read guard
     * stands, and so does one method of the context itself: `getObjectCollection()` is the
     * application's entrance to the object layer and is judged by the same guard (HIL-900).
     * Every other method is an ordinary one — `Hilos::$db->reHydrateDbBackedCollections()` can
     * raise anything, none of it a refusal, and `mountedObjectCollection()` is the layer's own
     * unjudged entrance — so judging those here would put records in the baseline that no
     * narrowing could ever remove.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $arrowIndex Index of the `->` or `?->` after the facade property
     * @return bool True when the arrow names a collection rather than a method
     */
    private function readsCollection(array $tokens, int $arrowIndex): bool
    {
        $name = $this->nextMeaningful($tokens, $arrowIndex + 1);
        if ($name === null) {
            return false;
        }

        $named = $tokens[$name];
        if (!is_array($named) || $named[0] !== T_STRING) {
            // `->{$key}` and `->$key`: a name computed at runtime is always a collection here.
            return true;
        }

        $after = $this->nextMeaningful($tokens, $name + 1);
        if ($after === null || $tokens[$after] !== '(') {
            return true;
        }

        return $named[1] === self::GUARDED_METHOD;
    }

    /**
     * Short names of the types one `catch` clause lists.
     *
     * Short, because that is how the code style writes them and how the rule's own vocabulary
     * reads; a fully qualified clause is judged by its last segment.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $catchIndex Index of the `catch` keyword
     * @return array<int, string> Type names the clause lists, in written order
     */
    private function caughtTypes(array $tokens, int $catchIndex): array
    {
        $types = [];
        $depth = 0;

        for ($index = $catchIndex + 1, $count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];

            if ($token === '(') {
                $depth++;
                continue;
            }
            if ($token === ')') {
                $depth--;
                if ($depth === 0) {
                    return $types;
                }
                continue;
            }
            if ($depth === 0 || !is_array($token)) {
                continue;
            }
            if ($token[0] === T_STRING || $token[0] === T_NAME_QUALIFIED || $token[0] === T_NAME_FULLY_QUALIFIED) {
                $segments = explode('\\', $token[1]);
                $types[] = (string) end($segments);
            }
        }

        return $types;
    }

    /**
     * Finds the braced block that follows a keyword, as a pair of brace indexes.
     *
     * String interpolation opens a brace the tokenizer does not spell `{` — `"{$e->message}"`
     * arrives as T_CURLY_OPEN and closes with a plain `}` — so those two count as openings too.
     * Left out, the closing brace of the first interpolated string inside a `try` would end the
     * block early and the catches after it would never be read.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $keywordIndex Index of `try` or `catch`
     * @return ?array{0: int, 1: int} Index just inside the block and index of its closing brace
     */
    private function blockAfter(array $tokens, int $keywordIndex): ?array
    {
        $depth = 0;
        $opened = null;

        for ($index = $keywordIndex + 1, $count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];
            if ($this->opensBrace($token)) {
                $depth++;
                $opened ??= $index;
                continue;
            }
            if ($token !== '}') {
                continue;
            }

            $depth--;
            if ($depth === 0) {
                return [(int) $opened + 1, $index];
            }
        }

        return null;
    }

    /**
     * @param string|array{0: int, 1: string, 2: int} $token One raw token
     * @return bool True for every form that the tokenizer closes with a plain `}`
     */
    private function opensBrace(string|array $token): bool
    {
        if (!is_array($token)) {
            return $token === '{';
        }

        return $token[0] === T_CURLY_OPEN || $token[0] === T_DOLLAR_OPEN_CURLY_BRACES;
    }

    /**
     * Reads the marker that legalizes one broad catch. The walk gives up as soon as it passes
     * above the previous line: "directly above" is the whole point of the marker, and a comment
     * further up belongs to something else.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param array<int, int> $lines Start line of every token
     * @param int $index Index of the `catch` keyword
     * @return ?string Text after the colon, or null when no marker covers the clause
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

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $from Index to start looking forward from
     * @return ?int Index of the next token that is neither whitespace nor a comment
     */
    private function nextMeaningful(array $tokens, int $from): ?int
    {
        for ($index = $from, $count = count($tokens); $index < $count; $index++) {
            if (!$this->isSkippable($tokens[$index])) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $from Index to start looking backward from
     * @return ?int Index of the previous token that is neither whitespace nor a comment
     */
    private function previousMeaningful(array $tokens, int $from): ?int
    {
        for ($index = $from; $index >= 0; $index--) {
            if (!$this->isSkippable($tokens[$index])) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param string|array{0: int, 1: string, 2: int} $token One raw token
     * @return bool True for whitespace and comments, which carry no syntax here
     */
    private function isSkippable(string|array $token): bool
    {
        return is_array($token)
            && ($token[0] === T_WHITESPACE || $token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT);
    }

    /**
     * Single-character tokens carry no line of their own, so the walk keeps the line the last
     * multi-character token ended on.
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
}
