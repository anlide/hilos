<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\CodeStyle;

use Hilos\Tests\CodeStyle\RootKind;
use Hilos\Tests\CodeStyle\Rule\IntegrationTestRule;
use Hilos\Tests\CodeStyle\ScannedRoots;
use Hilos\Tests\CodeStyle\Throws\SourceIndex;
use Hilos\Tests\CodeStyle\Violation;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/** The fixtures are source input only: several deliberately cannot be loaded as PHP classes. */
final class IntegrationTestFixtureTest extends TestCase
{
    private const string TREE = 'ThrowsTree/Integration';
    private const string NAMESPACE = 'Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Integration\Tests\\';

    /** Pins every finding, including helper source locations and absence of unrelated legacy/product findings. */
    public function testRuleReportsExactlyTheSeededCases(): void
    {
        $rule = new IntegrationTestRule(['Bad', 'Good', self::TREE . '/tests']);
        $hits = iterator_to_array($rule->check($this->index()), false);
        self::assertSame('INTEGRATION-TEST', $rule->id());
        self::assertSame('docs/agents/testing/integration-tests.md', $rule->doc());
        self::assertSame(array_fill(0, count($hits), $rule->id()), array_column($hits, 'ruleId'));
        self::assertSame([
            ['Bad/IntegrationTestSamples.php', 13, 'WrongIntegrationBase must extend RollbackIntegrationTestCase'],
            ['Bad/IntegrationTestSamples.php', 17, 'BreaksOrmState requires explicit IntegrationTest on its class'],
            ['Bad/IntegrationTestSamples.php', 20, 'BreaksOrmState requires explicit IntegrationTest on its class'],
            ['Bad/IntegrationTestSamples.php', 25, 'IntegrationTest is a class attribute, not a method attribute'],
            ['Bad/IntegrationTestSamples.php', 32, 'IntegrationTest belongs on a concrete case, not an environment base'],
            [self::TREE . '/tests/Cases.php', 45, 'setUp() overrides the managed integration lifecycle'],
            [self::TREE . '/tests/Cases.php', 49, 'tearDown() overrides the managed integration lifecycle'],
            [self::TREE . '/tests/Cases.php', 53, 'assertPostConditions() overrides the managed integration lifecycle'],
            [self::TREE . '/tests/Cases.php', 57, 'setUpBeforeClass() overrides the managed integration lifecycle'],
            [self::TREE . '/tests/Cases.php', 61, 'tearDownAfterClass() overrides the managed integration lifecycle'],
            [self::TREE . '/tests/Cases.php', 65, 'prepareIntegrationEnvironment() overrides the managed integration lifecycle'],
            [self::TREE . '/tests/Cases.php', 69, 'releaseIntegrationEnvironment() overrides the managed integration lifecycle'],
            [self::TREE . '/tests/Cases.php', 75, 'beginRollbackScopeForTest() is owned by the integration lifecycle'],
            [self::TREE . '/tests/Cases.php', 85, 'Explicit transactionCommit() is forbidden in an integration case or helper'],
            [self::TREE . '/tests/Environment.php', 29, 'Executed SQL leaves the rollback-safe statement families'],
            [self::TREE . '/tests/Fixtures/Visible.php', 11, 'Visible must extend RollbackIntegrationTestCase'],
            [self::TREE . '/tests/Helpers.php', 14, 'Explicit transactionCommit() is forbidden in an integration case or helper'],
            [self::TREE . '/tests/Helpers.php', 27, 'Executed SQL leaves the rollback-safe statement families'],
            [self::TREE . '/tests/Helpers.php', 33, 'Executed SQL leaves the rollback-safe statement families'],
            [self::TREE . '/tests/SqlCases.php', 19, 'Executed SQL leaves the rollback-safe statement families'],
            [self::TREE . '/tests/SqlCases.php', 20, 'Executed SQL leaves the rollback-safe statement families'],
            [self::TREE . '/tests/SqlCases.php', 21, 'Executed SQL leaves the rollback-safe statement families'],
            [self::TREE . '/tests/SqlCases.php', 23, 'Executed SQL leaves the rollback-safe statement families'],
            [self::TREE . '/tests/SqlCases.php', 24, 'Executed SQL leaves the rollback-safe statement families'],
            [self::TREE . '/tests/SqlCases.php', 25, 'Executed SQL leaves the rollback-safe statement families'],
            [self::TREE . '/tests/SqlCases.php', 26, 'Executed SQL leaves the rollback-safe statement families'],
            [self::TREE . '/tests/SqlCases.php', 29, 'Executed SQL leaves the rollback-safe statement families'],
            [self::TREE . '/tests/SqlCases.php', 30, 'Executed SQL leaves the rollback-safe statement families'],
            [self::TREE . '/tests/SqlCases.php', 31, 'Explicit transactionCommit() is forbidden in an integration case or helper'],
            [self::TREE . '/tests/SqlCases.php', 33, 'commit() is raw transaction control outside the integration lifecycle'],
            [self::TREE . '/tests/SqlCases.php', 34, 'beginTransaction() is raw transaction control outside the integration lifecycle'],
            [self::TREE . '/tests/SqlCases.php', 35, 'exec() on a native connection bypasses the rollback scope'],
            [self::TREE . '/tests/SqlCases.php', 36, 'autocommit() is raw transaction control outside the integration lifecycle'],
            [self::TREE . '/tests/SqlCases.php', 37, 'mysqli_query() bypasses the rollback scope'],
            [self::TREE . '/tests/SqlCases.php', 38, 'Native PDO bypasses the rollback scope'],
            [self::TREE . '/tests/SqlCases.php', 39, 'Native mysqli bypasses the rollback scope'],
        ], array_map(static fn(Violation $hit): array => [$hit->relativePath, $hit->line, $hit->message], $hits));
    }

    /** Imports, grouped attributes and nested attribute arguments retain their own source ownership. */
    public function testIndexPreservesQualifiedAttributesAndPositions(): void
    {
        $index = $this->index();
        $case = $index->find(self::NAMESPACE . 'HelperCase');
        self::assertNotNull($case);
        self::assertSame([
            ['name' => 'PHPUnit\Framework\Attributes\Group', 'line' => 14],
            ['name' => 'Hilos\Testing\IntegrationTest', 'line' => 14],
            ['name' => 'Hilos\Testing\BreaksOrmState', 'line' => 15],
        ], $case->attributes);
        self::assertSame(['observation' => 'stdClass'], $case->docPropertyTypes);
        self::assertSame([
            ['name' => 'Hilos\Testing\BreaksOrmState', 'line' => 20],
        ], $case->methods['exercise']->attributes);
        $qualified = $index->find(self::NAMESPACE . 'QualifiedCase');
        self::assertNotNull($qualified);
        self::assertSame([['name' => 'Hilos\Testing\IntegrationTest', 'line' => 79]], $qualified->attributes);
        self::assertSame([
            ['name' => 'Hilos\Testing\BreaksOrmState', 'line' => 82],
        ], $qualified->methods['exercise']->attributes);
        $lookAlike = $index->find(self::NAMESPACE . 'ArgumentLookAlike');
        self::assertNotNull($lookAlike);
        self::assertSame([['name' => 'PHPUnit\Framework\Attributes\Group', 'line' => 89]], $lookAlike->attributes);
        self::assertSame([], $lookAlike->methods['legacy']->attributes);
    }

    /** Exclusions name exact paths; another directory called Fixtures remains visible. */
    public function testIndexExcludesOnlyTheDeclaredFixturePath(): void
    {
        $index = $this->index();
        self::assertNull($index->find(self::NAMESPACE . 'CodeStyle\Fixtures\Excluded'));
        self::assertNotNull($index->find(self::NAMESPACE . 'Fixtures\Visible'));
    }

    /** The extra suite index must never make test roots part of any production/THROWS index. */
    public function testProductionIndexStillExcludesEverySuiteRoot(): void
    {
        $guard = new CodeStyleGuardTest('testSourcesCarryNoCodeStyleViolationsBeyondTheBaseline');
        $roots = new ReflectionMethod($guard, 'indexedRoots')->invoke($guard);
        $expected = array_keys(array_filter(
            ScannedRoots::all(dirname(__DIR__, 4)),
            static fn(RootKind $kind): bool => $kind !== RootKind::Suite,
        ));
        self::assertSame($expected, $roots);
        self::assertNotContains('framework/tests', $roots);
    }

    /** @return SourceIndex Toy declarations including both test helpers and a production callee */
    private function index(): SourceIndex
    {
        return SourceIndex::forRoots(
            dirname(__DIR__, 2) . '/CodeStyle/Fixtures',
            ['Bad', 'Good', self::TREE],
            [self::TREE => ['tests/CodeStyle/Fixtures']],
        );
    }
}
