<?php

declare(strict_types=1);

namespace Hilos\Testing;

use Hilos\Database\Database;
use Hilos\Database\Exception\TestRollbackRefusedException;
use Hilos\Database\Transaction\TestRollbackScope;
use PHPUnit\Event\Code\ThrowableBuilder;
use PHPUnit\Event\Facade;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Throwable;

/** Explicitly marked cases own one handler and one rollback scope; legacy tests keep their lifecycle. */
abstract class RollbackIntegrationTestCase extends TestCase
{
    private ?TestRollbackScope $rollbackScope = null;
    private bool $handlerStarted = false;
    private bool $environmentPrepared = false;
    private bool $resetRequired = false;
    private bool $scopeStarted = false;

    /**
     * Validates the concrete declaration before environment setup; case fixtures start inside the scope.
     * Setup failures clean up here because a runner need not call tearDown after failed setup.
     *
     * @throws Throwable When declaration, environment or transactional setup fails
     */
    final protected function setUp(): void
    {
        parent::setUp();
        // The declaration is the opt-in: PHP has no non-Reflection reader for class/method attributes.
        $class = new ReflectionClass($this);
        if ($class->getAttributes(IntegrationTest::class) === []) {
            throw new TestRollbackRefusedException('The concrete rollback test class must declare #[IntegrationTest]');
        }
        $this->resetRequired = $class->getAttributes(BreaksOrmState::class) !== []
            || $class->getMethod($this->name())->getAttributes(BreaksOrmState::class) !== [];

        try {
            $this->environmentPrepared = true;
            $this->prepareIntegrationEnvironment();
            Database::handlerStart();
            $this->handlerStarted = true;
            $this->rollbackScope = Database::beginRollbackScopeForTest();
            $this->scopeStarted = true;
            $this->setUpInTransaction();
        } catch (Throwable $primary) {
            foreach ($this->closeIntegrationEnvironment() as $failure) {
                // PHPUnit counts each pre-setup errored event as another case; a failed event keeps both diagnostics on this case.
                Facade::emitter()->testFailed($this->valueObjectForEvents(), ThrowableBuilder::from($failure), null);
            }
            throw $primary;
        }
    }

    /**
     * Rolls back before the passing case's handler is ended, without releasing its environment yet.
     *
     * @throws AssertionFailedError When rollback, handler cleanup or a marked reset fails
     */
    final protected function assertPostConditions(): void
    {
        parent::assertPostConditions();
        $this->reportFailures($this->closeTransaction());
    }

    /**
     * Covers failure, exception and skip paths too; cleanup is idempotent after postconditions/setup failure.
     * An assertion failure is recorded by PHPUnit even when the body already raised another failure.
     *
     * @throws AssertionFailedError When cleanup or environment release fails
     */
    final protected function tearDown(): void
    {
        try {
            $this->reportFailures($this->closeIntegrationEnvironment());
        } finally {
            parent::tearDown();
        }
    }

    /**
     * Environment bases alone prepare connections and schema before the scope, without case data.
     *
     * @throws Throwable When environment preparation fails
     */
    protected function prepareIntegrationEnvironment(): void
    {
    }

    /**
     * Environment bases alone release connections/schema and restore facade state, including after partial preparation.
     *
     * @throws Throwable When environment release fails
     */
    protected function releaseIntegrationEnvironment(): void
    {
    }

    /**
     * Prepares case data after the handler and rollback scope have both started.
     *
     * @throws Throwable When case fixtures cannot be prepared
     */
    protected function setUpInTransaction(): void
    {
    }

    /**
     * Called only for BreaksOrmState, after all roots and the handler close and before environment release.
     *
     * @return list<OrmResetTarget> Explicit affected processes; an empty or invalid list fails the case
     */
    protected function ormResetTargets(): array
    {
        return [OrmResetTarget::forCurrentProcess()];
    }

    /** @return list<Throwable> Every transaction/handler/reset failure, after attempting each phase */
    private function closeTransaction(): array
    {
        $failures = [];
        if ($this->rollbackScope !== null) {
            try {
                $this->rollbackScope->rollback();
            } catch (Throwable $failure) {
                $failures[] = $failure;
            } finally {
                $this->rollbackScope = null;
            }
        }
        if ($this->handlerStarted) {
            try {
                array_push($failures, ...Database::handlerEnd());
            } catch (Throwable $failure) {
                $failures[] = $failure;
            } finally {
                $this->handlerStarted = false;
            }
        }
        if ($this->scopeStarted) {
            $this->scopeStarted = false;
            if ($this->resetRequired) {
                try {
                    new OrmTestResetter()->reset($this->ormResetTargets());
                } catch (Throwable $failure) {
                    $failures[] = $failure;
                }
            }
        }

        return $failures;
    }

    /** @return list<Throwable> Cleanup failures, with release attempted even after rollback/reset failure */
    private function closeIntegrationEnvironment(): array
    {
        $failures = $this->closeTransaction();
        if ($this->environmentPrepared) {
            $this->environmentPrepared = false;
            try {
                $this->releaseIntegrationEnvironment();
            } catch (Throwable $failure) {
                $failures[] = $failure;
            }
        }

        return $failures;
    }

    /**
     * @param list<Throwable> $failures All failures from this cleanup attempt
     * @throws AssertionFailedError When any cleanup phase failed
     */
    private function reportFailures(array $failures): void
    {
        if ($failures !== []) {
            throw new AssertionFailedError(implode("\n", array_map(
                static fn (Throwable $failure): string => $failure::class . ': ' . $failure->getMessage(),
                $failures,
            )), 0, $failures[0]);
        }
    }
}
