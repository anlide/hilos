<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Database\Settings\ChatSettingsConstants;
use Demo\Chat\Database\Settings\SettingsCatalog;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Demo\Chat\Tables\ChatTableContext;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\TableViewportSubscription;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Table\DTO\TableWindowSignalData;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Object\Item\Setting as ObjectSetting;
use Hilos\Database\Settings\Exception\SettingNotInCatalogException;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\HilosException;
use Hilos\Tables\Settings\HilosSettingTableRow;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Integration coverage for the Hilos settings page browser payload.
 */
final class SettingsBrowserStateTest extends IntegrationTestCase
{
    private const string TEST_SETTINGS_AGENT_ID = 'test-settings-browser-agent';

    public function testSettingsSnapshotUsesBrowserRowsWithCatalogPlaceholdersAndOrphans(): void
    {
        $orphanKey = 'browser_snapshot_orphan_' . RandomHelper::hex(8);
        TruthSourceRegistry::register(HilosDbContext::settings, true, self::TEST_SETTINGS_AGENT_ID);

        try {
            $this->registerAdminConnection('settings-snapshot-ak');
            $this->createOrphanSetting($orphanKey, 'snapshot orphan');
            $rows = $this->sendSettingsWindow('settings-snapshot-ak');

            $placeholderRow = $this->findSettingsBrowserRow(
                $rows,
                SettingsCatalogConstants::STUB_KEY_EXAMPLE_INTEGER,
            );
            $this->assertIsArray($placeholderRow);
            $placeholder = $this->settingsSource($placeholderRow);
            $this->assertArrayNotHasKey(HilosSettingTableRow::id, $placeholder);
            $this->assertSame('0', $placeholder[HilosSettingTableRow::value]);
            $this->assertSame(HilosSettingTableRow::VALUE_SOURCE_DEFAULT, $placeholder[HilosSettingTableRow::valueSource]);

            $referenceRow = $this->findSettingsBrowserRow($rows, ChatSettingsConstants::CHAT_BOT_MODEL);
            $this->assertIsArray($referenceRow);
            $reference = $this->settingsSource($referenceRow);
            $this->assertSame(ChatSettingsConstants::DEFAULT_BOT_MODEL, $reference[HilosSettingTableRow::defaultReferenceKey]);
            $this->assertSame(HilosSettingTableRow::VALUE_SOURCE_REFERENCE, $reference[HilosSettingTableRow::valueSource]);

            $orphanRow = $this->findSettingsBrowserRow($rows, $orphanKey);
            $this->assertIsArray($orphanRow);
            $orphan = $this->settingsSource($orphanRow);
            $this->assertSame($orphanKey, $orphan[HilosSettingTableRow::key]);
            $this->assertSame(HilosSettingTableRow::VALUE_SOURCE_ORPHAN, $orphan[HilosSettingTableRow::valueSource]);
        } finally {
            Hilos::$rt->connections->actions->clear();
            RtTruthSourceRegistry::unregisterAgent(self::TEST_SETTINGS_AGENT_ID);
            $this->deleteSettingIfExists($orphanKey);
            TruthSourceRegistry::unregisterAgent(self::TEST_SETTINGS_AGENT_ID);
            $this->resetFrontendRouter();
        }
    }

    public function testSettingsBrowserMutationsUpdateCatalogRowsAndDeleteOrphans(): void
    {
        $catalogKey = SettingsCatalogConstants::STUB_KEY_EXAMPLE_STRING;
        $original = Hilos::$db->settings[$catalogKey] ?? null;
        $originalExists = $original !== null;
        $originalValue = $original?->value;
        $orphanKey = 'browser_delete_orphan_' . RandomHelper::hex(8);

        TruthSourceRegistry::register(HilosDbContext::settings, true, self::TEST_SETTINGS_AGENT_ID);

        try {
            $this->registerAdminConnection('settings-mutation-ak');
            $this->deleteSettingIfExists($catalogKey);
            $this->createOrphanSetting($orphanKey, 'delete orphan');

            Hilos::$table->settings->actions->add($catalogKey, 'browser custom');
            $created = $this->settingsSource($this->findSettingsBrowserRow(
                $this->sendSettingsWindow('settings-mutation-ak'),
                $catalogKey,
            ));
            $this->assertSame('browser custom', $created[HilosSettingTableRow::overrideValue]);
            $this->assertSame(HilosSettingTableRow::VALUE_SOURCE_OVERRIDE, $created[HilosSettingTableRow::valueSource]);

            Hilos::$table->settings[$catalogKey]->actions->updateValue(null);
            $updated = $this->settingsSource($this->findSettingsBrowserRow(
                $this->sendSettingsWindow('settings-mutation-ak'),
                $catalogKey,
            ));
            $this->assertNull($updated[HilosSettingTableRow::overrideValue]);
            $this->assertSame(HilosSettingTableRow::VALUE_SOURCE_DEFAULT, $updated[HilosSettingTableRow::valueSource]);

            // Deleting the override of a catalog key reverts the row to its default
            // placeholder; it stays in the window rather than being removed.
            Hilos::$db->settings[$catalogKey]?->actions->delete();
            $afterDelete = $this->settingsSource($this->findSettingsBrowserRow(
                $this->sendSettingsWindow('settings-mutation-ak'),
                $catalogKey,
            ));
            $this->assertArrayNotHasKey(HilosSettingTableRow::id, $afterDelete);
            $this->assertSame(HilosSettingTableRow::VALUE_SOURCE_DEFAULT, $afterDelete[HilosSettingTableRow::valueSource]);

            // Deleting an orphan (uncataloged) key removes it from the window entirely.
            Hilos::$table->settings[$orphanKey]->actions->delete();
            $this->assertNull($this->findSettingsBrowserRow(
                $this->sendSettingsWindow('settings-mutation-ak'),
                $orphanKey,
            ));
        } finally {
            Hilos::$rt->connections->actions->clear();
            RtTruthSourceRegistry::unregisterAgent(self::TEST_SETTINGS_AGENT_ID);
            $this->deleteSettingIfExists($catalogKey);
            if ($originalExists) {
                Hilos::$db->settings->actions->add($catalogKey, $originalValue, SettingsCatalog::getCatalog());
            }
            $this->deleteSettingIfExists($orphanKey);
            TruthSourceRegistry::unregisterAgent(self::TEST_SETTINGS_AGENT_ID);
            $this->resetFrontendRouter();
        }
    }

    public function testSettingsAddRejectsKeyNotInCatalog(): void
    {
        $nonCatalogKey = 'browser_non_catalog_' . RandomHelper::hex(8);
        TruthSourceRegistry::register(HilosDbContext::settings, true, self::TEST_SETTINGS_AGENT_ID);

        try {
            $this->expectException(SettingNotInCatalogException::class);
            Hilos::$table->settings->actions->add($nonCatalogKey, 'free-typed value');
        } finally {
            TruthSourceRegistry::unregisterAgent(self::TEST_SETTINGS_AGENT_ID);
        }
    }

    /**
     * Reinitializes worker-local routers before recording browser source changes.
     */
    private function resetFrontendRouter(): void
    {
        Hilos::initSignalRouter(new ChatSignalRouter());
        Hilos::initBrowser();
    }

    /**
     * Binds an admin user to an accept key, so the settings window passes the
     * ADMIN page access level (HIL-441): sendTableWindow re-checks the level on
     * every delivery and silently skips an anonymous accept key. The caller's
     * finally block clears the connections collection.
     *
     * @param string $acceptKey Accept key the test will request the window for
     * @throws HilosException When the user create, admin grant, or connection registration fails
     */
    private function registerAdminConnection(string $acceptKey): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, true, self::TEST_SETTINGS_AGENT_ID);
        $userId = (int) Hilos::$db->users->actions->createWithName('Settings Browser Admin')->id;
        Hilos::$db->users[$userId]?->actions->setAdmin(true);
        Hilos::$rt->connections->actions->register($acceptKey, $userId);
    }

    /**
     * Sends the full settings window to a connection and returns its rows.
     *
     * Settings is a viewport table, so its rows reach the client through the
     * table_viewport / table_window cycle. The window query reads the current DB
     * state, so it reflects any preceding mutation without a source-change flush.
     *
     * @param string $acceptKey Target accept key
     * @return list<array<string, mixed>> Window rows
     */
    private function sendSettingsWindow(string $acceptKey): array
    {
        $this->resetFrontendRouter();
        Hilos::$browser?->sendTableWindow(
            PageConstants::HILOS_SETTINGS,
            $acceptKey,
            new TableViewportSubscription(tableKey: ChatTableContext::settings, limit: TableConstants::NO_LIMIT),
        );

        return $this->drainWindowRows($acceptKey);
    }

    /**
     * Drains the single table_window addressed to the accept key and returns its rows.
     *
     * @param string $targetAcceptKey Expected target accept key
     * @return list<array<string, mixed>> Window rows
     */
    private function drainWindowRows(string $targetAcceptKey): array
    {
        $signals = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) instanceof SignalDTO) {
            if (
                $signal->signalName->getName() === SignalTypeConstants::TABLE_WINDOW
                && $signal->data instanceof WebSocketSignalData
                && $signal->data->targetAcceptKey === $targetAcceptKey
            ) {
                $signals[] = $signal;
            }
        }

        $this->assertCount(1, $signals);
        $webSocketData = $signals[0]->data;
        $this->assertInstanceOf(WebSocketSignalData::class, $webSocketData);
        $this->assertInstanceOf(TableWindowSignalData::class, $webSocketData->data);

        $rows = $webSocketData->data->toArray()[TableWindowSignalData::rows] ?? [];
        $this->assertIsArray($rows);

        return $rows;
    }

    /**
     * Finds a settings browser row by settings key.
     *
     * @param list<array<string, mixed>> $rows Browser rows
     * @param string $key Setting key to find
     * @return ?array<string, mixed> Matching browser row
     */
    private function findSettingsBrowserRow(array $rows, string $key): ?array
    {
        foreach ($rows as $row) {
            $setting = $row[PagePayload::slots][HilosDbContext::settings] ?? null;
            if (is_array($setting) && ($setting[HilosSettingTableRow::key] ?? null) === $key) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Returns the settings source fragment from a browser row.
     *
     * @param ?array<string, mixed> $row Browser row
     * @return array<string, mixed> Settings source fragment
     */
    private function settingsSource(?array $row): array
    {
        $this->assertIsArray($row);
        $setting = $row[PagePayload::slots][HilosDbContext::settings] ?? null;
        $this->assertIsArray($setting);

        return $setting;
    }

    /**
     * Creates an uncataloged persisted setting row for orphan coverage.
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
}
