<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Exception\OrmForgetRefusedException;
use Hilos\Hilos;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Testing\BreaksOrmState;
use Hilos\Testing\IntegrationTest;
use Hilos\Testing\OrmResetTarget;
use PHPUnit\Event\Facade;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\ErrorHandler;
use PHPUnit\TestRunner\IssueFilter;
use PHPUnit\TestRunner\TestResult\Collector;
use PHPUnit\TestRunner\TestResult\TestResult;
use PHPUnit\TextUI\Configuration\Registry;
use ReflectionProperty;
use RuntimeException;

/** Runs fixture cases through the installed PHPUnit runner and collects their real result events separately. */
final class RollbackHarnessIntegrationTest extends TestCase
{
    /** @return iterable<string, array{string, int, int, int}> Outcome and expected failure/error/skip events */
    public static function outcomes(): iterable
    {
        yield 'success' => ['success', 0, 0, 0];
        yield 'assertion' => ['assertion', 1, 0, 0];
        yield 'exception' => ['exception', 0, 1, 0];
        yield 'skip' => ['skip', 0, 0, 1];
        yield 'setup' => ['setup', 0, 1, 0];
        yield 'reset' => ['reset', 1, 0, 0];
        yield 'assertion and reset' => ['assertion-reset', 2, 0, 0];
        yield 'exception and reset' => ['exception-reset', 1, 1, 0];
        yield 'skip and reset' => ['skip-reset', 1, 0, 1];
        yield 'setup and reset' => ['setup-reset', 1, 1, 0];
        yield 'leaked application level' => ['leak', 1, 0, 0];
        yield 'rollback failure' => ['rollback', 1, 0, 0];
        yield 'primary and rollback and reset' => ['exception-rollback-reset', 1, 1, 0];
        yield 'release failure' => ['release', 1, 0, 0];
        yield 'prepare failure' => ['prepare', 0, 1, 0];
        yield 'empty targets' => ['empty-targets', 1, 0, 0];
        yield 'invalid targets' => ['invalid-targets', 1, 0, 0];
    }

    /**
     * @param string $outcome Controlled point of failure
     * @param int $failed Expected failed events
     * @param int $errored Expected errored events
     * @param int $skipped Expected skipped events
     */
    #[DataProvider('outcomes')]
    public function testRunnerAlwaysRollsBackAndRestoresEnvironment(string $outcome, int $failed, int $errored, int $skipped): void
    {
        $case = new RollbackHarnessCase('exercise');
        $case->outcome = $outcome;
        $result = $this->runFixture($case);
        self::assertSame(1, $result->numberOfTestsRun());
        self::assertSame($failed, $result->numberOfTestFailedEvents(), $this->messages($result));
        self::assertSame($errored, $result->numberOfTestErroredEvents(), $this->messages($result));
        self::assertSame($skipped, $result->numberOfTestSkippedEvents());
        self::assertFalse($result->hasTestConsideredRiskyEvents());

        $expected = ['prepare'];
        if ($outcome !== 'prepare') {
            $expected[] = 'setup';
            if (!str_starts_with($outcome, 'setup')) {
                $expected[] = 'body';
            }
            array_push($expected, 'rollback:0', 'rollback:1', 'targets');
            if (!in_array($outcome, ['empty-targets', 'invalid-targets'], true)) {
                $expected[] = 'reset';
            }
        }
        $expected[] = 'release';
        self::assertSame($expected, $case->trace->events);
        self::assertSame([], $case->trace->usersAfterRollback);
        self::assertSame([], $case->trace->settingsAfterRollback);
        if (str_contains($outcome, 'reset')) {
            self::assertStringContainsString('reset refusal', $this->messages($result));
        }
        if (str_contains($outcome, 'rollback')) {
            self::assertStringContainsString('rollback refusal', $this->messages($result));
        }
        foreach (['assertion', 'exception', 'setup'] as $primary) {
            if (str_starts_with($outcome, $primary)) {
                self::assertStringContainsString('primary ' . $primary, $this->messages($result));
            }
        }

        $next = new RollbackHarnessCase('exercise');
        $nextResult = $this->runFixture($next);
        self::assertSame(0, $nextResult->numberOfTestFailedEvents(), $this->messages($nextResult));
        self::assertSame(0, $nextResult->numberOfTestErroredEvents(), $this->messages($nextResult));
        self::assertSame([], $next->trace->usersAfterRollback);
        self::assertSame([], $next->trace->settingsAfterRollback);
    }

    /** A method-only marker resets that method; its unmarked sibling never even asks for targets. */
    public function testMethodMarkerAndUnmarkedCase(): void
    {
        $plain = new RollbackHarnessMethodCase('plain');
        $plainResult = $this->runFixture($plain);
        self::assertSame(0, $plainResult->numberOfTestFailedEvents(), $this->messages($plainResult));
        self::assertSame(0, $plainResult->numberOfTestErroredEvents(), $this->messages($plainResult));
        self::assertSame(['prepare', 'setup', 'body', 'rollback:0', 'rollback:1', 'release'], $plain->trace->events);

        $marked = new RollbackHarnessMethodCase('marked');
        $markedResult = $this->runFixture($marked);
        self::assertSame(0, $markedResult->numberOfTestFailedEvents(), $this->messages($markedResult));
        self::assertSame(0, $markedResult->numberOfTestErroredEvents(), $this->messages($markedResult));
        self::assertSame(['prepare', 'setup', 'body', 'rollback:0', 'rollback:1', 'targets', 'reset', 'release'], $marked->trace->events);
    }

    /** Existing nonnull facades, another claim and an execution frame survive a failing case. */
    public function testPreviousFacadesAndOwnershipSurvive(): void
    {
        $previousDb = Hilos::$db;
        $previousRt = Hilos::$rt;
        $previousSr = Hilos::$sr;
        Hilos::$db = new FrameworkRollbackDbContext();
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = new class extends RtContext {
            /** Sentinel context has no collections. */
            public function configure(): void
            {
            }
        };
        $token = ExecutionContext::push(new ExecutionFrame('outer-rollback-harness', 'outer-connection', 'outer-request'));
        TruthSourceRegistry::register(HilosDbContext::users, TruthSourceKeys::listed('9'), 'outer-rollback-harness');
        try {
            $case = new RollbackHarnessCase('exercise');
            $case->outcome = 'exception-reset';
            $result = $this->runFixture($case);
            self::assertSame(1, $result->numberOfTestFailedEvents());
            self::assertSame(1, $result->numberOfTestErroredEvents());
            self::assertSame('outer-connection', ExecutionContext::currentAcceptKey());
            self::assertSame('outer-request', ExecutionContext::currentRequestId());
        } finally {
            TruthSourceRegistry::unregisterAgent('outer-rollback-harness');
            ExecutionContext::pop($token);
            Hilos::$db = $previousDb;
            Hilos::$rt = $previousRt;
            Hilos::$sr = $previousSr;
        }
    }

    /**
     * PHPUnit 11 replaced the old mutable TestResult argument with an event collector.
     * Isolate those events so deliberate red fixture cases cannot fail the enclosing suite.
     *
     * @param RollbackHarnessEnvironment $case Actual test case to run
     * @return TestResult Result collected from its ordinary run() lifecycle
     */
    private function runFixture(RollbackHarnessEnvironment $case): TestResult
    {
        $facadeProperty = new ReflectionProperty(Facade::class, 'instance');
        $previousFacade = $facadeProperty->getValue();
        $previousAssertions = Assert::getCount();
        $previousDb = Hilos::$db;
        $previousRt = Hilos::$rt;
        $previousSr = Hilos::$sr;
        $previousEnv = Hilos::$env;
        $previousAgent = ExecutionContext::currentAgentId();
        $sources = new ReflectionProperty(TruthSourceRegistry::class, 'sources');
        $previousSources = $sources->getValue();
        $handler = ErrorHandler::instance();
        $handlerWasEnabled = new ReflectionProperty($handler, 'enabled')->getValue($handler);
        $handler->disable();
        $facade = new Facade();
        $facadeProperty->setValue(null, $facade);
        $collector = new Collector($facade, new IssueFilter(Registry::get()->source()));
        $facade->seal();
        $case->trace = new RollbackHarnessTrace();
        try {
            $case->run();
        } finally {
            $facadeProperty->setValue(null, $previousFacade);
            new ReflectionProperty(Assert::class, 'count')->setValue(null, $previousAssertions);
            if ($handlerWasEnabled) {
                $handler->enable();
            }
        }
        self::assertSame($previousDb, Hilos::$db);
        self::assertSame($previousRt, Hilos::$rt);
        self::assertSame($previousSr, Hilos::$sr);
        self::assertSame($previousEnv, Hilos::$env);
        self::assertSame($previousAgent, ExecutionContext::currentAgentId());
        self::assertSame($previousSources, $sources->getValue());
        self::assertFalse(Database::hasOpenTransactions());
        self::assertFalse(new ReflectionProperty(Database::class, 'handlerOpen')->getValue());
        self::assertSame([], Database::getConfiguredIndices());

        return $collector->result();
    }

    /**
     * @param TestResult $result Collected fixture result
     * @return string Every error/failure message, including simultaneous primary and cleanup failures
     */
    private function messages(TestResult $result): string
    {
        $messages = [];
        foreach ([...$result->testFailedEvents(), ...$result->testErroredEvents()] as $event) {
            $messages[] = $event->throwable()->asString();
        }

        return implode("\n", $messages);
    }
}

/** Private to one fixture run; it observes cleanup before the schema can hide any leaked writes. */
final class RollbackHarnessTrace
{
    /** @var list<string> Lifecycle observations */
    public array $events = [];
    /** @var ?array<array<string, mixed>> SQL baseline before schema release */
    public ?array $usersAfterRollback = null;
    /** @var ?array<array<string, mixed>> Secondary connection's SQL baseline */
    public ?array $settingsAfterRollback = null;
}

/** Unmarked fixture environment; only this layer installs/relinquishes resources. */
abstract class RollbackHarnessEnvironment extends FrameworkRollbackIntegrationTestCase
{
    public RollbackHarnessTrace $trace;
    public string $outcome = 'success';

    /** Installs a real Hilos context whose reset also records the lifecycle boundary. */
    protected function prepareIntegrationEnvironment(): void
    {
        parent::prepareIntegrationEnvironment();
        $this->trace->events[] = 'prepare';
        Hilos::$db = new RollbackHarnessDbContext($this->trace, str_contains($this->outcome, 'reset'));
        Hilos::$db->configure();
        self::assertFalse(Database::hasOpenTransactions());
        self::assertSame([], Database::sql('SELECT id FROM hilos_user ORDER BY id')->rows());
        if ($this->outcome === 'prepare') {
            throw new RuntimeException('prepare refusal');
        }
    }

    /** Reads the persisted baseline after rollback/reset and before dropping any table. */
    protected function releaseIntegrationEnvironment(): void
    {
        try {
            $this->trace->events[] = 'release';
            self::assertFalse(Database::hasOpenTransactions());
            self::assertFalse(new ReflectionProperty(Database::class, 'handlerOpen')->getValue());
            $this->trace->usersAfterRollback = Database::sql('SELECT id FROM hilos_user ORDER BY id')->rows();
            $this->trace->settingsAfterRollback = Database::sql('SELECT id FROM hilos_setting ORDER BY id')->rows();
            self::assertSame([], $this->trace->usersAfterRollback);
            self::assertSame([], $this->trace->settingsAfterRollback);
            if ($this->outcome === 'release') {
                throw new RuntimeException('release refusal');
            }
        } finally {
            parent::releaseIntegrationEnvironment();
        }
    }

    /** Sets up two roots, loaded ORM state and an undo observer while the scope is active. */
    protected function setUpInTransaction(): void
    {
        $this->trace->events[] = 'setup';
        self::assertTrue(Database::hasOpenTransactions());
        Database::sqlRun("INSERT INTO hilos_user (id, name) VALUES (1562, 'before raw SQL')");
        self::assertSame('before raw SQL', Hilos::$db->users[1562]->name);
        if ($this->name() !== 'plain') {
            Database::sqlRun("UPDATE hilos_user SET name = 'after raw SQL' WHERE id = 1562");
        }
        Database::onRollback(function (): void {
            self::assertTrue(new ReflectionProperty(Database::class, 'handlerOpen')->getValue());
            $this->trace->events[] = 'rollback:0';
            if (str_contains($this->outcome, 'rollback')) {
                throw new RuntimeException('rollback refusal');
            }
        });

        $config = Database::getConnectionConfig(0);
        Database::configure(1, $config->host, $config->user, $config->password, $config->database, $config->port, $config->charset);
        Database::connect(1);
        Database::useConnection(1);
        Database::sqlRun("INSERT INTO hilos_setting (`key`, value) VALUES ('rollback-harness', 'case')");
        Database::onRollback(function (): void {
            self::assertTrue(new ReflectionProperty(Database::class, 'handlerOpen')->getValue());
            $this->trace->events[] = 'rollback:1';
        });
        Database::useConnection(0);
        if (str_starts_with($this->outcome, 'setup')) {
            throw new RuntimeException('primary setup');
        }
    }

    /** @return list<OrmResetTarget> The actual local target, or a deliberately malformed hook result */
    protected function ormResetTargets(): array
    {
        $this->trace->events[] = 'targets';
        self::assertFalse(Database::hasOpenTransactions());
        self::assertFalse(new ReflectionProperty(Database::class, 'handlerOpen')->getValue());
        if ($this->name() === 'plain') {
            self::fail('An unmarked case must not call ormResetTargets');
        }
        if ($this->outcome === 'empty-targets') {
            return [];
        }
        if ($this->outcome === 'invalid-targets') {
            return ['invalid'];
        }

        return parent::ormResetTargets();
    }
}

/** Calls the real cold forget door after observing closed roots/handler and the still-mounted context. */
final class RollbackHarnessDbContext extends HilosDbContext
{
    /**
     * @param RollbackHarnessTrace $trace Fixture observations
     * @param bool $refuse Whether to fail this reset after checking its position
     */
    public function __construct(private readonly RollbackHarnessTrace $trace, private readonly bool $refuse)
    {
        parent::__construct();
    }

    /** Records the reset on the affected context before environment restoration. */
    public function forgetLoadedDataForTest(): void
    {
        TestCase::assertSame($this, Hilos::$db);
        TestCase::assertFalse(Database::hasOpenTransactions());
        TestCase::assertFalse(new ReflectionProperty(Database::class, 'handlerOpen')->getValue());
        TestCase::assertSame([], Database::sql('SELECT id FROM hilos_user ORDER BY id')->rows());
        TestCase::assertSame([], Database::sql('SELECT id FROM hilos_setting ORDER BY id')->rows());
        $this->trace->events[] = 'reset';
        if ($this->refuse) {
            throw new OrmForgetRefusedException('reset refusal');
        }
        parent::forgetLoadedDataForTest();
    }
}

#[IntegrationTest]
#[BreaksOrmState]
final class RollbackHarnessCase extends RollbackHarnessEnvironment
{
    /** Runner-invoked body, deliberately named without the test prefix to avoid suite discovery. */
    public function exercise(): void
    {
        $this->trace->events[] = 'body';
        self::assertTrue(Database::hasOpenTransactions());
        if (str_starts_with($this->outcome, 'assertion')) {
            self::fail('primary assertion');
        }
        if (str_starts_with($this->outcome, 'exception')) {
            throw new RuntimeException('primary exception');
        }
        if (str_starts_with($this->outcome, 'skip')) {
            self::markTestSkipped('primary skip');
        }
        if ($this->outcome === 'leak') {
            Database::transactionStart();
        }
    }
}

#[IntegrationTest]
final class RollbackHarnessMethodCase extends RollbackHarnessEnvironment
{
    /** This body has no ORM-breaking marker and must never request reset targets. */
    public function plain(): void
    {
        $this->trace->events[] = 'body';
        self::assertTrue(Database::hasOpenTransactions());
    }

    /** The same concrete class may mark just one body. */
    #[BreaksOrmState]
    public function marked(): void
    {
        $this->trace->events[] = 'body';
        self::assertTrue(Database::hasOpenTransactions());
    }
}
