<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\Hilos\DemoHilosLogsAgent;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\Logs\LogsSettingsPage;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Settings\Preset\SettingPresetResolver;
use Hilos\HilosException;
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
 *
 * Every case runs as the agent that serves the screen, holding what that agent's class declares
 * (HIL-888). That is not a detail of the harness: the guard on writing asks who is writing, and
 * a case that answers "nobody" is judged on the permissive branch - which is how all five of the
 * cases above passed while every application of a mode in a browser was refused. The last case is
 * the gate on that: the same apply, by an agent whose claim was never laid, has to be refused and
 * has to leave the settings as it found them.
 */
final class SettingPresetApplyTest extends IntegrationTestCase
{
    /** @var string Holder of the settings while the fixture takes back the rows a case wrote */
    private const string CLEANUP_AGENT_ID = 'test-setting-preset-cleanup';

    /** @var string Some other agent holding the settings, so a refusal is about the writer */
    private const string OUTSIDER_AGENT_ID = 'test-setting-preset-outsider';

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

    public function testAnAgentThatDoesNotOwnTheSettingsIsRefusedAndWritesNothing(): void
    {
        foreach ($this->groupKeys() as $key) {
            $this->assertNull(Hilos::$db->settings[$key], "the case opens on a settings row for {$key}");
        }
        TruthSourceRegistry::register(HilosDbContext::settings, TruthSourceKeys::all(), self::OUTSIDER_AGENT_ID);

        try {
            $this->underAgent(new DemoHilosLogsAgent(), function (): void {
                $this->applyPreset('unowned-ak', LogSettingsPresets::FRUGAL);
            });
            $this->fail('An agent with no claim over the settings must be refused');
        } catch (CreateNotAllowedException) {
            foreach ($this->groupKeys() as $key) {
                $this->assertNull(Hilos::$db->settings[$key], "a refused apply wrote a row for {$key}");
            }
        } finally {
            TruthSourceRegistry::unregisterAgent(self::OUTSIDER_AGENT_ID);
        }
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
     * Runs the body as the agent of the logs section runs it: holding what its class declares.
     *
     * The claim is laid the way a worker lays it ({@see WorkerManager::handleAgentStart()}) and
     * the body runs under that agent's execution context, because both halves are what the guard
     * asks about. A fixture granting the collection to an id of its own, with no context set,
     * answered neither question and passed every case here while the browser was refused on all
     * of them (HIL-888).
     *
     * @param callable():void $body Test body run as the logs agent
     * @throws HilosException When the body fails
     */
    private function withSettingsWriter(callable $body): void
    {
        $agent = new DemoHilosLogsAgent();
        OwnershipDeclaration::claimDb($agent::class, $agent->getId());

        try {
            $this->underAgent($agent, $body);
        } finally {
            TruthSourceRegistry::unregisterAgent($agent->getId());
            $this->removeTheRowsTheGroupWrote();
        }
    }

    /**
     * Takes the group's rows back out, under a holder of the fixture's own.
     *
     * Not under the agent of the section: it claims adding and updating and not removing, which
     * is the whole of what this test locks one method up. Undoing a fixture is not one of the
     * section's gestures, so it is done by somebody else - and after the agent's claim is gone,
     * so that nothing here can be mistaken for a right the section holds.
     */
    private function removeTheRowsTheGroupWrote(): void
    {
        TruthSourceRegistry::register(HilosDbContext::settings, TruthSourceKeys::all(), self::CLEANUP_AGENT_ID);

        try {
            foreach ($this->groupKeys() as $key) {
                Hilos::$db->settings[$key]?->actions->delete();
            }
        } finally {
            TruthSourceRegistry::unregisterAgent(self::CLEANUP_AGENT_ID);
        }
    }
}
