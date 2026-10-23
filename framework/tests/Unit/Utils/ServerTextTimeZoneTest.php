<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Utils;

use Hilos\Constants\EnvConstants;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogStub;
use Hilos\Environment\Exception\EnvInvalidValueException;
use Hilos\Hilos;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Utils\Helpers\TimeHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Anonymous and installation modes have no dependency on DB, RT or the process zone. */
final class ServerTextTimeZoneTest extends TestCase
{
    private ?EnvAccessor $previousEnv;
    private ?HilosDbContext $previousDb;
    private ?RtContext $previousRt;
    private string|false $previousMode;
    private string|false $previousProject;
    private string $previousTimezone;

    /** Null facades make any accidental personal lookup fail immediately. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousEnv = Hilos::$env;
        $this->previousDb = Hilos::$db;
        $this->previousRt = Hilos::$rt;
        $this->previousMode = getenv(EnvConstants::HILOS_SERVER_TEXT_TIME_ZONE_MODE->name);
        $this->previousProject = getenv(EnvConstants::HILOS_PROJECT_TIME_ZONE->name);
        $this->previousTimezone = date_default_timezone_get();
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
        Hilos::$db = null;
        Hilos::$rt = null;
        putenv(EnvConstants::HILOS_SERVER_TEXT_TIME_ZONE_MODE->name);
        putenv(EnvConstants::HILOS_PROJECT_TIME_ZONE->name);
        date_default_timezone_set('Pacific/Auckland');
    }

    /** Restores the caller's facades, env values and process clock. */
    protected function tearDown(): void
    {
        Hilos::$env = $this->previousEnv;
        Hilos::$db = $this->previousDb;
        Hilos::$rt = $this->previousRt;
        putenv(EnvConstants::HILOS_SERVER_TEXT_TIME_ZONE_MODE->name
            . ($this->previousMode === false ? '' : '=' . $this->previousMode));
        putenv(EnvConstants::HILOS_PROJECT_TIME_ZONE->name
            . ($this->previousProject === false ? '' : '=' . $this->previousProject));
        date_default_timezone_set($this->previousTimezone);
        parent::tearDown();
    }

    /** @return iterable<string, array{?string, ?string, string}> Browser and project fallback candidates */
    public static function anonymousZones(): iterable
    {
        yield 'browser first' => ['Asia/Tokyo', 'America/New_York', 'Asia/Tokyo'];
        yield 'no arguments' => [null, 'America/New_York', 'America/New_York'];
        yield 'catalog UTC' => [null, null, 'UTC'];
        yield 'explicit UTC' => ['UTC', 'Europe/Warsaw', 'UTC'];
        yield 'browser alias' => ['Europe/Kiev', 'Europe/Warsaw', 'Europe/Kiev'];
        yield 'project alias' => [null, 'US/Eastern', 'US/Eastern'];
        foreach (['', '  ', ' Asia/Tokyo', 'Asia/Tokyo ', '+02:00', '-0500', '−05:00', 'CEST', 'Not/AZone', 'utc'] as $invalid) {
            yield 'invalid browser ' . $invalid => [$invalid, 'Europe/Warsaw', 'Europe/Warsaw'];
            yield 'invalid project ' . $invalid => [null, $invalid, 'UTC'];
        }
    }

    /**
     * @param ?string $browser Saved anonymous browser zone
     * @param ?string $project Configured project value, or missing
     * @param string $expected Exact selected name
     */
    #[DataProvider('anonymousZones')]
    public function testAnonymousFallback(?string $browser, ?string $project, string $expected): void
    {
        if ($project !== null) {
            putenv(EnvConstants::HILOS_PROJECT_TIME_ZONE->name . '=' . $project);
        }

        $this->assertSame($expected, TimeHelper::serverTextTimeZone(browserTimeZone: $browser)->getName());
        $this->assertSame('Pacific/Auckland', date_default_timezone_get());
    }

    /** @return iterable<string, array{string, string, string}> Installation modes ignoring personal inputs */
    public static function installationModes(): iterable
    {
        yield 'project' => ['project', 'America/New_York', 'America/New_York'];
        yield 'project alias' => ['project', 'Europe/Kiev', 'Europe/Kiev'];
        yield 'invalid project' => ['project', 'Not/AZone', 'UTC'];
        yield 'empty project' => ['project', '', 'UTC'];
        yield 'utc' => ['utc', 'Europe/Warsaw', 'UTC'];
        yield 'utc ignores invalid project' => ['utc', 'Not/AZone', 'UTC'];
    }

    /**
     * @param string $mode Installation policy
     * @param string $project Configured project value
     * @param string $expected Selected zone
     */
    #[DataProvider('installationModes')]
    public function testInstallationModesIgnorePersonAndBrowser(string $mode, string $project, string $expected): void
    {
        putenv(EnvConstants::HILOS_SERVER_TEXT_TIME_ZONE_MODE->name . '=' . $mode);
        putenv(EnvConstants::HILOS_PROJECT_TIME_ZONE->name . '=' . $project);

        $this->assertSame($expected, TimeHelper::serverTextTimeZone(1578, 'Asia/Tokyo')->getName());
        $this->assertSame('Pacific/Auckland', date_default_timezone_get());
    }

    public function testNoArgumentsUseTheProjectZone(): void
    {
        putenv(EnvConstants::HILOS_PROJECT_TIME_ZONE->name . '=Europe/Warsaw');

        $this->assertSame('Europe/Warsaw', TimeHelper::serverTextTimeZone()->getName());
    }

    public function testInvalidModeIsNotSwallowedAsUtc(): void
    {
        putenv(EnvConstants::HILOS_SERVER_TEXT_TIME_ZONE_MODE->name . '=invalid');

        $this->expectException(EnvInvalidValueException::class);
        TimeHelper::serverTextTimeZone();
    }
}
