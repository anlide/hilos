<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database;

use Hilos\Core\Feature\Definition\AnalyticsFeature;
use Hilos\Core\Feature\Definition\ChangeLogFeature;
use Hilos\Core\Feature\FeatureDefinition;
use Hilos\Core\Feature\FeatureRegistry;
use Hilos\Core\Feature\FeatureRequirements;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\Migration\FrameworkSchemaCatalog;
use Hilos\Database\Migration\FrameworkSchemaUnit;
use Hilos\Database\Schema\FrameworkTablesWithoutEntity;
use Hilos\Hilos;
use Hilos\Runtime\View\Context\RtContext;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Metadata selection without a running facade, database or configured runtime. */
final class FrameworkMigrationCatalogTest extends TestCase
{
    public function testCoreIncludesBothEntityTablesAndAllFourBootstrapTables(): void
    {
        $units = new FrameworkSchemaCatalog()->selectedUnits(SchemaCoreHilos::class);

        $this->assertSame(
            ['migration', 'migration_claim', 'admin_view_mode_latch', 'database_marker', 'user', 'session'],
            array_column($units, 'key'),
        );
        $this->assertSame(
            ['migration', 'hilos_migration_claim', 'hilos_admin_view_mode_latch', 'hilos_database_marker', 'hilos_user', 'hilos_session'],
            array_merge(...array_column($units, 'tables')),
        );
        foreach ($units as $index => $unit) {
            $this->assertSame(DatabaseConnectionDefaults::PRIMARY_INDEX, $unit->connection);
            $this->assertSame($index < 4, $unit->bootstrapOwned);
            if ($unit->bootstrapOwned) {
                $this->assertNull($unit->upPath);
                $this->assertNull($unit->downPath);
            } else {
                $this->assertFileExists($unit->upPath);
                $this->assertFileExists($unit->downPath);
            }
        }
    }

    public function testRuntimeCircleDependsOnlyOnTheFactoryAndDoesNotInitializeTheFacade(): void
    {
        $runtimeBefore = Hilos::$rt;
        $databaseBefore = Hilos::$db;
        $envBefore = Hilos::$env;
        $appBefore = Hilos::appClass();

        $this->assertFalse(Hilos::usesRuntimeOf(SchemaCoreHilos::class));
        $this->assertTrue(Hilos::usesRuntimeOf(SchemaRuntimeHilos::class));
        $this->assertSame(
            ['migration', 'migration_claim', 'admin_view_mode_latch', 'database_marker', 'user', 'session', 'verifier_circle'],
            array_column(new FrameworkSchemaCatalog()->selectedUnits(SchemaRuntimeHilos::class), 'key'),
        );
        $this->assertSame($runtimeBefore, Hilos::$rt);
        $this->assertSame($databaseBefore, Hilos::$db);
        $this->assertSame($envBefore, Hilos::$env);
        $this->assertSame($appBefore, Hilos::appClass());
        $this->assertSame([], Hilos::featuresOf(SchemaRuntimeHilos::class));
    }

    public function testSharedVerificationRequirementSelectsOneUnit(): void
    {
        $keys = array_column(new FrameworkSchemaCatalog()->selectedUnits(SchemaAuthCodesHilos::class), 'key');

        $this->assertSame(1, count(array_keys($keys, 'user_verification', true)));
        $this->assertContains('identity', $keys);
        $this->assertContains('passkey_credential', $keys);
        $this->assertContains('user_merge', $keys);
        $this->assertContains('oauth_provider', $keys);
        $this->assertContains('legal_acceptance_export', $keys);
        $this->assertNotContains('verifier_circle', $keys);
        $this->assertCount(24, $keys);
    }

    public function testMetadataRequirementsAreTheSelectionSource(): void
    {
        $catalog = $this->catalogForRequirements(new FeatureRequirements(
            requiredDbTables: ['hilos_file_variant', 'hilos_user_verification', 'hilos_file_variant'],
        ));

        $this->assertSame(
            [
                'migration', 'migration_claim', 'admin_view_mode_latch', 'database_marker', 'user', 'session',
                'file', 'file_variant', 'user_verification',
            ],
            array_column($catalog->selectedUnits(SchemaSettingsHilos::class), 'key'),
        );
    }

    public function testImagesReadsItsFileDependencyFromFeatureRequirements(): void
    {
        $this->assertSame(
            ['migration', 'migration_claim', 'admin_view_mode_latch', 'database_marker', 'user', 'session', 'file', 'file_variant'],
            array_column(new FrameworkSchemaCatalog()->selectedUnits(SchemaImagesHilos::class), 'key'),
        );
    }

    public function testFeaturesWithoutDbTablesAddNoSchemaUnits(): void
    {
        $catalog = new FrameworkSchemaCatalog();

        $this->assertEquals($catalog->selectedUnits(SchemaCoreHilos::class), $catalog->selectedUnits(SchemaNoTablesHilos::class));
    }

    public function testModerationSelectsTheRulesUnitOnlyWhileItIsDeclared(): void
    {
        $catalog = new FrameworkSchemaCatalog();

        $this->assertSame(
            ['migration', 'migration_claim', 'admin_view_mode_latch', 'database_marker', 'user', 'session', 'moderation_rule'],
            array_column($catalog->selectedUnits(SchemaModerationHilos::class), 'key'),
        );
        $this->assertNotContains('moderation_rule', array_column($catalog->selectedUnits(SchemaCoreHilos::class), 'key'));
        $this->assertNotContains('moderation_rule', array_column($catalog->selectedUnits(SchemaNoModerationHilos::class), 'key'));
    }

    public function testI18nOrdersTheCountryBeforeTheLocaleStubThatAltersIt(): void
    {
        $units = new FrameworkSchemaCatalog()->selectedUnits(SchemaI18nHilos::class);

        $this->assertSame(
            [
                'migration', 'migration_claim', 'admin_view_mode_latch', 'database_marker', 'user', 'session',
                'language', 'country', 'locale', 'language_name', 'country_name', 'i18n_reflow',
            ],
            array_column($units, 'key'),
        );
        $byKey = array_column($units, null, 'key');
        $this->assertSame(['language', 'country'], $byKey['locale']->dependencies);
        $this->assertSame(['language', 'locale'], $byKey['language_name']->dependencies);
        $this->assertSame(['country', 'language', 'locale'], $byKey['country_name']->dependencies);
    }

    public function testAnalyticsRequirementKeepsAll28TablesInOneAggregate(): void
    {
        $tables = FrameworkTablesWithoutEntity::analyticsTables();
        $this->assertCount(28, $tables);
        $this->assertSame($tables, new AnalyticsFeature()->requirements()->requiredDbTables);
        $this->assertCount(32, FrameworkTablesWithoutEntity::tables());
        $this->assertNotContains('migration', $tables);
        $this->assertNotContains('hilos_migration_claim', $tables);
        $this->assertNotContains('hilos_admin_view_mode_latch', $tables);
        $this->assertNotContains('hilos_database_marker', $tables);

        $units = new FrameworkSchemaCatalog()->selectedUnits(SchemaAnalyticsHilos::class);
        $this->assertSame(
            ['migration', 'migration_claim', 'admin_view_mode_latch', 'database_marker', 'user', 'session', 'analytics'],
            array_column($units, 'key'),
        );
        $analytics = $units[array_key_last($units)];
        $this->assertSame($tables, $analytics->tables);
        $this->assertSame('create_hilos_analytics.sql', basename($analytics->upPath));
        $this->assertSame('create_hilos_analytics_down.sql', basename($analytics->downPath));
        $this->assertSame(DatabaseConnectionDefaults::PRIMARY_INDEX, $analytics->connection);
    }

    public function testEveryAnalyticsTableRequirementResolvesToTheSameUnit(): void
    {
        foreach (FrameworkTablesWithoutEntity::analyticsTables() as $table) {
            $units = $this->catalogForRequirements(new FeatureRequirements(requiredDbTables: [$table]))
                ->selectedUnits(SchemaSettingsHilos::class);

            $this->assertCount(7, $units, $table);
            $this->assertSame('analytics', $units[array_key_last($units)]->key, $table);
        }
    }

    public function testDerivedJournalIsSelectedByUnitAndKeepsItsPrimaryTrackSqlToken(): void
    {
        $requirements = new ChangeLogFeature()->requirements();
        $this->assertSame([], $requirements->requiredDbTables);
        $this->assertSame(['change_log'], $requirements->requiredSchemaUnits);

        $units = new FrameworkSchemaCatalog()->selectedUnits(SchemaChangeLogHilos::class);
        $this->assertSame(
            ['migration', 'migration_claim', 'admin_view_mode_latch', 'database_marker', 'user', 'session', 'change_log'],
            array_column($units, 'key'),
        );
        $journal = $units[array_key_last($units)];
        $this->assertSame(FrameworkSchemaCatalog::CHANGE_LOG_TABLES, $journal->tables);
        $this->assertCount(6, $journal->tables);
        $this->assertSame(ChangeLogDatabase::CONNECTION_INDEX, $journal->connection);
        $this->assertFalse($journal->bootstrapOwned);
        $this->assertStringContainsString('{{change_log_database}}', file_get_contents($journal->upPath));
        $this->assertStringContainsString('{{change_log_database}}', file_get_contents($journal->downPath));
        foreach (array_slice($units, 0, -1) as $unit) {
            $this->assertSame(DatabaseConnectionDefaults::PRIMARY_INDEX, $unit->connection);
        }
    }

    public function testUnitRequirementsUnionWithTableRequirementsWithoutDuplicates(): void
    {
        $units = $this->catalogForRequirements(new FeatureRequirements(
            requiredDbTables: ['hilos_analytics_page'],
            requiredSchemaUnits: ['analytics', 'change_log', 'change_log'],
        ))->selectedUnits(SchemaSettingsHilos::class);

        $this->assertSame(
            ['migration', 'migration_claim', 'admin_view_mode_latch', 'database_marker', 'user', 'session', 'analytics', 'change_log'],
            array_column($units, 'key'),
        );
    }

    public function testNewRequirementsDefaultDoesNotChangeExistingPositionalCallers(): void
    {
        $this->assertSame([], new FeatureRequirements()->requiredSchemaUnits);
        $requirements = new FeatureRequirements([], [], [], [], [], [], null, [], [], ['hilos_setting'], ['command'], true);

        $this->assertSame(['hilos_setting'], $requirements->requiredDbTables);
        $this->assertSame(['command'], $requirements->requiredCliCommands);
        $this->assertTrue($requirements->requiresPresenceSource);
        $this->assertSame([], $requirements->requiredSchemaUnits);
    }

    public function testEveryRealStubIsCatalogedWithExactTablesAndExecutableDependencyOrder(): void
    {
        // OAuth and legal-acceptance export enter AUTH in slice 5; they are real catalog units already.
        // Status storage arrives before its activation in HIL-1688 slice 2.
        $allRequirements = new FeatureRequirements(
            requiredDbTables: [
                'hilos_oauth_provider', 'hilos_legal_acceptance_export', 'hilos_status_incident', 'hilos_status_incident_update',
                'hilos_status_subscriber', 'hilos_status_work', 'hilos_status_work_component', 'hilos_status_maintenance_period',
            ],
        );
        $registry = new class ($allRequirements) extends FeatureRegistry {
            public function __construct(private readonly FeatureRequirements $extraRequirements)
            {
                parent::__construct();
            }

            protected function buildDefinitions(): array
            {
                return [...parent::buildDefinitions(), new class ($this->extraRequirements) extends FeatureDefinition {
                    public function __construct(private readonly FeatureRequirements $extraRequirements)
                    {
                    }

                    public function feature(): HilosFeature
                    {
                        return HilosFeature::DAEMON;
                    }

                    public function requirements(): FeatureRequirements
                    {
                        return $this->extraRequirements;
                    }
                }];
            }
        };
        $units = new FrameworkSchemaCatalog($registry)->selectedUnits(SchemaAllFeaturesHilos::class);
        $created = [];
        $paths = [];
        foreach ($units as $unit) {
            if ($unit->bootstrapOwned) {
                continue;
            }
            $this->assertFileExists($unit->upPath);
            $this->assertFileExists($unit->downPath);
            $sql = file_get_contents($unit->upPath);
            preg_match_all('/CREATE TABLE(?: IF NOT EXISTS)?\s+(?:\{\{change_log_database\}\}\.)?`([^`]+)`/i', $sql, $tables);
            $this->assertSame($unit->tables, $tables[1], $unit->key);

            // A reference to a table in the same aggregate needs no separate unit.
            preg_match_all('/REFERENCES\s+(?:\{\{change_log_database\}\}\.)?`([^`]+)`/i', $sql, $references);
            foreach ($references[1] as $table) {
                $this->assertTrue(isset($created[$table]) || in_array($table, $unit->tables, true), "{$unit->key} needs {$table}");
            }
            $created += array_fill_keys($unit->tables, true);
            $paths[] = $unit->upPath;
        }
        $stubs = array_values(array_filter(
            glob(__DIR__ . '/../../../backend/Database/Migration/Stub/*.sql'),
            static fn(string $path): bool => !str_ends_with($path, '_down.sql'),
        ));
        $this->assertEqualsCanonicalizing(array_map('realpath', $stubs), array_map('realpath', $paths));
        $this->assertCount(count($paths), array_unique($paths));
        $this->assertSame(array_column($units, 'key'), array_values(array_unique(array_column($units, 'key'))));
    }

    public function testUnknownPrimaryTableRequirementIsRefused(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Feature settings requires unknown primary schema table hilos_missing');

        $this->catalogForRequirements(new FeatureRequirements(requiredDbTables: ['hilos_missing']))->selectedUnits(SchemaSettingsHilos::class);
    }

    public function testDerivedJournalTableCannotMasqueradeAsAPrimaryRequirement(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('requires unknown primary schema table hilos_change_log_receipt');

        $this->catalogForRequirements(new FeatureRequirements(requiredDbTables: ['hilos_change_log_receipt']))
            ->selectedUnits(SchemaSettingsHilos::class);
    }

    public function testUnknownUnitRequirementIsRefused(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Unknown framework schema unit missing');

        $this->catalogForRequirements(new FeatureRequirements(requiredSchemaUnits: ['missing']))->selectedUnits(SchemaSettingsHilos::class);
    }

    public function testFeatureDependencyCycleIsRefused(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Framework schema feature dependency cycle at settings');

        $this->catalogForRequirements(new FeatureRequirements(requires: [HilosFeature::SETTINGS]))->selectedUnits(SchemaSettingsHilos::class);
    }

    /** @param list<FrameworkSchemaUnit> $units Deliberately invalid catalog definitions */
    #[DataProvider('invalidCatalogs')]
    public function testInvalidUnitCatalogIsRefused(array $units, string $message): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage($message);

        new class ($units) extends FrameworkSchemaCatalog {
            public function __construct(private readonly array $definitions)
            {
                parent::__construct();
            }

            protected function buildUnits(): array
            {
                return $this->definitions;
            }
        };
    }

    /** @return iterable<string, array{list<FrameworkSchemaUnit>, string}> Invalid graph cases */
    public static function invalidCatalogs(): iterable
    {
        yield 'duplicate key' => [[
            new FrameworkSchemaUnit('same', ['table_a'], '/a.sql', '/a_down.sql'),
            new FrameworkSchemaUnit('same', ['table_b'], '/b.sql', '/b_down.sql'),
        ], 'Duplicate framework schema unit same'];
        yield 'duplicate table' => [[
            new FrameworkSchemaUnit('a', ['table_a'], '/a.sql', '/a_down.sql'),
            new FrameworkSchemaUnit('b', ['table_a'], '/b.sql', '/b_down.sql'),
        ], 'Table table_a on connection 0 belongs to multiple schema units'];
        yield 'unknown dependency' => [[
            new FrameworkSchemaUnit('a', ['table_a'], '/a.sql', '/a_down.sql', ['missing']),
        ], 'Unknown framework schema unit missing'];
        yield 'self cycle' => [[
            new FrameworkSchemaUnit('a', ['table_a'], '/a.sql', '/a_down.sql', ['a']),
        ], 'Framework schema unit dependency cycle at a'];
        yield 'mutual cycle' => [[
            new FrameworkSchemaUnit('a', ['table_a'], '/a.sql', '/a_down.sql', ['b']),
            new FrameworkSchemaUnit('b', ['table_b'], '/b.sql', '/b_down.sql', ['a']),
        ], 'Framework schema unit dependency cycle at a'];
    }

    public function testBootstrapUnitCannotClaimAStub(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('paths inconsistent with bootstrap ownership');

        new FrameworkSchemaUnit('bootstrap', ['table'], '/create.sql', '/create_down.sql', bootstrapOwned: true);
    }

    public function testOrdinaryUnitCannotOmitItsSqlPair(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('paths inconsistent with bootstrap ownership');

        new FrameworkSchemaUnit('ordinary', ['table'], '/create.sql', null);
    }

    public function testUnitCannotOmitItsTables(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('needs a key and exact table names');

        new FrameworkSchemaUnit('empty', [], '/create.sql', '/create_down.sql');
    }

    private function catalogForRequirements(FeatureRequirements $requirements): FrameworkSchemaCatalog
    {
        $definition = $this->createStub(FeatureDefinition::class);
        $definition->method('requirements')->willReturn($requirements);
        $registry = $this->createStub(FeatureRegistry::class);
        $registry->method('definition')->willReturn($definition);

        return new FrameworkSchemaCatalog($registry);
    }
}

class SchemaCoreHilos extends Hilos
{
    /**
     * @return HilosDbContext Never returned during metadata selection
     * @throws LogicException When a metadata reader tries to create the database context
     */
    protected static function createDb(): HilosDbContext
    {
        throw new LogicException('Schema selection must not create the database context');
    }
}

final class SchemaRuntimeHilos extends SchemaCoreHilos
{
    /** @return RtContext A context whose configuration is forbidden in metadata reads */
    protected static function createRuntime(): ?RtContext
    {
        return new class extends RtContext {
            public function configure(): void
            {
                throw new LogicException('Schema selection must not configure runtime');
            }
        };
    }
}

final class SchemaSettingsHilos extends SchemaCoreHilos
{
    protected const array FEATURES = [HilosFeature::SETTINGS];
}

final class SchemaAuthCodesHilos extends SchemaCoreHilos
{
    protected const array FEATURES = [HilosFeature::AUTH, HilosFeature::CODE_CHANNELS];
}

final class SchemaImagesHilos extends SchemaCoreHilos
{
    protected const array FEATURES = [HilosFeature::IMAGES];
}

final class SchemaNoTablesHilos extends SchemaCoreHilos
{
    protected const array FEATURES = [HilosFeature::UPLOADS, HilosFeature::DAEMON, HilosFeature::LOGS];
}

final class SchemaI18nHilos extends SchemaCoreHilos
{
    protected const array FEATURES = [HilosFeature::I18N];
}

final class SchemaAnalyticsHilos extends SchemaCoreHilos
{
    protected const array FEATURES = [HilosFeature::ANALYTICS];
}

final class SchemaChangeLogHilos extends SchemaCoreHilos
{
    protected const array FEATURES = [HilosFeature::CHANGE_LOG];
}

final class SchemaModerationHilos extends SchemaCoreHilos
{
    protected const array FEATURES = [HilosFeature::MODERATION];
}

/** The all-features declaration without moderation, so no other feature drags the rules unit in. */
final class SchemaNoModerationHilos extends SchemaCoreHilos
{
    protected const array FEATURES = [
        HilosFeature::SETTINGS,
        HilosFeature::HILOS_USERS,
        HilosFeature::BACKUP,
        HilosFeature::LOGS,
        HilosFeature::DAEMON,
        HilosFeature::I18N,
        HilosFeature::NOTIFICATIONS,
        HilosFeature::NOTIFICATION_DELIVERY,
        HilosFeature::AUTH_THROTTLE,
        HilosFeature::CODE_CHANNELS,
        HilosFeature::AUTH,
        HilosFeature::FILES,
        HilosFeature::UPLOADS,
        HilosFeature::IMAGES,
        HilosFeature::PROFILE_PHOTO,
        HilosFeature::ANALYTICS,
        HilosFeature::CHANGE_LOG,
    ];
}

final class SchemaAllFeaturesHilos extends SchemaCoreHilos
{
    protected const array FEATURES = [
        HilosFeature::SETTINGS,
        HilosFeature::HILOS_USERS,
        HilosFeature::BACKUP,
        HilosFeature::LOGS,
        HilosFeature::DAEMON,
        HilosFeature::I18N,
        HilosFeature::NOTIFICATIONS,
        HilosFeature::NOTIFICATION_DELIVERY,
        HilosFeature::AUTH_THROTTLE,
        HilosFeature::CODE_CHANNELS,
        HilosFeature::AUTH,
        HilosFeature::FILES,
        HilosFeature::UPLOADS,
        HilosFeature::IMAGES,
        HilosFeature::PROFILE_PHOTO,
        HilosFeature::MODERATION,
        HilosFeature::ANALYTICS,
        HilosFeature::CHANGE_LOG,
    ];

    /** @return RtContext Unconfigured runtime, selecting the unconditional verifier circle */
    protected static function createRuntime(): ?RtContext
    {
        return new class extends RtContext {
            public function configure(): void
            {
                throw new LogicException('Schema selection must not configure runtime');
            }
        };
    }
}
