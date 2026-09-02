<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\Hilos\DemoHilosLogsAgent;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\Logs\LogsSettingsPage;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Settings\Preset\SettingPresetResolver;
use Hilos\Log\LogSettingsCatalog;
use Hilos\Log\LogSettingsPresets;
use Hilos\Pages\DTO\SettingPresetApplyActionDTO;
use Hilos\Tables\Settings\Actions\HilosSettingsTableActions;
use Hilos\Tables\Settings\HilosSettingsTable;

/**
 * Integration coverage for applying a logging mode (HIL-762).
 *
 * The half of the mechanism a unit test cannot reach: the writes themselves, against the real
 * catalog, the real settings table and a real database. What is locked here is the shape of the
 * operation rather than the recipe — that every member gets a row of its own, that the selection
 * is written under them, that pressing the same card twice is a success, and that a name the
 * group never declared writes nothing.
 *
 * Every member gets a row even where its value already equals the catalog default, and that is
 * the point of the assertion rather than an accident of the fixture: the default of these keys is
 * an environment variable, per node, while a settings row is shared by the database. A member
 * left without a row would sit at a different value on every node of a cluster, and a mode is a
 * statement about the installation.
 */
final class SettingPresetApplyTest extends IntegrationTestCase
{
    private const string SETTINGS_AGENT_ID = 'test-setting-preset-apply-agent';

    public function testApplyingAModeWritesEveryMemberAndThenTheSelection(): void
    {
        $this->withSettingsWriter(function (): void {
            $this->applyPreset('apply-ok-ak', LogSettingsPresets::FRUGAL);

            foreach (LogSettingsPresets::presetGroup()->memberKeys() as $key) {
                $this->assertNotNull(Hilos::$db->settings[$key], "no row written for {$key}");
            }
            $this->assertSame(LogSettingsPresets::FRUGAL, Hilos::$db->settings[LogSettingsCatalog::PRESET]?->value);
            $this->assertSame(LogSettingsPresets::FRUGAL, $this->resolver()->selectedName());
            $this->assertSame([], $this->resolver()->differences());
        });
    }

    public function testApplyingTheSameModeTwiceChangesNothingAndDoesNotFail(): void
    {
        $this->withSettingsWriter(function (): void {
            $this->applyPreset('apply-twice-ak', LogSettingsPresets::NORMAL);
            $rowIds = $this->settingRowIds();

            $this->applyPreset('apply-twice-ak', LogSettingsPresets::NORMAL);

            $this->assertSame($rowIds, $this->settingRowIds());
            $this->assertSame([], $this->resolver()->differences());
        });
    }

    public function testEditingOneMemberByHandShowsUpAsTheOnlyDifference(): void
    {
        $this->withSettingsWriter(function (): void {
            $this->applyPreset('drift-ak', LogSettingsPresets::NORMAL);
            $this->settingsTableActions()->add(LogSettingsCatalog::ARCHIVE_RETENTION_MAX_AGE_SECONDS, 1_209_600);

            $differences = $this->resolver()->differences();

            $this->assertCount(1, $differences);
            $this->assertSame(LogSettingsCatalog::ARCHIVE_RETENTION_MAX_AGE_SECONDS, $differences[0]->key);
            $this->assertSame(1_209_600, $differences[0]->currentValue);
            $this->assertSame(2_592_000, $differences[0]->presetValue);
            $this->assertSame(LogSettingsPresets::NORMAL, $this->resolver()->selectedName());
        });
    }

    public function testPuttingTheModeBackClearsTheDifference(): void
    {
        $this->withSettingsWriter(function (): void {
            $this->applyPreset('restore-ak', LogSettingsPresets::NORMAL);
            $this->settingsTableActions()->add(LogSettingsCatalog::ARCHIVE_RETENTION_MAX_AGE_SECONDS, 1_209_600);
            $this->assertCount(1, $this->resolver()->differences());

            $this->applyPreset('restore-ak', LogSettingsPresets::NORMAL);

            $this->assertSame([], $this->resolver()->differences());
        });
    }

    public function testAModeTheGroupDoesNotDeclareIsRefusedAndWritesNothing(): void
    {
        $this->withSettingsWriter(function (): void {
            $this->applyPreset('unknown-before-ak', LogSettingsPresets::FRUGAL);
            $rowIds = $this->settingRowIds();

            try {
                $this->applyPreset('unknown-ak', 'retired');
                $this->fail('An undeclared mode must be refused');
            } catch (TableActionException) {
                $this->assertSame($rowIds, $this->settingRowIds());
                $this->assertSame(LogSettingsPresets::FRUGAL, $this->resolver()->selectedName());
            }
        });
    }

    /**
     * Applies a mode the way the browser does, through the page action.
     *
     * @param string $acceptKey Connection accept key the action arrives on
     * @param string $preset Machine name of the mode to apply
     */
    private function applyPreset(string $acceptKey, string $preset): void
    {
        new LogsSettingsPage(new DemoHilosLogsAgent())->onAction(
            $acceptKey,
            HilosSignalConstants::SETTING_PRESET_APPLY,
            new SettingPresetApplyActionDTO($preset),
        );
    }

    /**
     * Reads the state of the logging modes the way the page payload does.
     *
     * @return SettingPresetResolver Resolver over the logs preset group
     */
    private function resolver(): SettingPresetResolver
    {
        return new SettingPresetResolver(LogSettingsPresets::presetGroup());
    }

    /**
     * Returns the table-level actions of the settings table, for the hand edit of one member.
     *
     * @return HilosSettingsTableActions Settings table actions
     */
    private function settingsTableActions(): HilosSettingsTableActions
    {
        $table = Hilos::$table?->get(HilosSettingsTable::TABLE);
        $this->assertInstanceOf(HilosSettingsTable::class, $table);

        return $table->actions;
    }

    /**
     * Row ids of every key a mode touches, so a second apply can be shown to have rewritten none.
     *
     * @return array<string, ?int> Row id by setting key, null where the key has no row
     */
    private function settingRowIds(): array
    {
        $ids = [];
        foreach ($this->groupKeys() as $key) {
            $ids[$key] = Hilos::$db->settings[$key]?->id;
        }

        return $ids;
    }

    /**
     * Every key of the group, its selection key included.
     *
     * @return list<string> Setting keys the group writes
     */
    private function groupKeys(): array
    {
        $group = LogSettingsPresets::presetGroup();

        return [...$group->memberKeys(), $group->selectionSettingKey];
    }

    /**
     * Registers the settings collection writer, runs the body, then removes every row it wrote.
     *
     * @param callable():void $body Test body run while the settings writer is held
     */
    private function withSettingsWriter(callable $body): void
    {
        TruthSourceRegistry::register(HilosDbContext::settings, true, self::SETTINGS_AGENT_ID);

        try {
            $body();
        } finally {
            foreach ($this->groupKeys() as $key) {
                Hilos::$db->settings[$key]?->actions->delete();
            }
            TruthSourceRegistry::unregisterAgent(self::SETTINGS_AGENT_ID);
        }
    }
}
