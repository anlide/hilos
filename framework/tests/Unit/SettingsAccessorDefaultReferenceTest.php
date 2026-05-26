<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Settings\Exception\SettingDefaultReferenceCycleException;
use Hilos\Database\Settings\Exception\SettingInvalidValueException;
use Hilos\Database\Settings\Exception\SettingNotInCatalogException;
use Hilos\Database\Settings\Exception\SettingTypeMismatchException;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for catalog default references.
 */
final class SettingsAccessorDefaultReferenceTest extends TestCase
{
    private ?DbContext $previousDb = null;
    private ?SettingsAccessor $previousSetting = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousDb = Hilos::$db;
        $this->previousSetting = Hilos::$setting;
        Hilos::$db = null;
        Hilos::$setting = null;
    }

    protected function tearDown(): void
    {
        Hilos::$db = $this->previousDb;
        Hilos::$setting = $this->previousSetting;

        parent::tearDown();
    }

    public function testResolvesDefaultReference(): void
    {
        $settings = $this->settings([
            'base_model' => $this->entry(SettingsCatalogConstants::TYPE_STRING, 'qwen2.5:3b'),
            'bot_model' => $this->entry(SettingsCatalogConstants::TYPE_STRING, [
                SettingsCatalogConstants::CATALOG_DEFAULT_SETTING_KEY => 'base_model',
            ]),
        ]);

        $this->assertSame('qwen2.5:3b', $settings->defaultValueFor('bot_model'));
        $this->assertSame('base_model', $settings->defaultReferenceKeyFor('bot_model'));
    }

    public function testResolvesDefaultReferenceChain(): void
    {
        $settings = $this->settings([
            'first' => $this->entry(SettingsCatalogConstants::TYPE_STRING, 'root'),
            'second' => $this->entry(SettingsCatalogConstants::TYPE_STRING, [
                SettingsCatalogConstants::CATALOG_DEFAULT_SETTING_KEY => 'first',
            ]),
            'third' => $this->entry(SettingsCatalogConstants::TYPE_STRING, [
                SettingsCatalogConstants::CATALOG_DEFAULT_SETTING_KEY => 'second',
            ]),
        ]);

        $this->assertSame('root', $settings->defaultValueFor('third'));
    }

    public function testRejectsSelfReferencingDefault(): void
    {
        $settings = $this->settings([
            'self' => $this->entry(SettingsCatalogConstants::TYPE_STRING, [
                SettingsCatalogConstants::CATALOG_DEFAULT_SETTING_KEY => 'self',
            ]),
        ]);

        $this->expectException(SettingDefaultReferenceCycleException::class);
        $this->expectExceptionMessage('self -> self');

        $settings->defaultValueFor('self');
    }

    public function testRejectsIndirectDefaultReferenceCycle(): void
    {
        $settings = $this->settings([
            'first' => $this->entry(SettingsCatalogConstants::TYPE_STRING, [
                SettingsCatalogConstants::CATALOG_DEFAULT_SETTING_KEY => 'second',
            ]),
            'second' => $this->entry(SettingsCatalogConstants::TYPE_STRING, [
                SettingsCatalogConstants::CATALOG_DEFAULT_SETTING_KEY => 'first',
            ]),
        ]);

        $this->expectException(SettingDefaultReferenceCycleException::class);
        $this->expectExceptionMessage('first -> second -> first');

        $settings->defaultValueFor('first');
    }

    public function testRejectsMalformedArrayDefault(): void
    {
        $settings = $this->settings([
            'bad' => $this->entry(SettingsCatalogConstants::TYPE_STRING, ['target' => 'base']),
        ]);

        $this->expectException(SettingInvalidValueException::class);

        $settings->defaultValueFor('bad');
    }

    public function testRejectsMissingDefaultValue(): void
    {
        $settings = $this->settings([
            'bad' => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_STRING,
            ],
        ]);

        $this->expectException(SettingInvalidValueException::class);
        $this->expectExceptionMessage('must define');

        $settings->defaultValueFor('bad');
    }

    public function testRejectsNullDefaultValue(): void
    {
        $settings = $this->settings([
            'bad' => $this->entry(SettingsCatalogConstants::TYPE_STRING, null),
        ]);

        $this->expectException(SettingInvalidValueException::class);
        $this->expectExceptionMessage('must be scalar or a default reference array');

        $settings->defaultValueFor('bad');
    }

    public function testRejectsUnknownReferenceTarget(): void
    {
        $settings = $this->settings([
            'bot_model' => $this->entry(SettingsCatalogConstants::TYPE_STRING, [
                SettingsCatalogConstants::CATALOG_DEFAULT_SETTING_KEY => 'missing',
            ]),
        ]);

        $this->expectException(SettingNotInCatalogException::class);

        $settings->defaultValueFor('bot_model');
    }

    public function testRejectsReferenceToDifferentType(): void
    {
        $settings = $this->settings([
            'base_timeout' => $this->entry(SettingsCatalogConstants::TYPE_FLOAT, 90.0),
            'bot_model' => $this->entry(SettingsCatalogConstants::TYPE_STRING, [
                SettingsCatalogConstants::CATALOG_DEFAULT_SETTING_KEY => 'base_timeout',
            ]),
        ]);

        $this->expectException(SettingTypeMismatchException::class);

        $settings->defaultValueFor('bot_model');
    }

    /**
     * @param array<string, array<string, mixed>> $catalog Settings catalog
     */
    private function settings(array $catalog): SettingsAccessor
    {
        SettingsAccessorTestCatalog::$catalog = $catalog;

        return new SettingsAccessor(SettingsAccessorTestCatalog::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(string $type, mixed $default): array
    {
        return [
            SettingsCatalogConstants::CATALOG_ENTRY_TYPE => $type,
            SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => $default,
        ];
    }
}

/**
 * Catalog provider for SettingsAccessor unit tests.
 */
final class SettingsAccessorTestCatalog implements CatalogProviderInterface
{
    /** @var array<string, array<string, mixed>> Settings catalog */
    public static array $catalog = [];

    /**
     * Returns the current test catalog.
     *
     * @return array<string, array<string, mixed>> Catalog keyed by setting key
     */
    public static function getCatalog(): array
    {
        return self::$catalog;
    }
}
