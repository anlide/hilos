<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\CodeStyle;

use Hilos\Tests\CodeStyle\Throws\SourceIndex;
use Hilos\Tests\CodeStyle\Throws\ThrowsOrphanRule;
use PHPUnit\Framework\TestCase;

/**
 * Runs the orphaned-tag rule over the same toy tree the propagation rule is pinned on
 * and pins the exact report — both the tags that must be caught and the look-alikes
 * that must stay silent.
 *
 * The silences are the half that matters more: a tag past an unresolved call, past
 * each entry the index sees and cannot follow, a magic read without a reader, a stub
 * with nothing in its body, a tag the implemented contract declares and a base tag
 * an override needs are promises not to speak. A reader's contract backs its live tag
 * directly, through a private helper and by inheritance; unrelated tags and a caught
 * reader's tag are reported. Two hits sit on fixtures seeded for the other directions,
 * and they are true: `narrowsTheBase()` and the widened constructor document a narrow
 * exception their bodies do not raise.
 */
final class ThrowsOrphanFixtureTest extends TestCase
{
    /** The toy tree, indexed and judged whole; its own exception hierarchy is inside it. */
    private const string FIXTURE_ROOT = 'ThrowsTree';

    public function testRuleReportsExactlyTheSeededCases(): void
    {
        $this->assertSame(
            [
                'THROWS-ORPHAN ThrowsTree/Caller.php:74 — Caller::narrowsTheBase() documents NarrowException its '
                    . 'body cannot throw (see docs/agents/code-style/phpdoc.md)',
                'THROWS-ORPHAN ThrowsTree/Support/MagicHolder.php:43 — MagicHolder::keepsADeadTagPastAMagicRead() '
                    . 'documents OtherException its body cannot throw (see docs/agents/code-style/phpdoc.md)',
                'THROWS-ORPHAN ThrowsTree/Support/MagicHolder.php:53 — '
                    . 'MagicHolder::keepsADeadTagPastAMagicReadInAHelper() documents OtherException its body cannot '
                    . 'throw (see docs/agents/code-style/phpdoc.md)',
                'THROWS-ORPHAN ThrowsTree/Support/MagicHolder.php:62 — MagicHolder::catchesTheMagicRead() documents '
                    . 'NarrowException its body cannot throw (see docs/agents/code-style/phpdoc.md)',
                'THROWS-ORPHAN ThrowsTree/Support/MagicInheritor.php:18 — '
                    . 'MagicInheritor::keepsADeadTagPastAnInheritedReader() documents OtherException its body cannot '
                    . 'throw (see docs/agents/code-style/phpdoc.md)',
                'THROWS-ORPHAN ThrowsTree/Support/OrphanHolder.php:27 — OrphanHolder::__construct() documents '
                    . 'OtherException its body cannot throw (see docs/agents/code-style/phpdoc.md)',
                'THROWS-ORPHAN ThrowsTree/Support/OrphanHolder.php:36 — OrphanHolder::keepsADeadTag() documents '
                    . 'NarrowException its body cannot throw (see docs/agents/code-style/phpdoc.md)',
                'THROWS-ORPHAN ThrowsTree/Support/OrphanHolder.php:63 — '
                    . 'OrphanHolder::constructsAClassWithoutAConstructor() documents NarrowException its body cannot '
                    . 'throw (see docs/agents/code-style/phpdoc.md)',
                'THROWS-ORPHAN ThrowsTree/Support/OrphanHolder.php:112 — '
                    . 'OrphanHolder::keepsADeadTagOnAPrivateHelper() documents OtherException its body cannot throw '
                    . '(see docs/agents/code-style/phpdoc.md)',
                'THROWS-ORPHAN ThrowsTree/Support/WidenedConstruct.php:25 — WidenedConstruct::__construct() '
                    . 'documents NarrowException its body cannot throw (see docs/agents/code-style/phpdoc.md)',
            ],
            $this->report(),
            'Fixture report drifted: THROWS-ORPHAN either stopped catching a seeded tag or started reporting one '
                . 'it must stay silent on.',
        );
    }

    /**
     * @return array<int, string> Reported lines, in the order the rule yields them
     */
    private function report(): array
    {
        $rule = ThrowsOrphanRule::forWholeIndex();
        $index = SourceIndex::forRoots(dirname(__DIR__, 2) . '/CodeStyle/Fixtures', [self::FIXTURE_ROOT]);

        $reported = [];
        foreach ($rule->check($index) as $violation) {
            $reported[] = $violation->describe($rule->doc());
        }

        return $reported;
    }
}
