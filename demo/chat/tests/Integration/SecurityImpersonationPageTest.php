<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\Hilos\DemoHilosAgent;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\Security\SecurityImpersonationPage;
use Hilos\Auth\Impersonation\DTO\ImpersonationPolicySignalData;
use Hilos\Auth\Impersonation\ImpersonationSettings;
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
use Hilos\Database\Settings\Library\DTO\SettingResetSignalData;
use Hilos\Database\Settings\Library\SettingsLibraryAgent;
use Hilos\Pages\Security\DTO\HilosImpersonationScopeSetActionDTO;
use Hilos\Pages\Security\DTO\HilosImpersonationSwitchSetActionDTO;

/**
 * Integration coverage for the impersonation settings page (HIL-1170, HIL-1307).
 *
 * The page narrows a write to its own keys and forwards it; the settings library writes it under
 * the key's rule and, when any of the seven settings changed, tells every connection
 * the new policy snapshot. Both halves run here in one process against the real catalog and database, the
 * frame carried across by hand as the step-up switches' suite carries it
 * ({@see SecurityStepUpSwitchTest}).
 */
final class SecurityImpersonationPageTest extends IntegrationTestCase
{
    private const string SETTINGS_AGENT_ID = 'test-impersonation-page-writer';

    private const string ACCEPT_KEY = 'impersonation-page-ak';

    /** @var list<ImpersonationPolicySignalData> Policy frames the last write sent to every connection */
    private array $policyFrames = [];

    protected function setUp(): void
    {
        parent::setUp();

        // The harness runs no worker, so nothing has queued the router the page hands its frame to.
        Hilos::$sr = new SignalRouter();
    }

    public function testASwitchIsWrittenAndBack(): void
    {
        $this->withSettingsWriter(function (): void {
            $this->assertNull($this->switch(ImpersonationSettings::ALLOWED_KEY, false));
            $this->assertFalse(ImpersonationSettings::isAllowed());
            $this->assertCount(1, $this->policyFrames);
            $this->assertFalse($this->policyFrames[0]->allowed);

            $this->assertNull($this->switch(ImpersonationSettings::ALLOWED_KEY, true));
            $this->assertTrue(ImpersonationSettings::isAllowed());
            $this->assertCount(1, $this->policyFrames);
            $this->assertTrue($this->policyFrames[0]->allowed);
        });
    }

    public function testOnlyLookingIsToldToEveryConnection(): void
    {
        $this->withSettingsWriter(function (): void {
            $this->assertNull($this->submit(
                HilosSignalConstants::SECURITY_IMPERSONATION_SCOPE_SET,
                new HilosImpersonationScopeSetActionDTO(ImpersonationSettings::SCOPE_VIEW),
            ));

            $this->assertTrue(ImpersonationSettings::isViewOnly());
            $this->assertCount(1, $this->policyFrames);
            $this->assertSame(
                [
                    'viewOnly' => true,
                    'carryAdmin' => false,
                    'allowed' => true,
                    'accountAccess' => false,
                    'blocked' => true,
                    'frozen' => true,
                    'equal' => true,
                ],
                $this->policyFrames[0]->toArray(),
            );
        });
    }

    public function testCarriedRightsAreToldToEveryConnection(): void
    {
        $this->withSettingsWriter(function (): void {
            $this->assertNull($this->switch(ImpersonationSettings::CARRY_ADMIN_KEY, true));

            $this->assertTrue(ImpersonationSettings::carriesAdmin());
            $this->assertCount(1, $this->policyFrames);
            $this->assertSame(
                [
                    'viewOnly' => false,
                    'carryAdmin' => true,
                    'allowed' => true,
                    'accountAccess' => false,
                    'blocked' => true,
                    'frozen' => true,
                    'equal' => true,
                ],
                $this->policyFrames[0]->toArray(),
            );
        });
    }

    public function testEveryOtherSwitchIsToldToEveryConnection(): void
    {
        $this->withSettingsWriter(function (): void {
            $this->assertNull($this->switch(ImpersonationSettings::ACCOUNT_ACCESS_KEY, true));
            $this->assertTrue(ImpersonationSettings::allowsAccountAccess());
            $this->assertCount(1, $this->policyFrames);
            $this->assertTrue($this->policyFrames[0]->accountAccess);

            $this->assertNull($this->switch(ImpersonationSettings::BLOCKED_KEY, false));
            $this->assertFalse(ImpersonationSettings::allowsBlocked());
            $this->assertCount(1, $this->policyFrames);
            $this->assertFalse($this->policyFrames[0]->blocked);

            $this->assertNull($this->switch(ImpersonationSettings::FROZEN_KEY, false));
            $this->assertFalse(ImpersonationSettings::allowsFrozen());
            $this->assertCount(1, $this->policyFrames);
            $this->assertFalse($this->policyFrames[0]->frozen);

            $this->assertNull($this->switch(ImpersonationSettings::EQUAL_KEY, false));
            $this->assertFalse(ImpersonationSettings::allowsEqual());
            $this->assertCount(1, $this->policyFrames);
            $this->assertFalse($this->policyFrames[0]->equal);
        });
    }

    public function testResetSendsTheFullDefaultPolicyOnce(): void
    {
        $this->withSettingsWriter(function (): void {
            $this->assertNull($this->switch(ImpersonationSettings::BLOCKED_KEY, false));
            $this->assertFalse(ImpersonationSettings::allowsBlocked());

            new SettingsLibraryAgent()->onSignalAgent(
                new AgentSignalData(new SettingResetSignalData(
                    replySignal: HilosSignalConstants::HILOS_IMPERSONATION_SETTING_WRITE_DONE,
                    acceptKey: self::ACCEPT_KEY,
                    requestId: null,
                    action: HilosSignalConstants::SETTING_RESET,
                    successMessage: null,
                    key: ImpersonationSettings::BLOCKED_KEY,
                )),
                'agent',
                HilosSignalConstants::HILOS_SETTING_RESET,
            );

            $this->assertNull($this->answer());
            $this->assertTrue(ImpersonationSettings::allowsBlocked());
            $this->assertCount(1, $this->policyFrames);
            $this->assertSame(
                [
                    'viewOnly' => false,
                    'carryAdmin' => false,
                    'allowed' => true,
                    'accountAccess' => false,
                    'blocked' => true,
                    'frozen' => true,
                    'equal' => true,
                ],
                $this->policyFrames[0]->toArray(),
            );
        });
    }

    public function testAWriteThatLeavesThePolicyAloneSendsNoPolicy(): void
    {
        $this->withSettingsWriter(function (): void {
            $this->assertNull($this->submit(
                HilosSignalConstants::SECURITY_IMPERSONATION_SCOPE_SET,
                new HilosImpersonationScopeSetActionDTO(ImpersonationSettings::SCOPE_ACT),
            ));
            $this->assertSame([], $this->policyFrames);

            $this->assertNull($this->switch(ImpersonationSettings::ALLOWED_KEY, true));
            $this->assertSame([], $this->policyFrames);
        });
    }

    public function testAScopeOutsideTheTwoIsRefusedByItsRule(): void
    {
        $this->withSettingsWriter(function (): void {
            $this->assertSame('Choose view only or view and act', $this->submit(
                HilosSignalConstants::SECURITY_IMPERSONATION_SCOPE_SET,
                new HilosImpersonationScopeSetActionDTO('admin'),
            ));

            $this->assertNull(Hilos::$db->settings[ImpersonationSettings::SCOPE_KEY]);
            $this->assertSame([], $this->policyFrames);
        });
    }

    /**
     * The scope is not a switch: the page refuses it, and any key not its own, before any write.
     */
    public function testAKeyThatIsNotOneOfTheSwitchesIsRefusedByThePage(): void
    {
        $this->expectException(TableActionException::class);
        $this->expectExceptionMessage('Unknown impersonation setting');

        new SecurityImpersonationPage(new DemoHilosAgent())->onAction(
            self::ACCEPT_KEY,
            HilosSignalConstants::SECURITY_IMPERSONATION_SWITCH_SET,
            new HilosImpersonationSwitchSetActionDTO(ImpersonationSettings::SCOPE_KEY, true),
        );
    }

    /**
     * @param string $key Switch key
     * @param bool $enabled Position to switch it to
     * @return ?string Sentence the library refused with, or null when the write went through
     */
    private function switch(string $key, bool $enabled): ?string
    {
        return $this->submit(
            HilosSignalConstants::SECURITY_IMPERSONATION_SWITCH_SET,
            new HilosImpersonationSwitchSetActionDTO($key, $enabled),
        );
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
        new SecurityImpersonationPage(new DemoHilosAgent())->onAction(self::ACCEPT_KEY, $action, $dto);

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
     * Reads back the library's answer and keeps the policy frames it sent beside it.
     *
     * @return ?string Sentence the write was refused with, or null when it went through
     */
    private function answer(): ?string
    {
        $this->policyFrames = [];
        $answered = false;
        $error = null;
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof AgentSignalData && $signal->data->data instanceof HandoverAnswerSignalData) {
                $this->assertSame(HilosSignalConstants::HILOS_IMPERSONATION_SETTING_WRITE_DONE, $signal->signalName->getName());
                $answered = true;
                $error = $signal->data->data->error;
            }
            if ($signal->signalName->getName() === HilosSignalConstants::HILOS_IMPERSONATION_POLICY) {
                $this->assertSame(SignalTypeConstants::WS_ALL_CONNECTED, $signal->signalType->getType());
                $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
                $this->assertInstanceOf(ImpersonationPolicySignalData::class, $signal->data->data);
                $this->policyFrames[] = $signal->data->data;
            }
        }
        $this->assertTrue($answered, 'An ask that arrived as a frame is answered or it hangs');

        return $error;
    }

    /**
     * Holds the settings writer around a body and takes every impersonation row away afterwards.
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
     * Takes every stored impersonation setting away.
     */
    private function clearRows(): void
    {
        foreach (ImpersonationSettings::KEYS as $key) {
            Hilos::$db->settings[$key]?->actions->delete();
        }
    }
}
