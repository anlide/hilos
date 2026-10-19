<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Files;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Feature\Definition\UploadsFeature;
use Hilos\Database\Settings\Exception\SettingValueRefusedException;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Database\Settings\Validation\PositiveIntegerRule;
use Hilos\Database\Settings\Validation\SettingValueRules;
use Hilos\Files\Upload\UploadsSettingsCatalog;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/** Budget validation uses the settings write gate and imposes no binary frame-size minimum. */
final class UploadsSettingsCatalogTest extends TestCase
{
    public function testCatalogAndFeatureRequirementsAreExact(): void
    {
        $this->assertSame('uploads.memory_pool_bytes', UploadsSettingsCatalog::MEMORY_POOL_BYTES_KEY);
        $this->assertSame(2_147_483_648, UploadsSettingsCatalog::DEFAULT_MEMORY_POOL_BYTES);
        $this->assertSame([
            'uploads.memory_pool_bytes' => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_INTEGER,
                SettingsCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE => true,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => 2_147_483_648,
                SettingsCatalogConstants::CATALOG_ENTRY_RULE => PositiveIntegerRule::class,
            ],
        ], UploadsSettingsCatalog::getCatalog());

        $requirements = new UploadsFeature()->requirements();
        $this->assertSame([HilosAgentType::HILOS_UPLOADS], $requirements->requiredAgents);
        $this->assertSame([HilosAgentType::HILOS_SETTINGS_LIBRARY], $requirements->requiredSharedAgents);
        $this->assertSame([UploadsSettingsCatalog::class], $requirements->requiredCatalogFragments);
        $this->assertTrue($requirements->requiresPresenceSource);
    }

    public function testSmallBudgetsAndDefaultPassTheSharedWriteGate(): void
    {
        $previous = Hilos::$setting;
        Hilos::$setting = new SettingsAccessor(UploadsSettingsCatalog::class);
        try {
            $this->assertTrue(Hilos::$setting->visibleInAdminViewMode(UploadsSettingsCatalog::MEMORY_POOL_BYTES_KEY));
            foreach ([1, 3, 65535, 2_147_483_648, '1', '3'] as $value) {
                SettingValueRules::assertValid(UploadsSettingsCatalog::MEMORY_POOL_BYTES_KEY, $value);
                $this->assertNull(PositiveIntegerRule::validate($value));
            }
            foreach ([0, -1, '0', '-1', true, false, null, 1.5, '', []] as $value) {
                try {
                    SettingValueRules::assertValid(UploadsSettingsCatalog::MEMORY_POOL_BYTES_KEY, $value);
                    $this->fail('A nonpositive or noninteger budget must be refused');
                } catch (SettingValueRefusedException $exception) {
                    $this->assertSame('Value must be a positive integer', $exception->getMessage());
                }
            }
        } finally {
            Hilos::$setting = $previous;
        }
    }
}
