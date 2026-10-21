<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Closure;
use Demo\Chat\Database\Settings\ChatSettingsConstants;
use Demo\Chat\Hilos;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\DbSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DbSyncApplicator;
use Hilos\Database\Entity\Item\Setting as EntitySetting;
use Hilos\Database\Settings\Exception\SettingNotInCatalogException;
use Hilos\Database\Settings\Exception\SettingTypeMismatchException;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Tables\Settings\HilosSettingTableRow;

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
        TruthSourceRegistry::register(HilosDbContext::settings, TruthSourceKeys::all(), self::TEST_SETTINGS_AGENT_ID);

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

    /** Reads held values until real DB sync applies the peer's update, without warm SELECTs. */
    public function testEffectiveValueFollowsAppliedSettingUpdates(): void
    {
        $key = ChatSettingsConstants::DEFAULT_BOT_MODEL;
        $held = Hilos::$db->settings[$key];
        $this->assertNotNull($held);
        $original = $held->value;
        $reader = Hilos::$setting[ChatSettingsConstants::CHAT_BOT_MODEL];
        $this->assertSame($original, $reader->string());
        $this->assertTrue(Hilos::$db->settings->getObjectCollection()->isAllLoaded());
        $remote = EntitySetting::get([EntitySetting::key => $key])->first();
        $this->assertNotNull($remote);

        try {
            foreach ([['model-from-peer', 'model-from-peer'], [null, 'qwen2.5:3b'], ['', '']] as [$value, $expected]) {
                $previous = $held->value;
                $previousEffective = $reader->string();
                $remote->value = $value;
                $remote->save();

                $this->assertQuietReads(function () use ($held, $reader, $previous, $previousEffective): void {
                    $this->assertSame($previous, $held->value);
                    $this->assertSame($previousEffective, $reader->string());
                    $this->assertSame($previousEffective, Hilos::$setting[ChatSettingsConstants::CHAT_BOT_MODEL]->string());
                });

                DbSyncApplicator::applyUpdated(new DbSyncUpdatedSignalData(
                    HilosDbContext::settings,
                    (string) $remote->id,
                    [EntitySetting::value => $value],
                ), originNodeId: 'peer');

                $this->assertQuietReads(function () use ($held, $reader, $key, $value, $expected): void {
                    $this->assertSame($value, $held->value);
                    $this->assertSame($held->getObject(), Hilos::$db->settings[$key]->getObject());
                    $this->assertSame($expected, $reader->string());
                    $this->assertSame($expected, Hilos::$setting[ChatSettingsConstants::CHAT_BOT_MODEL]->string());
                });
            }
        } finally {
            $remote->value = $original;
            $remote->save();
            DbSyncApplicator::applyUpdated(new DbSyncUpdatedSignalData(
                HilosDbContext::settings,
                (string) $remote->id,
                [EntitySetting::value => $original],
            ), originNodeId: 'peer');
        }
    }

    /** A warm complete collection takes remote membership changes and resolves every default hop. */
    public function testAppliedCreateAndDeleteResolveDefaultChainWithoutSelects(): void
    {
        $previousAccessor = Hilos::$setting;
        Hilos::$setting = new SettingsAccessor(SettingsAccessorMemoryCatalog::class);
        $remote = new EntitySetting();
        $remote->key = 'memory.root';
        $remote->value = 'peer-root';

        try {
            $unrelated = Hilos::$db->settings[ChatSettingsConstants::DEFAULT_BOT_MODEL];
            $reader = Hilos::$setting['memory.leaf'];
            $this->assertSame('catalog-root', $reader->string());
            $this->assertTrue(Hilos::$db->settings->getObjectCollection()->isAllLoaded());
            $remote->save();
            $this->assertQuietReads(function () use ($reader): void {
                $this->assertArrayNotHasKey('memory.root', Hilos::$db->settings);
                $this->assertSame('catalog-root', $reader->string());
                $this->assertSame('catalog-root', Hilos::$setting->defaultValueFor('memory.leaf'));
            });

            DbSyncApplicator::applyCreated(new DbSyncCreatedSignalData(
                HilosDbContext::settings,
                (string) $remote->id,
                $remote->toArray(),
            ), originNodeId: 'peer');
            $held = Hilos::$db->settings['memory.root'];
            $this->assertNotNull($held);
            $this->assertQuietReads(function () use ($reader, $held, $unrelated): void {
                $this->assertSame('peer-root', $reader->string());
                $this->assertSame('peer-root', Hilos::$setting['memory.leaf']->string());
                $this->assertSame('peer-root', Hilos::$setting->defaultValueFor('memory.leaf'));
                $this->assertSame($held, Hilos::$db->settings[$held->id]);
                $this->assertSame($unrelated, Hilos::$db->settings[ChatSettingsConstants::DEFAULT_BOT_MODEL]);
            });

            $remote->delete();
            $this->assertQuietReads(function () use ($reader, $held): void {
                $this->assertSame('peer-root', $reader->string());
                $this->assertSame($held, Hilos::$db->settings['memory.root']);
            });
            DbSyncApplicator::applyDeleted(new DbSyncDeletedSignalData(
                HilosDbContext::settings,
                (string) $remote->id,
                $remote->toArray(),
            ), originNodeId: 'peer');
            $this->assertQuietReads(function () use ($reader, $held, $unrelated): void {
                $this->assertArrayNotHasKey('memory.root', Hilos::$db->settings);
                $this->assertArrayNotHasKey($held->id, Hilos::$db->settings);
                $this->assertSame('catalog-root', $reader->string());
                $this->assertSame('catalog-root', Hilos::$setting['memory.leaf']->string());
                $this->assertSame('catalog-root', Hilos::$setting->defaultValueFor('memory.leaf'));
                $this->assertSame($unrelated, Hilos::$db->settings[ChatSettingsConstants::DEFAULT_BOT_MODEL]);
            });
        } finally {
            try {
                if ($remote->isRelated()) {
                    $remote->delete();
                }
                if ($remote->id !== null) {
                    DbSyncApplicator::applyDeleted(new DbSyncDeletedSignalData(
                        HilosDbContext::settings,
                        (string) $remote->id,
                    ), originNodeId: 'peer');
                }
            } finally {
                Hilos::$setting = $previousAccessor;
            }
        }
    }

    /** Null falls back, while persisted zero and false remain values of their declared type. */
    public function testAppliedNullAndZeroKeepTypedDefaultSemantics(): void
    {
        $previousAccessor = Hilos::$setting;
        Hilos::$setting = new SettingsAccessor(SettingsAccessorMemoryCatalog::class);

        try {
            foreach ([['integer', 'int', 23, 0], ['float', 'float', 2.5, 0.0], ['boolean', 'bool', true, false]] as $case) {
                [$type, $method, $default, $zero] = $case;
                $key = 'memory.' . $type;
                $reader = Hilos::$setting[$key];
                $this->assertSame($default, $reader->{$method}());
                $remote = new EntitySetting();
                $remote->key = $key;
                $remote->type = $type;
                $remote->value = null;

                try {
                    $remote->save();
                    DbSyncApplicator::applyCreated(new DbSyncCreatedSignalData(
                        HilosDbContext::settings,
                        (string) $remote->id,
                        $remote->toArray(),
                    ), originNodeId: 'peer');
                    $this->assertQuietReads(function () use ($reader, $method, $default): void {
                        $this->assertSame($default, $reader->{$method}());
                    });

                    $remote->value = '0';
                    $remote->save();
                    $this->assertQuietReads(function () use ($reader, $method, $default): void {
                        $this->assertSame($default, $reader->{$method}());
                    });
                    DbSyncApplicator::applyUpdated(new DbSyncUpdatedSignalData(
                        HilosDbContext::settings,
                        (string) $remote->id,
                        [EntitySetting::value => '0'],
                    ), originNodeId: 'peer');
                    $this->assertQuietReads(function () use ($reader, $method, $key, $zero): void {
                        $this->assertSame($zero, $reader->{$method}());
                        $this->assertSame($zero, Hilos::$setting[$key]->{$method}());
                    });
                } finally {
                    if ($remote->id !== null) {
                        $remote->delete();
                        DbSyncApplicator::applyDeleted(new DbSyncDeletedSignalData(
                            HilosDbContext::settings,
                            (string) $remote->id,
                        ), originNodeId: 'peer');
                    }
                }
            }
        } finally {
            Hilos::$setting = $previousAccessor;
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
            if (($candidate[HilosSettingTableRow::key] ?? null) === ChatSettingsConstants::CHAT_BOT_MODEL) {
                $row = $candidate;
                break;
            }
        }

        $this->assertIsArray($row);
        $this->assertSame('qwen2.5:3b', $row[HilosSettingTableRow::value]);
        $this->assertNull($row[HilosSettingTableRow::overrideValue]);
        $this->assertSame('qwen2.5:3b', $row[HilosSettingTableRow::defaultValue]);
        $this->assertSame(ChatSettingsConstants::DEFAULT_BOT_MODEL, $row[HilosSettingTableRow::defaultReferenceKey]);
        $this->assertSame(HilosSettingTableRow::VALUE_SOURCE_REFERENCE, $row[HilosSettingTableRow::valueSource]);
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

    /** @param Closure(): void $read Repeated reads whose SQL cost is measured */
    private function assertQuietReads(Closure $read): void
    {
        $before = (int) Database::sql("SHOW SESSION STATUS LIKE 'Com_select'")->firstRow()['Value'];
        for ($iteration = 0; $iteration < 3; $iteration++) {
            $read();
        }
        $this->assertSame($before, (int) Database::sql("SHOW SESSION STATUS LIKE 'Com_select'")->firstRow()['Value']);
    }
}

/** Catalog fixtures whose nonzero defaults expose accidental fallback on false or zero. */
final class SettingsAccessorMemoryCatalog implements CatalogProviderInterface
{
    /** @return array<string, array<string, mixed>> Typed defaults and a three-key reference chain */
    public static function getCatalog(): array
    {
        return [
            'memory.root' => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_STRING,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => 'catalog-root',
            ],
            'memory.middle' => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_STRING,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => [SettingsCatalogConstants::CATALOG_DEFAULT_SETTING_KEY => 'memory.root'],
            ],
            'memory.leaf' => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_STRING,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => [SettingsCatalogConstants::CATALOG_DEFAULT_SETTING_KEY => 'memory.middle'],
            ],
            'memory.integer' => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_INTEGER,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => 23,
            ],
            'memory.float' => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_FLOAT,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => 2.5,
            ],
            'memory.boolean' => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_BOOLEAN,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => true,
            ],
        ];
    }
}
