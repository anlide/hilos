<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\CodeStyle;

use Hilos\Tests\CodeStyle\RootKind;
use Hilos\Tests\CodeStyle\Rule\CodeFqnRule;
use Hilos\Tests\CodeStyle\Rule\EmptyStringSentinelRule;
use Hilos\Tests\CodeStyle\Rule\ErrorSuppressionRule;
use Hilos\Tests\CodeStyle\Rule\LineLengthRule;
use Hilos\Tests\CodeStyle\Rule\MagicRepeatRule;
use Hilos\Tests\CodeStyle\Rule\PayloadSentinelRule;
use Hilos\Tests\CodeStyle\Rule\PhpDocFqnRule;
use Hilos\Tests\CodeStyle\Rule\RandomSourceRule;
use Hilos\Tests\CodeStyle\Rule\RtStateReachRule;
use Hilos\Tests\CodeStyle\Rule\WireKeyCaseRule;
use Hilos\Tests\CodeStyle\ScannedRoots;
use PHPUnit\Framework\TestCase;

/**
 * Pins how a root earns its rules: by the kind declared beside it, never by what its
 * directory is called. `scripts/` is the case that says so — it holds production code
 * under a name no suffix rule would have recognized, and until it was declared it was
 * scanned by nobody at all.
 *
 * The declaration is what this test pins; the behaviour behind it is pinned by the
 * baseline, where `scripts/` now owes MAGIC-REPEAT records that only a production
 * rule set can produce. Together they leave a return to naming no quiet exit: one
 * fails on the kind, the other on debt that has stopped being reported.
 */
final class ScannedRootsTest extends TestCase
{
    /**
     * Rules a suite is allowed to break, named here rather than read out of
     * {@see RootKind}, which keeps them private: a test that asked the subject what it
     * thinks would agree with any answer.
     *
     * @var array<int, string>
     */
    private const array PRODUCTION_ONLY_RULE_IDS = [
        ErrorSuppressionRule::ID,
        RandomSourceRule::ID,
        MagicRepeatRule::ID,
    ];

    /**
     * The rest of the rules the guard applies per root. The cross-file rule is absent
     * on purpose: it is handed the index rather than a root and never asks the kind.
     *
     * @var array<int, string>
     */
    private const array EVERY_ROOT_RULE_IDS = [
        CodeFqnRule::ID,
        PhpDocFqnRule::ID,
        RtStateReachRule::ID,
        EmptyStringSentinelRule::ID,
        PayloadSentinelRule::ID,
        WireKeyCaseRule::ID,
        LineLengthRule::ID,
    ];

    public function testCodeThatRunsIsDeclaredProductionWhateverItsDirectoryIsCalled(): void
    {
        $roots = ScannedRoots::all($this->repositoryRoot());

        $this->assertSame(RootKind::Production, $roots['framework/backend'] ?? null);
        $this->assertSame(RootKind::Production, $roots['scripts'] ?? null);
    }

    public function testTheFrameworkSuiteIsDeclaredSuite(): void
    {
        $roots = ScannedRoots::all($this->repositoryRoot());

        $this->assertSame(RootKind::Suite, $roots['framework/tests'] ?? null);
    }

    public function testEveryDemoContributesItsBackendAndItsSuite(): void
    {
        $roots = ScannedRoots::all($this->repositoryRoot());
        $demos = glob($this->repositoryRoot() . '/demo/*', GLOB_ONLYDIR) ?: [];

        $this->assertNotSame([], $demos, 'the repository ships demos, so their roots have to be covered');
        foreach ($demos as $demo) {
            $name = basename($demo);
            $this->assertSame(RootKind::Production, $roots['demo/' . $name . '/backend'] ?? null);
            $this->assertSame(RootKind::Suite, $roots['demo/' . $name . '/tests'] ?? null);
        }
    }

    public function testASuiteIsJudgedByEveryRuleButTheThreeItIsAllowed(): void
    {
        foreach (self::PRODUCTION_ONLY_RULE_IDS as $ruleId) {
            $this->assertFalse(RootKind::Suite->allows($ruleId), $ruleId . ' judges production code only');
        }
        foreach (self::EVERY_ROOT_RULE_IDS as $ruleId) {
            $this->assertTrue(RootKind::Suite->allows($ruleId), $ruleId . ' judges a suite as well');
        }
    }

    public function testProductionIsJudgedByEveryRule(): void
    {
        foreach ([...self::PRODUCTION_ONLY_RULE_IDS, ...self::EVERY_ROOT_RULE_IDS] as $ruleId) {
            $this->assertTrue(RootKind::Production->allows($ruleId), $ruleId . ' judges production code');
        }
    }

    /**
     * @return string Absolute path of the repository root
     */
    private function repositoryRoot(): string
    {
        return dirname(__DIR__, 4);
    }
}
