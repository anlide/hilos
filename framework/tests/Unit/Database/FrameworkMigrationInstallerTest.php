<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database;

use Closure;
use FilesystemIterator;
use Hilos\Core\Feature\FeatureDefinition;
use Hilos\Core\Feature\FeatureRegistry;
use Hilos\Core\Feature\FeatureRequirements;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\DatabaseException;
use Hilos\Database\Migration;
use Hilos\Database\Migration\FrameworkMigrationCatalog;
use Hilos\Database\Migration\FrameworkMigrationInstaller;
use Hilos\Database\Migration\FrameworkMigrationOrigin;
use Hilos\Database\Migration\FrameworkMigrationPair;
use Hilos\Database\Migration\FrameworkMigrationPlan;
use Hilos\Database\Migration\FrameworkMigrationProject;
use Hilos\Database\Migration\FrameworkMigrationPublication;
use Hilos\Database\Migration\FrameworkSchemaCatalog;
use Hilos\Database\Migration\FrameworkSchemaUnit;
use Hilos\Fs\Exception\FileAlreadyExistsException;
use Hilos\Fs\Exception\FileMoveException;
use Hilos\Fs\FsPath;
use Hilos\Hilos;
use Hilos\Runtime\View\Context\RtContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionProperty;

/** Real temporary source trees; no database, facade initialization or second process. */
final class FrameworkMigrationInstallerTest extends TestCase
{
    private const string EARLY = '20261019120000000000_HIL-1518_early';
    private const string LATE = '20261020120000000000_HIL-1518_late';

    private string $root;
    private FrameworkSchemaCatalog $schema;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/hilos-installer-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/source/Change', 0775, true);
        mkdir($this->root . '/source/Stub');
        $this->schema = new InstallerSchema($this->root . '/source');
        $genesis = [];
        foreach ($this->schema->allUnits() as $unit) {
            if (!$unit->bootstrapOwned) {
                file_put_contents($unit->upPath, "CREATE {$unit->key};\n");
                file_put_contents($unit->downPath, "DROP {$unit->key};\n");
            }
            $genesis[] = [
                'unit' => $unit->key,
                'upSha256' => $unit->bootstrapOwned ? null : hash('sha256', file_get_contents($unit->upPath)),
                'downSha256' => $unit->bootstrapOwned ? null : hash('sha256', file_get_contents($unit->downPath)),
            ];
        }
        $this->json($this->root . '/source/catalog.json', ['format' => 1, 'units' => $genesis]);
    }

    protected function tearDown(): void
    {
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

    public function testTrackPathIsConfiguredMetadataWithoutDatabaseInitialization(): void
    {
        $path = new ReflectionProperty(Migration::class, 'migrationListPath');
        $name = new ReflectionProperty(Migration::class, 'migrationName');
        $oldPath = $path->getValue();
        $oldName = $name->getValue();
        try {
            Migration::setMigrationListPath($this->root . '/custom/');
            Migration::setMigrationName('Schema');
            $this->assertSame($this->root . '/custom/Schema', Migration::trackPath());
            $path->setValue(null, null);
            $this->expectException(DatabaseException::class);
            Migration::trackPath();
        } finally {
            $path->setValue(null, $oldPath);
            $name->setValue(null, $oldName);
        }
    }

    public function testLimitedAdoptionAndLateFeatureInstallCurrentStubWithExactCoveredPartsWithoutReplay(): void
    {
        $project = $this->project('limited', facade: InstallerFeatureHilos::class);
        $this->change(self::EARLY, 'shared');
        $this->change(self::LATE, 'shared', [[self::EARLY, 'shared']]);
        $installer = $this->installer();
        $before = $this->sqlFiles($project);
        $plan = $installer->plan($project)[0];
        $this->assertCount(1, $plan->publications);
        $this->assertSame($before, $this->sqlFiles($project), 'Planning writes no SQL');
        $publication = $plan->publications[0];
        $this->assertSame('install', $publication->pair->origin->kind);
        $this->assertSame(['shared'], $publication->pair->origin->units);
        $this->assertSame([self::EARLY, self::LATE], array_column($publication->pair->origin->parts, 'changeId'));
        $this->assertSame("CURRENT shared " . self::LATE . ";\n", $this->body($publication->pair->upSql));
        $this->assertStringNotContainsString('ALTER shared', $publication->pair->upSql);
        $outcome = $installer->execute($project);
        $this->assertFalse($outcome->hasFailures());
        $this->assertSame([$publication->downPath, $publication->upPath], $outcome->projects[0]->publishedPaths);
        $this->assertSame($publication->pair->upSql, file_get_contents($publication->upPath));
        $this->assertSame($publication->pair->downSql, file_get_contents($publication->downPath));
        $this->assertSame([], $installer->execute($project)->projects[0]->publishedPaths);
    }

    public function testNumbersUseEveryUpAndDownAcrossExplicitTargetsAndRepeatWritesNothing(): void
    {
        $one = $this->project('one', 3);
        $two = $this->project('two', 73);
        file_put_contents($one->root . '/009_legacy.sql', 'legacy up');
        file_put_contents($one->root . '/040_legacy_down.sql', 'orphan down');
        $this->change(self::EARLY, 'user');
        $before = file_get_contents($one->root . '/040_legacy_down.sql');
        $appClass = Hilos::appClass();
        $installer = $this->installer();
        $plans = $installer->plan($one, $two);
        $this->assertSame([41, 74], array_map(static fn (FrameworkMigrationPlan $plan): int => $plan->publications[0]->index, $plans));
        $outcome = $installer->execute($one, $two);
        $this->assertFalse($outcome->hasFailures());
        $this->assertSame($appClass, Hilos::appClass());
        foreach ($plans as $index => $plan) {
            $publication = $plan->publications[0];
            $this->assertSame([$publication->downPath, $publication->upPath], $outcome->projects[$index]->publishedPaths);
            $this->assertSame("ALTER user " . self::EARLY . ";\n", $this->body(file_get_contents($publication->upPath)));
            $this->assertSame("UNDO user " . self::EARLY . ";\n", $this->body(file_get_contents($publication->downPath)));
        }
        $this->assertSame($before, file_get_contents($one->root . '/040_legacy_down.sql'));
        $repeat = $installer->execute($one, $two);
        $this->assertFalse($repeat->hasFailures());
        $this->assertSame([[], []], array_column($repeat->projects, 'publishedPaths'));
    }

    public function testKnownDeltaPrecedesANewUnitWhoseCurrentStubCoversADependentPart(): void
    {
        $this->change(self::EARLY, 'user');
        $this->change(self::LATE, 'shared', [[self::EARLY, 'user']]);
        $project = $this->project('dependent', facade: InstallerFeatureHilos::class);
        $plan = $this->installer()->plan($project)[0];
        $this->assertSame(['change', 'install'], array_map(
            static fn (FrameworkMigrationPublication $publication): string => $publication->pair->origin->kind,
            $plan->publications,
        ));
        $this->assertFalse($this->installer()->execute($project)->hasFailures());
        $this->assertSame([], $this->installer()->plan($project)[0]->publications);
    }

    public function testNewCoreStubsFollowTheSchemaDependencyOrder(): void
    {
        $project = $this->project('new-core', initialize: false);
        $this->seedPair($project, 1, $this->pair('adopt_framework_catalog', 'adopt', ['migration']));
        $installer = $this->installer();
        $plan = $installer->plan($project)[0];
        $this->assertSame([['user'], ['session']], array_map(
            static fn (FrameworkMigrationPublication $publication): array => $publication->pair->origin->units,
            $plan->publications,
        ));
        $this->assertFalse($installer->execute($project)->hasFailures());
        $this->assertSame([], $installer->plan($project)[0]->publications);
    }

    public function testEarlierIdAddedLaterIsMissingEvenAfterHigherIdWasInstalled(): void
    {
        $this->change(self::LATE, 'shared');
        $project = $this->project('late-branch', facade: InstallerFeatureHilos::class);
        $this->assertFalse($this->installer()->execute($project)->hasFailures());
        $this->change(self::EARLY, 'user');
        $installer = $this->installer();
        $plan = $installer->plan($project)[0];
        $this->assertCount(1, $plan->publications);
        $this->assertSame(self::EARLY, $plan->publications[0]->pair->origin->parts[0]->changeId);
        $this->assertSame(3, $plan->publications[0]->index);
        $this->assertFalse($installer->execute($project)->hasFailures());
    }

    public function testChangeSlugContainingDownDoesNotTurnItsUpFilenameIntoADownFile(): void
    {
        $id = '20261020120000000000_HIL-1518_down_change';
        $this->change($id, 'user');
        $project = $this->project('down-slug');
        $installer = $this->installer();
        $plan = $installer->plan($project)[0];
        $this->assertFalse($installer->execute($project)->hasFailures());
        $this->assertSame($id, $plan->publications[0]->pair->origin->parts[0]->changeId);
        $this->assertSame([], $installer->plan($project)[0]->publications);
    }

    #[DataProvider('invalidPairNames')]
    public function testUnsafeOrMisclassifiedPairNamesAreRefusedBeforePublication(string $name): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Invalid framework migration filename stem');
        $this->pair($name, 'adopt', ['user']);
    }

    /** @return list<array{string}> Path escapes and names the migrator would not classify as up files */
    public static function invalidPairNames(): array
    {
        return [['../escape'], ['down'], ['example_downstream']];
    }

    public function testBootstrapOwnedUnitGetsMissingDeltasWithoutAnInventedStubInstall(): void
    {
        $this->change(self::EARLY, 'migration');
        $project = $this->project('bootstrap');
        $installer = $this->installer();
        $plan = $installer->plan($project)[0];
        $this->assertCount(1, $plan->publications);
        $this->assertSame('change', $plan->publications[0]->pair->origin->kind);
        $this->assertSame(['migration'], $plan->publications[0]->pair->origin->units);
        $this->assertFalse($installer->execute($project)->hasFailures());
    }

    public function testDriftInAFeatureOnlyStubFailsBatchPreflightWithoutWritingTheValidCoreTarget(): void
    {
        $core = $this->project('core');
        $feature = $this->project('feature', facade: InstallerFeatureHilos::class);
        $this->change(self::EARLY, 'user');
        file_put_contents($this->schema->unit('shared')->upPath, 'Uncataloged stub change');
        $outcome = $this->installer()->execute($core, $feature);
        $this->assertTrue($outcome->hasFailures());
        $this->assertNull($outcome->projects[0]->failure);
        $this->assertStringContainsString('stub drift', $outcome->projects[1]->failure->getMessage());
        $this->assertSame([[], []], array_column($outcome->projects, 'publishedPaths'));
        $this->assertCount(2, $this->sqlFiles($core));
    }

    public function testInvalidTargetAbortsEveryTargetBeforeFirstSqlPublication(): void
    {
        $good = $this->project('good');
        $bad = $this->project('unknown', initialize: false);
        file_put_contents($bad->root . '/050_existing.sql', 'Unverified external history');
        $this->change(self::EARLY, 'user');
        $before = $this->sqlFiles($good);
        $outcome = $this->installer()->execute($good, $bad);
        $this->assertTrue($outcome->hasFailures());
        $this->assertSame($before, $this->sqlFiles($good));
        $this->assertSame([[], []], array_column($outcome->projects, 'publishedPaths'));
        $this->assertSame([false, false], array_column($outcome->projects, 'attempted'));
        $this->assertNull($outcome->projects[0]->failure);
        $this->assertSame(
            'This project has no framework catalog origin; only projects initialized for this catalog are supported',
            $outcome->projects[1]->failure->getMessage(),
        );
        $this->assertSame('Unverified external history', file_get_contents($bad->root . '/050_existing.sql'));
    }

    #[DataProvider('directions')]
    public function testExactHalfPairIsResumedUnderTheSameNumberDespiteHigherLegacyNumbers(string $direction): void
    {
        $project = $this->project('resume');
        $this->change(self::EARLY, 'user');
        $installer = $this->installer();
        $publication = $installer->plan($project)[0]->publications[0];
        $existingPath = $direction === 'down' ? $publication->downPath : $publication->upPath;
        $existingSql = $direction === 'down' ? $publication->pair->downSql : $publication->pair->upSql;
        $missingPath = $direction === 'down' ? $publication->upPath : $publication->downPath;
        file_put_contents($existingPath, $existingSql);
        file_put_contents($project->root . '/100_later_legacy_down.sql', 'reserved');
        $outcome = $installer->execute($project);
        $this->assertFalse($outcome->hasFailures());
        $this->assertSame([$missingPath], $outcome->projects[0]->publishedPaths);
        $this->assertSame($existingSql, file_get_contents($existingPath));
        $this->assertSame(2, $publication->index);
        $this->assertSame([], $installer->plan($project)[0]->publications);
        $this->assertCount(5, $this->sqlFiles($project));
    }

    /** @return list<array{string}> Both possible interruption directions */
    public static function directions(): array
    {
        return [['up'], ['down']];
    }

    #[DataProvider('damagedHalves')]
    public function testUnknownDamagedOrForeignHalfPairsAreNeverGuessedOrOverwritten(string $case): void
    {
        $project = $this->project('damaged');
        $this->change(self::EARLY, 'user');
        $installer = $this->installer();
        $publication = $installer->plan($project)[0]->publications[0];
        $path = $publication->downPath;
        $sql = $publication->pair->downSql;
        switch ($case) {
            case 'body': $sql .= 'tampered'; break;
            case 'header': $sql = str_replace('"format":1', '"format":7', $sql); break;
            case 'no-header': $sql = 'foreign body'; break;
            case 'filename': $path = $project->root . '/002_foreign_down.sql'; break;
            case 'ambiguous': file_put_contents($project->root . '/003_' . $publication->pair->name . '_down.sql', $sql); break;
        }
        file_put_contents($path, $sql);
        $before = $this->sqlFiles($project);
        $outcome = $installer->execute($project);
        $this->assertTrue($outcome->hasFailures());
        $this->assertSame([], $outcome->projects[0]->publishedPaths);
        $this->assertSame($before, $this->sqlFiles($project));
        $this->assertSame($sql, file_get_contents($path));
    }

    /** @return list<array{string}> Independent provenance, body, filename and ambiguity failures */
    public static function damagedHalves(): array
    {
        return [['body'], ['header'], ['no-header'], ['filename'], ['ambiguous']];
    }

    public function testResumingBelowAMissingPrerequisiteIsRefusedWithoutCreatingAnUnusableTrack(): void
    {
        $project = $this->project('inverted-half');
        $this->change(self::EARLY, 'user');
        $this->change(self::LATE, 'session', [[self::EARLY, 'user']]);
        $installer = $this->installer();
        $publication = $installer->plan($project)[0]->publications[1];
        file_put_contents($publication->downPath, $publication->pair->downSql);
        $before = $this->sqlFiles($project);
        $outcome = $installer->execute($project);
        $this->assertTrue($outcome->hasFailures());
        $this->assertStringContainsString('would precede required part', $outcome->projects[0]->failure->getMessage());
        $this->assertSame([], $outcome->projects[0]->publishedPaths);
        $this->assertSame($before, $this->sqlFiles($project));
    }

    public function testCompleteTamperedPairAndDuplicateNumbersAbortTheBatchWithoutWrites(): void
    {
        $good = $this->project('good');
        $bad = $this->project('bad');
        file_put_contents($bad->root . '/001_adopt_framework_catalog_down.sql', 'foreign body');
        $this->change(self::EARLY, 'user');
        $outcome = $this->installer()->execute($good, $bad);
        $this->assertTrue($outcome->hasFailures());
        $this->assertInstanceOf(DatabaseException::class, $outcome->projects[1]->failure);
        $this->assertCount(2, $this->sqlFiles($good));
        $duplicate = $this->project('duplicate');
        file_put_contents($duplicate->root . '/001_other.sql', 'foreign duplicate');
        $outcome = $this->installer()->execute($good, $duplicate);
        $this->assertTrue($outcome->hasFailures());
        $this->assertStringContainsString('duplicate migration numbers', $outcome->projects[1]->failure->getMessage());
        $this->assertCount(2, $this->sqlFiles($good));
    }

    public function testGeneratedPairWithBothOriginHeadersRemovedIsRefusedInsteadOfTreatedAsLegacy(): void
    {
        $project = $this->project('removed-headers');
        $this->change(self::EARLY, 'user');
        $installer = $this->installer();
        $publication = $installer->plan($project)[0]->publications[0];
        file_put_contents($publication->upPath, $this->body($publication->pair->upSql));
        file_put_contents($publication->downPath, $this->body($publication->pair->downSql));
        $before = $this->sqlFiles($project);
        $outcome = $installer->execute($project);
        $this->assertTrue($outcome->hasFailures());
        $this->assertSame($before, $this->sqlFiles($project));
        $this->assertSame([], $outcome->projects[0]->publishedPaths);
    }

    public function testEveryFailedPreflightIsReportedAndPhysicalTargetAliasesAreRefused(): void
    {
        $one = $this->project('one', initialize: false);
        $two = $this->project('two', initialize: false);
        $outcome = $this->installer()->execute($one, $two);
        $this->assertTrue($outcome->hasFailures());
        $this->assertInstanceOf(DatabaseException::class, $outcome->projects[0]->failure);
        $this->assertInstanceOf(DatabaseException::class, $outcome->projects[1]->failure);
        $good = $this->project('good');
        symlink($good->root, $this->root . '/alias');
        $alias = new FrameworkMigrationProject($this->root . '/alias', InstallerCoreHilos::class);
        $this->assertSame($good->root, $alias->root);
        $outcome = $this->installer()->execute($good, $alias);
        $this->assertTrue($outcome->hasFailures());
        $this->assertStringContainsString('Duplicate framework migration target', $outcome->projects[1]->failure->getMessage());
    }

    public function testNumbersAndOriginsAreRecheckedAfterBatchPreflight(): void
    {
        $one = $this->project('one');
        $two = $this->project('two');
        $three = $this->project('three');
        $this->change(self::EARLY, 'user');
        $thirdPair = $this->installer()->plan($three)[0]->publications[0]->pair;
        $changed = false;
        $installer = $this->installer(function (string $tmp, string $final) use ($one, $two, $three, $thirdPair, &$changed): void {
            if (!$changed && dirname($final) === $one->root) {
                $changed = true;
                file_put_contents($two->root . '/080_new_legacy_down.sql', 'reserved after preflight');
                $this->seedPair($three, 90, $thirdPair);
            }
            FsPath::publishExclusive($tmp, $final);
        });
        $outcome = $installer->execute($one, $two, $three);
        $this->assertFalse($outcome->hasFailures());
        $this->assertStringContainsString('/081_', $outcome->projects[1]->publishedPaths[0]);
        $this->assertSame([], $outcome->projects[2]->publishedPaths);
    }

    public function testOriginCorruptionAfterPreflightIsReportedWithoutRollingBackAnotherTarget(): void
    {
        $one = $this->project('one');
        $two = $this->project('two');
        $this->change(self::EARLY, 'user');
        $installer = $this->installer(static function (string $tmp, string $final) use ($two): void {
            file_put_contents($two->root . '/001_adopt_framework_catalog_down.sql', 'corrupted after preflight');
            FsPath::publishExclusive($tmp, $final);
        });
        $outcome = $installer->execute($one, $two);
        $this->assertTrue($outcome->hasFailures());
        $this->assertNull($outcome->projects[0]->failure);
        $this->assertCount(2, $outcome->projects[0]->publishedPaths);
        $this->assertInstanceOf(DatabaseException::class, $outcome->projects[1]->failure);
        $this->assertSame([], $outcome->projects[1]->publishedPaths);
    }

    public function testPartialIoFailureReportsCompletedTargetsAndFirstHalfAndRepeatFinishesOnlyMissingUp(): void
    {
        $one = $this->project('one');
        $two = $this->project('two');
        $three = $this->project('three');
        $this->change(self::EARLY, 'user');
        $installer = $this->installer(static function (string $tmp, string $final) use ($two): void {
            if (dirname($final) === $two->root && !str_ends_with($final, '_down.sql')) {
                throw new FileMoveException('Injected up publication failure');
            }
            FsPath::publishExclusive($tmp, $final);
        });
        $outcome = $installer->execute($one, $two, $three);
        $this->assertTrue($outcome->hasFailures());
        $this->assertSame([2, 1, 2], array_map(static fn ($project): int => count($project->publishedPaths), $outcome->projects));
        $this->assertSame([true, true, true], array_column($outcome->projects, 'attempted'));
        $this->assertNull($outcome->projects[0]->failure);
        $this->assertInstanceOf(FileMoveException::class, $outcome->projects[1]->failure);
        $this->assertNull($outcome->projects[2]->failure);
        $downPath = $outcome->projects[1]->publishedPaths[0];
        $this->assertStringEndsWith('_down.sql', $downPath);
        $repeat = $this->installer()->execute($one, $two, $three);
        $this->assertFalse($repeat->hasFailures());
        $this->assertSame([0, 1, 0], array_map(static fn ($project): int => count($project->publishedPaths), $repeat->projects));
        $this->assertSame(substr($downPath, 0, -strlen('_down.sql')) . '.sql', $repeat->projects[1]->publishedPaths[0]);
        $this->assertSame([], glob($two->root . '/.hilos-framework-*.tmp'));
    }

    public function testCompletedPairIsReportedWhenTheNextPairFails(): void
    {
        $project = $this->project('two-pairs');
        $this->change(self::EARLY, 'user');
        $this->change(self::LATE, 'session', [[self::EARLY, 'user']]);
        $calls = 0;
        $installer = $this->installer(static function (string $tmp, string $final) use (&$calls): void {
            if (++$calls === 3) {
                throw new FileMoveException('Injected second pair failure');
            }
            FsPath::publishExclusive($tmp, $final);
        });
        $outcome = $installer->execute($project);
        $this->assertTrue($outcome->hasFailures());
        $this->assertCount(2, $outcome->projects[0]->publishedPaths);
        $repeat = $this->installer()->execute($project);
        $this->assertFalse($repeat->hasFailures());
        $this->assertCount(2, $repeat->projects[0]->publishedPaths);
        $this->assertStringContainsString('/003_', $repeat->projects[0]->publishedPaths[0]);
    }

    public function testLinkSuccessFollowedByTempCleanupFailureStillReportsThePublishedHalf(): void
    {
        $project = $this->project('cleanup-failure');
        $this->change(self::EARLY, 'user');
        $installer = $this->installer(static function (string $tmp, string $final): void {
            link($tmp, $final);
            throw new FileMoveException('File published, but temp removal failed');
        });
        $outcome = $installer->execute($project);
        $this->assertTrue($outcome->hasFailures());
        $this->assertCount(1, $outcome->projects[0]->publishedPaths);
        $this->assertFileExists($outcome->projects[0]->publishedPaths[0]);
        $this->assertSame([], glob($project->root . '/.hilos-framework-*.tmp'));
        $repeat = $this->installer()->execute($project);
        $this->assertFalse($repeat->hasFailures());
        $this->assertCount(1, $repeat->projects[0]->publishedPaths);
    }

    #[DataProvider('competitors')]
    public function testCompetingFilesArePreservedAndNeverReportedAsOurPublications(string $case): void
    {
        $project = $this->project('conflict');
        $this->change(self::EARLY, 'user');
        $foreign = $this->root . '/foreign.sql';
        file_put_contents($foreign, 'foreign original');
        $finalPath = null;
        $installer = $this->installer(static function (string $tmp, string $final) use ($case, $foreign, &$finalPath): void {
            $finalPath = $final;
            switch ($case) {
                case 'same': copy($tmp, $final); break;
                case 'different': file_put_contents($final, 'foreign SQL'); break;
                case 'symlink': symlink($foreign, $final); break;
                case 'directory': mkdir($final); break;
            }
            FsPath::publishExclusive($tmp, $final);
        });
        $outcome = $installer->execute($project);
        $this->assertTrue($outcome->hasFailures());
        $this->assertInstanceOf(FileAlreadyExistsException::class, $outcome->projects[0]->failure);
        $this->assertSame([], $outcome->projects[0]->publishedPaths);
        $this->assertSame('foreign original', file_get_contents($foreign));
        if ($case === 'different') {
            $this->assertSame('foreign SQL', file_get_contents($finalPath));
        } elseif ($case === 'directory') {
            $this->assertDirectoryExists($finalPath);
        } elseif ($case === 'symlink') {
            $this->assertTrue(is_link($finalPath));
        } else {
            $repeat = $this->installer()->execute($project);
            $this->assertFalse($repeat->hasFailures());
            $this->assertCount(1, $repeat->projects[0]->publishedPaths);
        }
        $this->assertSame([], glob($project->root . '/.hilos-framework-*.tmp'));
    }

    /** @return list<array{string}> Occupied destinations the writer must preserve */
    public static function competitors(): array
    {
        return [['same'], ['different'], ['symlink'], ['directory']];
    }

    public function testPublicationUsesCompleteSameDirectoryTempsAndHoldsTheTrackLockDownBeforeUp(): void
    {
        $project = $this->project('locked');
        $this->change(self::EARLY, 'user');
        $directions = [];
        $installer = $this->installer(function (string $tmp, string $final) use ($project, &$directions): void {
            $this->assertSame(dirname($final), dirname($tmp));
            $this->assertStringStartsWith(FrameworkMigrationOrigin::PREFIX, file_get_contents($tmp));
            $probe = fopen($project->root . '/' . FrameworkMigrationInstaller::LOCK_FILE, 'c');
            try {
                $this->assertFalse(flock($probe, LOCK_EX | LOCK_NB), 'Publication must hold the exclusive source lock');
            } finally {
                fclose($probe);
            }
            $directions[] = str_ends_with($final, '_down.sql') ? 'down' : 'up';
            FsPath::publishExclusive($tmp, $final);
        });
        $this->assertFalse($installer->execute($project)->hasFailures());
        $this->assertSame(['down', 'up'], $directions);
    }

    public function testReviewedArtifactsUseTheSameResumableWriterWithoutInferringLegacySchema(): void
    {
        $project = $this->project('artifact', 1, initialize: false);
        file_put_contents($project->root . '/012_reviewed_history.sql', 'Already verified by the repository artifact');
        $pair = $this->pair('adopt_framework_catalog', 'adopt', ['migration', 'user', 'session']);
        $installer = $this->installer();
        $plan = $installer->planSources($project, $pair);
        $publication = $plan->publications[0];
        $this->assertSame(13, $publication->index);
        file_put_contents($publication->downPath, $pair->downSql);
        $outcome = $installer->publish($plan);
        $this->assertFalse($outcome->hasFailures());
        $this->assertSame([$publication->upPath], $outcome->projects[0]->publishedPaths);
        $this->assertSame([], $installer->publish($plan)->projects[0]->publishedPaths);
        $this->assertSame([], $installer->execute($project)->projects[0]->publishedPaths);
    }

    public function testReviewedAdoptionAndTwoInstallArtifactsUseOneBatchWithExactCoverage(): void
    {
        $project = $this->project('artifact-batch', facade: InstallerFeatureHilos::class, initialize: false);
        $this->change(self::EARLY, 'shared');
        $catalog = new FrameworkMigrationCatalog($this->schema, $this->root . '/source');
        $installer = $this->installer();
        $adopt = $this->pair('adopt_framework_catalog', 'adopt', ['migration', 'user']);
        $session = $this->schema->unit('session');
        $shared = $this->schema->unit('shared');
        $sessionUp = file_get_contents($session->upPath);
        $sessionDown = file_get_contents($session->downPath);
        $sharedUp = file_get_contents($shared->upPath);
        $sharedDown = file_get_contents($shared->downPath);
        $installSession = new FrameworkMigrationPair(
            'framework_install_session',
            FrameworkMigrationOrigin::forBodies('install', ['session'], [], $sessionUp, $sessionDown),
            $sessionUp,
            $sessionDown,
        );
        $installShared = new FrameworkMigrationPair(
            'framework_install_shared',
            FrameworkMigrationOrigin::forBodies('install', ['shared'], [$catalog->part(self::EARLY, 'shared')], $sharedUp, $sharedDown),
            $sharedUp,
            $sharedDown,
        );
        $plan = $installer->planSources($project, $adopt, $installSession, $installShared);
        $this->assertSame('adopt', $plan->publications[0]->pair->origin->kind);
        $outcome = $installer->publish($plan);
        $this->assertFalse($outcome->hasFailures());
        $this->assertCount(6, $outcome->projects[0]->publishedPaths);
        $this->assertSame([], $installer->plan($project)[0]->publications);
        $this->assertSame([], $installer->publish($plan)->projects[0]->publishedPaths);
    }

    /**
     * A project takes a feature's table before it declares the feature, as chat takes `hilos_moderation_rule`
     * for its seed (HIL-1624): the reviewed pair is the one the ordinary pull would write, and that pull,
     * once the feature is on, knows the unit installed and writes no second installation.
     */
    public function testExplicitInstallWhileTheFeatureIsOffIsTheInstallTheOrdinaryPullKnowsOnceItIsOn(): void
    {
        $this->change(self::EARLY, 'shared');
        $catalog = new FrameworkMigrationCatalog($this->schema, $this->root . '/source');
        $installer = $this->installer();
        $off = $this->project('feature-off');
        $this->assertSame([], $installer->plan($off)[0]->publications, 'The ordinary pull selects no feature unit while the feature is off');
        $shared = $this->schema->unit('shared');
        $up = file_get_contents($shared->upPath);
        $down = file_get_contents($shared->downPath);
        $pair = new FrameworkMigrationPair(
            'framework_install_shared',
            FrameworkMigrationOrigin::forBodies('install', ['shared'], $catalog->partsForUnits(['shared']), $up, $down),
            $up,
            $down,
        );
        $pulled = $installer->plan($this->project('feature-on', facade: InstallerFeatureHilos::class))[0]->publications;
        $this->assertCount(1, $pulled);
        $this->assertSame($pulled[0]->pair->name, $pair->name);
        $this->assertSame($pulled[0]->pair->upSql, $pair->upSql);
        $this->assertSame($pulled[0]->pair->downSql, $pair->downSql);

        $plan = $installer->planSources($off, $pair);
        $this->assertSame(2, $plan->publications[0]->index);
        $this->assertFalse($installer->publish($plan)->hasFailures());
        $this->assertSame([], $installer->planSources($off, $pair)->publications, 'The same origin again is already delivered');
        $this->assertSame([], $installer->publish($plan)->projects[0]->publishedPaths);

        $on = new FrameworkMigrationProject($off->root, InstallerFeatureHilos::class);
        $this->assertSame([], $installer->plan($on)[0]->publications);
        $outcome = $installer->execute($on);
        $this->assertFalse($outcome->hasFailures());
        $this->assertSame([], $outcome->projects[0]->publishedPaths);
        $this->assertSame([
            '001_adopt_framework_catalog.sql', '001_adopt_framework_catalog_down.sql',
            '002_framework_install_shared.sql', '002_framework_install_shared_down.sql',
        ], $this->sqlFiles($on));
    }

    public function testExplicitPlanPathsAreRebuiltAndAllArtifactTargetsArePreflighted(): void
    {
        $one = $this->project('one', initialize: false);
        $two = $this->project('two', initialize: false);
        $pair = $this->pair('adopt_framework_catalog', 'adopt', ['user', 'session']);
        $plan = new FrameworkMigrationPlan($one, [new FrameworkMigrationPublication($pair, 900, '/foreign/up.sql', '/foreign/down.sql', true, true)]);
        $outcome = $this->installer()->publish($plan);
        $this->assertFalse($outcome->hasFailures());
        $this->assertSame([
            $one->root . '/001_adopt_framework_catalog_down.sql',
            $one->root . '/001_adopt_framework_catalog.sql',
        ], $outcome->projects[0]->publishedPaths);
        $bad = new FrameworkMigrationPlan($two, [
            new FrameworkMigrationPublication($pair, 1, 'ignored', 'ignored'),
            new FrameworkMigrationPublication($pair, 2, 'ignored', 'ignored'),
        ]);
        $three = $this->project('three', initialize: false);
        $thirdPlan = $this->installer()->planSources($three, $pair);
        $this->assertTrue($this->installer()->publish($thirdPlan, $bad)->hasFailures());
        $this->assertSame([], $this->sqlFiles($two));
        $this->assertSame([], $this->sqlFiles($three));
    }

    /**
     * @param ?Closure $publisher Optional fault injection at the publication seam
     * @return FrameworkMigrationInstaller Source-only installer for this temporary catalog
     */
    private function installer(?Closure $publisher = null): FrameworkMigrationInstaller
    {
        $catalog = new FrameworkMigrationCatalog($this->schema, $this->root . '/source');
        if ($publisher === null) {
            return new FrameworkMigrationInstaller($this->schema, $catalog);
        }

        return new class ($this->schema, $catalog, $publisher) extends FrameworkMigrationInstaller {
            /**
             * @param FrameworkSchemaCatalog $schema Test schema
             * @param FrameworkMigrationCatalog $catalog Test source catalog
             * @param Closure $publisher Injected publication behavior
             * @throws DatabaseException When catalog initialization fails
             */
            public function __construct(FrameworkSchemaCatalog $schema, FrameworkMigrationCatalog $catalog, private readonly Closure $publisher)
            {
                parent::__construct($schema, $catalog);
            }

            /**
             * @param string $tmpPath Complete temporary source
             * @param string $finalPath Rechecked final path
             */
            protected function publishFile(string $tmpPath, string $finalPath): void
            {
                ($this->publisher)($tmpPath, $finalPath);
            }
        };
    }

    /**
     * @param string $name Temporary target name
     * @param int $index Adoption artifact index
     * @param class-string<Hilos> $facade Explicit feature declaration
     * @param bool $initialize Whether to seed reviewed core provenance
     * @return FrameworkMigrationProject Source target independent of application globals
     */
    private function project(
        string $name,
        int $index = 1,
        string $facade = InstallerCoreHilos::class,
        bool $initialize = true,
    ): FrameworkMigrationProject {
        mkdir($this->root . '/' . $name);
        $project = new FrameworkMigrationProject($this->root . '/' . $name, $facade);
        if ($initialize) {
            $this->seedPair($project, $index, $this->pair('adopt_framework_catalog', 'adopt', ['migration', 'user', 'session']));
        }

        return $project;
    }

    /**
     * @param string $name Source filename stem
     * @param string $kind Origin kind
     * @param list<string> $units Explicit verified unit set
     * @return FrameworkMigrationPair No-op provenance fixture
     */
    private function pair(string $name, string $kind, array $units): FrameworkMigrationPair
    {
        $up = "-- Verified genesis.\n";
        $down = "-- No schema changes.\n";

        return new FrameworkMigrationPair($name, FrameworkMigrationOrigin::forBodies($kind, $units, [], $up, $down), $up, $down);
    }

    private function seedPair(FrameworkMigrationProject $project, int $index, FrameworkMigrationPair $pair): void
    {
        $base = $project->root . '/' . str_pad((string)$index, 3, '0', STR_PAD_LEFT) . '_' . $pair->name;
        file_put_contents($base . '.sql', $pair->upSql);
        file_put_contents($base . '_down.sql', $pair->downSql);
    }

    private function sqlFiles(FrameworkMigrationProject $project): array
    {
        return array_map('basename', glob($project->root . '/*.sql'));
    }

    private function body(string $sql): string
    {
        return substr($sql, strpos($sql, "\n") + 1);
    }

    /**
     * @param string $id Immutable change id
     * @param string $key Schema unit key
     * @param list<array{string, string}> $requires Exact dependency references at the JSON boundary
     */
    private function change(string $id, string $key, array $requires = []): void
    {
        $directory = $this->root . '/source/Change/' . $id;
        mkdir($directory);
        file_put_contents($directory . '/up.sql', "ALTER {$key} {$id};\n");
        file_put_contents($directory . '/down.sql', "UNDO {$key} {$id};\n");
        $unit = $this->schema->unit($key);
        if (!$unit->bootstrapOwned) {
            file_put_contents($unit->upPath, "CURRENT {$key} {$id};\n");
            file_put_contents($unit->downPath, "CURRENT DOWN {$key} {$id};\n");
        }
        $this->json($directory . '/change.json', [
            'format' => 1,
            'id' => $id,
            'parts' => [[
                'unit' => $key,
                'up' => 'up.sql',
                'down' => 'down.sql',
                'requires' => array_map(static fn (array $part): array => ['changeId' => $part[0], 'unit' => $part[1]], $requires),
                'afterStubUpSha256' => $unit->bootstrapOwned ? null : hash('sha256', file_get_contents($unit->upPath)),
                'afterStubDownSha256' => $unit->bootstrapOwned ? null : hash('sha256', file_get_contents($unit->downPath)),
            ]],
        ]);
    }

    private function json(string $path, array $data): void
    {
        file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));
    }
}

final class InstallerSchema extends FrameworkSchemaCatalog
{
    /**
     * @param string $root Temporary framework source root
     * @throws DatabaseException When unit metadata is inconsistent
     */
    public function __construct(private readonly string $root)
    {
        parent::__construct(new class extends FeatureRegistry {
            /** @return list<FeatureDefinition> Only the synthetic feature this schema carries */
            protected function buildDefinitions(): array
            {
                return [new class extends FeatureDefinition {
                    /** @return HilosFeature Feature using the optional shared unit */
                    public function feature(): HilosFeature { return HilosFeature::AUTH; }
                    /** @return FeatureRequirements Source schema selection only */
                    public function requirements(): FeatureRequirements { return new FeatureRequirements(requiredDbTables: ['hilos_shared']); }
                }];
            }
        });
    }

    /**
     * @return list<FrameworkSchemaUnit> Core units and the optional feature unit
     * @throws DatabaseException When unit metadata is inconsistent
     */
    protected function buildUnits(): array
    {
        $units = [new FrameworkSchemaUnit('migration', ['migration'], null, null, bootstrapOwned: true)];
        foreach (['user', 'session', 'shared'] as $key) {
            $units[] = new FrameworkSchemaUnit(
                $key,
                ['hilos_' . $key],
                $this->root . '/Stub/' . $key . '.sql',
                $this->root . '/Stub/' . $key . '_down.sql',
                $key === 'session' ? ['user'] : [],
            );
        }

        return $units;
    }
}

abstract class InstallerCoreHilos extends Hilos
{
    /** @return ?RtContext No runtime tables in these source-only targets */
    protected static function createRuntime(): ?RtContext { return null; }
}

abstract class InstallerFeatureHilos extends InstallerCoreHilos
{
    protected const array FEATURES = [HilosFeature::AUTH];
}
