<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Runtime;

use Hilos\Core\Feature\Definition\BackupFeature;
use Hilos\Core\Feature\Definition\SettingsFeature;
use Hilos\Core\Feature\Exception\FeatureRuntimeOverwrittenException;
use Hilos\Core\Feature\Exception\IncompleteFeatureActivationException;
use Hilos\Core\Feature\FeatureDefinition;
use Hilos\Core\Feature\FeatureRegistry;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Fs\Context\FsContext;
use Hilos\Hilos as HilosFacade;
use Hilos\Runtime\State\Collection\BackupHistories as StateBackupHistories;
use Hilos\Runtime\State\Item\BackupHistory as StateBackupHistory;
use Hilos\Runtime\View\Collection\BackupHistories;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Item\BackupRuntime;
use Hilos\Runtime\View\Item\ProtectedModeRuntime;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

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

    public function testEveryDefinitionAnswersMountsRuntimeAsItsOwnMountSays(): void
    {
        foreach ((new FeatureRegistry())->all() as $definition) {
            // Reflection asks which class declares mount(); no plain-PHP call answers that,
            // and the point of the check is the declaration rather than what a call returns.
            $mounts = (new ReflectionMethod($definition, 'mount'))->getDeclaringClass()->getName()
                !== FeatureDefinition::class;

            $this->assertSame(
                $mounts,
                $definition->mountsRuntime(),
                $definition::class . '::mountsRuntime() disagrees with whether it declares its own mount()',
            );
        }
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

    public function testDeclaringUploadsWithoutATmpDirectoryIsRefused(): void
    {
        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage(
            'HilosFeature::UPLOADS keeps chunks in the tmp directory, but the FS context configures none',
        );

        $this->withFs(new FeatureUploadsTestFsContext(withTmp: false), FeatureUploadsHilos::refuseForTest(...));
    }

    public function testDeclaringUploadsWithoutAnFsContextIsRefused(): void
    {
        $this->expectException(IncompleteFeatureActivationException::class);

        $this->withFs(null, FeatureUploadsHilos::refuseForTest(...));
    }

    public function testDeclaringUploadsWithATmpDirectoryPasses(): void
    {
        $this->withFs(new FeatureUploadsTestFsContext(withTmp: true), FeatureUploadsHilos::refuseForTest(...));

        $this->addToAssertionCount(1);
    }

    public function testAProjectWithoutUploadsNeedsNoTmpDirectory(): void
    {
        $this->withFs(null, FeatureRuntimeContextlessHilos::refuseUploadsForTest(...));

        $this->addToAssertionCount(1);
    }

    public function testDeclaringFilesWithoutAFilesDirectoryIsRefused(): void
    {
        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage(
            'HilosFeature::FILES keeps published files in the files directory, but the FS context registers none',
        );

        $this->withFs(new FeatureFilesTestFsContext(withFiles: false), FeatureFilesHilos::refuseForTest(...));
    }

    public function testDeclaringFilesWithoutAnFsContextIsRefused(): void
    {
        $this->expectException(IncompleteFeatureActivationException::class);

        $this->withFs(null, FeatureFilesHilos::refuseForTest(...));
    }

    public function testDeclaringFilesWithAFilesDirectoryPasses(): void
    {
        $this->withFs(new FeatureFilesTestFsContext(withFiles: true), FeatureFilesHilos::refuseForTest(...));

        $this->addToAssertionCount(1);
    }

    public function testAProjectWithoutFilesNeedsNoFilesDirectory(): void
    {
        $this->withFs(null, FeatureRuntimeContextlessHilos::refuseFilesForTest(...));

        $this->addToAssertionCount(1);
    }

    /**
     * Runs one check with the FS context set, and puts the previous one back whatever happens.
     *
     * @param ?FsContext $fs FS context the check sees
     * @param callable(): void $check Check to run
     */
    private function withFs(?FsContext $fs, callable $check): void
    {
        $previous = HilosFacade::$fs;
        HilosFacade::$fs = $fs;
        try {
            $check();
        } finally {
            HilosFacade::$fs = $previous;
        }
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
     * Runs the uploads tmp check the way init() does, on a project that declares no uploads.
     *
     * @throws IncompleteFeatureActivationException When UPLOADS is declared and no tmp directory is configured
     */
    public static function refuseUploadsForTest(): void
    {
        static::refuseUploadsWithoutTmp();
    }

    /**
     * Runs the files directory check the way init() does, on a project that declares no files registry.
     *
     * @throws IncompleteFeatureActivationException When FILES is declared and no files directory is registered
     */
    public static function refuseFilesForTest(): void
    {
        static::refuseFilesWithoutDirectory();
    }

    /**
     * Creates a no-op DB context; the fixture never reaches a layer.
     *
     * @return HilosDbContext Test DB context
     */
    protected static function createDb(): HilosDbContext
    {
        return new FeatureRuntimeTestDbContext();
    }
}

/**
 * DB context the context-less fixture hands back; never configured, never queried.
 */
final class FeatureRuntimeTestDbContext extends HilosDbContext
{
    /**
     * No-op DB configuration for runtime mount tests.
     */
    public function configure(): void
    {
    }
}

/**
 * Facade standing in for a project that declares uploads; only its tmp check is run.
 */
final class FeatureUploadsHilos extends HilosFacade
{
    protected const array FEATURES = [HilosFeature::UPLOADS];

    /**
     * Runs the uploads tmp check the way init() does.
     *
     * @throws IncompleteFeatureActivationException When no tmp directory is configured
     */
    public static function refuseForTest(): void
    {
        static::refuseUploadsWithoutTmp();
    }

    /**
     * Creates a no-op DB context; the fixture never reaches a layer.
     *
     * @return HilosDbContext Test DB context
     */
    protected static function createDb(): HilosDbContext
    {
        return new FeatureRuntimeTestDbContext();
    }
}

/**
 * FS context with or without a tmp directory; nothing is ever written through it.
 */
final class FeatureUploadsTestFsContext extends FsContext
{
    /**
     * @param bool $withTmp Whether the context configures a tmp directory
     */
    public function __construct(bool $withTmp)
    {
        if ($withTmp) {
            $this->setTmpPath(sys_get_temp_dir());
        }
    }

    /**
     * The constructor already configured what this fixture has.
     */
    public function configure(): void
    {
    }
}


/**
 * Facade standing in for a project that declares the files registry; only its directory check is run.
 */
final class FeatureFilesHilos extends HilosFacade
{
    protected const array FEATURES = [HilosFeature::FILES];

    /**
     * Runs the files directory check the way init() does.
     *
     * @throws IncompleteFeatureActivationException When no files directory is registered
     */
    public static function refuseForTest(): void
    {
        static::refuseFilesWithoutDirectory();
    }

    /**
     * Creates a no-op DB context; the fixture never reaches a layer.
     *
     * @return HilosDbContext Test DB context
     */
    protected static function createDb(): HilosDbContext
    {
        return new FeatureRuntimeTestDbContext();
    }
}

/**
 * FS context with or without the files directory; nothing is ever written through it.
 */
final class FeatureFilesTestFsContext extends FsContext
{
    /**
     * @param bool $withFiles Whether the context registers the files directory
     */
    public function __construct(bool $withFiles)
    {
        if ($withFiles) {
            $this->registerDirectory(FsContext::FILES, sys_get_temp_dir());
        }
    }

    /**
     * The constructor already configured what this fixture has.
     */
    public function configure(): void
    {
    }
}
