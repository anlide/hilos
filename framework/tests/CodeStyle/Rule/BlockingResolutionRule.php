<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Rule;

use Hilos\Tests\CodeStyle\CodeStyleRule;
use Hilos\Tests\CodeStyle\RootKind;
use Hilos\Tests\CodeStyle\Violation;

/**
 * Enforces blocking-resolution.md: a name is never turned into an address by a call
 * that blocks the process while it waits for a DNS server to answer.
 *
 * Every Hilos process is a loop — the daemon accepting connections, a worker serving
 * its clients, an agent ticking — and each of the functions below stops that loop dead
 * for as long as the resolver takes, which on a lost or slow nameserver is seconds.
 * Nothing about the call site says so: `gethostbyname()` reads like a lookup in a
 * table. The rule is what says so.
 *
 * It judges every root rather than production alone. A test that resolves a name is
 * wrong in the same way and hangs the suite instead of the node, so this id is absent
 * from the production-only list in {@see RootKind} on purpose.
 *
 * `gethostname()` is deliberately not in the family: it is a `uname(2)` read of the
 * local host name, with no socket and no resolver behind it.
 *
 * Only tokens in call position are read, so one of these names quoted in a string,
 * written in a comment, or worn by a method of some object is not a hit. A name carrying
 * a namespace is not a hit either, and `T_NAME_QUALIFIED` is deliberately absent from the
 * tokens below: `App\gethostbyname()` names a function of that namespace, and PHP's fallback
 * to the global one is reserved for unqualified names.
 */
final class BlockingResolutionRule implements CodeStyleRule
{
    public const string ID = 'BLOCKING-RESOLUTION';

    private const string DOC = 'docs/agents/code-style/blocking-resolution.md';

    /**
     * The PHP builtins that go to a nameserver and wait for it.
     *
     * @var array<int, string>
     */
    private const array RESOLVING_FUNCTIONS = [
        'gethostbyname',
        'gethostbynamel',
        'gethostbyaddr',
        'dns_get_record',
        'dns_get_mx',
        'checkdnsrr',
    ];

    /**
     * Tokens that mean the name belongs to something else — a method, a class constant,
     * a declaration — rather than naming the global function being called.
     *
     * @var array<int, int>
     */
    private const array NOT_A_GLOBAL_CALL = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION];

    /**
     * Files allowed to resolve a name anyway. Empty, and that is the current truth of
     * the tree rather than an oversight: there is not one such call in Hilos today.
     *
     * A line added here is the rule working as intended, not a way around it — but it
     * owes a reason in the docblock of the file it names, and the reason has to say why
     * the loop this call sits in can afford to stop.
     *
     * @var array<int, string>
     */
    private const array ALLOWED_PATHS = [];

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
     * @return iterable<Violation> One entry per resolving call
     */
    public function check(string $relativePath, array $tokens): iterable
    {
        if (in_array($relativePath, self::ALLOWED_PATHS, true)) {
            return;
        }

        foreach ($tokens as $index => $token) {
            if (!is_array($token) || !in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }

            $function = $this->calledFunction($tokens, $index);
            if ($function === null) {
                continue;
            }

            yield new Violation(
                self::ID,
                $relativePath,
                $token[2],
                $function . '() blocks the process until a nameserver answers; resolve the name outside the '
                    . 'loop, or name this file in the rule\'s list with a reason',
            );
        }
    }

    /**
     * Reads the name at this index as a call to one of the resolving builtins, or as
     * nothing at all.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $index Index of the name token
     * @return ?string The resolving function called here, or null when this is not such a call
     */
    private function calledFunction(array $tokens, int $index): ?string
    {
        $name = $tokens[$index];
        if (!is_array($name)) {
            return null;
        }

        // Only a name with one segment can be the builtin: `gethostbyname()` and `\gethostbyname()`
        // are it, while `\App\Dns\gethostbyname()` is somebody's own function that merely wears the
        // name. That is why the family below is matched against the whole written name and not
        // against its last segment.
        $written = ltrim($name[1], '\\');
        if (str_contains($written, '\\')) {
            return null;
        }

        $shortName = strtolower($written);
        if (!in_array($shortName, self::RESOLVING_FUNCTIONS, true)) {
            return null;
        }

        if ($this->significantToken($tokens, $index, 1) !== '(') {
            return null;
        }

        $before = $this->significantToken($tokens, $index, -1);
        if (is_array($before) && in_array($before[0], self::NOT_A_GLOBAL_CALL, true)) {
            return null;
        }

        return $shortName;
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $index Index to walk away from
     * @param int $step Direction to walk in
     * @return string|array{0: int, 1: string, 2: int}|null Nearest token that is not whitespace or a comment
     */
    private function significantToken(array $tokens, int $index, int $step): string|array|null
    {
        for ($cursor = $index + $step; isset($tokens[$cursor]); $cursor += $step) {
            $token = $tokens[$cursor];
            if (!is_array($token)) {
                return $token;
            }

            if ($token[0] !== T_WHITESPACE && $token[0] !== T_COMMENT && $token[0] !== T_DOC_COMMENT) {
                return $token;
            }
        }

        return null;
    }
}
