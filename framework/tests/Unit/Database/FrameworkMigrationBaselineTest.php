<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database;

use Closure;
use FilesystemIterator;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\DatabaseException;
use Hilos\Database\Migration\FrameworkMigrationCatalog;
use Hilos\Database\Migration\FrameworkMigrationInstaller;
use Hilos\Database\Migration\FrameworkMigrationOrigin;
use Hilos\Database\Migration\FrameworkMigrationPair;
use Hilos\Database\Migration\FrameworkMigrationPart;
use Hilos\Database\Migration\FrameworkMigrationProject;
use Hilos\Database\Migration\FrameworkMigrationPublication;
use Hilos\Database\Migration\FrameworkSchemaCatalog;
use Hilos\Database\Migration\FrameworkSchemaUnit;
use Hilos\Fs\Exception\FileAlreadyExistsException;
use Hilos\Fs\Exception\FileMoveException;
use Hilos\Fs\FsException;
use Hilos\Fs\FsPath;
use Hilos\Hilos;
use Hilos\Runtime\View\Context\RtContext;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** Temporary copies of real source metadata and SQL; no database, runtime initialization or second process. */
final class FrameworkMigrationBaselineTest extends TestCase
{
    private const string TIME_ZONE = '20261021021039718057_HIL-1580_add_user_time_zone';
    private const string BROWSER_TIME_ZONES = '20261022124515804554_HIL-1582_browser_time_zones';
    private const string EARLY = '20261019120000000000_HIL-1519_early';
    private const string LATE = '20261022120000000000_HIL-1519_late';

    private string $root;
    private FrameworkSchemaCatalog $schema;

    /** Copy the real sources so each test can evolve them without changing the repository. */
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/hilos-baseline-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/source/Stub', 0775, true);
        mkdir($this->root . '/source/Change');
        $source = dirname(__DIR__, 3) . '/backend/Database/Migration';
        copy($source . '/catalog.json', $this->root . '/source/catalog.json');
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source . '/Change', FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        ) as $entry) {
            $destination = $this->root . '/source/Change/' . substr($entry->getPathname(), strlen($source . '/Change/'));
            if ($entry->isDir()) {
                mkdir($destination);
            } else {
                copy($entry->getPathname(), $destination);
            }
        }
        foreach (new FrameworkSchemaCatalog()->allUnits() as $unit) {
            if (!$unit->bootstrapOwned) {
                copy($unit->upPath, $this->root . '/source/Stub/' . basename($unit->upPath));
                copy($unit->downPath, $this->root . '/source/Stub/' . basename($unit->downPath));
            }
        }
        $this->schema = new BaselineSourceSchema($this->root . '/source');
    }

    /** Remove the complete temporary tree, including injected competing entries. */
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

    public function testCoreProducesOneDeterministicCurrentPairWithExactPartsAndNoBootstrapDdl(): void
    {
        $one = $this->project('one');
        $two = $this->project('two');
        file_put_contents($one->root . '/.gitkeep', '');
        file_put_contents($one->root . '/.hilos-framework-old.tmp', 'service temp');
        $installer = $this->installer();
        $plans = $installer->planBaseline($one, $two);
        $this->assertSame([], $this->sqlSnapshot($one));
        $this->assertCount(2, $plans);
        $this->assertCount(1, $plans[0]->publications);
        $publication = $plans[0]->publications[0];
        $pair = $publication->pair;
        $this->assertSame(1, $publication->index);
        $this->assertSame($one->root . '/001_framework_baseline.sql', $publication->upPath);
        $this->assertSame($one->root . '/001_framework_baseline_down.sql', $publication->downPath);
        $this->assertSame($pair->upSql, $plans[1]->publications[0]->pair->upSql);
        $this->assertSame($pair->downSql, $plans[1]->publications[0]->pair->downSql);
        $this->assertSame('baseline', $pair->origin->kind);
        $this->assertSame(
            ['admin_view_mode_latch', 'database_marker', 'migration', 'migration_claim', 'session', 'user'],
            $pair->origin->units,
        );
        $this->assertSame(
            [self::TIME_ZONE . '/user', self::BROWSER_TIME_ZONES . '/session', self::BROWSER_TIME_ZONES . '/user'],
            array_map(static fn (FrameworkMigrationPart $part): string => $part->key(), $pair->origin->parts),
        );
        $up = $this->stub('user') . "\n" . $this->stub('session');
        $down = $this->stub('session', down: true) . "\n" . $this->stub('user', down: true);
        $this->assertSame($up, $this->body($pair->upSql));
        $this->assertSame($down, $this->body($pair->downSql));
        $this->assertSame(hash('sha256', $up), $pair->origin->upSha256);
        $this->assertSame(hash('sha256', $down), $pair->origin->downSha256);
        $header = json_decode(substr(strtok($pair->upSql, "\n"), strlen(FrameworkMigrationOrigin::PREFIX)), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['format', 'kind', 'units', 'parts', 'upSha256', 'downSha256'], array_keys($header));
        $this->assertSame(1, $header['format']);
        $this->assertSame($pair->origin->toArray(), $header);
        $this->assertSame($pair->origin->header(), strtok($pair->downSql, "\n"));
        $this->assertSame($pair->origin->toArray(), FrameworkMigrationOrigin::parsePair($pair->upSql, $pair->downSql)->toArray());
        $this->assertSame(1, substr_count($up, '`time_zone`'));
        $this->assertSame(1, substr_count($up, '`browser_time_zone` VARCHAR(64) NULL DEFAULT NULL'));
        $this->assertSame(1, substr_count($up, '`last_browser_time_zone` VARCHAR(64) NULL DEFAULT NULL'));
        $this->assertStringNotContainsString('ALTER TABLE `hilos_user`', $up);
        $this->assertStringNotContainsString('ALTER TABLE `hilos_session`', $up);
        foreach (['migration', 'hilos_migration_claim', 'hilos_admin_view_mode_latch', 'hilos_database_marker'] as $table) {
            $this->assertStringNotContainsString('`' . $table . '`', $up . $down);
        }
        $outcome = $installer->executeBaseline($one, $two);
        $this->assertFalse($outcome->hasFailures());
        $this->assertSame([$publication->downPath, $publication->upPath], $outcome->projects[0]->publishedPaths);
        $this->assertSame(['001_framework_baseline.sql', '001_framework_baseline_down.sql'], array_keys($this->sqlSnapshot($one)));
        $this->assertSame($pair->upSql, file_get_contents($publication->upPath));
        $this->assertSame($pair->downSql, file_get_contents($publication->downPath));
        $this->assertSame('service temp', file_get_contents($one->root . '/.hilos-framework-old.tmp'));
    }

    public function testRuntimeCircleIsComposedWithoutBackupOrFacadeInitialization(): void
    {
        $project = $this->project('runtime', BaselineRuntimeHilos::class);
        $before = [Hilos::$rt, Hilos::$db, Hilos::$env, Hilos::appClass()];
        $pair = $this->installer()->planBaseline($project)[0]->publications[0]->pair;
        $this->assertSame(
            $this->stub('user') . "\n" . $this->stub('session') . "\n" . $this->stub('verifier_circle'),
            $this->body($pair->upSql),
        );
        $this->assertSame(
            $this->stub('verifier_circle', true) . "\n" . $this->stub('session', true) . "\n" . $this->stub('user', true),
            $this->body($pair->downSql),
        );
        $this->assertContains('verifier_circle', $pair->origin->units);
        $this->assertSame([], Hilos::featuresOf(BaselineRuntimeHilos::class));
        $this->assertSame($before, [Hilos::$rt, Hilos::$db, Hilos::$env, Hilos::appClass()]);
    }

    public function testAuthAndCodeChannelsComposeTheirSharedUnitExactlyOnce(): void
    {
        $pair = $this->installer()->planBaseline($this->project('auth', BaselineAuthCodesHilos::class))[0]->publications[0]->pair;
        $this->assertCount(24, $pair->origin->units);
        $this->assertSame(1, count(array_keys($pair->origin->units, 'user_verification', true)));
        $this->assertSame(1, substr_count($pair->upSql, $this->stub('user_verification')));
        $this->assertSame(1, substr_count($pair->downSql, $this->stub('user_verification', true)));
        foreach (['identity', 'passkey_credential', 'oauth_provider', 'legal_acceptance_export'] as $unit) {
            $this->assertContains($unit, $pair->origin->units);
            $this->assertStringContainsString($this->stub($unit), $pair->upSql);
        }
        $this->assertNotContains('verifier_circle', $pair->origin->units);
    }

    public function testI18nUsesDependencyOrderAndTheExactReverseForDown(): void
    {
        $pair = $this->installer()->planBaseline($this->project('i18n', BaselineI18nHilos::class))[0]->publications[0]->pair;
        $order = ['user', 'session', 'language', 'country', 'locale', 'language_name', 'country_name', 'i18n_reflow'];
        $this->assertSame(implode("\n", array_map(fn (string $unit): string => $this->stub($unit), $order)), $this->body($pair->upSql));
        $this->assertSame(
            implode("\n", array_map(fn (string $unit): string => $this->stub($unit, true), array_reverse($order))),
            $this->body($pair->downSql),
        );
        $this->assertStringContainsString('ALTER TABLE `hilos_country`', $pair->upSql);
        $this->assertStringContainsString('DROP FOREIGN KEY IF EXISTS `fk_country_default_locale`', $pair->downSql);
    }

    public function testAnalyticsAndJournalKeepWholeAggregatesTokensAndExistingDownRefusalsInOnePair(): void
    {
        $project = $this->project('aggregates', BaselineAggregatesHilos::class);
        $pair = $this->installer()->planBaseline($project)[0]->publications[0]->pair;
        $this->assertSame(
            $this->stub('user') . "\n" . $this->stub('session') . "\n" . $this->stub('analytics') . "\n" . $this->stub('change_log'),
            $this->body($pair->upSql),
        );
        $this->assertSame(
            implode("\n", [$this->stub('change_log', true), $this->stub('analytics', true), $this->stub('session', true), $this->stub('user', true)]),
            $this->body($pair->downSql),
        );
        $this->assertSame(1, $this->schema->unit('change_log')->connection);
        $this->assertSame(28, substr_count($this->stub('analytics'), 'CREATE TABLE'));
        $this->assertSame(6, substr_count($this->stub('change_log'), 'CREATE TABLE'));
        $this->assertStringContainsString('{{change_log_database}}', $pair->upSql);
        $this->assertStringContainsString('DELIMITER $$', $pair->downSql);
        $this->assertStringContainsString("SIGNAL SQLSTATE '45000'", $pair->downSql);
        $this->assertStringContainsString('DROP TABLE IF EXISTS {{change_log_database}}.', $pair->downSql);
        $this->assertFalse($this->installer()->executeBaseline($project)->hasFailures());
        $this->assertCount(2, $this->sqlSnapshot($project));
    }

    public function testCanonicalLfDoesNotTrimOrRewriteFragmentBytes(): void
    {
        $expected = $this->installer()->planBaseline($this->project('canonical'))[0]->publications[0]->pair;
        foreach (['user', 'session'] as $key) {
            $unit = $this->schema->unit($key);
            file_put_contents($unit->upPath, str_replace("\n", "\r\n", file_get_contents($unit->upPath)));
            file_put_contents($unit->downPath, str_replace("\n", "\r", file_get_contents($unit->downPath)));
        }
        $pair = $this->installer()->planBaseline($this->project('crlf'))[0]->publications[0]->pair;
        $this->assertSame($expected->upSql, $pair->upSql);
        $this->assertSame($expected->downSql, $pair->downSql);
        $this->assertStringNotContainsString("\r", $pair->upSql . $pair->downSql);
    }

    public function testHistoricalBaselineSkipsAfterStubDriftAndFeatureChangesWithoutRewritingBytes(): void
    {
        $project = $this->project('historical');
        $this->assertFalse($this->installer()->executeBaseline($project)->hasFailures());
        $before = $this->sqlSnapshot($project);
        file_put_contents($this->schema->unit('user')->upPath, 'An uncataloged future stub');
        $expanded = new FrameworkMigrationProject($project->root, BaselineAuthCodesHilos::class);
        $installer = $this->installer();
        $this->assertSame([], $installer->planBaseline($expanded)[0]->publications);
        $repeat = $installer->executeBaseline($expanded);
        $this->assertFalse($repeat->hasFailures());
        $this->assertSame([], $repeat->projects[0]->publishedPaths);
        $this->assertSame($before, $this->sqlSnapshot($project));
        $this->assertTrue($installer->execute($expanded)->hasFailures(), 'The historical skip does not certify current sources');
    }

    public function testPullAppendsOnlyNewDeltaAndLateFeatureAndBaselineKeepsItsHistoricalComposition(): void
    {
        $project = $this->project('late-feature');
        $this->assertFalse($this->installer()->executeBaseline($project)->hasFailures());
        $baseline = $this->sqlSnapshot($project);
        $this->change(self::LATE, 'user', [[self::BROWSER_TIME_ZONES, 'user']]);
        $expanded = new FrameworkMigrationProject($project->root, BaselineSettingsHilos::class);
        $installer = $this->installer();
        $this->assertSame([], $installer->planBaseline($expanded)[0]->publications);
        $plan = $installer->plan($expanded)[0];
        $this->assertCount(2, $plan->publications);
        $this->assertSame([2, 3], array_column($plan->publications, 'index'));
        $this->assertSame(['install', 'change'], array_map(
            static fn (FrameworkMigrationPublication $publication): string => $publication->pair->origin->kind,
            $plan->publications,
        ));
        $this->assertSame(['setting'], $plan->publications[0]->pair->origin->units);
        $this->assertSame(self::LATE, $plan->publications[1]->pair->origin->parts[0]->changeId);
        $this->assertFalse($installer->execute($expanded)->hasFailures());
        file_put_contents($project->root . '/004_project_table.sql', 'CREATE project_table;');
        file_put_contents($project->root . '/004_project_table_down.sql', 'DROP project_table;');
        $this->assertSame([], $installer->planBaseline($expanded)[0]->publications);
        $this->assertSame([], $installer->plan($expanded)[0]->publications);
        $shrunk = new FrameworkMigrationProject($project->root, BaselineCoreHilos::class);
        $this->assertFalse($installer->executeBaseline($shrunk)->hasFailures());
        $this->assertSame($baseline, array_intersect_key($this->sqlSnapshot($project), $baseline));
    }

    public function testPartAddedLaterWithEarlierIdIsMissingByExactSetAfterBaseline(): void
    {
        $this->change(self::LATE, 'session', [[self::BROWSER_TIME_ZONES, 'session']]);
        $project = $this->project('exact-set');
        $this->assertFalse($this->installer()->executeBaseline($project)->hasFailures());
        $baseline = $this->sqlSnapshot($project);
        $origin = $this->installer()->readTrack($project)[0]->origin;
        $this->assertSame(
            [self::TIME_ZONE, self::LATE, self::BROWSER_TIME_ZONES, self::BROWSER_TIME_ZONES],
            array_column($origin->parts, 'changeId'),
        );
        $this->assertStringNotContainsString('ALTER session', $this->body($baseline['001_framework_baseline.sql']));
        $this->change(self::EARLY, 'user', [[self::BROWSER_TIME_ZONES, 'user']]);
        $installer = $this->installer();
        $this->assertSame([], $installer->planBaseline($project)[0]->publications);
        $plan = $installer->plan($project)[0];
        $this->assertCount(1, $plan->publications);
        $this->assertSame(2, $plan->publications[0]->index);
        $this->assertSame(self::EARLY, $plan->publications[0]->pair->origin->parts[0]->changeId);
        $this->assertSame("ALTER user " . self::EARLY . ";\n", $this->body($plan->publications[0]->pair->upSql));
        $this->assertFalse($installer->execute($project)->hasFailures());
        $this->assertSame([], $installer->plan($project)[0]->publications);
        $this->assertSame($baseline, array_intersect_key($this->sqlSnapshot($project), $baseline));
    }

    #[DataProvider('occupiedTracks')]
    public function testOccupiedOrInvalidTracksAreRefusedWithoutAdoptionAppendOrOverwrite(string $case): void
    {
        $project = $this->project('occupied');
        $baseline = $this->installer()->planBaseline($project)[0]->publications[0]->pair;
        switch ($case) {
            case 'legacy-pair':
                file_put_contents($project->root . '/001_legacy.sql', 'legacy up');
                file_put_contents($project->root . '/001_legacy_down.sql', 'legacy down');
                break;
            case 'project-up': file_put_contents($project->root . '/040_project.sql', 'project up'); break;
            case 'foreign-down': file_put_contents($project->root . '/002_foreign_down.sql', 'foreign down'); break;
            case 'adopt': $this->seedPair($project, 1, $this->pair('adopt_framework_catalog', 'adopt', ['user'])); break;
            case 'install': $this->seedPair($project, 1, $this->pair('framework_install_user', 'install', ['user'])); break;
            case 'change':
                $part = $this->catalog()->part(self::TIME_ZONE, 'user');
                $this->seedPair($project, 1, new FrameworkMigrationPair(
                    'framework_change_user',
                    FrameworkMigrationOrigin::forBodies('change', ['user'], [$part], $part->upSql, $part->downSql),
                    $part->upSql,
                    $part->downSql,
                ));
                break;
            case 'wrong-number': $this->seedPair($project, 2, $baseline); break;
            case 'zero-number': $this->seedPair($project, 0, $baseline); break;
            case 'wrong-name': $this->seedPair($project, 1, $this->pair('other_baseline', 'baseline', ['user'])); break;
            case 'short-number':
                file_put_contents($project->root . '/1_framework_baseline.sql', $baseline->upSql);
                file_put_contents($project->root . '/1_framework_baseline_down.sql', $baseline->downSql);
                break;
            case 'preceded-baseline':
                file_put_contents($project->root . '/000_project.sql', 'earlier');
                $this->seedPair($project, 1, $baseline);
                break;
            case 'two-baselines':
                $this->seedPair($project, 1, $baseline);
                $this->seedPair($project, 2, $this->pair('framework_baseline_other', 'baseline', ['setting']));
                break;
            case 'overlap':
                $this->seedPair($project, 1, $baseline);
                $this->seedPair($project, 2, $this->pair('framework_install_user', 'install', ['user']));
                break;
            case 'duplicate-number':
                $this->seedPair($project, 1, $baseline);
                file_put_contents($project->root . '/001_project.sql', 'duplicate');
                break;
            case 'bad-body':
                $this->seedPair($project, 1, $baseline);
                file_put_contents($project->root . '/001_framework_baseline.sql', $baseline->upSql . 'tampered');
                break;
            case 'bad-header':
            case 'unknown-kind':
            case 'unknown-unit':
            case 'unknown-part':
                $replace = match ($case) {
                    'bad-header' => ['"format":1', '"format":9'],
                    'unknown-kind' => ['"kind":"baseline"', '"kind":"unknown"'],
                    'unknown-unit' => ['"session"', '"unknown_unit"'],
                    'unknown-part' => [self::TIME_ZONE, self::EARLY],
                };
                file_put_contents($project->root . '/001_framework_baseline.sql', str_replace($replace[0], $replace[1], $baseline->upSql));
                file_put_contents($project->root . '/001_framework_baseline_down.sql', str_replace($replace[0], $replace[1], $baseline->downSql));
                break;
            case 'missing-headers':
                file_put_contents($project->root . '/001_framework_baseline.sql', $this->body($baseline->upSql));
                file_put_contents($project->root . '/001_framework_baseline_down.sql', $this->body($baseline->downSql));
                break;
            case 'half-with-foreign':
                file_put_contents($project->root . '/001_framework_baseline_down.sql', $baseline->downSql);
                file_put_contents($project->root . '/002_project.sql', 'later');
                break;
            case 'half-wrong-number': file_put_contents($project->root . '/002_framework_baseline_down.sql', $baseline->downSql); break;
            case 'half-wrong-name': file_put_contents($project->root . '/001_foreign_down.sql', $baseline->downSql); break;
            case 'mismatched-names':
                file_put_contents($project->root . '/001_framework_baseline.sql', $baseline->upSql);
                file_put_contents($project->root . '/001_foreign_down.sql', $baseline->downSql);
                break;
            case 'later-framework-half':
                $this->seedPair($project, 1, $baseline);
                file_put_contents($project->root . '/002_framework_install_setting_down.sql', 'foreign');
                break;
        }
        $before = $this->sqlSnapshot($project);
        $outcome = $this->installer()->executeBaseline($project);
        $this->assertTrue($outcome->hasFailures());
        $this->assertInstanceOf(DatabaseException::class, $outcome->projects[0]->failure);
        $this->assertFalse($outcome->projects[0]->attempted);
        $this->assertSame([], $outcome->projects[0]->publishedPaths);
        $this->assertSame($before, $this->sqlSnapshot($project));
        $this->expectException(DatabaseException::class);
        $this->installer()->planBaseline($project);
    }

    /** @return list<array{string}> Occupied tracks and independently damaged provenance */
    public static function occupiedTracks(): array
    {
        return array_map(static fn (string $case): array => [$case], [
            'legacy-pair', 'project-up', 'foreign-down', 'adopt', 'install', 'change',
            'wrong-number', 'zero-number', 'wrong-name', 'short-number', 'preceded-baseline', 'two-baselines',
            'overlap', 'duplicate-number', 'bad-body', 'bad-header', 'unknown-kind', 'unknown-unit', 'unknown-part',
            'missing-headers', 'half-with-foreign', 'half-wrong-number', 'half-wrong-name', 'mismatched-names', 'later-framework-half',
        ]);
    }

    #[DataProvider('directions')]
    public function testExactInterruptedHalfResumesAtOneWithoutChangingTheExistingHalf(string $direction): void
    {
        $project = $this->project('half');
        $installer = $this->installer();
        $publication = $installer->planBaseline($project)[0]->publications[0];
        $existing = $direction === 'down' ? $publication->downPath : $publication->upPath;
        $missing = $direction === 'down' ? $publication->upPath : $publication->downPath;
        $sql = $direction === 'down' ? $publication->pair->downSql : $publication->pair->upSql;
        file_put_contents($existing, $sql);
        $plan = $installer->planBaseline($project)[0];
        $this->assertSame(1, $plan->publications[0]->index);
        $this->assertSame($direction === 'up', $plan->publications[0]->upPresent);
        $this->assertSame($direction === 'down', $plan->publications[0]->downPresent);
        $outcome = $installer->executeBaseline($project);
        $this->assertFalse($outcome->hasFailures());
        $this->assertSame([$missing], $outcome->projects[0]->publishedPaths);
        $this->assertSame($sql, file_get_contents($existing));
        $this->assertSame([], $installer->executeBaseline($project)->projects[0]->publishedPaths);
        $this->assertCount(2, $this->sqlSnapshot($project));
    }

    /** @return list<array{string}> Both interruption directions */
    public static function directions(): array
    {
        return [['up'], ['down']];
    }

    #[DataProvider('changedHalves')]
    public function testChangedOrForeignHalfIsNeverReconstructedAgainstDifferentSources(string $case): void
    {
        $project = $this->project('changed-half');
        $pair = $this->installer()->planBaseline($project)[0]->publications[0]->pair;
        $sql = $pair->downSql;
        switch ($case) {
            case 'body': $sql .= 'changed'; break;
            case 'origin': $sql = str_replace('"kind":"baseline"', '"kind":"install"', $sql); break;
            case 'foreign': $sql = 'foreign SQL'; break;
            case 'new-part': $this->change(self::LATE, 'user', [[self::BROWSER_TIME_ZONES, 'user']]); break;
            case 'features': $project = new FrameworkMigrationProject($project->root, BaselineSettingsHilos::class); break;
        }
        file_put_contents($project->root . '/001_framework_baseline_down.sql', $sql);
        $before = $this->sqlSnapshot($project);
        $outcome = $this->installer()->executeBaseline($project);
        $this->assertTrue($outcome->hasFailures());
        $this->assertStringContainsString('Unknown or damaged framework migration half-pair', $outcome->projects[0]->failure->getMessage());
        $this->assertSame([], $outcome->projects[0]->publishedPaths);
        $this->assertSame($before, $this->sqlSnapshot($project));
    }

    /** @return list<array{string}> Origin, body and source evolution that cannot be recovered exactly */
    public static function changedHalves(): array
    {
        return [['body'], ['origin'], ['foreign'], ['new-part'], ['features']];
    }

    public function testAbsentSourceDirectoryIsNamedAndNotCreated(): void
    {
        $project = new FrameworkMigrationProject($this->root . '/absent/Schema', BaselineCoreHilos::class);
        $outcome = $this->installer()->executeBaseline($project);
        $this->assertTrue($outcome->hasFailures());
        $this->assertInstanceOf(FsException::class, $outcome->projects[0]->failure);
        $this->assertStringContainsString($project->root, $outcome->projects[0]->failure->getMessage());
        $this->assertDirectoryDoesNotExist($project->root);
        $this->expectException(FsException::class);
        $this->expectExceptionMessage($project->root);
        $this->installer()->planBaseline($project);
    }

    public function testEveryTargetIsPreflightedAndPhysicalAliasesCannotBeTargetedTwice(): void
    {
        $good = $this->project('good');
        $bad = $this->project('bad');
        file_put_contents($bad->root . '/005_legacy.sql', 'occupied');
        $installer = $this->installer();
        $outcome = $installer->executeBaseline($good, $bad);
        $this->assertTrue($outcome->hasFailures());
        $this->assertSame([false, false], array_column($outcome->projects, 'attempted'));
        $this->assertSame([[], []], array_column($outcome->projects, 'publishedPaths'));
        $this->assertSame([], $this->sqlSnapshot($good));
        symlink($good->root, $this->root . '/alias');
        $alias = new FrameworkMigrationProject($this->root . '/alias', BaselineCoreHilos::class);
        $outcome = $installer->executeBaseline($good, $alias);
        $this->assertTrue($outcome->hasFailures());
        $this->assertStringContainsString('Duplicate framework migration target', $outcome->projects[1]->failure->getMessage());
        $this->assertSame([], $this->sqlSnapshot($good));
        $this->expectException(DatabaseException::class);
        $installer->planBaseline($good, $alias);
    }

    public function testSelectedStubDriftFailsEveryTargetBeforePublication(): void
    {
        $core = $this->project('core');
        $feature = $this->project('feature', BaselineSettingsHilos::class);
        file_put_contents($this->schema->unit('setting')->upPath, 'Uncataloged setting DDL');
        $outcome = $this->installer()->executeBaseline($core, $feature);
        $this->assertTrue($outcome->hasFailures());
        $this->assertNull($outcome->projects[0]->failure);
        $this->assertStringContainsString('stub drift for unit setting', $outcome->projects[1]->failure->getMessage());
        $this->assertSame([[], []], array_column($outcome->projects, 'publishedPaths'));
        $this->assertSame([], $this->sqlSnapshot($core));
    }

    public function testTrackIsRecheckedAfterPreflightBeforeASecondTargetCanPublish(): void
    {
        $one = $this->project('one');
        $two = $this->project('two');
        $installer = $this->installer(static function (string $tmp, string $final) use ($two): void {
            file_put_contents($two->root . '/002_project.sql', 'Created after preflight');
            FsPath::publishExclusive($tmp, $final);
        });
        $outcome = $installer->executeBaseline($one, $two);
        $this->assertTrue($outcome->hasFailures());
        $this->assertNull($outcome->projects[0]->failure);
        $this->assertCount(2, $outcome->projects[0]->publishedPaths);
        $this->assertInstanceOf(DatabaseException::class, $outcome->projects[1]->failure);
        $this->assertSame([], $outcome->projects[1]->publishedPaths);
        $this->assertSame(['002_project.sql' => 'Created after preflight'], $this->sqlSnapshot($two));
    }

    public function testWriterHoldsTheCommonExclusiveLockAndPublishesCompleteDownBeforeUp(): void
    {
        $project = $this->project('locked');
        $pair = $this->installer()->planBaseline($project)[0]->publications[0]->pair;
        $directions = [];
        $installer = $this->installer(function (string $tmp, string $final) use ($project, $pair, &$directions): void {
            $this->assertSame(dirname($final), dirname($tmp));
            $probe = fopen($project->root . '/' . FrameworkMigrationInstaller::LOCK_FILE, 'c');
            try {
                $this->assertFalse(flock($probe, LOCK_EX | LOCK_NB));
            } finally {
                fclose($probe);
            }
            $down = str_ends_with($final, '_down.sql');
            $this->assertSame($down ? $pair->downSql : $pair->upSql, file_get_contents($tmp));
            $directions[] = $down ? 'down' : 'up';
            FsPath::publishExclusive($tmp, $final);
        });
        $this->assertFalse($installer->executeBaseline($project)->hasFailures());
        $this->assertSame(['down', 'up'], $directions);
        $this->assertSame([], glob($project->root . '/.hilos-framework-*.tmp'));
    }

    #[DataProvider('competitors')]
    public function testCompetingDestinationIsPreservedAndReportsOnlyOurCompletedFiles(string $case, bool $onUp): void
    {
        $project = $this->project('competing');
        $pair = $this->installer()->planBaseline($project)[0]->publications[0]->pair;
        $foreign = $this->root . '/foreign.sql';
        file_put_contents($foreign, 'foreign original');
        $destination = $project->root . '/001_framework_baseline' . ($onUp ? '.sql' : '_down.sql');
        $installer = $this->installer(static function (string $tmp, string $final) use ($case, $onUp, $foreign): void {
            if ($onUp === !str_ends_with($final, '_down.sql')) {
                switch ($case) {
                    case 'same': copy($tmp, $final); break;
                    case 'different': file_put_contents($final, 'foreign SQL'); break;
                    case 'symlink': symlink($foreign, $final); break;
                    case 'directory': mkdir($final); break;
                }
            }
            FsPath::publishExclusive($tmp, $final);
        });
        $outcome = $installer->executeBaseline($project);
        $this->assertTrue($outcome->hasFailures());
        $this->assertInstanceOf(FileAlreadyExistsException::class, $outcome->projects[0]->failure);
        $this->assertSame($onUp ? [$project->root . '/001_framework_baseline_down.sql'] : [], $outcome->projects[0]->publishedPaths);
        $this->assertSame('foreign original', file_get_contents($foreign));
        if ($case === 'different') {
            $this->assertSame('foreign SQL', file_get_contents($destination));
        } elseif ($case === 'same') {
            $this->assertSame($onUp ? $pair->upSql : $pair->downSql, file_get_contents($destination));
            $this->assertFalse($this->installer()->executeBaseline($project)->hasFailures());
        } elseif ($case === 'symlink') {
            $this->assertTrue(is_link($destination));
        } else {
            $this->assertDirectoryExists($destination);
        }
        $this->assertSame([], glob($project->root . '/.hilos-framework-*.tmp'));
    }

    /** @return list<array{string, bool}> Competing entries on each direction */
    public static function competitors(): array
    {
        return [
            ['same', false], ['different', false], ['symlink', false], ['directory', false],
            ['same', true], ['different', true], ['symlink', true], ['directory', true],
        ];
    }

    public function testPartialIoFailureReportsAllActualPathsAndRepeatFinishesTheSameNumber(): void
    {
        $one = $this->project('one');
        $two = $this->project('two');
        $three = $this->project('three');
        $installer = $this->installer(static function (string $tmp, string $final) use ($two): void {
            if (dirname($final) === $two->root && !str_ends_with($final, '_down.sql')) {
                throw new FileMoveException('Injected baseline up failure');
            }
            FsPath::publishExclusive($tmp, $final);
        });
        $outcome = $installer->executeBaseline($one, $two, $three);
        $this->assertTrue($outcome->hasFailures());
        $this->assertSame([2, 1, 2], array_map(static fn ($project): int => count($project->publishedPaths), $outcome->projects));
        $this->assertSame([true, true, true], array_column($outcome->projects, 'attempted'));
        $this->assertInstanceOf(FileMoveException::class, $outcome->projects[1]->failure);
        $down = $two->root . '/001_framework_baseline_down.sql';
        $this->assertSame([$down], $outcome->projects[1]->publishedPaths);
        $before = file_get_contents($down);
        $repeat = $this->installer()->executeBaseline($one, $two, $three);
        $this->assertFalse($repeat->hasFailures());
        $this->assertSame([[], [$two->root . '/001_framework_baseline.sql'], []], array_column($repeat->projects, 'publishedPaths'));
        $this->assertSame($before, file_get_contents($down));
        $this->assertSame([], glob($two->root . '/.hilos-framework-*.tmp'));
    }

    public function testLinkedHalfIsReportedEvenWhenRemovingItsTempFails(): void
    {
        $project = $this->project('linked');
        $installer = $this->installer(static function (string $tmp, string $final): void {
            link($tmp, $final);
            throw new FileMoveException('Published link with failed temp cleanup');
        });
        $outcome = $installer->executeBaseline($project);
        $this->assertTrue($outcome->hasFailures());
        $this->assertSame([$project->root . '/001_framework_baseline_down.sql'], $outcome->projects[0]->publishedPaths);
        $this->assertSame([], glob($project->root . '/.hilos-framework-*.tmp'));
        $repeat = $this->installer()->executeBaseline($project);
        $this->assertFalse($repeat->hasFailures());
        $this->assertSame([$project->root . '/001_framework_baseline.sql'], $repeat->projects[0]->publishedPaths);
    }

    /**
     * @param ?Closure $publisher Optional fault injection at the shared writer seam
     * @return FrameworkMigrationInstaller Installer using private temporary sources
     */
    private function installer(?Closure $publisher = null): FrameworkMigrationInstaller
    {
        $catalog = $this->catalog();
        if ($publisher === null) {
            return new FrameworkMigrationInstaller($this->schema, $catalog);
        }

        return new class ($this->schema, $catalog, $publisher) extends FrameworkMigrationInstaller {
            /**
             * @param FrameworkSchemaCatalog $schema Temporary source metadata
             * @param FrameworkMigrationCatalog $catalog Temporary immutable catalog
             * @param Closure $publisher Publication override
             */
            public function __construct(FrameworkSchemaCatalog $schema, FrameworkMigrationCatalog $catalog, private readonly Closure $publisher)
            {
                parent::__construct($schema, $catalog);
            }

            /**
             * @param string $tmpPath Complete same-directory temporary source
             * @param string $finalPath Rechecked destination
             */
            protected function publishFile(string $tmpPath, string $finalPath): void
            {
                ($this->publisher)($tmpPath, $finalPath);
            }
        };
    }

    /** @return FrameworkMigrationCatalog Catalog rebuilt to observe test source changes */
    private function catalog(): FrameworkMigrationCatalog
    {
        return new FrameworkMigrationCatalog($this->schema, $this->root . '/source');
    }

    /**
     * @param string $name Temporary target name
     * @param class-string<Hilos> $facade Feature selection without facade initialization
     * @return FrameworkMigrationProject Empty source track
     */
    private function project(string $name, string $facade = BaselineCoreHilos::class): FrameworkMigrationProject
    {
        mkdir($this->root . '/' . $name);

        return new FrameworkMigrationProject($this->root . '/' . $name, $facade);
    }

    /**
     * @param string $key Unit key
     * @param bool $down Whether to read the down fragment
     * @return string Canonical fragment without trimming
     */
    private function stub(string $key, bool $down = false): string
    {
        $unit = $this->schema->unit($key);

        return FrameworkMigrationOrigin::canonicalSql(file_get_contents($down ? $unit->downPath : $unit->upPath));
    }

    /**
     * @param string $sql Generated SQL including its origin line
     * @return string Body without its origin line
     */
    private function body(string $sql): string
    {
        return substr($sql, strpos($sql, "\n") + 1);
    }

    /**
     * @param FrameworkMigrationProject $project Source track
     * @return array<string, string> Numeric SQL filenames and original bytes
     */
    private function sqlSnapshot(FrameworkMigrationProject $project): array
    {
        $snapshot = [];
        foreach (glob($project->root . '/*.sql') as $path) {
            $snapshot[basename($path)] = file_get_contents($path);
        }

        return $snapshot;
    }

    /**
     * @param string $name Filename stem
     * @param string $kind Origin kind
     * @param list<string> $units Exact unit set
     * @return FrameworkMigrationPair Valid source provenance for invalid-track scenarios
     */
    private function pair(string $name, string $kind, array $units): FrameworkMigrationPair
    {
        $up = "-- Reviewed source fixture.\n";
        $down = "-- Reviewed down fixture.\n";

        return new FrameworkMigrationPair($name, FrameworkMigrationOrigin::forBodies($kind, $units, [], $up, $down), $up, $down);
    }

    /**
     * @param FrameworkMigrationProject $project Source track
     * @param int $index Numeric position
     * @param FrameworkMigrationPair $pair Complete pair to seed
     */
    private function seedPair(FrameworkMigrationProject $project, int $index, FrameworkMigrationPair $pair): void
    {
        $base = $project->root . '/' . str_pad((string)$index, 3, '0', STR_PAD_LEFT) . '_' . $pair->name;
        file_put_contents($base . '.sql', $pair->upSql);
        file_put_contents($base . '_down.sql', $pair->downSql);
    }

    /**
     * @param string $id Immutable change id
     * @param string $key Unit whose current stub evolves
     * @param list<array{string, string}> $requires Exact part references at the JSON boundary
     */
    private function change(string $id, string $key, array $requires = []): void
    {
        $directory = $this->root . '/source/Change/' . $id;
        mkdir($directory);
        file_put_contents($directory . '/up.sql', "ALTER {$key} {$id};\n");
        file_put_contents($directory . '/down.sql', "UNDO {$key} {$id};\n");
        $unit = $this->schema->unit($key);
        file_put_contents($unit->upPath, $this->stub($key) . "-- Current snapshot {$id}\n");
        file_put_contents($unit->downPath, $this->stub($key, true) . "-- Current down snapshot {$id}\n");
        file_put_contents($directory . '/change.json', json_encode([
            'format' => 1,
            'id' => $id,
            'parts' => [[
                'unit' => $key,
                'up' => 'up.sql',
                'down' => 'down.sql',
                'requires' => array_map(static fn (array $part): array => ['changeId' => $part[0], 'unit' => $part[1]], $requires),
                'afterStubUpSha256' => FrameworkMigrationOrigin::bodyHash($this->stub($key)),
                'afterStubDownSha256' => FrameworkMigrationOrigin::bodyHash($this->stub($key, true)),
            ]],
        ], JSON_THROW_ON_ERROR));
    }
}

final class BaselineSourceSchema extends FrameworkSchemaCatalog
{
    /** @param string $root Temporary copy of the real framework source tree */
    public function __construct(private readonly string $root)
    {
        parent::__construct();
    }

    /** @return list<FrameworkSchemaUnit> Real metadata with paths redirected to this test's private copy */
    protected function buildUnits(): array
    {
        return array_map(fn (FrameworkSchemaUnit $unit): FrameworkSchemaUnit => new FrameworkSchemaUnit(
            $unit->key,
            $unit->tables,
            $unit->upPath === null ? null : $this->root . '/Stub/' . basename($unit->upPath),
            $unit->downPath === null ? null : $this->root . '/Stub/' . basename($unit->downPath),
            $unit->dependencies,
            $unit->connection,
            $unit->bootstrapOwned,
        ), parent::buildUnits());
    }
}

abstract class BaselineCoreHilos extends Hilos
{
    /** @return ?RtContext No runtime for the minimal baseline */
    protected static function createRuntime(): ?RtContext
    {
        return null;
    }
}

abstract class BaselineRuntimeHilos extends BaselineCoreHilos
{
    /** @return RtContext Runtime existence alone selects the verifier circle */
    protected static function createRuntime(): ?RtContext
    {
        return new class extends RtContext {
            /** Metadata selection must leave runtime configuration untouched. */
            public function configure(): void
            {
                throw new LogicException('Baseline selection must not configure runtime');
            }
        };
    }
}

abstract class BaselineAuthCodesHilos extends BaselineCoreHilos
{
    protected const array FEATURES = [HilosFeature::AUTH, HilosFeature::CODE_CHANNELS];
}

abstract class BaselineI18nHilos extends BaselineCoreHilos
{
    protected const array FEATURES = [HilosFeature::I18N];
}

abstract class BaselineAggregatesHilos extends BaselineCoreHilos
{
    protected const array FEATURES = [HilosFeature::ANALYTICS, HilosFeature::CHANGE_LOG];
}

abstract class BaselineSettingsHilos extends BaselineCoreHilos
{
    protected const array FEATURES = [HilosFeature::SETTINGS];
}
