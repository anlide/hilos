<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core;

use FilesystemIterator;
use Hilos\Cluster\ClusterContext;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Bootstrap\EntrypointPrelude;
use Hilos\Core\Feature\Exception\IncompleteFeatureActivationException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Migration;
use Hilos\Database\Migration\FrameworkMigrationCatalog;
use Hilos\Database\Migration\FrameworkMigrationOrigin;
use Hilos\Database\Migration\FrameworkMigrationPair;
use Hilos\Database\Migration\FrameworkMigrationStartupGuard;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\Exception\EnvException;
use Hilos\Environment\Exception\EnvInvalidValueException;
use Hilos\Environment\ServerTextTimeZoneMode;
use Hilos\Fs\Exception\LfsPointerException;
use Hilos\Fs\FsException;
use Hilos\Hilos;
use Hilos\LLM\Routing\LlmRouter;
use Hilos\Utils\Helpers\TimeHelper;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionProperty;

/**
 * The shared start sets this process to UTC and judges the project zone and server text mode only on run().
 *
 * No second process: date_default_timezone_set is the clock this PHP already has.
 * A child would only repeat what this process can already observe.
 */
final class EntrypointPreludeTest extends TestCase
{
    private const string CONTAINER_TZ = 'Europe/Kyiv';

    private ?string $root = null;

    private bool $saved = false;

    private ?EnvAccessor $previousEnv;

    private ?LlmRouter $previousLlm;

    private ?ClusterContext $previousCluster;

    /** @var class-string<Hilos> */
    private string $previousAppClass;

    private ?string $previousMigrationPath;

    private string $previousMigrationName;

    private string $previousTimezone;

    private string|false $previousTz;

    private string|false $previousZone;

    private string|false $previousMode;

    /** Saves process globals and isolates each scenario from an earlier prepared startup. */
    protected function setUp(): void
    {
        parent::setUp();
        FrameworkMigrationStartupGuard::resetForTests();
        $this->previousEnv = Hilos::$env;
        $this->previousLlm = Hilos::$llm;
        $this->previousCluster = Hilos::$cluster;
        $this->previousAppClass = Hilos::appClass();
        $migrationPath = new ReflectionProperty(Migration::class, 'migrationListPath');
        $migrationName = new ReflectionProperty(Migration::class, 'migrationName');
        $this->previousMigrationPath = $migrationPath->getValue();
        $this->previousMigrationName = $migrationName->getValue();
        $this->previousTimezone = date_default_timezone_get();
        $this->previousTz = getenv('TZ');
        $this->previousZone = getenv(EnvConstants::HILOS_PROJECT_TIME_ZONE->name);
        $this->previousMode = getenv(EnvConstants::HILOS_SERVER_TEXT_TIME_ZONE_MODE->name);
        $this->saved = true;

        $this->root = sys_get_temp_dir() . '/hilos-entrypoint-prelude-' . bin2hex(random_bytes(4));
        if (!mkdir($this->root) && !is_dir($this->root)) {
            $this->fail("Cannot create {$this->root}");
        }
        if (!mkdir($this->root . '/tests') && !is_dir($this->root . '/tests')) {
            $this->fail("Cannot create {$this->root}/tests");
        }

        Hilos::$env = null;
        putenv(EnvConstants::HILOS_PROJECT_TIME_ZONE->name);
        putenv(EnvConstants::HILOS_SERVER_TEXT_TIME_ZONE_MODE->name);
        putenv('TZ=' . self::CONTAINER_TZ);
        date_default_timezone_set(self::CONTAINER_TZ);
        $this->assertNotSame('+00:00', date('P'));
    }

    /** Disarms startup, restores process globals and removes the temporary project including its source lock. */
    protected function tearDown(): void
    {
        FrameworkMigrationStartupGuard::resetForTests();
        if ($this->saved) {
            Hilos::$env = $this->previousEnv;
            Hilos::$llm = $this->previousLlm;
            Hilos::$cluster = $this->previousCluster;
            new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->previousAppClass);
            new ReflectionProperty(Migration::class, 'migrationListPath')->setValue(null, $this->previousMigrationPath);
            new ReflectionProperty(Migration::class, 'migrationName')->setValue(null, $this->previousMigrationName);
            date_default_timezone_set($this->previousTimezone);
            if ($this->previousTz === false) {
                putenv('TZ');
            } else {
                putenv('TZ=' . $this->previousTz);
            }
            if ($this->previousZone === false) {
                putenv(EnvConstants::HILOS_PROJECT_TIME_ZONE->name);
            } else {
                putenv(EnvConstants::HILOS_PROJECT_TIME_ZONE->name . '=' . $this->previousZone);
            }
            putenv(EnvConstants::HILOS_SERVER_TEXT_TIME_ZONE_MODE->name
                . ($this->previousMode === false ? '' : '=' . $this->previousMode));
        }
        $this->removeRoot();
        parent::tearDown();
    }

    public function testInitEnvironmentSetsUtcWithoutJudgingTheProjectZone(): void
    {
        $this->writeEnv("HILOS_PROJECT_TIME_ZONE=Not/AZone\n", "HILOS_PROJECT_TIME_ZONE=Europe/Warsaw\n");

        EntrypointPrelude::initEnvironment(EntrypointPreludeTestHilos::class, $this->root());

        $this->assertClockIsUtc();
        $this->assertSame(
            'Europe/Warsaw',
            Hilos::$env[EnvConstants::HILOS_PROJECT_TIME_ZONE]->string(),
        );
    }

    public function testInitEnvironmentStaysAvailableWhenTheOverrideIsInvalid(): void
    {
        $this->writeEnv("HILOS_PROJECT_TIME_ZONE=Europe/Kyiv\n", "HILOS_PROJECT_TIME_ZONE=Not/AZone\n");

        EntrypointPrelude::initEnvironment(EntrypointPreludeTestHilos::class, $this->root());

        $this->assertClockIsUtc();
        $this->assertSame(
            'Not/AZone',
            Hilos::$env[EnvConstants::HILOS_PROJECT_TIME_ZONE]->string(),
        );
    }

    /**
     * @throws EnvException When the fixture environment cannot be loaded
     * @throws EnvInvalidValueException When the override supplies an invalid project zone
     * @throws LfsPointerException When framework data has not been materialized
     * @throws FsException When startup sources cannot be read or locked
     * @throws DatabaseException When the fixture origin or catalog coverage is invalid
     * @throws IncompleteFeatureActivationException When the fixture facade selects an incomplete feature
     */
    public function testRunCallsPersistenceInUtcAfterTheTestOverride(): void
    {
        $this->writeEnv("HILOS_PROJECT_TIME_ZONE=Not/AZone\n", "HILOS_PROJECT_TIME_ZONE=Europe/Kyiv\n");
        $this->writeFrameworkSources();
        $called = false;

        EntrypointPrelude::run(
            EntrypointPreludeTestHilos::class,
            $this->root(),
            function () use (&$called): void {
                $called = true;
                $this->assertClockIsUtc();
                $this->assertSame(
                    'Europe/Kyiv',
                    Hilos::$env[EnvConstants::HILOS_PROJECT_TIME_ZONE]->string(),
                );
            },
        );

        $this->assertTrue($called);
    }

    /**
     * @throws EnvException When the fixture environment cannot be loaded
     * @throws EnvInvalidValueException When the catalog default is not a valid project zone
     * @throws LfsPointerException When framework data has not been materialized
     * @throws FsException When startup sources cannot be read or locked
     * @throws DatabaseException When the fixture origin or catalog coverage is invalid
     * @throws IncompleteFeatureActivationException When the fixture facade selects an incomplete feature
     */
    public function testRunUsesTheCatalogDefaultWhenTheOverrideOmitsTheZone(): void
    {
        $this->writeEnv("HILOS_PROJECT_TIME_ZONE=Not/AZone\n", "APP_ENV=test\n");
        $this->writeFrameworkSources();
        $called = false;

        EntrypointPrelude::run(
            EntrypointPreludeTestHilos::class,
            $this->root(),
            function () use (&$called): void {
                $called = true;
                $this->assertSame(
                    TimeConstants::UTC_TIME_ZONE,
                    Hilos::$env[EnvConstants::HILOS_PROJECT_TIME_ZONE]->string(),
                );
            },
        );

        $this->assertTrue($called);
        $this->assertClockIsUtc();
    }

    public function testRunRefusesTheOverrideAndSkipsPersistence(): void
    {
        $this->writeEnv("HILOS_PROJECT_TIME_ZONE=Europe/Kyiv\n", "HILOS_PROJECT_TIME_ZONE=+02:00\n");
        $called = false;

        try {
            EntrypointPrelude::run(
                EntrypointPreludeTestHilos::class,
                $this->root(),
                function () use (&$called): void {
                    $called = true;
                },
            );
            $this->fail('An offset was accepted as a project zone');
        } catch (EnvInvalidValueException $refusal) {
            $this->assertSame(
                "Environment variable 'HILOS_PROJECT_TIME_ZONE' must name an IANA time zone; got '+02:00'",
                $refusal->getMessage(),
            );
        }

        $this->assertFalse($called);
        $this->assertClockIsUtc();
    }

    public function testInitEnvironmentAllowsAnInvalidServerTextModeForRepair(): void
    {
        $this->writeEnv("HILOS_SERVER_TEXT_TIME_ZONE_MODE=recipient\n", "HILOS_SERVER_TEXT_TIME_ZONE_MODE=invalid\n");

        EntrypointPrelude::initEnvironment(EntrypointPreludeTestHilos::class, $this->root());

        $this->assertSame('invalid', Hilos::$env[EnvConstants::HILOS_SERVER_TEXT_TIME_ZONE_MODE]->string());
        $this->assertClockIsUtc();
    }

    public function testRunRefusesAnInvalidServerTextModeFromDotenv(): void
    {
        $previousAppEnv = getenv(EnvConstants::APP_ENV->name);
        putenv('APP_ENV=dev');
        try {
            $this->writeEnv("HILOS_SERVER_TEXT_TIME_ZONE_MODE=invalid\n", "HILOS_SERVER_TEXT_TIME_ZONE_MODE=recipient\n");
            $this->assertServerTextModeRefusesPersistence();
        } finally {
            putenv(EnvConstants::APP_ENV->name . ($previousAppEnv === false ? '' : '=' . $previousAppEnv));
        }
    }

    public function testRunRefusesAnInvalidServerTextModeFromTheTestOverride(): void
    {
        $this->writeEnv("HILOS_SERVER_TEXT_TIME_ZONE_MODE=recipient\n", "HILOS_SERVER_TEXT_TIME_ZONE_MODE=invalid\n");

        $this->assertServerTextModeRefusesPersistence();
    }

    public function testRunParsesTheModeAfterTheTestOverride(): void
    {
        $this->writeEnv("HILOS_SERVER_TEXT_TIME_ZONE_MODE=invalid\n", "HILOS_SERVER_TEXT_TIME_ZONE_MODE=project\n");
        $this->writeFrameworkSources();
        $called = false;

        EntrypointPrelude::run(EntrypointPreludeTestHilos::class, $this->root(), function () use (&$called): void {
            $called = true;
            $this->assertSame(ServerTextTimeZoneMode::PROJECT, ServerTextTimeZoneMode::fromEnv());
            $this->assertClockIsUtc();
        });

        $this->assertTrue($called);
    }

    public function testRunAcceptsTheMissingModeCatalogDefault(): void
    {
        $this->writeEnv("HILOS_SERVER_TEXT_TIME_ZONE_MODE=invalid\n", "APP_ENV=test\n");
        $this->writeFrameworkSources();
        $called = false;

        EntrypointPrelude::run(EntrypointPreludeTestHilos::class, $this->root(), function () use (&$called): void {
            $called = true;
            $this->assertSame(ServerTextTimeZoneMode::RECIPIENT, ServerTextTimeZoneMode::fromEnv());
            $this->assertClockIsUtc();
        });

        $this->assertTrue($called);
    }

    /** Confirms a bad policy fails before migrations and persistence and still sets PHP to UTC. */
    private function assertServerTextModeRefusesPersistence(): void
    {
        $called = false;
        try {
            EntrypointPrelude::run(EntrypointPreludeTestHilos::class, $this->root(), function () use (&$called): void {
                $called = true;
            });
            $this->fail('An invalid server text time zone mode was accepted');
        } catch (EnvInvalidValueException $refusal) {
            $this->assertSame(
                "Environment variable 'HILOS_SERVER_TEXT_TIME_ZONE_MODE' must be recipient, project or utc; got 'invalid'",
                $refusal->getMessage(),
            );
        }

        $this->assertFalse($called);
        $this->assertClockIsUtc();
    }

    private function assertClockIsUtc(): void
    {
        $this->assertSame(self::CONTAINER_TZ, getenv('TZ'));
        $this->assertSame(TimeConstants::UTC_TIME_ZONE, date_default_timezone_get());
        $this->assertSame('+00:00', date('P'));
        $second = time();
        $accepted = [
            gmdate('Y-m-d H:i:s', $second - 1),
            gmdate('Y-m-d H:i:s', $second),
            gmdate('Y-m-d H:i:s', $second + 1),
        ];
        $this->assertContains(date('Y-m-d H:i:s'), $accepted);
        $this->assertContains(TimeHelper::getSqlDateTime(), $accepted);
    }

    private function writeEnv(string $dotenv, string $testEnv): void
    {
        if (file_put_contents($this->root() . '/.env', $dotenv) === false) {
            $this->fail('Cannot write .env');
        }
        if (file_put_contents($this->root() . '/tests/.env', $testEnv) === false) {
            $this->fail('Cannot write tests/.env');
        }
    }

    /**
     * Supplies genesis and the current user and session changes to the real source gate, without opening a database.
     *
     * @throws DatabaseException When the no-op adoption pair is invalid
     */
    private function writeFrameworkSources(): void
    {
        $track = $this->root() . '/backend/Database/Migration/Schema';
        if (!mkdir($track, 0775, true) && !is_dir($track)) {
            $this->fail("Cannot create {$track}");
        }
        $body = "SELECT 1;\n";
        $pair = new FrameworkMigrationPair(
            'adopt_framework_catalog',
            FrameworkMigrationOrigin::forBodies(
                FrameworkMigrationOrigin::ADOPT,
                ['migration', 'migration_claim', 'admin_view_mode_latch', 'database_marker', 'user', 'session'],
                [],
                $body,
                $body,
            ),
            $body,
            $body,
        );
        if (file_put_contents($track . '/001_' . $pair->name . '.sql', $pair->upSql) === false) {
            $this->fail('Cannot write framework adoption up source');
        }
        if (file_put_contents($track . '/001_' . $pair->name . '_down.sql', $pair->downSql) === false) {
            $this->fail('Cannot write framework adoption down source');
        }
        $part = new FrameworkMigrationCatalog()->part('20261021021039718057_HIL-1580_add_user_time_zone', 'user');
        $change = new FrameworkMigrationPair(
            'user_time_zone',
            FrameworkMigrationOrigin::forBodies(FrameworkMigrationOrigin::CHANGE, ['user'], [$part], $part->upSql, $part->downSql),
            $part->upSql,
            $part->downSql,
        );
        if (file_put_contents($track . '/002_user_time_zone.sql', $change->upSql) === false) {
            $this->fail('Cannot write user time zone up source');
        }
        if (file_put_contents($track . '/002_user_time_zone_down.sql', $change->downSql) === false) {
            $this->fail('Cannot write user time zone down source');
        }
        foreach (['session' => '003', 'user' => '004'] as $unit => $number) {
            $part = new FrameworkMigrationCatalog()->part('20261022124515804554_HIL-1582_browser_time_zones', $unit);
            $change = new FrameworkMigrationPair(
                $unit . '_browser_time_zone',
                FrameworkMigrationOrigin::forBodies(FrameworkMigrationOrigin::CHANGE, [$unit], [$part], $part->upSql, $part->downSql),
                $part->upSql,
                $part->downSql,
            );
            if (file_put_contents($track . '/' . $number . '_' . $change->name . '.sql', $change->upSql) === false) {
                $this->fail("Cannot write {$unit} browser time zone up source");
            }
            if (file_put_contents($track . '/' . $number . '_' . $change->name . '_down.sql', $change->downSql) === false) {
                $this->fail("Cannot write {$unit} browser time zone down source");
            }
        }
    }

    private function root(): string
    {
        if ($this->root === null) {
            $this->fail('Project root was not created');
        }

        return $this->root;
    }

    private function removeRoot(): void
    {
        if ($this->root === null) {
            return;
        }
        if (is_dir($this->root)) {
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
        $this->root = null;
    }
}

/**
 * Facade standing in for a project spine.
 *
 * Abstract because the prelude calls static init on the class and builds no instance.
 */
abstract class EntrypointPreludeTestHilos extends Hilos
{
}
