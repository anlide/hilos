<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database;

use Closure;
use FilesystemIterator;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Bootstrap\EntrypointPrelude;
use Hilos\Database\DatabaseException;
use Hilos\Database\Migration;
use Hilos\Database\Migration\FrameworkMigrationCatalog;
use Hilos\Database\Migration\FrameworkMigrationInstaller;
use Hilos\Database\Migration\FrameworkMigrationOrigin;
use Hilos\Database\Migration\FrameworkMigrationPair;
use Hilos\Database\Migration\FrameworkMigrationStartupGuard;
use Hilos\Database\Migration\FrameworkSchemaCatalog;
use Hilos\Database\Migration\FrameworkSchemaUnit;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/** Temporary SQL/catalog fixtures and exact-index doubles; no DB, runtime or second process. */
final class FrameworkMigrationStartupGuardTest extends TestCase
{
    private const string CHANGE = '20261020120000000000_HIL-1518_startup';
    private const string ADOPT_FILE = '003_adopt_framework_catalog.sql';

    private string $root;
    private string $track;
    private ?EnvAccessor $oldEnv;
    private ?SettingsAccessor $oldSetting;

    protected function setUp(): void
    {
        FrameworkMigrationStartupGuard::resetForTests();
        $this->oldEnv = Hilos::$env;
        $this->oldSetting = Hilos::$setting;
        $this->root = sys_get_temp_dir() . '/hilos-startup-' . bin2hex(random_bytes(8));
        $this->track = $this->root . '/backend/Database/Migration/Schema';
        mkdir($this->track, 0775, true);
        mkdir($this->root . '/tests');
        file_put_contents($this->root . '/tests/.env', "APP_ENV=test\n");
        mkdir($this->root . '/source/Change', 0775, true);
        mkdir($this->root . '/source/Stub');
        StartupTestGuard::$sourceRoot = $this->root . '/source';
        StartupTestGuard::$schema = new StartupSchema($this->root . '/source');
        StartupTestGuard::$applied = [];
        StartupTestGuard::$reads = 0;
        StartupTestGuard::$onCatalog = null;
        StartupCoreHilos::$topologyReached = false;
        StartupCoreHilos::$settingsReached = false;
        StartupCoreHilos::$loadedEnvFile = null;
        $genesis = [];
        foreach (StartupTestGuard::$schema->allUnits() as $unit) {
            if (!$unit->bootstrapOwned) {
                file_put_contents($unit->upPath, "CREATE {$unit->key};\n");
                file_put_contents($unit->downPath, "DROP {$unit->key};\n");
            }
            $genesis[] = [
                'unit' => $unit->key,
                'upSha256' => $unit->bootstrapOwned ? null : hash_file('sha256', $unit->upPath),
                'downSha256' => $unit->bootstrapOwned ? null : hash_file('sha256', $unit->downPath),
            ];
        }
        $this->json($this->root . '/source/catalog.json', ['format' => 1, 'units' => $genesis]);
        Migration::setMigrationListPath($this->root . '/backend/Database/Migration');
        Migration::setMigrationName('Schema');
        $this->adopt();
    }

    protected function tearDown(): void
    {
        FrameworkMigrationStartupGuard::resetForTests();
        StartupTestGuard::$schema = null;
        StartupTestGuard::$sourceRoot = null;
        StartupTestGuard::$applied = [];
        StartupTestGuard::$onCatalog = null;
        Hilos::$env = $this->oldEnv;
        Hilos::$setting = $this->oldSetting;
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($this->root);
    }

    public function testSourceStageReadsActualFilesUnderSharedLockWithoutReadingAppliedIndices(): void
    {
        $before = $this->sources();
        $locked = false;
        StartupTestGuard::$onCatalog = function () use (&$locked): void {
            $probe = fopen($this->track . '/' . FrameworkMigrationInstaller::LOCK_FILE, 'c');
            try {
                $this->assertFalse(flock($probe, LOCK_EX | LOCK_NB));
                $this->assertTrue(flock($probe, LOCK_SH | LOCK_NB), 'The startup lock must be shared');
                $locked = true;
            } finally {
                fclose($probe);
            }
        };
        StartupTestGuard::prepare(StartupCoreHilos::class, $this->root);
        $this->assertTrue($locked);
        $this->assertSame(0, StartupTestGuard::$reads);
        $this->assertSame($before, $this->sources());
    }

    public function testProjectWithoutOriginIsRefusedEvenWhenLegacySqlNamesItsTables(): void
    {
        $this->removePair(self::ADOPT_FILE);
        file_put_contents($this->track . '/001_legacy.sql', 'CREATE TABLE hilos_user; CREATE TABLE hilos_session;');
        $this->assertSame(
            'This project has no framework catalog origin; only projects initialized for this catalog are supported',
            $this->sourceRefusal(),
        );
    }

    public function testMissingSelectedUnitNamesTheUnitAndPullCommand(): void
    {
        $this->adopt(['migration', 'user']);
        $this->assertSame('Framework migration session is missing from this project; run db:framework:pull', $this->sourceRefusal());
    }

    public function testChangingTheSelectedUnitsRequiresTheirActualOrigin(): void
    {
        $this->assertSame(
            'Framework migration extra is missing from this project; run db:framework:pull',
            $this->sourceRefusal(StartupExtraHilos::class),
        );
    }

    public function testMissingSelectedPartNamesItsExactIdentityAndPullCommand(): void
    {
        $this->change();
        $this->assertSame(
            'Framework migration ' . self::CHANGE . '/user is missing from this project; run db:framework:pull',
            $this->sourceRefusal(),
        );
    }

    public function testUnselectedPartDoesNotPreventStartup(): void
    {
        $this->change('extra');
        StartupTestGuard::prepare(StartupCoreHilos::class, $this->root);
        StartupTestGuard::$applied = [3];
        FrameworkMigrationStartupGuard::assertAppliedIfStarting();
        $this->assertSame(1, StartupTestGuard::$reads);
    }

    public function testMetadataCoverageDoesNotStandInForMissingSourceFiles(): void
    {
        $this->change();
        $this->writeChangePair();
        $this->removePair('008_framework_change_user.sql');
        $this->assertSame(
            'Framework migration ' . self::CHANGE . '/user is missing from this project; run db:framework:pull',
            $this->sourceRefusal(),
        );
    }

    #[DataProvider('halfDirections')]
    public function testHalfPairIsRefusedWithoutRepairingIt(string $missing): void
    {
        $name = $missing === 'up' ? self::ADOPT_FILE : '003_adopt_framework_catalog_down.sql';
        unlink($this->track . '/' . $name);
        $before = $this->sources();
        $message = $this->sourceRefusal();
        $this->assertSame("Framework migration {$name} is missing from this project; run db:framework:pull", $message);
        $this->assertSame($before, $this->sources());
    }

    public static function halfDirections(): array
    {
        return [['up'], ['down']];
    }

    #[DataProvider('corruptions')]
    public function testDamageNamesTheFileAndNeverOffersForce(string $case): void
    {
        $path = $this->track . '/' . self::ADOPT_FILE;
        switch ($case) {
            case 'body': file_put_contents($path, "changed body\n", FILE_APPEND); break;
            case 'header': file_put_contents($path, "-- hilos-framework: broken\nSELECT 1;\n"); break;
            case 'direction':
                $pair = $this->pair('adopt_other', 'adopt', ['migration', 'user', 'session'], 'SELECT 2;', 'SELECT 2;');
                file_put_contents($this->track . '/003_adopt_framework_catalog_down.sql', $pair->downSql);
                break;
            case 'encoding': file_put_contents($path, "\xEF\xBB\xBF" . file_get_contents($path)); break;
        }
        $message = $this->sourceRefusal();
        $this->assertStringContainsString(self::ADOPT_FILE, $message);
        $this->assertStringNotContainsString('--force', $message);
    }

    public static function corruptions(): array
    {
        return [['body'], ['header'], ['direction'], ['encoding']];
    }

    public function testDuplicateNumberNamesBothFiles(): void
    {
        copy($this->track . '/' . self::ADOPT_FILE, $this->track . '/003_other.sql');
        $message = $this->sourceRefusal();
        $this->assertStringContainsString(self::ADOPT_FILE, $message);
        $this->assertStringContainsString('003_other.sql', $message);
        $this->assertStringNotContainsString('--force', $message);
    }

    public function testDuplicateOriginNamesTheOffendingFiles(): void
    {
        copy($this->track . '/' . self::ADOPT_FILE, $this->track . '/009_adopt_again.sql');
        copy($this->track . '/003_adopt_framework_catalog_down.sql', $this->track . '/009_adopt_again_down.sql');
        $message = $this->sourceRefusal();
        $this->assertStringContainsString('Duplicate framework genesis adoption provenance', $message);
        $this->assertStringContainsString('009_adopt_again.sql', $message);
    }

    public function testDuplicatePartNamesItsExactIdentityAndFiles(): void
    {
        $this->change();
        $this->writeChangePair();
        copy($this->track . '/008_framework_change_user.sql', $this->track . '/009_framework_again.sql');
        copy($this->track . '/008_framework_change_user_down.sql', $this->track . '/009_framework_again_down.sql');
        $message = $this->sourceRefusal();
        $this->assertStringContainsString('Duplicate framework part provenance ' . self::CHANGE . '/user', $message);
        $this->assertStringContainsString('009_framework_again.sql', $message);
    }

    public function testOriginCannotCoverAChangedImmutableCatalogPart(): void
    {
        $this->change();
        $this->writeChangePair();
        file_put_contents($this->root . '/source/Change/' . self::CHANGE . '/up.sql', 'rewritten immutable SQL');
        $message = $this->sourceRefusal();
        $this->assertStringContainsString('008_framework_change_user.sql', $message);
        $this->assertStringContainsString('Framework origin disagrees with immutable source ' . self::CHANGE . '/user', $message);
    }

    public function testPairFilenameDisagreementNamesBothFiles(): void
    {
        rename($this->track . '/003_adopt_framework_catalog_down.sql', $this->track . '/003_wrong_down.sql');
        $message = $this->sourceRefusal();
        $this->assertStringContainsString('Framework origin pair filenames disagree', $message);
        $this->assertStringContainsString(self::ADOPT_FILE, $message);
        $this->assertStringContainsString('003_wrong_down.sql', $message);
    }

    public function testCurrentStubDriftNamesItsUnit(): void
    {
        file_put_contents(StartupTestGuard::$schema->unit('user')->upPath, 'undeclared change');
        $this->assertStringContainsString('Framework stub drift for unit user', $this->sourceRefusal());
    }

    #[DataProvider('unsuccessfulIndices')]
    public function testPendingFailedRolledBackAndLowHoleAreNotSuccessful(array $successful): void
    {
        StartupTestGuard::prepare(StartupCoreHilos::class, $this->root);
        StartupTestGuard::$applied = $successful;
        $this->assertSame('Framework migration ' . self::ADOPT_FILE . ' is not applied; run db:migration:up', $this->appliedRefusal());
    }

    /** @return array<string, array{list<int>}> Rows visible after the migration layer excluded every failed row */
    public static function unsuccessfulIndices(): array
    {
        return [
            'pending' => [[]],
            'failed row excluded' => [[1, 2]],
            'rolled back row absent' => [[1, 2, 4]],
            'higher MAX cannot cover lowest origin' => [[100]],
        ];
    }

    public function testEveryOriginIndexIsRequiredIncludingAnUnselectedUnitsCopiedPair(): void
    {
        $this->change('extra');
        $this->writeChangePair('extra');
        StartupTestGuard::prepare(StartupCoreHilos::class, $this->root);
        StartupTestGuard::$applied = [3, 100];
        $this->assertSame(
            'Framework migration 008_framework_change_extra.sql is not applied; run db:migration:up',
            $this->appliedRefusal(),
        );
        StartupTestGuard::$applied = [3, 8];
        FrameworkMigrationStartupGuard::assertAppliedIfStarting();
    }

    public function testDirectStartIsRefusedInsidePersistenceBeforeTopologySettingsOrDbReads(): void
    {
        StartupTestGuard::prepare(StartupCoreHilos::class, $this->root);
        Hilos::$env = new EnvAccessor();
        Hilos::$setting = null;
        try {
            StartupCoreHilos::init();
            $this->fail('A direct start with pending origin SQL was accepted');
        } catch (DatabaseException $exception) {
            $this->assertSame('Framework migration ' . self::ADOPT_FILE . ' is not applied; run db:migration:up', $exception->getMessage());
        }
        $this->assertFalse(StartupCoreHilos::$topologyReached);
        $this->assertFalse(StartupCoreHilos::$settingsReached);
    }

    public function testDockerCanRollOutBetweenSourcePrepareAndHilosInitIncludingInsideAfterRollout(): void
    {
        $this->change();
        $this->writeChangePair();
        StartupTestGuard::prepare(StartupCoreHilos::class, $this->root);
        $this->assertSame(0, StartupTestGuard::$reads);
        $afterRollout = static function (): void { StartupCoreHilos::init(); };
        StartupTestGuard::$applied = [3, 8];
        Hilos::$env = new EnvAccessor();
        Hilos::$setting = null;
        try {
            $afterRollout();
            $this->fail('The settings boundary should stop this unit-only bootstrap');
        } catch (StartupSettingsBoundary) {
            $this->assertTrue(StartupCoreHilos::$topologyReached);
            $this->assertTrue(StartupCoreHilos::$settingsReached);
            $this->assertSame(1, StartupTestGuard::$reads);
        }
    }

    public function testResetDisarmsTheProcessContextExplicitly(): void
    {
        StartupTestGuard::prepare(StartupCoreHilos::class, $this->root);
        FrameworkMigrationStartupGuard::resetForTests();
        FrameworkMigrationStartupGuard::assertAppliedIfStarting();
        $this->assertSame(0, StartupTestGuard::$reads);
    }

    public function testFailedPreparationCannotKeepAnEarlierStartupContext(): void
    {
        StartupTestGuard::prepare(StartupCoreHilos::class, $this->root);
        $this->removePair(self::ADOPT_FILE);
        $this->sourceRefusal();
        FrameworkMigrationStartupGuard::assertAppliedIfStarting();
        $this->assertSame(0, StartupTestGuard::$reads);
    }

    public function testAppliedGateUsesTheImmutablePreparedSourceSnapshot(): void
    {
        StartupTestGuard::prepare(StartupCoreHilos::class, $this->root);
        $this->removePair(self::ADOPT_FILE);
        $this->assertSame('Framework migration ' . self::ADOPT_FILE . ' is not applied; run db:migration:up', $this->appliedRefusal());
    }

    public function testSwitchingConfiguredTrackCannotReuseAnotherPreparedAddress(): void
    {
        StartupTestGuard::prepare(StartupCoreHilos::class, $this->root);
        Migration::setMigrationName('Other');
        $this->assertSame('Configured migration track differs from the prepared framework startup track', $this->appliedRefusal());
        $this->assertSame(0, StartupTestGuard::$reads);
    }

    public function testPreludeRefusesSourcesAfterTestEnvironmentOverrideBeforePersistence(): void
    {
        file_put_contents($this->root . '/.env', "APP_ENV=test\n");
        $this->removePair(self::ADOPT_FILE);
        $persistenceCalled = false;
        try {
            EntrypointPrelude::run(StartupCoreHilos::class, $this->root, static function () use (&$persistenceCalled): void {
                $persistenceCalled = true;
            });
            $this->fail('Prelude accepted a track without origin');
        } catch (DatabaseException $exception) {
            $this->assertSame(
                'This project has no framework catalog origin; only projects initialized for this catalog are supported',
                $exception->getMessage(),
            );
        }
        $this->assertFalse($persistenceCalled);
        $this->assertSame($this->root . '/tests/.env', StartupCoreHilos::$loadedEnvFile);
        $this->assertSame('test', Hilos::$env[EnvConstants::APP_ENV]->string());
        $this->assertSame($this->track, Migration::trackPath());
    }

    public function testRealPreludeAcceptsSourcesWithoutAskingForAppliedSqlBeforeDockerPersistence(): void
    {
        $part = new FrameworkMigrationCatalog()->part('20261021021039718057_HIL-1580_add_user_time_zone', 'user');
        $this->writePair('004_user_time_zone.sql', $this->pair(
            'user_time_zone',
            FrameworkMigrationOrigin::CHANGE,
            ['user'],
            $part->upSql,
            $part->downSql,
            [$part],
        ));
        $persistenceCalled = false;
        EntrypointPrelude::run(StartupCoreHilos::class, $this->root, static function () use (&$persistenceCalled): void {
            $persistenceCalled = true;
        });
        $this->assertTrue($persistenceCalled);
    }

    public function testCliEnvironmentOnlyPathLeavesRepairStatusMigrateAndResetAvailableWithoutOrigin(): void
    {
        $this->removePair(self::ADOPT_FILE);
        EntrypointPrelude::initEnvironment(StartupCoreHilos::class, $this->root);
        FrameworkMigrationStartupGuard::assertAppliedIfStarting();
        $this->assertSame($this->track, Migration::trackPath());
        $this->assertSame(0, StartupTestGuard::$reads);
    }

    private function adopt(array $units = ['migration', 'user', 'session']): void
    {
        $this->writePair(self::ADOPT_FILE, $this->pair('adopt_framework_catalog', 'adopt', $units, 'SELECT 1;', 'SELECT 1;'));
    }

    private function pair(string $name, string $kind, array $units, string $up, string $down, array $parts = []): FrameworkMigrationPair
    {
        return new FrameworkMigrationPair($name, FrameworkMigrationOrigin::forBodies($kind, $units, $parts, $up, $down), $up, $down);
    }

    private function writePair(string $name, FrameworkMigrationPair $pair): void
    {
        file_put_contents($this->track . '/' . $name, $pair->upSql);
        file_put_contents($this->track . '/' . substr($name, 0, -4) . '_down.sql', $pair->downSql);
    }

    private function removePair(string $name): void
    {
        unlink($this->track . '/' . $name);
        unlink($this->track . '/' . substr($name, 0, -4) . '_down.sql');
    }

    private function change(string $key = 'user'): void
    {
        $directory = $this->root . '/source/Change/' . self::CHANGE;
        mkdir($directory);
        file_put_contents($directory . '/up.sql', "ALTER {$key};\n");
        file_put_contents($directory . '/down.sql', "UNDO {$key};\n");
        $this->json($directory . '/change.json', [
            'format' => 1,
            'id' => self::CHANGE,
            'parts' => [[
                'unit' => $key, 'up' => 'up.sql', 'down' => 'down.sql', 'requires' => [],
                'afterStubUpSha256' => hash_file('sha256', StartupTestGuard::$schema->unit($key)->upPath),
                'afterStubDownSha256' => hash_file('sha256', StartupTestGuard::$schema->unit($key)->downPath),
            ]],
        ]);
    }

    private function writeChangePair(string $key = 'user'): void
    {
        $part = (new FrameworkMigrationCatalog(StartupTestGuard::$schema, $this->root . '/source'))->part(self::CHANGE, $key);
        $this->writePair(
            '008_framework_change_' . $key . '.sql',
            $this->pair('framework_change_' . $key, 'change', [$key], $part->upSql, $part->downSql, [$part]),
        );
    }

    private function json(string $path, array $data): void
    {
        file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function sourceRefusal(string $facade = StartupCoreHilos::class): string
    {
        try {
            StartupTestGuard::prepare($facade, $this->root);
        } catch (DatabaseException $exception) {
            return $exception->getMessage();
        }
        $this->fail('Invalid framework sources were accepted');
    }

    private function appliedRefusal(): string
    {
        try {
            FrameworkMigrationStartupGuard::assertAppliedIfStarting();
        } catch (DatabaseException $exception) {
            return $exception->getMessage();
        }
        $this->fail('An unapplied framework origin was accepted');
    }

    private function sources(): array
    {
        $sources = [];
        foreach (glob($this->track . '/*.sql') as $file) {
            $sources[basename($file)] = file_get_contents($file);
        }

        return $sources;
    }
}

final class StartupTestGuard extends FrameworkMigrationStartupGuard
{
    public static ?FrameworkSchemaCatalog $schema = null;
    public static ?string $sourceRoot = null;
    public static array $applied = [];
    public static int $reads = 0;
    public static ?Closure $onCatalog = null;

    protected static function createSchemaCatalog(): FrameworkSchemaCatalog
    {
        if (self::$onCatalog !== null) {
            (self::$onCatalog)();
        }

        return self::$schema;
    }

    protected static function createMigrationCatalog(FrameworkSchemaCatalog $schema): FrameworkMigrationCatalog
    {
        return new FrameworkMigrationCatalog($schema, self::$sourceRoot);
    }

    protected function readAppliedIndices(): array
    {
        ++self::$reads;

        return self::$applied;
    }
}

final class StartupSchema extends FrameworkSchemaCatalog
{
    public function __construct(private readonly string $root)
    {
        parent::__construct();
    }

    protected function buildUnits(): array
    {
        $units = [new FrameworkSchemaUnit('migration', ['migration'], null, null, bootstrapOwned: true)];
        foreach (['user', 'session', 'extra'] as $key) {
            $units[] = new FrameworkSchemaUnit(
                $key,
                ['hilos_' . $key],
                $this->root . '/Stub/' . $key . '.sql',
                $this->root . '/Stub/' . $key . '_down.sql',
            );
        }

        return $units;
    }

    public function selectedUnits(string $hilosClass): array
    {
        return $hilosClass === StartupExtraHilos::class ? $this->allUnits() : parent::selectedUnits($hilosClass);
    }
}

abstract class StartupCoreHilos extends Hilos
{
    public static bool $topologyReached = false;
    public static bool $settingsReached = false;
    public static ?string $loadedEnvFile = null;

    public static function initEnv(?string $rootPath = null): void
    {
        Hilos::$env = new EnvAccessor();
        Hilos::$env->init($rootPath);
    }

    public static function loadEnv(string $envFilePath): void
    {
        self::$loadedEnvFile = $envFilePath;
        parent::loadEnv($envFilePath);
    }

    public static function validateTopology(): void
    {
        self::$topologyReached = true;
    }

    public static function validateFeatureActivation(): void
    {
    }

    protected static function createSetting(): SettingsAccessor
    {
        self::$settingsReached = true;
        throw new StartupSettingsBoundary();
    }
}

abstract class StartupExtraHilos extends StartupCoreHilos
{
}

final class StartupSettingsBoundary extends RuntimeException
{
}
