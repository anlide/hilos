<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Rule;

use Hilos\Tests\CodeStyle\CodeStyleRule;
use Hilos\Tests\CodeStyle\RootKind;
use Hilos\Tests\CodeStyle\Violation;

/**
 * Enforces truth-source.md: ownership is declared on the class, never made in a call.
 *
 * What the rule judges is the direct road into the ownership registry -
 * `TruthSourceRegistry::register()` and its runtime twin - because that is the only way left
 * to claim a collection from inside a running hook. The pair of helpers that used to offer a
 * shorter road is gone (HIL-898); a call to one of them no longer compiles, so there is
 * nothing there for a rule to watch.
 *
 * Why a claim is a constant and not a call: the worker deciding whether to build the agent,
 * and the validator judging a topology with no process running at all, have only the class to
 * ask. A claim made inside `onStart()` is invisible to both, and a collection whose owner
 * cannot be named before the instance exists has no owner as far as either of them is
 * concerned.
 *
 * The list of allowed files lives here and not in `baseline.txt` for the reason
 * {@see RandomSourceRule} gives: a baseline record names the leaf that will pay it off and
 * may only shrink, while these four files are a standing answer nobody is going to pay off.
 *
 * What the rule does NOT catch, each for its own reason:
 *   - `registerCreate()` / `unregisterCreate()` - the right to bring a row into being, a
 *     neighbouring mechanism with a different meaning and no production caller today;
 *   - `registerDaemon()` / `unregisterDaemon()` - a daemon's claim rather than an agent's,
 *     laid over a different seam;
 *   - `unregister()` / `unregisterAgent()` - giving a claim back, which is not declaring one,
 *     and what a test's tearDown calls.
 * Widening the match to the `register` prefix would paint all three, and the production code
 * that legitimately calls them, so the exclusion is written out rather than left to be
 * rediscovered.
 *
 * Why a suite is not judged by this rule: an agent started outside `WorkerManager` gets no
 * claim from a declaration, because the resolver runs on the worker's start path. A test that
 * builds an agent itself and calls `onStart()` has the call as its only form, which is why the
 * rule stands among the production-only ones ({@see RootKind}).
 *
 * Only real tokens are read, so a registry name written inside a string literal or inside a
 * docblock is not a hit, and neither is the `public static function register(` that declares
 * the method: none of the three carries a `::`.
 */
final class TruthSourceClaimRule implements CodeStyleRule
{
    public const string ID = 'TRUTH-SOURCE-CLAIM';

    private const string DOC = 'docs/agents/architecture/truth-source.md';

    /**
     * How an ownership registry is written at a call site, by short name.
     *
     * `self` and `static` are in the list for the sake of a subclass of the registry: without
     * them a new class extending the base would declare ownership through `self::register()`
     * and walk past the rule. The registries themselves stay silent not because those two
     * spellings are unreadable but because their files are allowed outright.
     *
     * @var array<int, string>
     */
    private const array REGISTRY_NAMES = [
        'TruthSourceRegistry',
        'RtTruthSourceRegistry',
        'AbstractTruthSourceRegistry',
        'self',
        'static',
    ];

    /** The one method of those registries that lays a claim down. */
    private const string CLAIM_METHOD = 'register';

    /**
     * Files allowed to reach the registry directly, listed under the reason they are allowed
     * for, each path relative to the backend root it sits in.
     *
     * @var array<string, array<int, string>>
     */
    private const array ALLOWED_PATHS = [
        'the resolver is the one door in from outside: it lays the claim down having read the'
        . ' constant off the class' => [
            'Core/TruthSource/OwnershipDeclaration.php',
        ],
        'the registry itself: register() is its own method, and an inside road to it is a claim'
        . ' being carried out rather than declared' => [
            'Core/TruthSource/AbstractTruthSourceRegistry.php',
            'Core/TruthSource/TruthSourceRegistry.php',
            'TruthSource/RtTruthSourceRegistry.php',
        ],
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
     * @return iterable<Violation> One entry per claim made in a call from a file not allowed one
     */
    public function check(string $relativePath, array $tokens): iterable
    {
        if ($this->isAllowed($relativePath)) {
            return;
        }

        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_DOUBLE_COLON) {
                continue;
            }

            $registry = $this->significantToken($tokens, $index, -1);
            $method = $this->significantToken($tokens, $index, 1);
            if ($registry === null || $method === null || $method[0] !== T_STRING) {
                continue;
            }

            $name = $this->shortName($registry[1]);
            if (!in_array($name, self::REGISTRY_NAMES, true) || $method[1] !== self::CLAIM_METHOD) {
                continue;
            }

            yield new Violation(
                self::ID,
                $relativePath,
                $registry[2],
                $name . '::' . self::CLAIM_METHOD . '() declares ownership in a call; name the collection in'
                    . ' OWNS_DB or OWNS_RT on the class, which the resolver reads before the instance exists',
            );
        }
    }

    /**
     * Matches by the tail of the path rather than by the whole of it, so that the fixture
     * proving the resolver stays silent - which lives under a `Good/` prefix - is judged the
     * same way the resolver itself is. The price is named: a file of a demo carrying the same
     * tail would be allowed too, but such a file would be a second resolver, and that is a
     * conversation rather than a way round the rule.
     *
     * @param string $relativePath File path relative to the scanned root
     * @return bool True when this file may reach the registry directly
     */
    private function isAllowed(string $relativePath): bool
    {
        foreach (self::ALLOWED_PATHS as $paths) {
            foreach ($paths as $path) {
                if (str_ends_with($relativePath, $path)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Walks away from `::` in one direction to the token that carries meaning, so a call
     * broken across lines or written with a comment inside it reads the same.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $index Index of the `::` token
     * @param int $step Direction to walk in: -1 for the class side, 1 for the method side
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
     * A class is written either by its imported short name or fully qualified, and PHP hands
     * back the whole name as one token; the tail is what names the class.
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
