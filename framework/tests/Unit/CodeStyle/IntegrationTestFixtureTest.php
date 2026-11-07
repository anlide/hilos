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
    private const string BAD = 'Hilos\Tests\CodeStyle\Fixtures\Bad\\';
    private const string GOOD = 'Hilos\Tests\CodeStyle\Fixtures\Good\\';

    /** Pins every finding, including helper source locations and absence of unrelated legacy/product findings. */
    public function testRuleReportsExactlyTheSeededCases(): void
    {
        $rule = new IntegrationTestRule(['Bad', 'Good', self::TREE . '/tests']);
        $hits = iterator_to_array($rule->check($this->index()), false);
        self::assertSame('INTEGRATION-TEST', $rule->id());
        self::assertSame('docs/agents/testing/integration-tests.md', $rule->doc());
        self::assertSame(array_fill(0, count($hits), $rule->id()), array_column($hits, 'ruleId'));
        self::assertSame([
            ['Bad/IntegrationTestSamples.php', 17, 'WrongIntegrationBase must extend RollbackIntegrationTestCase'],
            ['Bad/IntegrationTestSamples.php', 21, 'BreaksOrmState is an interface; implement it on the integration class'],
            ['Bad/IntegrationTestSamples.php', 24, 'BreaksOrmState is an interface; implement it on the integration class'],
            ['Bad/IntegrationTestSamples.php', 29, 'IntegrationTest is a class attribute, not a method attribute'],
            ['Bad/IntegrationTestSamples.php', 36, 'IntegrationTest belongs on a concrete case, not an environment base'],
            ['Bad/IntegrationTestSamples.php', 41, 'IntegrationTest belongs on a concrete case, not an environment base'],
            ['Bad/IntegrationTestSamples.php', 49, 'Concrete rollback test class must declare #[IntegrationTest]'],
            ['Bad/IntegrationTestSamples.php', 53, 'Concrete rollback test class must declare #[IntegrationTest]'],
            ['Bad/IntegrationTestSamples.php', 57, 'BreaksOrmState requires explicit IntegrationTest on its class'],
            ['Bad/IntegrationTestSamples.php', 57, 'Concrete rollback test class must declare #[IntegrationTest]'],
            ['Bad/IntegrationTestSamples.php', 61, 'BreaksOrmState requires explicit IntegrationTest on its class'],
            ['Bad/IntegrationTestSamples.php', 61, 'Concrete rollback test class must declare #[IntegrationTest]'],
            ['Bad/IntegrationTestSamples.php', 65, 'BreaksOrmState requires explicit IntegrationTest on its class'],
            ['Bad/IntegrationTestSamples.php', 65, 'Concrete rollback test class must declare #[IntegrationTest]'],
            ['Bad/IntegrationTestSamples.php', 69, 'BreaksOrmState requires explicit IntegrationTest on its class'],
            ['Bad/IntegrationTestSamples.php', 73, 'BreaksOrmState requires explicit IntegrationTest on its class'],
            ['Bad/IntegrationTestSamples.php', 73, 'Concrete rollback test class must declare #[IntegrationTest]'],
            ['Bad/IntegrationTestSamples.php', 78, 'BreaksOrmState is an interface; implement it on the integration class'],
            ['Bad/IntegrationTestSamples.php', 81, 'BreaksOrmState is an interface; implement it on the integration class'],
            ['Bad/IntegrationTestSamples.php', 87, 'BreaksOrmState requires explicit IntegrationTest on its class'],
            ['Bad/IntegrationTestSamples.php', 87, 'Concrete rollback test class must declare #[IntegrationTest]'],
            ['Bad/IntegrationTestSamples.php', 91, 'Concrete rollback test class must declare #[IntegrationTest]'],
            [self::TREE . '/tests/Cases.php', 44, 'setUp() overrides the managed integration lifecycle'],
            [self::TREE . '/tests/Cases.php', 48, 'tearDown() overrides the managed integration lifecycle'],
            [self::TREE . '/tests/Cases.php', 52, 'assertPostConditions() overrides the managed integration lifecycle'],
            [self::TREE . '/tests/Cases.php', 56, 'setUpBeforeClass() overrides the managed integration lifecycle'],
            [self::TREE . '/tests/Cases.php', 60, 'tearDownAfterClass() overrides the managed integration lifecycle'],
            [self::TREE . '/tests/Cases.php', 64, 'prepareIntegrationEnvironment() overrides the managed integration lifecycle'],
            [self::TREE . '/tests/Cases.php', 68, 'releaseIntegrationEnvironment() overrides the managed integration lifecycle'],
            [self::TREE . '/tests/Cases.php', 74, 'beginRollbackScopeForTest() is owned by the integration lifecycle'],
            [self::TREE . '/tests/Cases.php', 83, 'Explicit transactionCommit() is forbidden in an integration case or helper'],
            [self::TREE . '/tests/Environment.php', 30, 'Executed SQL leaves the rollback-safe statement families'],
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

    /** Imports, grouped attributes, interface aliases and nested attribute arguments keep their source ownership. */
    public function testIndexPreservesQualifiedAttributesAndPositions(): void
    {
        $index = $this->index();
        $case = $index->find(self::NAMESPACE . 'HelperCase');
        self::assertNotNull($case);
        self::assertSame([
            ['name' => 'PHPUnit\Framework\Attributes\Group', 'line' => 15],
            ['name' => 'Hilos\Testing\IntegrationTest', 'line' => 15],
        ], $case->attributes);
        self::assertSame(['Hilos\Testing\BreaksOrmState'], $case->interfaces);
        self::assertSame(['observation' => 'stdClass'], $case->docPropertyTypes);
        self::assertSame([], $case->methods['exercise']->attributes);
        $qualified = $index->find(self::NAMESPACE . 'QualifiedCase');
        self::assertNotNull($qualified);
        self::assertSame([['name' => 'Hilos\Testing\IntegrationTest', 'line' => 78]], $qualified->attributes);
        self::assertSame(['Hilos\Testing\BreaksOrmState'], $qualified->interfaces);
        self::assertSame([], $qualified->methods['exercise']->attributes);
        $lookAlike = $index->find(self::NAMESPACE . 'ArgumentLookAlike');
        self::assertNotNull($lookAlike);
        self::assertSame([['name' => 'PHPUnit\Framework\Attributes\Group', 'line' => 87]], $lookAlike->attributes);
        self::assertSame([], $lookAlike->methods['legacy']->attributes);
    }

    /** The index keeps the declaration keyword and stores an interface alias fully qualified. */
    public function testIndexPreservesDeclarationKindsAndInterfaceAliases(): void
    {
        $index = $this->index();
        $case = $index->find(self::NAMESPACE . 'HelperCase');
        self::assertNotNull($case);
        self::assertSame(T_CLASS, $case->declarationKind);
        self::assertSame(['Hilos\Testing\BreaksOrmState'], $case->interfaces);
        $derived = $index->find(self::NAMESPACE . 'DerivedOrmReset');
        self::assertNotNull($derived);
        self::assertSame(T_INTERFACE, $derived->declarationKind);
        self::assertSame(['Hilos\Testing\BreaksOrmState'], $derived->interfaces);
        $trait = $index->find(self::NAMESPACE . 'OuterHelper');
        self::assertNotNull($trait);
        self::assertSame(T_TRAIT, $trait->declarationKind);
        $kind = $index->find(self::NAMESPACE . 'DeclarationKindSample');
        self::assertNotNull($kind);
        self::assertSame(T_ENUM, $kind->declarationKind);
        $aliased = $index->find(self::GOOD . 'AliasedOrmReset');
        self::assertNotNull($aliased);
        self::assertSame(T_CLASS, $aliased->declarationKind);
        self::assertSame(['Hilos\Testing\BreaksOrmState'], $aliased->interfaces);
    }

    /** Direct, aliased, inherited and cyclic BreaksOrmState are judged from the rule report. */
    public function testInterfaceInheritanceMatchesInstanceofAcrossParents(): void
    {
        $rule = new IntegrationTestRule(['Bad', 'Good', self::TREE . '/tests']);
        $index = $this->index();
        $hits = iterator_to_array($rule->check($index), false);
        $concrete = 'Concrete rollback test class must declare #[IntegrationTest]';
        $needsMark = 'BreaksOrmState requires explicit IntegrationTest on its class';
        $both = [$needsMark, $concrete];
        $obsolete = 'BreaksOrmState is an interface; implement it on the integration class';

        self::assertSame($both, $this->classMessages($index, $hits, self::BAD . 'UnmarkedDirectInterface'));
        self::assertSame($both, $this->classMessages($index, $hits, self::BAD . 'UnmarkedAliasedInterface'));
        self::assertSame($both, $this->classMessages($index, $hits, self::BAD . 'UnmarkedInheritedInterface'));
        self::assertSame($both, $this->classMessages($index, $hits, self::BAD . 'UnmarkedInheritedClass'));
        self::assertSame($both, $this->classMessages($index, $hits, self::BAD . 'UnmarkedCycle'));
        self::assertSame([$concrete], $this->classMessages($index, $hits, self::BAD . 'UnmarkedDirect'));
        self::assertSame([$concrete], $this->classMessages($index, $hits, self::BAD . 'UnmarkedIndirect'));
        self::assertSame([$concrete], $this->classMessages($index, $hits, self::BAD . 'UnmarkedMissingInterface'));
        self::assertSame([$needsMark], $this->classMessages($index, $hits, self::BAD . 'UnmarkedOrmEnvironment'));
        self::assertSame([], $this->classMessages($index, $hits, self::BAD . 'PlainEnvironment'));
        self::assertSame(
            ['IntegrationTest belongs on a concrete case, not an environment base'],
            $this->classMessages($index, $hits, self::BAD . 'MarkedOrmEnvironment'),
        );
        self::assertSame([], $this->classMessages($index, $hits, self::BAD . 'ObsoleteMarkedAttribute'));
        self::assertSame([$obsolete, $obsolete], $this->breaksAttributeMessages($index, $hits, self::BAD . 'OrphanOrmMarker'));
        self::assertSame(
            [$obsolete, $obsolete],
            $this->breaksAttributeMessages($index, $hits, self::BAD . 'ObsoleteMarkedAttribute'),
        );
        self::assertSame([], $this->classMessages($index, $hits, self::GOOD . 'DirectOrmReset'));
        self::assertSame([], $this->classMessages($index, $hits, self::GOOD . 'AliasedOrmReset'));
        self::assertSame([], $this->classMessages($index, $hits, self::GOOD . 'InheritedOrmReset'));
        self::assertSame([], $this->classMessages($index, $hits, self::GOOD . 'InheritedInterfaceReset'));
        self::assertSame([], $this->classMessages($index, $hits, self::GOOD . 'LegacyIntegration'));
        self::assertSame([], $this->classMessages($index, $hits, self::GOOD . 'ForeignMarker'));
        self::assertSame([], $this->classMessages($index, $hits, self::NAMESPACE . 'Environment'));
        self::assertSame([], $this->classMessages($index, $hits, self::NAMESPACE . 'MiddleEnvironment'));
        self::assertSame([], $this->classMessages($index, $hits, self::NAMESPACE . 'ArgumentLookAlike'));
        self::assertSame([], $this->classMessages($index, $hits, self::NAMESPACE . 'HelperCase'));
        self::assertSame([], $this->classMessages($index, $hits, self::NAMESPACE . 'QualifiedCase'));
        self::assertSame([], $this->classMessages($index, $hits, self::NAMESPACE . 'DerivedOrmReset'));
        self::assertSame([], $this->classMessages($index, $hits, self::NAMESPACE . 'CycleLeft'));
        self::assertSame([], $this->classMessages($index, $hits, self::NAMESPACE . 'CycleRight'));
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

    /**
     * @param list<Violation> $hits Findings of one rule run
     * @return list<string> Messages reported on the class declaration, alphabetically
     */
    private function classMessages(SourceIndex $index, array $hits, string $class): array
    {
        $record = $index->find($class);
        self::assertNotNull($record);

        return $this->messagesAt($hits, $record->path, $record->line);
    }

    /**
     * @param list<Violation> $hits Findings of one rule run
     * @return list<string> Messages reported on each BreaksOrmState attribute of the class and its methods
     */
    private function breaksAttributeMessages(SourceIndex $index, array $hits, string $class): array
    {
        $record = $index->find($class);
        self::assertNotNull($record);
        $lines = [];
        foreach ($record->attributes as $attribute) {
            if (strcasecmp($attribute['name'], 'Hilos\Testing\BreaksOrmState') === 0) {
                $lines[] = $attribute['line'];
            }
        }
        foreach ($record->methods as $method) {
            foreach ($method->attributes as $attribute) {
                if (strcasecmp($attribute['name'], 'Hilos\Testing\BreaksOrmState') === 0) {
                    $lines[] = $attribute['line'];
                }
            }
        }
        $found = [];
        foreach ($lines as $line) {
            array_push($found, ...$this->messagesAt($hits, $record->path, $line));
        }

        return $found;
    }

    /**
     * @param list<Violation> $hits Findings of one rule run
     * @param string $path Path addressed from the fixture root
     * @param int $line Source line a finding is reported on
     * @return list<string> Messages reported on that line, alphabetically
     */
    private function messagesAt(array $hits, string $path, int $line): array
    {
        $found = [];
        foreach ($hits as $hit) {
            if ($hit->relativePath === $path && $hit->line === $line) {
                $found[] = $hit->message;
            }
        }
        sort($found);

        return $found;
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
