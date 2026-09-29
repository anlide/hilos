<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\Hilos\DemoHilosAgent;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\Security\SecurityTwoFactorPage;
use Hilos\Auth\StepUp\StepUpOperationKey;
use Hilos\Auth\StepUp\StepUpSettings;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Settings\Library\SettingsLibraryAgent;
use Hilos\Pages\Security\DTO\HilosStepUpOperationSetActionDTO;

/**
 * Integration coverage for the switches of the operations that ask for confirmation (HIL-495, HIL-1275).
 *
 * A switch writes the departures from the operation's DECLARED position: an operation declared on
 * lands in the switched-off list, one declared off in the switched-on list, and a switch back takes
 * it out again. The page picks the list and forwards; the settings library writes it under the
 * key's rule. Both halves run here in one process against the real catalog and database, the frame
 * carried across by hand as the sign-in methods screen's suite carries it
 * ({@see SecuritySignInMethodsPageActionTest}).
 */
final class SecurityTwoFactorStepUpSwitchTest extends IntegrationTestCase
{
    private const string SETTINGS_AGENT_ID = 'test-step-up-switch-writer';

    private const string ACCEPT_KEY = 'step-up-switch-ak';

    protected function setUp(): void
    {
        parent::setUp();

        // The harness runs no worker, so nothing has queued the router the page hands its frame to.
        Hilos::$sr = new SignalRouter();
    }

    /**
     * An operation declared on is switched off into the switched-off list and back out of it.
     */
    public function testAnOperationDeclaredOnIsListedWhenSwitchedOff(): void
    {
        $this->withSettingsWriter(function (): void {
            $this->assertNull($this->submit(StepUpOperationKey::MERGE_ACCOUNTS, false));
            $this->assertSame(StepUpOperationKey::MERGE_ACCOUNTS, Hilos::$db->settings[StepUpSettings::DISABLED_KEY]?->value);
            $this->assertNull(Hilos::$db->settings[StepUpSettings::ENABLED_KEY]?->value);
            $this->assertFalse(StepUpSettings::isEnabled(StepUpOperationKey::MERGE_ACCOUNTS));

            $this->assertNull($this->submit(StepUpOperationKey::MERGE_ACCOUNTS, true));
            $this->assertSame([], StepUpSettings::disabledKeys());
            $this->assertTrue(StepUpSettings::isEnabled(StepUpOperationKey::MERGE_ACCOUNTS));
        });
    }

    /**
     * An operation declared off is switched on into the switched-on list, in directory order, and back out of it.
     */
    public function testAnOperationDeclaredOffIsListedWhenSwitchedOn(): void
    {
        $this->withSettingsWriter(function (): void {
            $this->assertFalse(StepUpSettings::isEnabled(StepUpOperationKey::BLOCK_ACCOUNT));

            $this->assertNull($this->submit(StepUpOperationKey::BLOCK_ACCOUNT, true));
            $this->assertNull($this->submit(StepUpOperationKey::REVOKE_ADMIN, true));
            $this->assertSame(
                StepUpOperationKey::REVOKE_ADMIN . ',' . StepUpOperationKey::BLOCK_ACCOUNT,
                Hilos::$db->settings[StepUpSettings::ENABLED_KEY]?->value,
            );
            $this->assertNull(Hilos::$db->settings[StepUpSettings::DISABLED_KEY]?->value);
            $this->assertTrue(StepUpSettings::isEnabled(StepUpOperationKey::BLOCK_ACCOUNT));

            $this->assertNull($this->submit(StepUpOperationKey::BLOCK_ACCOUNT, false));
            $this->assertSame(StepUpOperationKey::REVOKE_ADMIN, Hilos::$db->settings[StepUpSettings::ENABLED_KEY]?->value);
            $this->assertFalse(StepUpSettings::isEnabled(StepUpOperationKey::BLOCK_ACCOUNT));
        });
    }

    /**
     * A switched-off list that already stands does not carry an operation declared off into the
     * "on" position: it keeps to its own side, and the operation stays where it was declared.
     */
    public function testAStoredSwitchedOffListLeavesAnOperationDeclaredOffWhereItWas(): void
    {
        $this->withSettingsWriter(function (): void {
            $this->assertNull($this->submit(StepUpOperationKey::CHANGE_EMAIL, false));
            $this->assertNull($this->submit(StepUpOperationKey::BLOCK_ACCOUNT, false));

            $this->assertSame(StepUpOperationKey::CHANGE_EMAIL, Hilos::$db->settings[StepUpSettings::DISABLED_KEY]?->value);
            $this->assertSame([], StepUpSettings::enabledKeys());
            $this->assertFalse(StepUpSettings::isEnabled(StepUpOperationKey::BLOCK_ACCOUNT));
            $this->assertTrue(StepUpSettings::isEnabled(StepUpOperationKey::DELETE_OTHER_ACCOUNT));
        });
    }

    /**
     * An operation the project never declared is refused by the page, before any write.
     */
    public function testAnUndeclaredOperationIsRefusedByThePage(): void
    {
        $this->expectException(TableActionException::class);

        new SecurityTwoFactorPage(new DemoHilosAgent())->onAction(
            self::ACCEPT_KEY,
            HilosSignalConstants::SECURITY_STEP_UP_OPERATION_SET,
            new HilosStepUpOperationSetActionDTO('launch_rockets', true),
        );
    }

    /**
     * Runs one switch end to end: the page picks the list and forwards, the library writes.
     *
     * @param string $operationKey Operation to switch
     * @param bool $enabled Whether to switch it on
     * @return ?string Sentence the library refused with, or null when the write went through
     */
    private function submit(string $operationKey, bool $enabled): ?string
    {
        new SecurityTwoFactorPage(new DemoHilosAgent())->onAction(
            self::ACCEPT_KEY,
            HilosSignalConstants::SECURITY_STEP_UP_OPERATION_SET,
            new HilosStepUpOperationSetActionDTO($operationKey, $enabled),
        );

        $asks = array_keys(SettingsLibraryAgent::AGENT_SIGNALS);
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if (!in_array($signal->signalName->getName(), $asks, true)) {
                continue;
            }

            $this->assertInstanceOf(AgentSignalData::class, $signal->data);
            new SettingsLibraryAgent()->onSignalAgent($signal->data, 'agent', $signal->signalName->getName());

            while (($answer = Hilos::$sr?->getNextQueuedSignal()) !== null) {
                if ($answer->data instanceof AgentSignalData && $answer->data->data instanceof HandoverAnswerSignalData) {
                    return $answer->data->data->error;
                }
            }
            $this->fail('An ask that arrived as a frame is answered or it hangs');
        }

        $this->fail('The page owes the library a frame for every switch it takes');
    }

    /**
     * Holds the settings writer around a body and takes both stored lists away afterwards.
     *
     * @param callable():void $body Test body run while the settings writer is held
     */
    private function withSettingsWriter(callable $body): void
    {
        TruthSourceRegistry::register(HilosDbContext::settings, TruthSourceKeys::all(), self::SETTINGS_AGENT_ID);
        Hilos::$db->settings[StepUpSettings::DISABLED_KEY]?->actions->delete();
        Hilos::$db->settings[StepUpSettings::ENABLED_KEY]?->actions->delete();

        try {
            $body();
        } finally {
            Hilos::$db->settings[StepUpSettings::DISABLED_KEY]?->actions->delete();
            Hilos::$db->settings[StepUpSettings::ENABLED_KEY]?->actions->delete();
            TruthSourceRegistry::unregisterAgent(self::SETTINGS_AGENT_ID);
        }
    }
}
