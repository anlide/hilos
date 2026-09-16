<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\Hilos\DemoHilosLogsAgent;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\Logs\LogsSettingsPage;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Settings\Library\SettingsLibraryAgent;
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
 * Every case runs as the agent that writes, holding what that agent's class declares (HIL-888).
 * That is not a detail of the harness: the guard on writing asks who is writing, and a case that
 * answers "nobody" is judged on the permissive branch - which is how all five of the cases above
 * passed while every application of a mode in a browser was refused. The last case is the gate
 * on that: the same apply, with the claim held by somebody else, has to be refused and has to
 * leave the settings as it found them.
 *
 * Since HIL-946 the writer is {@see SettingsLibraryAgent} and not the agent of the section, and
 * a case here is two steps: the page checks the caller and sends a frame, the library writes and
 * answers. The frame is carried across by hand ({@see self::applyPreset()}) because a real
 * router would carry it to another worker and there is none here. The refusals moved with the
 * write: what used to be thrown out of the page comes back as the sentence in the answer.
 */
final class SettingPresetApplyTest extends IntegrationTestCase
{
    /** @var string Holder of the settings while the fixture takes back the rows a case wrote */
    private const string CLEANUP_AGENT_ID = 'test-setting-preset-cleanup';

    /** @var string Some other agent holding the settings, so a refusal is about the writer */
    private const string OUTSIDER_AGENT_ID = 'test-setting-preset-outsider';

    protected function setUp(): void
    {
        parent::setUp();

        // The harness runs no worker, so nothing has queued the router the page hands its frame to.
        Hilos::$sr = new SignalRouter();
    }

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

    /**
     * The sentence survived the move: an undeclared mode is told apart from a fault, though its
     * exception family is not the one the wire gate lets through. The page used to re-raise it
     * for exactly that reason; the library carries it past the gate itself now.
     */
    public function testAModeTheGroupDoesNotDeclareIsRefusedAndWritesNothing(): void
    {
        $this->withSettingsWriter(function (): void {
            $this->applyPreset('unknown-before-ak', LogSettingsPresets::FRUGAL);
            $rowIds = $this->settingRowIds();

            $error = $this->applyPreset('unknown-ak', 'retired');

            $this->assertStringContainsString("Setting preset 'retired'", (string)$error);
            $this->assertSame($rowIds, $this->settingRowIds());
            $this->assertSame(LogSettingsPresets::FRUGAL, $this->resolver()->selectedName());
        });
    }

    /**
     * The gate on the whole arrangement: with the collection held by somebody else, the write is
     * refused and nothing lands. What the administrator reads is the placeholder and not the
     * guard's own words - a claim that was never laid is a fault of how the installation is
     * wired, and the wire gate keeps that kind of detail on the server.
     */
    public function testAWriterWithoutTheClaimIsRefusedAndWritesNothing(): void
    {
        foreach ($this->groupKeys() as $key) {
            $this->assertNull(Hilos::$db->settings[$key], "the case opens on a settings row for {$key}");
        }
        TruthSourceRegistry::register(HilosDbContext::settings, TruthSourceKeys::all(), self::OUTSIDER_AGENT_ID);

        try {
            $error = $this->underAgent(new SettingsLibraryAgent(), fn (): ?string
                => $this->applyPreset('unowned-ak', LogSettingsPresets::FRUGAL));

            $this->assertSame(SignalConstants::ACTION_FAILED_REASON, $error);
            foreach ($this->groupKeys() as $key) {
                $this->assertNull(Hilos::$db->settings[$key], "a refused apply wrote a row for {$key}");
            }
        } finally {
            TruthSourceRegistry::unregisterAgent(self::OUTSIDER_AGENT_ID);
        }
    }

    /**
     * Applies a mode the way the browser does: through the page action, then through the library.
     *
     * Both halves in one process, and the frame handed over by hand, because a real router would
     * carry it to the worker holding the library and there is none here.
     *
     * @param string $acceptKey Connection accept key the action arrives on
     * @param string $preset Machine name of the mode to apply
     * @return ?string Sentence the library refused with, or null when the mode was applied
     */
    private function applyPreset(string $acceptKey, string $preset): ?string
    {
        new LogsSettingsPage(new DemoHilosLogsAgent())->onAction(
            $acceptKey,
            HilosSignalConstants::SETTING_PRESET_APPLY,
            new SettingPresetApplyActionDTO($preset),
        );

        $asks = array_keys(SettingsLibraryAgent::AGENT_SIGNALS);
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            // The queue also carries the DB-sync frames earlier writes announced; what is wanted
            // is the one frame the page addressed to the library.
            if (!in_array($signal->signalName->getName(), $asks, true)) {
                continue;
            }

            $this->assertInstanceOf(AgentSignalData::class, $signal->data);
            new SettingsLibraryAgent()->onSignalAgent($signal->data, 'agent', $signal->signalName->getName());

            return $this->answeredError();
        }

        $this->fail('The page owes the library a frame for every apply it takes');
    }

    /**
     * Reads back the outcome the library sent to the section.
     *
     * @return ?string Sentence the apply was refused with, or null when it went through
     */
    private function answeredError(): ?string
    {
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof AgentSignalData && $signal->data->data instanceof HandoverAnswerSignalData) {
                return $signal->data->data->error;
            }
        }

        $this->fail('An ask that arrived as a frame is answered or it hangs');
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
     * Runs the body as the agent that writes the settings runs it: holding what its class
     * declares.
     *
     * The claim is laid the way a worker lays it ({@see WorkerManager::handleAgentStart()}) and
     * the body runs under that agent's execution context, because both halves are what the guard
     * asks about. A fixture granting the collection to an id of its own, with no context set,
     * answered neither question and passed every case here while the browser was refused on all
     * of them (HIL-888). Since HIL-946 that agent is the settings library and not the agent of
     * the section - which is the whole of what this leaf moved.
     *
     * @param callable():void $body Test body run as the settings library
     * @throws HilosException When the body fails
     */
    private function withSettingsWriter(callable $body): void
    {
        $agent = new SettingsLibraryAgent();
        OwnershipDeclaration::claimAll($agent);

        try {
            $this->underAgent($agent, $body);
        } finally {
            TruthSourceRegistry::unregisterAgent($agent->getId());
            RtTruthSourceRegistry::unregisterAgent($agent->getId());
            $this->removeTheRowsTheGroupWrote();
        }
    }

    /**
     * Takes the group's rows back out, under a holder of the fixture's own.
     *
     * Not under the writer of the case: undoing a fixture is not one of the gestures the screen
     * offers, so it is done by somebody else - and after the writer's claim is gone, so that
     * nothing here can be mistaken for a right the section holds.
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
