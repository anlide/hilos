<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\Hilos\DemoHilosAgent;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\SettingsPage;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Object\Item\Setting as ObjectSetting;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Tables\Settings\DTO\HilosSettingAddActionDTO;
use Hilos\Tables\Settings\DTO\HilosSettingDeleteActionDTO;
use Hilos\Tables\Settings\DTO\HilosSettingResetActionDTO;
use Hilos\Tables\Settings\DTO\HilosSettingUpdateActionDTO;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Integration coverage for the settings page action layer (HIL-85).
 *
 * Drives {@see SettingsPage::onAction()} directly against the real catalog and DB:
 * the page routing + handler layer is not exercised by the actions-level
 * {@see SettingsBrowserStateTest}. Success cases assert the resulting DB state;
 * error cases assert the caller-facing exception a direct onAction() call raises
 * (no ack sink is needed when the page method is called directly).
 */
final class SettingsPageActionTest extends IntegrationTestCase
{
    private const string SETTINGS_AGENT_ID = 'test-settings-page-action-agent';

    /** @var string Catalog key (type string) used for add/update/delete-guard cases. */
    private const string CATALOG_KEY = SettingsCatalogConstants::STUB_KEY_EXAMPLE_STRING;

    /** @var string A second catalog key (no seeded override) for the update-missing case. */
    private const string UNSEEDED_CATALOG_KEY = SettingsCatalogConstants::STUB_KEY_EXAMPLE_INTEGER;

    public function testAddActionCreatesCatalogOverride(): void
    {
        $this->withSettingsWriter(function (): void {
            $this->deleteSettingIfExists(self::CATALOG_KEY);

            $this->settingsPage()->onAction(
                'add-ok-ak',
                HilosSignalConstants::SETTING_ADD,
                new HilosSettingAddActionDTO(self::CATALOG_KEY, 'from-page-action'),
            );

            $this->assertSame('from-page-action', Hilos::$db->settings[self::CATALOG_KEY]?->value);
        }, [self::CATALOG_KEY]);
    }

    public function testAddActionUpdatesExistingKeyInPlace(): void
    {
        $this->withSettingsWriter(function (): void {
            $this->deleteSettingIfExists(self::CATALOG_KEY);
            $this->settingsPage()->onAction(
                'add-dup-ak',
                HilosSignalConstants::SETTING_ADD,
                new HilosSettingAddActionDTO(self::CATALOG_KEY, 'first'),
            );
            // Snapshot before the second add: the row must be updated, not replaced.
            $createdId = Hilos::$db->settings[self::CATALOG_KEY]?->id;

            $this->settingsPage()->onAction(
                'add-dup-ak',
                HilosSignalConstants::SETTING_ADD,
                new HilosSettingAddActionDTO(self::CATALOG_KEY, 'second'),
            );

            $this->assertSame('second', Hilos::$db->settings[self::CATALOG_KEY]?->value);
            $this->assertSame($createdId, Hilos::$db->settings[self::CATALOG_KEY]?->id);
        }, [self::CATALOG_KEY]);
    }

    public function testUpdateActionChangesValue(): void
    {
        $this->withSettingsWriter(function (): void {
            $this->deleteSettingIfExists(self::CATALOG_KEY);
            $this->settingsPage()->onAction(
                'update-ok-ak',
                HilosSignalConstants::SETTING_ADD,
                new HilosSettingAddActionDTO(self::CATALOG_KEY, 'before'),
            );

            $this->settingsPage()->onAction(
                'update-ok-ak',
                HilosSignalConstants::SETTING_UPDATE,
                new HilosSettingUpdateActionDTO(self::CATALOG_KEY, 'after'),
            );

            $this->assertSame('after', Hilos::$db->settings[self::CATALOG_KEY]?->value);
        }, [self::CATALOG_KEY]);
    }

    public function testUpdateActionRejectsMissingSetting(): void
    {
        $this->withSettingsWriter(function (): void {
            $this->deleteSettingIfExists(self::UNSEEDED_CATALOG_KEY);

            $this->expectException(TableActionException::class);
            $this->settingsPage()->onAction(
                'update-missing-ak',
                HilosSignalConstants::SETTING_UPDATE,
                new HilosSettingUpdateActionDTO(self::UNSEEDED_CATALOG_KEY, 'no-row'),
            );
        }, []);
    }

    public function testDeleteActionRemovesOrphan(): void
    {
        $orphanKey = 'page_action_delete_orphan_' . RandomHelper::hex(8);
        $this->withSettingsWriter(function () use ($orphanKey): void {
            $this->createOrphanSetting($orphanKey, 'to-remove');
            $this->assertNotNull(Hilos::$db->settings[$orphanKey]);

            $this->settingsPage()->onAction(
                'delete-orphan-ak',
                HilosSignalConstants::SETTING_DELETE,
                new HilosSettingDeleteActionDTO($orphanKey),
            );

            $this->assertNull(Hilos::$db->settings[$orphanKey]);
        }, [$orphanKey]);
    }

    public function testDeleteActionRejectsCatalogKey(): void
    {
        $this->withSettingsWriter(function (): void {
            $this->deleteSettingIfExists(self::CATALOG_KEY);
            $this->settingsPage()->onAction(
                'delete-catalog-ak',
                HilosSignalConstants::SETTING_ADD,
                new HilosSettingAddActionDTO(self::CATALOG_KEY, 'override'),
            );

            $this->expectException(TableActionException::class);
            $this->settingsPage()->onAction(
                'delete-catalog-ak',
                HilosSignalConstants::SETTING_DELETE,
                new HilosSettingDeleteActionDTO(self::CATALOG_KEY),
            );
        }, [self::CATALOG_KEY]);
    }

    public function testResetActionRemovesTheCatalogKeyRow(): void
    {
        $this->withSettingsWriter(function (): void {
            $this->deleteSettingIfExists(self::CATALOG_KEY);
            $this->settingsPage()->onAction(
                'reset-ok-ak',
                HilosSignalConstants::SETTING_ADD,
                new HilosSettingAddActionDTO(self::CATALOG_KEY, 'to-reset'),
            );
            $this->assertNotNull(Hilos::$db->settings[self::CATALOG_KEY]);

            $this->settingsPage()->onAction(
                'reset-ok-ak',
                HilosSignalConstants::SETTING_RESET,
                new HilosSettingResetActionDTO(self::CATALOG_KEY),
            );

            // The row is gone, so the key reads as being on its catalog default again,
            // and the table row it leaves behind is the catalog placeholder (no id).
            $this->assertNull(Hilos::$db->settings[self::CATALOG_KEY]);
            $this->assertNull(Hilos::$table->settings->rowForKey(self::CATALOG_KEY)?->id);
        }, [self::CATALOG_KEY]);
    }

    public function testResetActionOnAKeyWithoutARowSucceeds(): void
    {
        $this->withSettingsWriter(function (): void {
            $this->deleteSettingIfExists(self::UNSEEDED_CATALOG_KEY);

            // Idempotent on purpose: a second admin may have reset first, and the race
            // must not turn into an error on this one's screen.
            $this->settingsPage()->onAction(
                'reset-absent-ak',
                HilosSignalConstants::SETTING_RESET,
                new HilosSettingResetActionDTO(self::UNSEEDED_CATALOG_KEY),
            );

            $this->assertNull(Hilos::$db->settings[self::UNSEEDED_CATALOG_KEY]);
        }, [self::UNSEEDED_CATALOG_KEY]);
    }

    public function testResetActionRejectsOrphan(): void
    {
        $orphanKey = 'page_action_reset_orphan_' . RandomHelper::hex(8);
        $this->withSettingsWriter(function () use ($orphanKey): void {
            $this->createOrphanSetting($orphanKey, 'not-resettable');

            // An orphan has no catalog default to return to. The screen never sends
            // this, but hiding a gesture is not securing it.
            $this->expectException(TableActionException::class);
            $this->settingsPage()->onAction(
                'reset-orphan-ak',
                HilosSignalConstants::SETTING_RESET,
                new HilosSettingResetActionDTO($orphanKey),
            );
        }, [$orphanKey]);
    }

    public function testAddActionRejectsEmptyKey(): void
    {
        $this->expectException(TableActionException::class);
        $this->settingsPage()->onAction(
            'empty-key-ak',
            HilosSignalConstants::SETTING_ADD,
            new HilosSettingAddActionDTO('', 'ignored'),
        );
    }

    public function testActionRejectsMismatchedPayloadType(): void
    {
        $this->expectException(InvalidActionPayloadException::class);
        $this->settingsPage()->onAction(
            'wrong-dto-ak',
            HilosSignalConstants::SETTING_ADD,
            new HilosSettingDeleteActionDTO(self::CATALOG_KEY),
        );
    }

    public function testUnknownActionThrows(): void
    {
        $this->expectException(AgentUnknownActionException::class);
        $this->settingsPage()->onAction(
            'unknown-ak',
            'setting_nonexistent_action',
            new HilosSettingDeleteActionDTO(self::CATALOG_KEY),
        );
    }

    /**
     * Builds a settings page bound to the Hilos index agent that owns it.
     *
     * @return SettingsPage Settings page under test
     */
    private function settingsPage(): SettingsPage
    {
        return new SettingsPage(new DemoHilosAgent());
    }

    /**
     * Creates an uncataloged persisted setting row for the delete-orphan case.
     *
     * @param string $key Orphan setting key
     * @param string $value Orphan setting value
     */
    private function createOrphanSetting(string $key, string $value): void
    {
        $setting = ObjectSetting::create();
        $setting->key = $key;
        $setting->type = SettingsCatalogConstants::TYPE_STRING;
        $setting->value = $value;
        $setting->sync();
    }

    /**
     * Deletes a persisted setting row when present.
     *
     * @param string $key Setting key
     */
    private function deleteSettingIfExists(string $key): void
    {
        Hilos::$db->settings[$key]?->actions->delete();
    }

    /**
     * Registers the settings collection writer, runs the test body, then cleans up any
     * rows it may have written and releases the writer.
     *
     * @param callable():void $body Test body run while the settings writer is held
     * @param list<string> $keysToCleanup Setting keys to delete afterward when present
     */
    private function withSettingsWriter(callable $body, array $keysToCleanup): void
    {
        TruthSourceRegistry::register(HilosDbContext::settings, true, self::SETTINGS_AGENT_ID);

        try {
            $body();
        } finally {
            foreach ($keysToCleanup as $key) {
                $this->deleteSettingIfExists($key);
            }
            TruthSourceRegistry::unregisterAgent(self::SETTINGS_AGENT_ID);
        }
    }
}
