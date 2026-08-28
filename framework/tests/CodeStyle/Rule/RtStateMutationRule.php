<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Rule;

use Hilos\Tests\CodeStyle\CodeStyleRule;
use Hilos\Tests\CodeStyle\Violation;

/**
 * Enforces rt-state.md: which rows a backing RT state collection holds changes only
 * through the base actions, so that one road carries both the store write and the
 * announcement every dependent view listens to.
 *
 * The rule inventories the legal writers rather than watching a zone: the collection
 * announces its own membership now, and the four files below are the ones that either
 * own that road or apply a change that has already been announced elsewhere. Anything
 * else calls the base method, which is what remembers. A caller that hand-rolls the
 * mutation loses the cache drop and the outgoing sync, and it does so silently - the
 * row is in the store and the views go on showing the old membership.
 *
 * The store's own array is the second road to the same place, so writing
 * `$this->states` is left to the class that declares it; a subclass reading it is
 * untouched, since a read cannot desynchronize anybody.
 *
 * Only real tokens are read, so the same names inside a comment or a string literal
 * are not hits, and a declaration of a same-named method is not a call.
 */
final class RtStateMutationRule implements CodeStyleRule
{
    public const string ID = 'RT-STATE-MUTATE';

    private const string DOC = 'docs/agents/runtime/rt-state.md';

    /**
     * Methods that change which rows the collection holds. A write into a row that is
     * already there is not among them: it travels the item's own sync.
     *
     * @var array<int, string>
     */
    private const array MUTATING_METHODS = ['add', 'remove', 'clear'];

    /** Accessor that hands the backing collection out, on whatever receiver it is called. */
    private const string STATE_ACCESSOR = 'getStateCollection';

    /**
     * Magic properties that hand the backing collection out inside actions.
     *
     * @var array<int, string>
     */
    private const array STATE_PROPERTIES = ['stateCollection', '_stateCollection'];

    /** The array a state collection keeps its rows in, written only by the class declaring it. */
    private const string STORE_PROPERTY = 'states';

    /**
     * Files allowed to mutate the membership directly, each path relative to the
     * backend root it sits in - which is what the rule is handed. The first two are
     * the base actions themselves, the road every other caller is told to take. The
     * other two apply a membership change this process did not decide: a sync that
     * arrived from another worker and a snapshot handed over at startup, both of which
     * announce nothing on purpose, because rebroadcasting a change would send it back
     * where it came from.
     *
     * Adding a line is the point of the rule, not a way around it: a fifth writer is a
     * decision about who may change membership without announcing it, and the reason
     * belongs in that file's own docblock.
     *
     * @var array<int, string>
     */
    private const array ALLOWED_PATHS = [
        'Runtime/View/Actions/Collection/RtActions.php',
        'Runtime/View/Actions/Item/RtActions.php',
        'Runtime/RtSyncApplicator.php',
        'Runtime/RtSnapshot.php',
    ];

    /** The store's own file, the only one allowed to write {@see self::STORE_PROPERTY}. */
    private const string STORE_OWNER_PATH = 'Runtime/State/Collection/RtStates.php';

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
     * @return iterable<Violation> One entry per mutation, membership road first
     */
    public function check(string $relativePath, array $tokens): iterable
    {
        if (!in_array($relativePath, self::ALLOWED_PATHS, true)) {
            yield from $this->checkMembership($relativePath, $tokens);
        }

        if ($relativePath !== self::STORE_OWNER_PATH) {
            yield from $this->checkStore($relativePath, $tokens);
        }
    }

    /**
     * @param string $relativePath File path relative to the scanned root
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @return iterable<Violation> One entry per mutating call, key write or unset
     */
    private function checkMembership(string $relativePath, array $tokens): iterable
    {
        $aliases = $this->aliases($tokens);

        foreach ($tokens as $index => $token) {
            if (!is_array($token)) {
                continue;
            }

            $end = $this->receiverEnd($tokens, $index);
            if ($end === null && $token[0] === T_VARIABLE && in_array($token[1], $aliases, true)) {
                $end = $index;
            }

            if ($end !== null) {
                yield from $this->mutationAt($relativePath, $tokens, $index, $end, $token[2]);
            }
        }
    }

    /**
     * The second road: the store's own array, whose writer is the class that declares
     * it. A read is not judged - `array_filter($this->states, ...)` inside a subclass
     * is how a narrowed lookup is written.
     *
     * @param string $relativePath File path relative to the scanned root
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @return iterable<Violation> One entry per direct write of the store array
     */
    private function checkStore(string $relativePath, array $tokens): iterable
    {
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_STRING || $token[1] !== self::STORE_PROPERTY) {
                continue;
            }

            if (!$this->isOwnProperty($tokens, $index)) {
                continue;
            }

            if (!$this->isWritten($tokens, $index) && !$this->isInsideUnsetCall($tokens, $index)) {
                continue;
            }

            yield new Violation(
                self::ID,
                $relativePath,
                $token[2],
                '$this->' . self::STORE_PROPERTY . ' is written directly outside RtStates;'
                    . ' go through add(), remove() or clear()',
            );
        }
    }

    /**
     * Reports whatever stands on the receiver that ends at the given token.
     *
     * @param string $relativePath File path relative to the scanned root
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $start Index of the token the receiver is recognized by
     * @param int $end Index of the token the receiver expression ends on
     * @param int $line Line the receiver stands on
     * @return iterable<Violation> The one mutation standing on this receiver, if any
     */
    private function mutationAt(string $relativePath, array $tokens, int $start, int $end, int $line): iterable
    {
        $nextIndex = $this->significantIndex($tokens, $end, 1);
        if ($nextIndex === null) {
            return;
        }

        $next = $tokens[$nextIndex];
        if (is_array($next) && in_array($next[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
            $method = $this->significantIndex($tokens, $nextIndex, 1);
            if ($method === null) {
                return;
            }

            $name = $tokens[$method];
            if (
                is_array($name)
                && $name[0] === T_STRING
                && in_array($name[1], self::MUTATING_METHODS, true)
                && $this->isCall($tokens, $method)
            ) {
                yield new Violation(
                    self::ID,
                    $relativePath,
                    $name[2],
                    $name[1] . '() mutates the backing RT state collection outside the base actions;'
                        . ' call the base RtActions method instead',
                );
            }

            return;
        }

        if ($next === '[') {
            yield from $this->keyMutationAt($relativePath, $tokens, $start, $line);
        }
    }

    /**
     * A key of the receiver is either written or dropped; reading one is legal, and so
     * is writing a field of the row the key holds.
     *
     * @param string $relativePath File path relative to the scanned root
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $start Index of the token the receiver is recognized by
     * @param int $line Line the receiver stands on
     * @return iterable<Violation> The one key mutation, if this is one
     */
    private function keyMutationAt(string $relativePath, array $tokens, int $start, int $line): iterable
    {
        if ($this->isWritten($tokens, $start)) {
            yield new Violation(
                self::ID,
                $relativePath,
                $line,
                'a key of the backing RT state collection is written outside the base actions;'
                    . ' call the base RtActions method instead',
            );

            return;
        }

        if ($this->isInsideUnsetCall($tokens, $start)) {
            yield new Violation(
                self::ID,
                $relativePath,
                $line,
                'unset() drops a key of the backing RT state collection outside the base actions;'
                    . ' call the base RtActions method instead',
            );
        }
    }

    /**
     * Variables holding the backing collection itself, collected over the whole file
     * rather than per scope: `RtSnapshot` mutates its alias from inside a closure
     * declared below the assignment, and a token walk has no scopes to hand out.
     *
     * Only a direct assignment counts, and a coalescing one is direct: a variable that
     * took the collection on the run it was empty holds it just as much. Nothing derived
     * from an alias is one - a detached copy built by `$alias::init()` is a read surface
     * with rows of its own, and banning writes into it would ban the very construction
     * of a filtered view.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @return array<int, string> Variable names, spelled with their dollar sign
     */
    private function aliases(array $tokens): array
    {
        $aliases = [];
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_VARIABLE) {
                continue;
            }

            $assignment = $this->significantIndex($tokens, $index, 1);
            if (
                $assignment !== null
                && $this->isAssignment($tokens[$assignment])
                && $this->assignsReceiver($tokens, $assignment)
            ) {
                $aliases[] = $token[1];
            }
        }

        return $aliases;
    }

    /**
     * True when the assigned expression is the backing collection and nothing further:
     * a receiver whose statement ends right after it. `$state = $this->getStateCollection()
     * ->get($id)` therefore holds a row rather than the collection.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $assignment Index of the assignment operator
     * @return bool True when the right-hand side is the backing collection itself
     */
    private function assignsReceiver(array $tokens, int $assignment): bool
    {
        for ($cursor = $assignment + 1; isset($tokens[$cursor]); $cursor++) {
            if ($tokens[$cursor] === ';') {
                return false;
            }

            $end = $this->receiverEnd($tokens, $cursor);
            if ($end !== null) {
                return $this->significantToken($tokens, $end, 1) === ';';
            }
        }

        return false;
    }

    /**
     * Recognizes the two spellings that name the backing collection outright, and
     * answers where the naming expression ends: after the accessor's argument list, or
     * on the property name itself.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $index Index of the token to read
     * @return int|null Index the receiver expression ends on, or null when this is not one
     */
    private function receiverEnd(array $tokens, int $index): ?int
    {
        $token = $tokens[$index];
        if (!is_array($token) || $token[0] !== T_STRING) {
            return null;
        }

        if ($token[1] === self::STATE_ACCESSOR && $this->isCall($tokens, $index)) {
            $parenthesis = $this->significantIndex($tokens, $index, 1);

            return $parenthesis === null ? null : $this->closingBracket($tokens, $parenthesis, '(', ')');
        }

        return in_array($token[1], self::STATE_PROPERTIES, true) && $this->isOwnProperty($tokens, $index)
            ? $index
            : null;
    }

    /**
     * True when the named token is assigned to, whole or by one key. A key read is told
     * from a key write by what follows the closing bracket, which is also what keeps a
     * write into a row's field - `$collection[$id]->field = ...` - out of the rule: the
     * bracket is followed by an object operator there, not by an assignment. A
     * coalescing assignment is one of those assignments; `??` is not, and stays a read.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $index Index of the token naming the receiver
     * @return bool True when the receiver, or one of its keys, is written
     */
    private function isWritten(array $tokens, int $index): bool
    {
        $nextIndex = $this->significantIndex($tokens, $index, 1);
        if ($nextIndex === null) {
            return false;
        }

        if ($this->isAssignment($tokens[$nextIndex])) {
            return true;
        }

        if ($tokens[$nextIndex] !== '[') {
            return false;
        }

        $closing = $this->closingBracket($tokens, $nextIndex, '[', ']');

        return $closing !== null && $this->isAssignment($this->significantToken($tokens, $closing, 1));
    }

    /**
     * The assignment operators that put a row into the collection, or hand the
     * collection itself to a variable: the plain one and the coalescing one, which
     * writes on the run the left side is missing. `??` alone carries a different token
     * and is therefore not one of them.
     *
     * @param string|array{0: int, 1: string, 2: int}|null $token Token standing where an assignment would
     * @return bool True when the token assigns
     */
    private function isAssignment(string|array|null $token): bool
    {
        return $token === '=' || (is_array($token) && $token[0] === T_COALESCE_EQUAL);
    }

    /**
     * Walks out of the brackets the token stands in until an unclosed one is found, and
     * reads what opened it; the walk stops at a statement or block boundary so that a
     * receiver outside any call is not matched against a distant `unset`.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $index Index of the token naming the receiver
     * @return bool True when the receiver stands inside the argument list of an unset
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
     * A declaration of a same-named method is not a call, so `function` in front of the
     * name disqualifies it.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $index Index of the name token
     * @return bool True when the name is being called, not declared
     */
    private function isCall(array $tokens, int $index): bool
    {
        $previous = $this->significantToken($tokens, $index, -1);

        return $this->significantToken($tokens, $index, 1) === '('
            && !(is_array($previous) && $previous[0] === T_FUNCTION);
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
