<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Rule;

use Hilos\Tests\CodeStyle\CodeStyleRule;
use Hilos\Tests\CodeStyle\Violation;

/**
 * Enforces secret-in-query.md: a secret does not travel in a url, so a query parameter
 * is read only under a name this rule lists.
 *
 * The rule lists allowed names rather than guessing which look secret. A "looks like a
 * secret" pattern would have to stay silent on the protected-mode pass - putting the
 * hole exactly where the secret actually is - and would wave through the next parameter
 * called `code`, `sig` or `invite`. Two names are allowed today, and each is a decision
 * somebody wrote down.
 *
 * The list holds the TEXT of the argument as it stands at the call site, not the value
 * that reaches the wire: a token walk cannot resolve another class's constant. Renaming
 * that constant reopens the question, which is the right outcome - the call is being
 * edited anyway.
 *
 * Two narrownesses, named here rather than left to be rediscovered. The rule reads a
 * file only if it names the query accessor at all, because the receiver of the
 * call is a variable and a token walk cannot type it: `has()` and `getString()` are also
 * spelled on page route params and on runtime collections, which have no say in this
 * rule. And it reads the four by-key readers only - `toArray()` hands back the whole map
 * and would carry a secret past it. No such call exists in the scanned roots today.
 */
final class SecretInQueryRule implements CodeStyleRule
{
    public const string ID = 'SECRET-IN-QUERY';

    private const string DOC = 'docs/agents/antipatterns/secret-in-query.md';

    /** The accessor the url is reached through, named the way a caller writes it. */
    private const string ACCESSOR = 'RequestQueryParams';

    /**
     * The by-key readers. `toArray()` is deliberately absent - see the class docblock.
     *
     * @var array<int, string>
     */
    private const array READER_METHODS = ['getString', 'requireString', 'requireStringMatching', 'has'];

    /**
     * Query parameter names any file may read, written as the argument stands in code.
     *
     * `hilosPass` is a secret and is the exception the rule is built around: while a node
     * is frozen the frontend is forbidden outgoing frames, so a verifier can present the
     * key only on the upgrade request itself, where a browser cannot set a header. It is
     * single-use, lives minutes, and is stored as a hash (HIL-481). The attachment id is
     * not a secret at all - it names a row, and the session cookie on the same request
     * says whether the caller may have it.
     *
     * Adding a line is the point of the rule, not a way around it: a value whose
     * possession is what grants access belongs in a cookie or a header instead.
     *
     * @var array<int, string>
     */
    private const array ALLOWED_PARAMS = [
        'ProtectedModeAdmissionConstants::HILOS_PASS_QUERY_PARAM',
        'ObjectEventAttachment::id',
    ];

    /**
     * Files that exercise the accessor itself and therefore read keys of their own naming,
     * each path relative to the root it sits in. They put no secret anywhere: the names
     * are test data for a parser and a DTO round-trip.
     *
     * This list answers "which file may read any name"; ALLOWED_PARAMS answers "which name
     * any file may read". They are separate because the questions are.
     *
     * @var array<int, string>
     */
    private const array ACCESSOR_EXERCISE_PATHS = [
        'Unit/RequestQueryParamsTest.php',
        'Unit/WebSocketHandshakeSignalDTOTest.php',
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
     * @return iterable<Violation> One entry per query read under a name the list does not hold
     */
    public function check(string $relativePath, array $tokens): iterable
    {
        if (in_array($relativePath, self::ACCESSOR_EXERCISE_PATHS, true) || !$this->namesAccessor($tokens)) {
            return;
        }

        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_OBJECT_OPERATOR) {
                continue;
            }

            $method = $this->significantToken($tokens, $index, 1);
            if ($method === null || $method[0] !== T_STRING || !in_array($method[1], self::READER_METHODS, true)) {
                continue;
            }

            $receiver = $this->significantToken($tokens, $index, -1);
            if ($receiver !== null && $receiver[0] === T_VARIABLE && $receiver[1] === '$this') {
                continue;
            }

            $argument = $this->firstArgument($tokens, $index);
            if ($argument === null || in_array($argument, self::ALLOWED_PARAMS, true)) {
                continue;
            }

            yield new Violation(
                self::ID,
                $relativePath,
                $method[2],
                'query param ' . $argument . ' is read from the url; a session token or any other secret'
                    . ' arrives in a cookie or a header',
            );
        }
    }

    /**
     * A file that never writes the accessor's name cannot be reading query params by key,
     * and judging it would report the same method names spelled on other objects.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @return bool True when the file names the accessor somewhere - an import, a type, a call
     */
    private function namesAccessor(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if (!is_array($token)) {
                continue;
            }

            if (($token[0] === T_STRING || $token[0] === T_NAME_QUALIFIED || $token[0] === T_NAME_FULLY_QUALIFIED)
                && $this->shortName($token[1]) === self::ACCESSOR
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reads the argument a reader is called with, as text.
     *
     * Only the first one is read: every reader names its key there, and the pattern of the
     * matching reader is not the rule's business. The text is joined from the significant
     * tokens, so a call broken across lines reads the same as one written inline.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $index Index of the `->` token
     * @return ?string The argument as written, or null when the call takes none
     */
    private function firstArgument(array $tokens, int $index): ?string
    {
        $cursor = $index;
        while (isset($tokens[$cursor]) && $tokens[$cursor] !== '(') {
            $cursor++;
        }

        $depth = 0;
        $argument = '';
        for (; isset($tokens[$cursor]); $cursor++) {
            $token = $tokens[$cursor];
            if ($token === '(' || $token === '[') {
                $depth++;
                if ($depth === 1) {
                    continue;
                }
            }

            if ($token === ')' || $token === ']') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }

            if ($depth === 1 && $token === ',') {
                break;
            }

            if (is_array($token) && ($token[0] === T_WHITESPACE || $token[0] === T_COMMENT)) {
                continue;
            }

            $argument .= is_array($token) ? $token[1] : $token;
        }

        return $argument === '' ? null : $argument;
    }

    /**
     * Walks away from `->` in one direction to the token that carries meaning, so a call
     * broken across lines or written with a comment inside it reads the same.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $index Index of the `->` token
     * @param int $step Direction to walk in: -1 for the receiver, 1 for the method name
     * @return ?array{0: int, 1: string, 2: int} The token found, or null when it is a single-character one
     */
    private function significantToken(array $tokens, int $index, int $step): ?array
    {
        for ($cursor = $index + $step; isset($tokens[$cursor]); $cursor += $step) {
            $token = $tokens[$cursor];
            if (!is_array($token)) {
                return null;
            }

            if ($token[0] !== T_WHITESPACE && $token[0] !== T_COMMENT && $token[0] !== T_DOC_COMMENT) {
                return $token;
            }
        }

        return null;
    }

    /**
     * A class is written either by its imported short name or fully qualified, and PHP
     * hands back the whole name as one token; the tail is what names the class.
     *
     * @param string $name Class name as written at the call site
     * @return string The name without its namespace
     */
    private function shortName(string $name): string
    {
        $separator = strrpos($name, '\\');

        return $separator === false ? $name : substr($name, $separator + 1);
    }
}
