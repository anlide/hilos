<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Rule;

use Hilos\Tests\CodeStyle\CodeStyleRule;
use Hilos\Tests\CodeStyle\Violation;

/**
 * Enforces object.md: which rows a DB object store holds changes only through the
 * ArrayAccess door of `Objects`, so that one road carries both the store write and the
 * announcement every dependent view listens to.
 *
 * A row dropped or put in by hand leaves the store correct and the view wrong, silently:
 * nothing is published on the change bus, so `ViewCacheSubscriber` never drops the
 * wrapper, and `DbCollection` goes on answering that key out of its cache without asking
 * the store at all. The door is the fix for a write that changes the table; a row read
 * BACK out of the table is not one, and goes through `hydrate()`, which is silent on
 * purpose.
 *
 * The store's own array is left to the class that declares it. A subclass reading it is
 * untouched — `isset()`, `??`, `array_filter($this->objects, ...)`, `return
 * $this->objects[$key]` — because a read desynchronizes nobody, and a narrowed lookup
 * inside a concrete collection is written exactly that way. Writing a field of a row
 * that is already there is not a membership change either, and is told from a key write
 * by what follows the closing bracket.
 *
 * The receiver is recognized lexically, as `$this->objects` and nothing else, so a class
 * that is no object store but keeps an `$objects` property of its own is reported with
 * the same sentence about `Objects`. The way out of such a hit is to rename that
 * property, which is what the store's own neighbours already do.
 *
 * Only real tokens are read, so the same names inside a comment or a string literal are
 * not hits.
 */
final class ObjectStoreMutationRule implements CodeStyleRule
{
    public const string ID = 'DB-OBJECT-MUTATE';

    private const string DOC = 'docs/agents/orm/object.md';

    /** The array an object store keeps its rows in, written only by the classes declaring it. */
    private const string STORE_PROPERTY = 'objects';

    /**
     * Files allowed to write the row array directly, each path relative to the backend
     * root it sits in - which is what the rule is handed. The first is the store itself,
     * where the door and the silent load seam live and where the writes have to happen.
     * The second is no subclass of it at all: it is the light object container, which
     * keeps a private array of its own under the same name and answers to nobody's view.
     *
     * Adding a line is the point of the rule, not a way around it: a third writer is a
     * decision about who may change membership without announcing it, and the reason
     * belongs in that file's own docblock.
     *
     * @var array<int, string>
     */
    private const array ALLOWED_PATHS = [
        'Database/Object/Objects.php',
        'Database/Object/Collection/ObjectCollection.php',
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
     * very same code as the real sources - a fixture standing on the path of a legal
     * writer earns that writer's silence.
     *
     * @param string $relativePath File path relative to the scanned root
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @return iterable<Violation> One entry per direct write or drop of the row array
     */
    public function check(string $relativePath, array $tokens): iterable
    {
        if (in_array($relativePath, self::ALLOWED_PATHS, true)) {
            return;
        }

        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_STRING || $token[1] !== self::STORE_PROPERTY) {
                continue;
            }

            if (!$this->isOwnProperty($tokens, $index)) {
                continue;
            }

            if ($this->isInsideUnsetCall($tokens, $index)) {
                yield new Violation(
                    self::ID,
                    $relativePath,
                    $token[2],
                    'unset() drops a row of the object store directly; use unset($this[$id]),'
                        . ' which announces the loss and lets the view drop its wrapper',
                );

                continue;
            }

            if ($this->isWritten($tokens, $index)) {
                yield new Violation(
                    self::ID,
                    $relativePath,
                    $token[2],
                    '$this->' . self::STORE_PROPERTY . ' is written directly outside Objects;'
                        . ' go through $this[$id] = $object for a new row,'
                        . ' or hydrate() for a row read out of storage',
                );
            }
        }
    }

    /**
     * True when the named token is assigned to, whole or by one key. A key read is told
     * from a key write by what follows the closing bracket, which is also what keeps a
     * write into a row's field - `$this->objects[$id]->userId = ...` - out of the rule:
     * the bracket is followed by an object operator there, not by an assignment.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $index Index of the token naming the store
     * @return bool True when the store, or one of its keys, is written
     */
    private function isWritten(array $tokens, int $index): bool
    {
        $nextIndex = $this->significantIndex($tokens, $index, 1);
        if ($nextIndex === null) {
            return false;
        }

        if ($tokens[$nextIndex] === '=') {
            return true;
        }

        if ($tokens[$nextIndex] !== '[') {
            return false;
        }

        $closing = $this->closingBracket($tokens, $nextIndex, '[', ']');

        return $closing !== null && $this->significantToken($tokens, $closing, 1) === '=';
    }

    /**
     * Walks out of the brackets the token stands in until an unclosed one is found, and
     * reads what opened it; the walk stops at a statement or block boundary so that a
     * store named outside any call is not matched against a distant `unset`.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $index Index of the token naming the store
     * @return bool True when the store stands inside the argument list of an unset
     */
    private function isInsideUnsetCall(array $tokens, int $index): bool
    {
        $depth = 0;
        for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
            $token = $tokens[$cursor];
            if ($token === ')' || $token === ']') {
                $depth++;
                continue;
            }

            if ($token === '(' || $token === '[') {
                if ($depth > 0) {
                    $depth--;
                    continue;
                }

                $opener = $this->significantToken($tokens, $cursor, -1);

                return $token === '(' && is_array($opener) && $opener[0] === T_UNSET;
            }

            if ($token === ';' || $token === '{' || $token === '}') {
                return false;
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
