<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core;

use Hilos\Cluster\ClusterContext;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Bootstrap\EntrypointPrelude;
use Hilos\Database\Migration;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\Exception\EnvInvalidValueException;
use Hilos\Hilos;
use Hilos\LLM\Routing\LlmRouter;
use Hilos\Utils\Helpers\TimeHelper;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * The shared start sets this process to UTC and judges the project zone only on run().
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

    protected function setUp(): void
    {
        parent::setUp();
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
        putenv('TZ=' . self::CONTAINER_TZ);
        date_default_timezone_set(self::CONTAINER_TZ);
        $this->assertNotSame('+00:00', date('P'));
    }

    protected function tearDown(): void
    {
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

    public function testRunCallsPersistenceInUtcAfterTheTestOverride(): void
    {
        $this->writeEnv("HILOS_PROJECT_TIME_ZONE=Not/AZone\n", "HILOS_PROJECT_TIME_ZONE=Europe/Kyiv\n");
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

    public function testRunUsesTheCatalogDefaultWhenTheOverrideOmitsTheZone(): void
    {
        $this->writeEnv("HILOS_PROJECT_TIME_ZONE=Not/AZone\n", "APP_ENV=test\n");
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
        $testEnv = $this->root . '/tests/.env';
        if (is_file($testEnv)) {
            unlink($testEnv);
        }
        if (is_dir($this->root . '/tests')) {
            rmdir($this->root . '/tests');
        }
        if (is_file($this->root . '/.env')) {
            unlink($this->root . '/.env');
        }
        if (is_dir($this->root)) {
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
