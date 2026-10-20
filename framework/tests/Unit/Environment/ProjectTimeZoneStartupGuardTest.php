<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Environment;

use Hilos\Constants\EnvConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogConstants;
use Hilos\Environment\EnvCatalogStub;
use Hilos\Environment\Exception\EnvInvalidValueException;
use Hilos\Environment\ProjectTimeZoneStartupGuard;
use Hilos\Hilos;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The startup guard and the catalog default share one exact IANA name. */
final class ProjectTimeZoneStartupGuardTest extends TestCase
{
    private ?EnvAccessor $previousEnv;

    private string|false $previousZone;

    private string $previousTimezone;

    private string|false $previousTz;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousEnv = Hilos::$env;
        $this->previousZone = getenv(EnvConstants::HILOS_PROJECT_TIME_ZONE->name);
        $this->previousTimezone = date_default_timezone_get();
        $this->previousTz = getenv('TZ');
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
        putenv(EnvConstants::HILOS_PROJECT_TIME_ZONE->name);
    }

    protected function tearDown(): void
    {
        if ($this->previousZone === false) {
            putenv(EnvConstants::HILOS_PROJECT_TIME_ZONE->name);
        } else {
            putenv(EnvConstants::HILOS_PROJECT_TIME_ZONE->name . '=' . $this->previousZone);
        }
        date_default_timezone_set($this->previousTimezone);
        if ($this->previousTz === false) {
            putenv('TZ');
        } else {
            putenv('TZ=' . $this->previousTz);
        }
        Hilos::$env = $this->previousEnv;
        parent::tearDown();
    }

    public function testCatalogDefaultIsUtcAndAnEmptyStringIsKept(): void
    {
        $entry = EnvCatalogStub::getCatalog()[EnvConstants::HILOS_PROJECT_TIME_ZONE->name];

        $this->assertSame(EnvCatalogConstants::TYPE_STRING, $entry[EnvCatalogConstants::CATALOG_ENTRY_TYPE]);
        $this->assertSame(TimeConstants::UTC_TIME_ZONE, $entry[EnvCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE]);
        $this->assertFalse($entry[EnvCatalogConstants::CATALOG_ENTRY_EMPTY_IS_MISSING]);
        $this->assertFalse($entry[EnvCatalogConstants::CATALOG_ENTRY_THROW_IF_MISSING]);
        $this->assertArrayNotHasKey(EnvCatalogConstants::CATALOG_ENTRY_SENSITIVE, $entry);
        $this->assertArrayNotHasKey(EnvCatalogConstants::CATALOG_ENTRY_PER_NODE, $entry);
        $this->assertArrayNotHasKey(EnvCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE, $entry);
    }

    public function testMissingZoneReadsUtcThroughTheAccessor(): void
    {
        ProjectTimeZoneStartupGuard::assertConfigured();

        $this->assertSame(
            TimeConstants::UTC_TIME_ZONE,
            Hilos::$env[EnvConstants::HILOS_PROJECT_TIME_ZONE]->string(),
        );
    }

    /** @return iterable<string, array{string}> Zones the IANA list names exactly */
    public static function acceptedZones(): iterable
    {
        yield 'UTC' => ['UTC'];
        yield 'Kyiv' => ['Europe/Kyiv'];
        yield 'Warsaw' => ['Europe/Warsaw'];
        yield 'backward alias' => ['Europe/Kiev'];
    }

    #[DataProvider('acceptedZones')]
    public function testAcceptedZonePassesUnchanged(string $zone): void
    {
        putenv(EnvConstants::HILOS_PROJECT_TIME_ZONE->name . '=' . $zone);

        ProjectTimeZoneStartupGuard::assertConfigured();

        $this->assertSame($zone, Hilos::$env[EnvConstants::HILOS_PROJECT_TIME_ZONE]->string());
    }

    /** @return iterable<string, array{string}> Values that are not exact IANA names */
    public static function refusedZones(): iterable
    {
        yield 'empty' => [''];
        yield 'unknown' => ['Not/AZone'];
        yield 'offset' => ['+02:00'];
        yield 'whitespace' => [' Europe/Kyiv'];
        yield 'blank' => ['   '];
        yield 'wrong case' => ['utc'];
    }

    #[DataProvider('refusedZones')]
    public function testRefusedZoneNamesTheVariableAndOriginalValue(string $zone): void
    {
        putenv(EnvConstants::HILOS_PROJECT_TIME_ZONE->name . '=' . $zone);

        $this->expectException(EnvInvalidValueException::class);
        $this->expectExceptionMessage(
            "Environment variable 'HILOS_PROJECT_TIME_ZONE' must name an IANA time zone; got '{$zone}'",
        );
        ProjectTimeZoneStartupGuard::assertConfigured();
    }
}
