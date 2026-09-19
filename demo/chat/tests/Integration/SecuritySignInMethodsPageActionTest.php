<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\Hilos\DemoHilosAgent;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\Security\SecuritySignInMethodsPage;
use Hilos\Auth\AuthMethodKey;
use Hilos\Auth\Method\AuthMethodSettings;
use Hilos\Auth\Method\EnabledAuthMethods;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Settings\Library\SettingsLibraryAgent;
use Hilos\Pages\Security\DTO\HilosSignInMethodSetActionDTO;

/**
 * Integration coverage for the sign-in methods screen's switch (HIL-427).
 *
 * The page rebuilds the stored list of switched-off methods and forwards it; the settings
 * library writes it under the key's rule. Both halves run here in one process against the
 * real catalog and database, the frame carried across by hand as the general settings screen's
 * suite carries it ({@see SettingsPageActionTest}).
 */
final class SecuritySignInMethodsPageActionTest extends IntegrationTestCase
{
    private const string SETTINGS_AGENT_ID = 'test-sign-in-methods-writer';

    protected function setUp(): void
    {
        parent::setUp();

        // The harness runs no worker, so nothing has queued the router the page hands its frame to.
        Hilos::$sr = new SignalRouter();
    }

    /**
     * Switching a method off stores it; switching it back on takes it out of the list again.
     */
    public function testASwitchRewritesTheStoredList(): void
    {
        $this->withSettingsWriter(function (): void {
            $this->assertNull($this->submit('switch-ak', AuthMethodKey::SMS, false));
            $this->assertSame(AuthMethodKey::SMS, Hilos::$db->settings[AuthMethodSettings::DISABLED_KEY]?->value);
            $this->assertFalse(EnabledAuthMethods::isEnabled(AuthMethodKey::SMS));

            $this->assertNull($this->submit('switch-ak', AuthMethodKey::PASSWORD, false));
            $this->assertSame(
                AuthMethodKey::PASSWORD . ',' . AuthMethodKey::SMS,
                Hilos::$db->settings[AuthMethodSettings::DISABLED_KEY]?->value,
            );

            $this->assertNull($this->submit('switch-ak', AuthMethodKey::SMS, true));
            $this->assertSame(AuthMethodKey::PASSWORD, Hilos::$db->settings[AuthMethodSettings::DISABLED_KEY]?->value);
        });
    }

    /**
     * The last method on cannot be switched off: the rule refuses, and the list stays as it was.
     */
    public function testTheLastMethodCannotBeSwitchedOff(): void
    {
        $this->withSettingsWriter(function (): void {
            $wired = Hilos::authMethodDirectoryClass()::keys();
            $last = array_pop($wired);
            foreach ($wired as $methodKey) {
                $this->assertNull($this->submit('last-ak', $methodKey, false));
            }

            $this->assertSame('At least one sign-in method must stay on', $this->submit('last-ak', $last, false));
            $this->assertSame([$last], EnabledAuthMethods::keys());
        });
    }

    /**
     * A method the project never wired is refused by the page, before any write.
     */
    public function testAnUnwiredMethodIsRefusedByThePage(): void
    {
        $this->expectException(TableActionException::class);

        $this->page()->onAction(
            'unwired-ak',
            HilosSignalConstants::SECURITY_SIGN_IN_METHOD_SET,
            new HilosSignInMethodSetActionDTO('oauth:acme', false),
        );
    }

    /**
     * Runs one switch end to end: the page checks and forwards, the library writes.
     *
     * @param string $acceptKey Connection accept key the action arrives on
     * @param string $methodKey Method to switch
     * @param bool $enabled Whether to switch it on
     * @return ?string Sentence the library refused with, or null when the write went through
     */
    private function submit(string $acceptKey, string $methodKey, bool $enabled): ?string
    {
        $this->page()->onAction(
            $acceptKey,
            HilosSignalConstants::SECURITY_SIGN_IN_METHOD_SET,
            new HilosSignInMethodSetActionDTO($methodKey, $enabled),
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
     * @return SecuritySignInMethodsPage Sign-in methods page bound to the Hilos index agent that owns it
     */
    private function page(): SecuritySignInMethodsPage
    {
        return new SecuritySignInMethodsPage(new DemoHilosAgent());
    }

    /**
     * Holds the settings writer around a body and takes the stored list away afterwards.
     *
     * @param callable():void $body Test body run while the settings writer is held
     */
    private function withSettingsWriter(callable $body): void
    {
        TruthSourceRegistry::register(HilosDbContext::settings, TruthSourceKeys::all(), self::SETTINGS_AGENT_ID);
        Hilos::$db->settings[AuthMethodSettings::DISABLED_KEY]?->actions->delete();

        try {
            $body();
        } finally {
            Hilos::$db->settings[AuthMethodSettings::DISABLED_KEY]?->actions->delete();
            TruthSourceRegistry::unregisterAgent(self::SETTINGS_AGENT_ID);
        }
    }
}
