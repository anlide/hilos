<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Database\Settings\SettingsCatalog;
use Demo\Chat\Hilos;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\OrphanTestCreateCommand;
use Hilos\Core\CLI\Commands\OrphanTestDeleteCommand;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\ItemNotFoundForDeleteException;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Settings\Exception\SettingKeyInCatalogException;
use Hilos\Database\Settings\Exception\SettingTypeMismatchException;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Integration coverage for the orphan settings write pair (HIL-118):
 * SettingsActions::addOrphan/deleteOrphan and the test:orphan CLI commands.
 *
 * Catalog overrides go through add()/OrphanSettingTestCreateCommand; this pair is the
 * uncataloged (true orphan) path an e2e fixture needs, with the inverse catalog guard
 * and non-idempotent contract.
 */
final class OrphanSettingActionsTest extends IntegrationTestCase
{
    private const string SETTINGS_AGENT_ID = 'test-orphan-actions-agent';

    /** @var string Catalog key, used to assert the inverse catalog guard refuses it */
    private const string CATALOG_KEY = SettingsCatalogConstants::STUB_KEY_EXAMPLE_STRING;

    public function testAddOrphanWritesUncatalogedRowReadableAsOrphan(): void
    {
        $key = 'orphan_actions_create_' . RandomHelper::hex(8);
        $this->withSettingsWriter(function () use ($key): void {
            $created = Hilos::$db->settings->actions->addOrphan(
                $key,
                SettingsCatalogConstants::TYPE_INTEGER,
                42,
                SettingsCatalog::getCatalog(),
            );

            $this->assertSame($key, $created->key);
            $this->assertSame(SettingsCatalogConstants::TYPE_INTEGER, $created->type);
            $this->assertSame('42', $created->value);

            $reloaded = Hilos::$db->settings[$key];
            $this->assertNotNull($reloaded);
            $this->assertTrue($reloaded->isOrphan(SettingsCatalog::getCatalog()));
        }, [$key]);
    }

    public function testAddOrphanRejectsCatalogKey(): void
    {
        $this->withSettingsWriter(function (): void {
            $this->expectException(SettingKeyInCatalogException::class);
            Hilos::$db->settings->actions->addOrphan(
                self::CATALOG_KEY,
                SettingsCatalogConstants::TYPE_STRING,
                'override-disguised-as-orphan',
                SettingsCatalog::getCatalog(),
            );
        }, []);
    }

    public function testAddOrphanRejectsUnsupportedType(): void
    {
        $key = 'orphan_actions_badtype_' . RandomHelper::hex(8);
        $this->withSettingsWriter(function () use ($key): void {
            $this->expectException(SettingTypeMismatchException::class);
            Hilos::$db->settings->actions->addOrphan(
                $key,
                'datetime',
                'now',
                SettingsCatalog::getCatalog(),
            );
        }, [$key]);
    }

    public function testAddOrphanIsNotIdempotent(): void
    {
        $key = 'orphan_actions_dup_' . RandomHelper::hex(8);
        $this->withSettingsWriter(function () use ($key): void {
            Hilos::$db->settings->actions->addOrphan(
                $key,
                SettingsCatalogConstants::TYPE_STRING,
                'first',
                SettingsCatalog::getCatalog(),
            );

            $this->expectException(DuplicateValueException::class);
            Hilos::$db->settings->actions->addOrphan(
                $key,
                SettingsCatalogConstants::TYPE_STRING,
                'second',
                SettingsCatalog::getCatalog(),
            );
        }, [$key]);
    }

    public function testDeleteOrphanRemovesRow(): void
    {
        $key = 'orphan_actions_delete_' . RandomHelper::hex(8);
        $this->withSettingsWriter(function () use ($key): void {
            Hilos::$db->settings->actions->addOrphan(
                $key,
                SettingsCatalogConstants::TYPE_STRING,
                'to-remove',
                SettingsCatalog::getCatalog(),
            );
            $this->assertNotNull(Hilos::$db->settings[$key]);

            Hilos::$db->settings->actions->deleteOrphan($key, SettingsCatalog::getCatalog());
            $this->assertNull(Hilos::$db->settings[$key]);
        }, [$key]);
    }

    public function testDeleteOrphanRejectsCatalogKey(): void
    {
        $this->withSettingsWriter(function (): void {
            $this->expectException(SettingKeyInCatalogException::class);
            Hilos::$db->settings->actions->deleteOrphan(self::CATALOG_KEY, SettingsCatalog::getCatalog());
        }, []);
    }

    public function testDeleteOrphanIsNotIdempotent(): void
    {
        $key = 'orphan_actions_absent_' . RandomHelper::hex(8);
        $this->withSettingsWriter(function () use ($key): void {
            $this->expectException(ItemNotFoundForDeleteException::class);
            Hilos::$db->settings->actions->deleteOrphan($key, SettingsCatalog::getCatalog());
        }, [$key]);
    }

    public function testCreateCommandWritesOrphanAndDeleteCommandRemovesIt(): void
    {
        $key = 'orphan_cmd_roundtrip_' . RandomHelper::hex(8);
        $this->withSettingsWriter(function () use ($key): void {
            $create = new OrphanTestCreateCommand();
            $this->assertSame(
                ExitCode::SUCCESS,
                $create->execute([], [$key, SettingsCatalogConstants::TYPE_BOOLEAN, 'true']),
            );
            $row = Hilos::$db->settings[$key];
            $this->assertNotNull($row);
            $this->assertSame('1', $row->value);

            $delete = new OrphanTestDeleteCommand();
            $this->assertSame(ExitCode::SUCCESS, $delete->execute([], [$key]));
            $this->assertNull(Hilos::$db->settings[$key]);
        }, [$key]);
    }

    public function testCreateCommandRejectsValueThatDoesNotMatchType(): void
    {
        $key = 'orphan_cmd_badvalue_' . RandomHelper::hex(8);
        $this->withSettingsWriter(function () use ($key): void {
            $create = new OrphanTestCreateCommand();
            $this->assertSame(
                ExitCode::INVALID_ARGUMENT,
                $create->execute([], [$key, SettingsCatalogConstants::TYPE_INTEGER, 'not-a-number']),
            );
            $this->assertNull(Hilos::$db->settings[$key]);
        }, [$key]);
    }

    public function testCreateCommandRejectsUnknownType(): void
    {
        $key = 'orphan_cmd_badtype_' . RandomHelper::hex(8);
        $this->withSettingsWriter(function () use ($key): void {
            $create = new OrphanTestCreateCommand();
            $this->assertSame(
                ExitCode::INVALID_ARGUMENT,
                $create->execute([], [$key, 'datetime', 'now']),
            );
            $this->assertNull(Hilos::$db->settings[$key]);
        }, [$key]);
    }

    /**
     * Registers the settings collection writer, runs the test body, then cleans up any
     * rows it may have written and releases the writer.
     *
     * @param callable():void $body Test body run while the settings writer is held
     * @param list<string> $keysToCleanup Orphan keys to delete afterward when present
     */
    private function withSettingsWriter(callable $body, array $keysToCleanup): void
    {
        TruthSourceRegistry::register(HilosDbContext::settings, true, self::SETTINGS_AGENT_ID);

        try {
            $body();
        } finally {
            foreach ($keysToCleanup as $key) {
                Hilos::$db->settings[$key]?->actions->delete();
            }
            TruthSourceRegistry::unregisterAgent(self::SETTINGS_AGENT_ID);
        }
    }
}
