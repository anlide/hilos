<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Theme;

use Hilos\Database\Settings\Exception\SettingValueRefusedException;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Database\Settings\Validation\SettingValueRules;
use Hilos\Hilos;
use Hilos\Theme\ThemeDefaultRule;
use Hilos\Theme\ThemeSettingsCatalog;
use PHPUnit\Framework\TestCase;

/** Tests the theme catalog contract and the shared write gate (HIL-1426). */
final class ThemeSettingsCatalogTest extends TestCase
{
    private ?SettingsAccessor $previousSettings = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousSettings = Hilos::$setting;
        Hilos::$setting = new SettingsAccessor(ThemeSettingsCatalog::class);
    }

    protected function tearDown(): void
    {
        Hilos::$setting = $this->previousSettings;

        parent::tearDown();
    }

    public function testCatalogDeclaresBothDefaultsAndTheRule(): void
    {
        $this->assertSame([
            ThemeSettingsCatalog::SWITCHING_ENABLED_KEY,
            ThemeSettingsCatalog::DEFAULT_THEME_KEY,
        ], ThemeSettingsCatalog::KEYS);
        $this->assertSame([
            ThemeSettingsCatalog::SWITCHING_ENABLED_KEY => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_BOOLEAN,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => true,
                SettingsCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE => true,
            ],
            ThemeSettingsCatalog::DEFAULT_THEME_KEY => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_STRING,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => ThemeSettingsCatalog::SYSTEM,
                SettingsCatalogConstants::CATALOG_ENTRY_RULE => ThemeDefaultRule::class,
                SettingsCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE => true,
            ],
        ], ThemeSettingsCatalog::getCatalog());
    }

    public function testDefaultAndEveryAllowedPositionPassTheWriteGate(): void
    {
        $entry = ThemeSettingsCatalog::getCatalog()[ThemeSettingsCatalog::DEFAULT_THEME_KEY];
        $this->assertNull(ThemeDefaultRule::validate($entry[SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE]));

        foreach (ThemeSettingsCatalog::THEME_VALUES as $value) {
            SettingValueRules::assertValid(ThemeSettingsCatalog::DEFAULT_THEME_KEY, $value);
            $this->assertNull(ThemeDefaultRule::validate($value));
        }
    }

    public function testOtherValuesAreRefusedByTheWriteGate(): void
    {
        foreach (['', 'blue', 'Light', ' dark', 'system ', 1, true, null, ['light']] as $value) {
            try {
                SettingValueRules::assertValid(ThemeSettingsCatalog::DEFAULT_THEME_KEY, $value);
                $this->fail('Expected the theme default write to be refused');
            } catch (SettingValueRefusedException $exception) {
                $this->assertSame('Choose light, dark or system', $exception->getMessage());
            }
        }
    }
}
