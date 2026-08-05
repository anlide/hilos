<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Runtime;

use Hilos\Core\Feature\Definition\BackupFeature;
use Hilos\Core\Feature\Definition\SettingsFeature;
use Hilos\Core\Feature\Exception\FeatureRuntimeOverwrittenException;
use Hilos\Core\Feature\Exception\IncompleteFeatureActivationException;
use Hilos\Core\Feature\FeatureDefinition;
use Hilos\Database\Context\DbContext;
use Hilos\Hilos as HilosFacade;
use Hilos\Runtime\State\Collection\BackupHistories as StateBackupHistories;
use Hilos\Runtime\State\Item\BackupHistory as StateBackupHistory;
use Hilos\Runtime\View\Collection\BackupHistories;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Item\BackupRuntime;
use Hilos\Runtime\View\Item\ProtectedModeRuntime;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the framework-owned runtime mount driven by the feature declaration.
 *
 * The point of the mount is that a project no longer writes a line to switch a feature's
 * runtime state on: it declares the feature and the framework brings the rows. These tests
 * hold the two halves of that promise - the rows appear without the project, and a project
 * that mounts them anyway is refused instead of quietly ending up with two of them.
 */
final class FeatureRuntimeMountTest extends TestCase
{
    public function testDeclaredFeatureBringsItsRuntimeStateWithoutTheProject(): void
    {
        $context = new EmptyTestRtContext();
        $context->mountFeatureRuntime([new BackupFeature()]);
        $context->configure();
        $context->assertFeatureRuntimeIntact();

        $this->assertInstanceOf(BackupHistories::class, $context->hilosBackupHistories);
        $this->assertInstanceOf(BackupRuntime::class, $context->hilosBackupRuntime);
    }

    public function testUndeclaredFeatureLeavesItsRowUnmounted(): void
    {
        $context = new EmptyTestRtContext();
        $context->mountFeatureRuntime([]);
        $context->configure();
        $context->assertFeatureRuntimeIntact();

        $this->assertNull($context->hilosBackupRuntime);
    }

    public function testProtectedModeIsMountedWithoutAnyDeclaration(): void
    {
        $context = new EmptyTestRtContext();
        $context->mountFeatureRuntime([]);
        $context->configure();

        $this->assertInstanceOf(ProtectedModeRuntime::class, $context->hilosProtectedModeRuntime);
    }

    public function testProjectThatMountsAFeatureCollectionItselfIsRefused(): void
    {
        $context = new FeatureRemountingTestRtContext();
        $context->mountFeatureRuntime([new BackupFeature()]);
        $context->configure();

        $this->expectException(FeatureRuntimeOverwrittenException::class);
        $this->expectExceptionMessage('state collection ' . StateBackupHistory::RT_COLLECTION . ' is mounted by the framework');

        $context->assertFeatureRuntimeIntact();
    }

    public function testOnlyAFeatureWithItsOwnMountSaysItBringsRuntimeState(): void
    {
        $this->assertTrue((new BackupFeature())->mountsRuntime());
        $this->assertFalse((new SettingsFeature())->mountsRuntime());
    }

    public function testDeclaringARuntimeFeatureWithoutARuntimeContextIsRefused(): void
    {
        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage('HilosFeature::BACKUP brings runtime state, but createRuntime() returned no context');

        FeatureRuntimeContextlessHilos::refuseForTest([new BackupFeature()]);
    }

    public function testAFeatureWithoutRuntimeStateNeedsNoRuntimeContext(): void
    {
        FeatureRuntimeContextlessHilos::refuseForTest([new SettingsFeature()]);

        $this->addToAssertionCount(1);
    }
}

/**
 * Context standing in for a project that re-mounts the backup index in its own configure().
 */
final class FeatureRemountingTestRtContext extends RtContext
{
    /**
     * Mounts a second, empty backup index over the one the framework already mounted.
     */
    public function configure(): void
    {
        $this->_stateCollections[StateBackupHistory::RT_COLLECTION] = StateBackupHistories::init();
    }
}

/**
 * Facade standing in for a project that builds no runtime context.
 *
 * The refusal is a protected step of init(), and init() would build every other layer first,
 * so the fixture calls that one step directly - what is under test is which declarations a
 * context-less project may carry, not the order the rest of the layers come up in.
 */
final class FeatureRuntimeContextlessHilos extends HilosFacade
{
    /**
     * Runs the context-less declaration check the way init() does.
     *
     * @param list<FeatureDefinition> $definitions Definitions of the declared features
     * @throws IncompleteFeatureActivationException When a declared feature mounts runtime state
     */
    public static function refuseForTest(array $definitions): void
    {
        static::refuseRuntimeFeaturesWithoutContext($definitions);
    }

    /**
     * Creates a no-op DB context; the fixture never reaches a layer.
     *
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new FeatureRuntimeTestDbContext();
    }
}

/**
 * DB context the context-less fixture hands back; never configured, never queried.
 */
final class FeatureRuntimeTestDbContext extends DbContext
{
    /**
     * No-op DB configuration for runtime mount tests.
     */
    public function configure(): void
    {
    }
}
