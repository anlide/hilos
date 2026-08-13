<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\CodeStyle;

use Hilos\Tests\CodeStyle\Throws\SourceIndex;
use Hilos\Tests\CodeStyle\Throws\ThrowsPropagationRule;
use PHPUnit\Framework\TestCase;

/**
 * Runs the throws rule over a toy tree with every judged form seeded on purpose and
 * pins the exact report — both what must be caught and what must stay silent.
 *
 * The guard test is green by design, so it proves nothing about whether the rule
 * still fires. This test is the only thing that does, and it fails on drift in
 * either direction: a seeded case gone unreported, or a look-alike reported.
 *
 * The fixtures need no entry in the guard's excluded paths: the cross-file index is
 * built over the backend roots alone, and `framework/tests` is not one of them. The
 * toy tree is a root of its own here, and the only one — an index that reached the
 * production classes would answer the fixture's questions with production contracts.
 */
final class ThrowsPropagationFixtureTest extends TestCase
{
    /** The toy tree, indexed and judged whole; its own exception hierarchy is inside it. */
    private const string FIXTURE_ROOT = 'ThrowsTree';

    /** A zone entry naming one file rather than a directory, which the sibling zone also has. */
    private const string SINGLE_FILE_ZONE = 'ThrowsTree/Contract/AbstractSource.php';

    public function testRuleReportsExactlyTheSeededCases(): void
    {
        $this->assertSame(
            [
                'THROWS-PROPAGATION ThrowsTree/Caller.php:57 — Caller::missesTheTag() does not propagate '
                    . 'NarrowException documented on SourceInterface::read() (see docs/agents/code-style/phpdoc.md)',
                'THROWS-PROPAGATION ThrowsTree/Caller.php:75 — Caller::narrowsTheBase() does not propagate '
                    . 'OtherException documented on AbstractSource::start() (see docs/agents/code-style/phpdoc.md)',
                'THROWS-PROPAGATION ThrowsTree/Caller.php:110 — Caller::convertsInTheCatch() does not document '
                    . 'OtherException it throws (see docs/agents/code-style/phpdoc.md)',
                'THROWS-PROPAGATION ThrowsTree/Caller.php:119 — Caller::goesThroughAPrivateLink() does not '
                    . 'propagate NarrowException: Caller::goesThroughAPrivateLink() -> private '
                    . 'Caller::readThroughHelper() -> NarrowException (see docs/agents/code-style/phpdoc.md)',
                'THROWS-PROPAGATION ThrowsTree/Caller.php:149 — Caller::callsByClassName() does not propagate '
                    . 'NarrowException documented on Registry::lookup() (see docs/agents/code-style/phpdoc.md)',
                'THROWS-PROPAGATION ThrowsTree/Caller.php:157 — Caller::callsThroughAStaticProperty() does not '
                    . 'propagate OtherException documented on Registry::name() (see docs/agents/code-style/phpdoc.md)',
                'THROWS-PROPAGATION ThrowsTree/Caller.php:165 — Caller::callsATraitMethod() does not propagate '
                    . 'OtherException documented on HelperTrait::helpFromTrait() (see docs/agents/code-style/phpdoc.md)',
                'THROWS-PROPAGATION ThrowsTree/Caller.php:175 — Caller::iteratesADeclaredArray() does not '
                    . 'propagate NarrowException documented on SourceInterface::read() (see '
                    . 'docs/agents/code-style/phpdoc.md)',
                'THROWS-PROPAGATION ThrowsTree/Caller.php:187 — Caller::constructsAThrowingClass() does not '
                    . 'propagate OtherException documented on Constructed::__construct() (see '
                    . 'docs/agents/code-style/phpdoc.md)',
                'THROWS-PROPAGATION ThrowsTree/Caller.php:195 — Caller::throwsWithoutSayingSo() does not document '
                    . 'NarrowException it throws (see docs/agents/code-style/phpdoc.md)',
                'THROWS-PROPAGATION ThrowsTree/Caller.php:216 — Caller::iteratesASpacedGenericArray() does not '
                    . 'propagate NarrowException documented on SourceInterface::read() (see '
                    . 'docs/agents/code-style/phpdoc.md)',
                'THROWS-PROPAGATION ThrowsTree/Caller.php:231 — Caller::iteratesAParameterArray() does not '
                    . 'propagate NarrowException documented on SourceInterface::read() (see '
                    . 'docs/agents/code-style/phpdoc.md)',
                'THROWS-PROPAGATION ThrowsTree/Caller.php:283 — Caller::callsAKeywordNamedMethod() does not '
                    . 'propagate NarrowException documented on Registry::match() (see docs/agents/code-style/phpdoc.md)',
                'THROWS-PROPAGATION ThrowsTree/Caller.php:291 — Caller::callsAByReferenceMethod() does not '
                    . 'propagate OtherException documented on Registry::entries() (see '
                    . 'docs/agents/code-style/phpdoc.md)',
                'THROWS-PROPAGATION ThrowsTree/Caller.php:300 — Caller::readsPastAParameterAttribute() does not '
                    . 'propagate NarrowException documented on SourceInterface::read() (see '
                    . 'docs/agents/code-style/phpdoc.md)',
                'THROWS-PROPAGATION ThrowsTree/Contract/AbstractSource.php:20 — AbstractSource::start() documents '
                    . 'OtherException that SourceInterface::start() does not declare (see '
                    . 'docs/agents/code-style/phpdoc.md)',
                'THROWS-PROPAGATION ThrowsTree/Support/Hooked.php:43 — Hooked::readsPastAnAttributeArray() does '
                    . 'not propagate OtherException documented on Registry::name() (see '
                    . 'docs/agents/code-style/phpdoc.md)',
                'THROWS-PROPAGATION ThrowsTree/Support/Hooked.php:52 — Hooked::readsTheMemberAfterTheHook() does '
                    . 'not propagate OtherException documented on Registry::name() (see '
                    . 'docs/agents/code-style/phpdoc.md)',
                'THROWS-PROPAGATION ThrowsTree/Support/MidChain.php:59 — MidChain::missesAGroupImportedClass() '
                    . 'does not propagate NarrowException documented on Registry::match() (see '
                    . 'docs/agents/code-style/phpdoc.md)',
                'THROWS-PROPAGATION ThrowsTree/Support/WideningTrait.php:29 — WideningTrait::start() documents '
                    . 'NarrowException that SourceInterface::start() does not declare (see '
                    . 'docs/agents/code-style/phpdoc.md)',
            ],
            $this->report(ThrowsPropagationRule::forWholeIndex()),
            'Fixture report drifted: THROWS-PROPAGATION either stopped catching a seeded case or started '
                . 'reporting a legitimate one.',
        );
    }

    /**
     * A zone entry may name one file, because the sibling empty-string zone has such
     * an entry and the shape is one copied line away. Judged by prefix alone it would
     * match nothing and read as coverage.
     */
    public function testZoneEntryNamingOneFileJudgesThatFileAlone(): void
    {
        $this->assertSame(
            [
                'THROWS-PROPAGATION ThrowsTree/Contract/AbstractSource.php:20 — AbstractSource::start() '
                    . 'documents OtherException that SourceInterface::start() does not declare '
                    . '(see docs/agents/code-style/phpdoc.md)',
            ],
            $this->report(ThrowsPropagationRule::forZone([self::SINGLE_FILE_ZONE])),
            'The zone either stopped matching a file-shaped entry or stopped narrowing to it.',
        );
    }

    /**
     * @param ThrowsPropagationRule $rule Rule under test, judging the whole toy tree or a zone of it
     * @return array<int, string> Reported lines, in the order the rule yields them
     */
    private function report(ThrowsPropagationRule $rule): array
    {
        $index = SourceIndex::forRoots(dirname(__DIR__, 2) . '/CodeStyle/Fixtures', [self::FIXTURE_ROOT]);

        $reported = [];
        foreach ($rule->check($index) as $violation) {
            $reported[] = $violation->describe($rule->doc());
        }

        return $reported;
    }
}
