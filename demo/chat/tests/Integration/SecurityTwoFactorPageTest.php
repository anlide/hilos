<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\Hilos\DemoHilosAgent;
use Demo\Chat\Database\Database;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\Security\SecurityTwoFactorPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Demo\Chat\Tables\ChatTableContext;
use Hilos\Auth\SecondFactor\DTO\SecondFactorPolicySignalData;
use Hilos\Auth\SecondFactor\SecondFactorPolicy;
use Hilos\Auth\SecondFactor\SecondFactorSettings;
use Hilos\Constants\CliCommands;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\DTO\TableViewportDeltaDTO;
use Hilos\Core\Table\DTO\TableWindowDescriptorDTO;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Object\Item\Setting as ObjectSetting;
use Hilos\Database\Settings\Library\DTO\SettingWriteSignalData;
use Hilos\Database\Settings\Library\SettingsLibraryAgent;
use Hilos\Pages\Security\DTO\HilosSecondFactorSettingSetActionDTO;
use Hilos\Pages\Security\DTO\HilosSecondFactorSwitchSetActionDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Tables\Security\HilosSecurityTwoFactorTable;
use Hilos\Tables\Security\HilosSecurityTwoFactorTableRow;
use Hilos\TruthSource\RtTruthSourceRegistry;

/**
 * Integration coverage for the two switches of the two-factor settings page (HIL-1303).
 *
 * The page narrows each action to its own keys and forwards the write; the settings library writes
 * it and, when the policy changed, tells every connection. Both halves run here in one process
 * against the real catalog and database, the frame carried across by hand as the impersonation
 * page's suite carries it ({@see SecurityImpersonationPageTest}). Turning the factor off and its
 * confirmation are the settings library's, covered by {@see SettingsPageActionTest}.
 */
final class SecurityTwoFactorPageTest extends IntegrationTestCase
{
    private const string SETTINGS_AGENT_ID = 'test-two-factor-page-writer';

    private const string ACCEPT_KEY = 'two-factor-page-ak';

    /** Connection of the administrator whose open window watches the redraws. */
    private const string WINDOW_KEY = 'two-factor-page-window';

    /** Why a row below the general switch is locked while the factor is off. */
    private const string LOCKED_WHILE_OFF = 'Turn on two-step verification to change this setting.';

    /** @var list<SecondFactorPolicySignalData> Policy frames the last write sent to every connection */
    private array $policyFrames = [];

    /** @var list<string> Names of the agent frames the last write sent past its answer */
    private array $agentFrames = [];

    protected function setUp(): void
    {
        parent::setUp();

        // The harness runs no worker, so nothing has queued the router the page hands its frame to.
        Hilos::$sr = new SignalRouter();
    }

    /**
     * The forget switch is written and back; the policy frame does not carry it, so none is sent.
     */
    public function testTheForgetSwitchIsWrittenAndBack(): void
    {
        $this->withSettingsWriter(function (): void {
            $this->assertNull($this->switch(SecondFactorSettings::FORGET_TRUSTS_ON_DISABLE_KEY, false));
            $this->assertFalse(SecondFactorPolicy::current()->forgetTrustsOnDisable);
            $this->assertSame([], $this->policyFrames);

            $this->assertNull($this->switch(SecondFactorSettings::FORGET_TRUSTS_ON_DISABLE_KEY, true));
            $this->assertTrue(SecondFactorPolicy::current()->forgetTrustsOnDisable);
        });
    }

    /**
     * Turning the factor on asks no confirmation and applies nothing: one policy frame, and the
     * write answered at once. Switching it on when it is on changes nothing.
     */
    public function testTurningOnSendsOnlyThePolicy(): void
    {
        $this->withSettingsWriter(function (): void {
            $this->assertNull($this->switch(SecondFactorSettings::ENABLED_KEY, true));
            $this->assertSame([], $this->policyFrames);

            $this->writeFromTheConsole(SecondFactorSettings::ENABLED_KEY, false);
            $this->assertFalse(SecondFactorPolicy::current()->enabled);

            $this->assertNull($this->switch(SecondFactorSettings::ENABLED_KEY, true));

            $this->assertTrue(SecondFactorPolicy::current()->enabled);
            $this->assertCount(1, $this->policyFrames);
            $this->assertTrue($this->policyFrames[0]->enabled);
            $this->assertNotContains(HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_DISABLE_APPLY, $this->agentFrames);
        });
    }

    /**
     * A dialog key is not a switch: the page refuses it, and any key not its own, before any write.
     */
    public function testAKeyThatIsNotOneOfTheSwitchesIsRefusedByThePage(): void
    {
        $this->expectException(TableActionException::class);
        $this->expectExceptionMessage('Unknown two-factor setting');

        new SecurityTwoFactorPage(new DemoHilosAgent())->onAction(
            self::ACCEPT_KEY,
            HilosSignalConstants::SECURITY_2FA_SWITCH_SET,
            new HilosSecondFactorSwitchSetActionDTO(SecondFactorSettings::REQUIRED_KEY, true),
        );
    }

    /**
     * A switch is not a dialog key: the dialog action refuses it, so turning the factor off cannot
     * go around the switch.
     */
    public function testASwitchIsRefusedByTheDialogAction(): void
    {
        $this->expectException(TableActionException::class);
        $this->expectExceptionMessage('Unknown two-factor setting');

        new SecurityTwoFactorPage(new DemoHilosAgent())->onAction(
            self::ACCEPT_KEY,
            HilosSignalConstants::SECURITY_2FA_SETTING_SET,
            new HilosSecondFactorSettingSetActionDTO(SecondFactorSettings::ENABLED_KEY, 'false'),
        );
    }

    /**
     * The table holds the eight settings in the order of the screen, a switch read as 'true' or 'false'.
     */
    public function testTheTableHoldsEightRowsWithTheSwitchesAsText(): void
    {
        $this->withSettingsWriter(function (): void {
            $rows = new HilosSecurityTwoFactorTable()->getFullSnapshot()->rows;
            $this->assertSame(SecondFactorSettings::KEYS, array_map(
                static fn (HilosSecurityTwoFactorTableRow $row): string => (string)$row->rowKey,
                $rows,
            ));
            $this->assertSame('true', $rows[0]->value);
            $this->assertSame('true', $rows[0]->defaultValue);
            $this->assertSame('true', $rows[1]->value);
            $this->assertSame('true', $rows[1]->defaultValue);
            $this->assertSame((string)SecondFactorSettings::DEFAULT_TRUST_DAYS, $rows[3]->value);

            $this->assertNull($this->switch(SecondFactorSettings::FORGET_TRUSTS_ON_DISABLE_KEY, false));

            $rows = new HilosSecurityTwoFactorTable()->getFullSnapshot()->rows;
            $this->assertSame(SecondFactorSettings::FORGET_TRUSTS_ON_DISABLE_KEY, $rows[1]->rowKey);
            $this->assertSame('false', $rows[1]->value);
            $this->assertSame('true', $rows[1]->defaultValue);
        });
    }

    /**
     * Every row says whether it is locked: none while the factor is on, every row below the switch while it is off.
     */
    public function testEveryRowBelowTheSwitchSaysItIsLockedWhileTheFactorIsOff(): void
    {
        $this->withSettingsWriter(function (): void {
            foreach (new HilosSecurityTwoFactorTable()->getFullSnapshot()->rows as $row) {
                $this->assertInstanceOf(HilosSecurityTwoFactorTableRow::class, $row);
                $this->assertNull($row->editDisabledReason);
            }

            $this->writeFromTheConsole(SecondFactorSettings::ENABLED_KEY, false);

            $reasons = [];
            foreach (new HilosSecurityTwoFactorTable()->getFullSnapshot()->rows as $row) {
                $this->assertInstanceOf(HilosSecurityTwoFactorTableRow::class, $row);
                $reasons[$row->rowKey] = $row->editDisabledReason;
            }
            $this->assertSame($this->lockReasons(false), $reasons);
        });
    }

    /**
     * Turning the factor off and on - a first write, an update, a reset to the default - redraws every row
     * of an open window with its lock reason; a write of another setting redraws only its own row.
     */
    public function testTheSwitchRedrawsEveryLockReasonInAnOpenWindow(): void
    {
        $this->withSettingsWriter(function (): void {
            $this->withOpenWindow(function (): void {
                $this->writeFromTheConsole(SecondFactorSettings::ENABLED_KEY, false);
                $enabledId = (string) Hilos::$db->settings[SecondFactorSettings::ENABLED_KEY]->id;
                $this->assertSame($this->lockReasons(false), $this->redrawnReasons(SourceChange::dbCreated(
                    HilosDbContext::settings,
                    $enabledId,
                    [ObjectSetting::key => SecondFactorSettings::ENABLED_KEY],
                )));

                $this->writeFromTheConsole(SecondFactorSettings::FORGET_TRUSTS_ON_DISABLE_KEY, false);
                $this->assertSame(
                    [SecondFactorSettings::FORGET_TRUSTS_ON_DISABLE_KEY => self::LOCKED_WHILE_OFF],
                    $this->redrawnReasons(SourceChange::dbCreated(
                        HilosDbContext::settings,
                        (string) Hilos::$db->settings[SecondFactorSettings::FORGET_TRUSTS_ON_DISABLE_KEY]->id,
                        [ObjectSetting::key => SecondFactorSettings::FORGET_TRUSTS_ON_DISABLE_KEY],
                    )),
                );

                $this->writeFromTheConsole(SecondFactorSettings::ENABLED_KEY, true);
                $this->assertSame(
                    $this->lockReasons(true),
                    $this->redrawnReasons(SourceChange::dbUpdated(HilosDbContext::settings, $enabledId, [])),
                );

                $this->writeFromTheConsole(SecondFactorSettings::ENABLED_KEY, false);
                $this->assertSame(
                    $this->lockReasons(false),
                    $this->redrawnReasons(SourceChange::dbUpdated(HilosDbContext::settings, $enabledId, [])),
                );

                Hilos::$db->settings[SecondFactorSettings::ENABLED_KEY]->actions->delete();
                $this->assertSame($this->lockReasons(true), $this->redrawnReasons(SourceChange::dbDeleted(
                    HilosDbContext::settings,
                    $enabledId,
                    [ObjectSetting::key => SecondFactorSettings::ENABLED_KEY],
                )));
            });
        });
    }

    /**
     * @param string $key Switch key
     * @param bool $enabled Position to switch it to
     * @return ?string Sentence the library refused with, or null when the write went through
     */
    private function switch(string $key, bool $enabled): ?string
    {
        return $this->submit(HilosSignalConstants::SECURITY_2FA_SWITCH_SET, new HilosSecondFactorSwitchSetActionDTO($key, $enabled));
    }

    /**
     * Runs one write end to end: the page forwards, the library writes, answers and announces.
     *
     * @param string $action Page action name
     * @param ActionPayloadDTO $dto Action payload
     * @return ?string Sentence the library refused with, or null when the write went through
     */
    private function submit(string $action, ActionPayloadDTO $dto): ?string
    {
        new SecurityTwoFactorPage(new DemoHilosAgent())->onAction(self::ACCEPT_KEY, $action, $dto);

        $asks = array_keys(SettingsLibraryAgent::AGENT_SIGNALS);
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if (!in_array($signal->signalName->getName(), $asks, true)) {
                continue;
            }

            $this->assertInstanceOf(AgentSignalData::class, $signal->data);
            new SettingsLibraryAgent()->onSignalAgent($signal->data, 'agent', $signal->signalName->getName());

            return $this->answer();
        }

        $this->fail('The page owes the library a frame for every write it takes');
    }

    /**
     * Writes a value the way the console's settings:set does - no connection behind it - and drops what it sent.
     *
     * @param string $key Setting key
     * @param bool $value Value to write
     */
    private function writeFromTheConsole(string $key, bool $value): void
    {
        new SettingsLibraryAgent()->onSignalAgent(
            new AgentSignalData(new SettingWriteSignalData(
                replySignal: HilosSignalConstants::HILOS_SETTING_COMMAND_DONE,
                acceptKey: '',
                requestId: 'two-factor-console',
                action: CliCommands::SETTINGS_SET,
                successMessage: null,
                key: $key,
                value: $value,
            )),
            'agent',
            HilosSignalConstants::HILOS_SETTING_WRITE,
        );
        while (Hilos::$sr?->getNextQueuedSignal() !== null) {
            // dropped: this is the arrangement, not the act
        }
    }

    /**
     * Reads back the library's answer and keeps the frames it sent beside it.
     *
     * @return ?string Sentence the write was refused with, or null when it went through
     */
    private function answer(): ?string
    {
        $this->policyFrames = [];
        $this->agentFrames = [];
        $answered = false;
        $error = null;
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $name = $signal->signalName->getName();
            if ($signal->data instanceof AgentSignalData && $signal->data->data instanceof HandoverAnswerSignalData) {
                $this->assertSame(HilosSignalConstants::HILOS_SECOND_FACTOR_SETTING_WRITE_DONE, $name);
                $answered = true;
                $error = $signal->data->data->error;

                continue;
            }
            if ($signal->data instanceof AgentSignalData) {
                $this->agentFrames[] = $name;
            }
            if ($name === HilosSignalConstants::HILOS_SECOND_FACTOR_POLICY) {
                $this->assertSame(SignalTypeConstants::WS_ALL_CONNECTED, $signal->signalType->getType());
                $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
                $this->assertInstanceOf(SecondFactorPolicySignalData::class, $signal->data->data);
                $this->policyFrames[] = $signal->data->data;
            }
        }
        $this->assertTrue($answered, 'An ask that arrived as a frame is answered or it hangs');

        return $error;
    }

    /**
     * The lock reason each of the eight rows carries, in the order of the screen.
     *
     * @param bool $enabled Position of the general switch
     * @return array<string, ?string> Lock reason by setting key
     */
    private function lockReasons(bool $enabled): array
    {
        if ($enabled) {
            return array_fill_keys(SecondFactorSettings::KEYS, null);
        }

        return [
            SecondFactorSettings::ENABLED_KEY => null,
            SecondFactorSettings::FORGET_TRUSTS_ON_DISABLE_KEY => self::LOCKED_WHILE_OFF,
            SecondFactorSettings::REQUIRED_KEY => self::LOCKED_WHILE_OFF,
            SecondFactorSettings::TRUST_DAYS_KEY => self::LOCKED_WHILE_OFF,
            SecondFactorSettings::BACKUP_CODES_KEY => self::LOCKED_WHILE_OFF,
            SecondFactorSettings::RESET_WAIT_DEFAULT_DAYS_KEY => self::LOCKED_WHILE_OFF,
            SecondFactorSettings::RESET_WAIT_MIN_DAYS_KEY => self::LOCKED_WHILE_OFF,
            SecondFactorSettings::RESET_WAIT_MAX_DAYS_KEY => self::LOCKED_WHILE_OFF,
        ];
    }

    /**
     * Opens the two-factor page with its window for an administrator around a body, and closes it afterwards.
     *
     * @param callable():void $body Test body run while the window is open
     */
    private function withOpenWindow(callable $body): void
    {
        Hilos::initBrowser();
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::SETTINGS_AGENT_ID);
        $admin = Hilos::$db->users->actions->createWithName('Two-factor window admin');
        $admin->actions->setAdmin(true);

        try {
            Hilos::$rt->connections->actions->register(self::WINDOW_KEY, (int) $admin->id);
            $windows = [ChatTableContext::hilosSecurityTwoFactor => new TableWindowDescriptorDTO(limit: TableConstants::NO_LIMIT)];
            Hilos::$sr?->subscribeToPage(
                SecurityTwoFactorPage::PAGE,
                new WebSocketPageSubscribeSignalDTO(self::WINDOW_KEY, SecurityTwoFactorPage::PAGE, [], $windows),
            );
            Hilos::$sr?->reportTableWindows(self::WINDOW_KEY, $windows);
            ExecutionContext::run(new ExecutionFrame(acceptKey: self::WINDOW_KEY), static function (): void {
                new SecurityTwoFactorPage(new DemoHilosAgent())->onSubscribe(self::WINDOW_KEY, new PageRouteParams([]));
            });
            while (Hilos::$sr?->getNextQueuedSignal() !== null) {
                // dropped: the first answer is the arrangement, the redraws are the act
            }

            $body();
        } finally {
            Hilos::$rt->connections->actions->clear();
            RtTruthSourceRegistry::unregisterAgent(self::SETTINGS_AGENT_ID);
            Hilos::initBrowser();
            Database::sqlRun('DELETE FROM hilos_session WHERE user_id = ?', [(int) $admin->id]);
            Database::sqlRun('DELETE FROM hilos_user WHERE id = ?', [(int) $admin->id]);
        }
    }

    /**
     * Hands one settings change to the browser fan-out and reads the rows the open window was redrawn with.
     *
     * @param SourceChange $change Settings change as the worker would record it
     * @return array<string, ?string> Lock reason of every redrawn row, by setting key, in the order delivered
     */
    private function redrawnReasons(SourceChange $change): array
    {
        Hilos::$browser->record($change);
        Hilos::$browser->flushToSignalRouter();

        $reasons = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if (!$signal->data instanceof WebSocketSignalData || !$signal->data->data instanceof TableViewportDeltaDTO) {
                continue;
            }
            $delta = $signal->data->data;
            $this->assertSame(self::WINDOW_KEY, $signal->data->targetAcceptKey);
            $this->assertSame(TableViewportDeltaDTO::KIND_ROW_UPDATED, $delta->kind);
            $slot = $delta->row[PagePayload::slots]['setting'];
            $this->assertSame($delta->rowKey, $slot[HilosSecurityTwoFactorTableRow::rowKey]);
            $reasons[(string) $delta->rowKey] = $slot[HilosSecurityTwoFactorTableRow::editDisabledReason];
        }

        return $reasons;
    }

    /**
     * Holds the settings writer around a body and takes every second-factor row away afterwards.
     *
     * @param callable():void $body Test body run while the settings writer is held
     */
    private function withSettingsWriter(callable $body): void
    {
        TruthSourceRegistry::register(HilosDbContext::settings, TruthSourceKeys::all(), self::SETTINGS_AGENT_ID);
        $this->clearRows();

        try {
            $body();
        } finally {
            $this->clearRows();
            TruthSourceRegistry::unregisterAgent(self::SETTINGS_AGENT_ID);
        }
    }

    /**
     * Takes every stored second-factor setting away.
     */
    private function clearRows(): void
    {
        foreach (SecondFactorSettings::KEYS as $key) {
            if (isset(Hilos::$db->settings[$key])) {
                Hilos::$db->settings[$key]->actions->delete();
            }
        }
    }
}
