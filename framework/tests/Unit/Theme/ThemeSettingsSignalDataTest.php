<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Theme;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\Settings\SettingsCatalogStub;
use Hilos\Hilos;
use Hilos\Theme\DTO\ThemeSettingsSignalData;
use Hilos\Theme\ThemeSettingsCatalog;
use PHPUnit\Framework\TestCase;

/** Theme settings use one complete, typed wire pair (HIL-1428). */
final class ThemeSettingsSignalDataTest extends TestCase
{
    private ?SettingsAccessor $previousSetting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousSetting = Hilos::$setting;
    }

    protected function tearDown(): void
    {
        Hilos::$setting = $this->previousSetting;
        parent::tearDown();
    }

    public function testAProjectWithoutTheThemeFragmentGetsTheDefaults(): void
    {
        Hilos::$setting = new SettingsAccessor(SettingsCatalogStub::class);

        self::assertSame(
            ['switchingEnabled' => true, 'defaultTheme' => 'system'],
            ThemeSettingsSignalData::current()->toArray(),
        );
    }

    public function testAProcessWithoutSettingsGetsTheDefaults(): void
    {
        Hilos::$setting = null;

        self::assertSame(
            ['switchingEnabled' => true, 'defaultTheme' => 'system'],
            ThemeSettingsSignalData::current()->toArray(),
        );
    }

    public function testRoundtripKeepsBothSettings(): void
    {
        $data = new ThemeSettingsSignalData(false, ThemeSettingsCatalog::DARK);

        self::assertSame(['switchingEnabled' => false, 'defaultTheme' => 'dark'], $data->toArray());
        self::assertSame($data->toArray(), ThemeSettingsSignalData::fromArray($data->toArray())->toArray());
    }

    public function testSerializationDoesNotReplaceAValueWithTheDefault(): void
    {
        $data = new ThemeSettingsSignalData(true, ThemeSettingsCatalog::LIGHT);

        self::assertSame(ThemeSettingsCatalog::LIGHT, $data->toArray()[ThemeSettingsSignalData::defaultTheme]);
    }

    public function testMissingSwitchingFlagIsRejected(): void
    {
        $this->expectException(InvalidFormatException::class);

        ThemeSettingsSignalData::fromArray(['defaultTheme' => ThemeSettingsCatalog::SYSTEM]);
    }

    public function testWrongSwitchingFlagTypeIsRejected(): void
    {
        $this->expectException(InvalidFormatException::class);

        ThemeSettingsSignalData::fromArray(['switchingEnabled' => 'true', 'defaultTheme' => ThemeSettingsCatalog::SYSTEM]);
    }

    public function testMissingDefaultThemeIsRejected(): void
    {
        $this->expectException(InvalidFormatException::class);

        ThemeSettingsSignalData::fromArray(['switchingEnabled' => true]);
    }

    public function testUnknownDefaultThemeIsRejected(): void
    {
        $this->expectException(InvalidFormatException::class);

        ThemeSettingsSignalData::fromArray(['switchingEnabled' => true, 'defaultTheme' => 'sepia']);
    }
}
