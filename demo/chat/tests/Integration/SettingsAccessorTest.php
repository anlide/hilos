<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Database\Settings\ChatSettingsConstants;
use Demo\Chat\Hilos;
use Hilos\Database\Settings\Exception\SettingNotInCatalogException;
use Hilos\Database\Settings\Exception\SettingTypeMismatchException;
use Hilos\Database\Settings\SettingsCatalogConstants;

/**
 * Integration coverage for catalog-backed Hilos::$setting access.
 */
final class SettingsAccessorTest extends IntegrationTestCase
{
    /**
     * Reads persisted settings through matching typed accessors.
     */
    public function testReadsPersistedTypedSettings(): void
    {
        $this->assertSame('qwen2.5:3b', Hilos::$setting[ChatSettingsConstants::CHAT_BOT_MODEL]->string());
        $this->assertSame(90.0, Hilos::$setting[ChatSettingsConstants::CHAT_BOT_TIMEOUT_SEC]->float());
    }

    /**
     * Reads catalog defaults when a cataloged setting has no persisted row.
     */
    public function testReadsCatalogDefaultForMissingPersistedSetting(): void
    {
        $this->assertSame(0, Hilos::$setting[SettingsCatalogConstants::STUB_KEY_EXAMPLE_INTEGER]->int());
    }

    /**
     * Rejects access through a reader that does not match the catalog type.
     */
    public function testRejectsWrongTypedReader(): void
    {
        $this->expectException(SettingTypeMismatchException::class);

        Hilos::$setting[ChatSettingsConstants::CHAT_BOT_TIMEOUT_SEC]->string();
    }

    /**
     * Rejects keys that are not present in the catalog.
     */
    public function testRejectsUncatalogedSettingKey(): void
    {
        $this->expectException(SettingNotInCatalogException::class);

        Hilos::$setting[ChatSettingsConstants::ORPHAN_TEST_KEY]->string();
    }
}
