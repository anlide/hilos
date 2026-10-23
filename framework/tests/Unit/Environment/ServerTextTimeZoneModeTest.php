<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Environment;

use Hilos\Constants\EnvConstants;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogConstants;
use Hilos\Environment\EnvCatalogStub;
use Hilos\Environment\Exception\EnvInvalidValueException;
use Hilos\Environment\ServerTextTimeZoneMode;
use Hilos\Hilos;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Exact policy parsing through the real catalog and accessor. */
final class ServerTextTimeZoneModeTest extends TestCase
{
    private ?EnvAccessor $previousEnv;
    private string|false $previousMode;
    private string $previousTimezone;

    /** Isolates the process value and the active accessor for each case. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousEnv = Hilos::$env;
        $this->previousMode = getenv(EnvConstants::HILOS_SERVER_TEXT_TIME_ZONE_MODE->name);
        $this->previousTimezone = date_default_timezone_get();
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
        putenv(EnvConstants::HILOS_SERVER_TEXT_TIME_ZONE_MODE->name);
    }

    /** Restores the environment and verifies parsing left the process clock alone. */
    protected function tearDown(): void
    {
        Hilos::$env = $this->previousEnv;
        putenv(EnvConstants::HILOS_SERVER_TEXT_TIME_ZONE_MODE->name
            . ($this->previousMode === false ? '' : '=' . $this->previousMode));
        $this->assertSame($this->previousTimezone, date_default_timezone_get());
        parent::tearDown();
    }

    public function testCatalogDeclaresTheDefaultAndKeepsExplicitEmptyInput(): void
    {
        $entry = EnvCatalogStub::getCatalog()[EnvConstants::HILOS_SERVER_TEXT_TIME_ZONE_MODE->name];

        $this->assertSame(EnvCatalogConstants::TYPE_STRING, $entry[EnvCatalogConstants::CATALOG_ENTRY_TYPE]);
        $this->assertSame('recipient', $entry[EnvCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE]);
        $this->assertFalse($entry[EnvCatalogConstants::CATALOG_ENTRY_EMPTY_IS_MISSING]);
        $this->assertFalse($entry[EnvCatalogConstants::CATALOG_ENTRY_THROW_IF_MISSING]);
        $this->assertArrayNotHasKey(EnvCatalogConstants::CATALOG_ENTRY_SENSITIVE, $entry);
        $this->assertArrayNotHasKey(EnvCatalogConstants::CATALOG_ENTRY_PER_NODE, $entry);
        $this->assertArrayNotHasKey(EnvCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE, $entry);
    }

    public function testMissingValueUsesRecipient(): void
    {
        $this->assertSame(ServerTextTimeZoneMode::RECIPIENT, ServerTextTimeZoneMode::fromEnv());
    }

    /** @return iterable<string, array{string, ServerTextTimeZoneMode}> Exact supported names */
    public static function acceptedModes(): iterable
    {
        yield 'recipient' => ['recipient', ServerTextTimeZoneMode::RECIPIENT];
        yield 'project' => ['project', ServerTextTimeZoneMode::PROJECT];
        yield 'utc' => ['utc', ServerTextTimeZoneMode::UTC];
    }

    /**
     * @param string $value Exact env value
     * @param ServerTextTimeZoneMode $expected Corresponding policy
     */
    #[DataProvider('acceptedModes')]
    public function testExactNamesAreAccepted(string $value, ServerTextTimeZoneMode $expected): void
    {
        putenv(EnvConstants::HILOS_SERVER_TEXT_TIME_ZONE_MODE->name . '=' . $value);

        $this->assertSame($expected, ServerTextTimeZoneMode::fromEnv());
    }

    /** @return iterable<string, array{string}> Values that must not be normalized or defaulted */
    public static function refusedModes(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => ['   '];
        yield 'padded' => [' recipient '];
        yield 'uppercase' => ['UTC'];
        yield 'unknown' => ['browser'];
    }

    /** @param string $value Invalid original value */
    #[DataProvider('refusedModes')]
    public function testInvalidValueNamesTheKeyAndAllowedValues(string $value): void
    {
        putenv(EnvConstants::HILOS_SERVER_TEXT_TIME_ZONE_MODE->name . '=' . $value);

        $this->expectException(EnvInvalidValueException::class);
        $this->expectExceptionMessage(
            "Environment variable 'HILOS_SERVER_TEXT_TIME_ZONE_MODE' must be recipient, project or utc; got '{$value}'",
        );
        ServerTextTimeZoneMode::fromEnv();
    }
}
