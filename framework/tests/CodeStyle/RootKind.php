<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle;

use Hilos\Tests\CodeStyle\Rule\ErrorSuppressionRule;
use Hilos\Tests\CodeStyle\Rule\MagicRepeatRule;
use Hilos\Tests\CodeStyle\Rule\RandomSourceRule;

/**
 * What the code under a scanned root is, and therefore which rules judge it.
 *
 * The kind states a property of the code, never the name of the directory holding
 * it: the rules withheld below judge code that runs for real, and a suite is
 * allowed what production is not — suppressing a warning while it arranges the
 * failure it is about to assert, repeating one number across a dozen assertions
 * where a constant would hide from the reader the very value under test, and
 * drawing throwaway tokens and names from the tolerant axis of the random helper,
 * which it also has to call in order to check it.
 *
 * A rule cannot tell the two apart on its own: it is handed the path relative to
 * the scanned root, so `framework/tests/Unit/X.php` reaches it as `Unit/X.php` and
 * reads exactly like a backend file. The kind is declared next to the root, in
 * {@see ScannedRoots}, and is the whole answer to how a root earns its rule set.
 */
enum RootKind
{
    /** Code that runs in production, or that decides a run of it, judged by every rule. */
    case Production;

    /** A test suite, judged by every rule but the three a suite is allowed to break. */
    case Suite;

    /**
     * Rules that judge production code only. They are named here, beside the kinds,
     * rather than in the guard test: the kind is the property they are withheld by,
     * and a list kept anywhere else would be a second place to remember.
     *
     * @var array<int, string>
     */
    private const array PRODUCTION_ONLY_RULES = [ErrorSuppressionRule::ID, RandomSourceRule::ID, MagicRepeatRule::ID];

    /**
     * @param string $ruleId Id of the rule about to be applied
     * @return bool True when a root of this kind is judged by that rule
     */
    public function allows(string $ruleId): bool
    {
        return match ($this) {
            self::Production => true,
            self::Suite => !in_array($ruleId, self::PRODUCTION_ONLY_RULES, true),
        };
    }
}
