<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Database\Settings\ChatSettingsConstants;
use Demo\Chat\Hilos;
use Demo\Chat\Tables\Settings\SettingTableRow;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Settings\Exception\SettingNotInCatalogException;
use Hilos\Database\Settings\Exception\SettingTypeMismatchException;
use Hilos\Database\Settings\SettingsCatalogConstants;

/**
 * Integration coverage for catalog-backed Hilos::$setting access.
 */
final class SettingsAccessorTest extends IntegrationTestCase
{
    private const string TEST_SETTINGS_AGENT_ID = 'test-settings-agent';

    /**
     * Reads resolved settings through matching typed accessors.
     */
    public function testReadsResolvedTypedSettings(): void
    {
        $this->assertSame('qwen2.5:3b', Hilos::$setting[ChatSettingsConstants::CHAT_BOT_MODEL]->string());
        $this->assertSame('qwen2.5:3b', Hilos::$setting[ChatSettingsConstants::CHAT_MODERATION_MODEL]->string());
        $this->assertSame(90.0, Hilos::$setting[ChatSettingsConstants::CHAT_BOT_TIMEOUT_SEC]->float());
    }

    /**
     * Reads inherited defaults through the configured reference key.
     */
    public function testReadsDefaultReferenceMetadata(): void
    {
        $this->assertSame(
            ChatSettingsConstants::DEFAULT_BOT_MODEL,
            Hilos::$setting->defaultReferenceKeyFor(ChatSettingsConstants::CHAT_BOT_MODEL),
        );
        $this->assertSame(
            ChatSettingsConstants::DEFAULT_BOT_TIMEOUT_SEC,
            Hilos::$setting->defaultReferenceKeyFor(ChatSettingsConstants::CHAT_MODERATION_TIMEOUT_SEC),
        );
    }

    /**
     * Reads persisted settings through the documented key-based collection offset.
     */
    public function testSettingsCollectionSupportsKeyBasedOffset(): void
    {
        $this->assertSame(
            'qwen2.5:3b',
            Hilos::$db->settings[ChatSettingsConstants::DEFAULT_BOT_MODEL]?->value,
        );
        $this->assertTrue(isset(Hilos::$db->settings[ChatSettingsConstants::DEFAULT_BOT_MODEL]));
    }

    /**
     * Keeps an explicit empty string as the persisted value.
     */
    public function testEmptyPersistedStringDoesNotFallBackToDefault(): void
    {
        $setting = Hilos::$db->settings[ChatSettingsConstants::DEFAULT_BOT_MODEL];
        $originalValue = $setting?->value;
        TruthSourceRegistry::register(HilosDbContext::settings, true, self::TEST_SETTINGS_AGENT_ID);

        try {
            $setting?->actions->updateValue('');

            $this->assertSame('', Hilos::$setting[ChatSettingsConstants::CHAT_BOT_MODEL]->string());
        } finally {
            try {
                $setting?->actions->updateValue($originalValue);
            } finally {
                TruthSourceRegistry::unregisterAgent(self::TEST_SETTINGS_AGENT_ID);
            }
        }
    }

    /**
     * Exposes resolved default reference metadata in the settings table row.
     */
    public function testSettingsTableRowExposesDefaultReferenceMetadata(): void
    {
        $snapshot = Hilos::$table->settings->getFullSnapshot()->toArray();
        $rows = $snapshot[TableConstants::RESULT_KEY_ROWS];
        $row = null;
        foreach ($rows as $candidate) {
            if (($candidate[SettingTableRow::key] ?? null) === ChatSettingsConstants::CHAT_BOT_MODEL) {
                $row = $candidate;
                break;
            }
        }

        $this->assertIsArray($row);
        $this->assertSame('qwen2.5:3b', $row[SettingTableRow::value]);
        $this->assertNull($row[SettingTableRow::overrideValue]);
        $this->assertSame('qwen2.5:3b', $row[SettingTableRow::defaultValue]);
        $this->assertSame(ChatSettingsConstants::DEFAULT_BOT_MODEL, $row[SettingTableRow::defaultReferenceKey]);
        $this->assertSame(SettingTableRow::VALUE_SOURCE_REFERENCE, $row[SettingTableRow::valueSource]);
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
