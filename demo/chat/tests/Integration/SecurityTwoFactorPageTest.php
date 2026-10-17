<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\Hilos\DemoHilosAgent;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\Security\SecurityTwoFactorPage;
use Hilos\Auth\SecondFactor\DTO\SecondFactorPolicySignalData;
use Hilos\Auth\SecondFactor\SecondFactorPolicy;
use Hilos\Auth\SecondFactor\SecondFactorSettings;
use Hilos\Constants\CliCommands;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Settings\Library\DTO\SettingWriteSignalData;
use Hilos\Database\Settings\Library\SettingsLibraryAgent;
use Hilos\Pages\Security\DTO\HilosSecondFactorSettingSetActionDTO;
use Hilos\Pages\Security\DTO\HilosSecondFactorSwitchSetActionDTO;
use Hilos\Tables\Security\HilosSecurityTwoFactorTable;
use Hilos\Tables\Security\HilosSecurityTwoFactorTableRow;

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
            Hilos::$db->settings[$key]?->actions->delete();
        }
    }
}
