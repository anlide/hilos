<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\ProtectedMode;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Hilos;
use Hilos\ProtectedMode\DTO\ProtectedModeStateSignalData;
use Hilos\ProtectedMode\ProtectedModeStubCopy;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * What an admitted browser is told, and when it is told nothing.
 *
 * The admission is decided on a 101, and nothing tears the other sockets of that browser down:
 * a verifier typing the code in one tab leaves its other tabs standing on the maintenance stub
 * for the whole window unless the master pushes to them. That push is what is pinned here - the
 * frame, whom it is addressed to, and the crossing it is owed to rather than the state it
 * describes, because a code presented from a third tab must wake nobody.
 */
final class ProtectedModeAdmissionFrameTest extends TestCase
{
    /** Session token hash of the browser the code was read out to. */
    private const string VERIFIER_SESSION = 'session-hash-verifier';

    private ?SignalRouter $previousSignalRouter = null;

    private AdmissionFrameTestManager $daemon;

    protected function setUp(): void
    {
        $this->previousSignalRouter = Hilos::$sr;
        // The manager installs its own router on construction, so it is built before anything
        // reads the queue: the one Hilos::$sr carries afterwards is the one it queues into.
        $this->daemon = new AdmissionFrameTestManager();
        Hilos::$rt = new AdmissionFrameTestRtContext();
        Hilos::$rt->mountFeatureItem(StateProtectedModeRuntime::RT_ITEM, StateProtectedModeRuntime::fromRow([
            StateProtectedModeRuntime::phase => StateProtectedModeRuntime::PHASE_VERIFYING,
            StateProtectedModeRuntime::operation => 'restore',
            StateProtectedModeRuntime::passHashes => ['hash-of-a-pass'],
            StateProtectedModeRuntime::admittedSessionTokenHashes => [],
        ]));
        RtTruthSourceRegistry::registerDaemon(StateProtectedModeRuntime::RT_ITEM);
    }

    protected function tearDown(): void
    {
        RtTruthSourceRegistry::unregisterDaemon(StateProtectedModeRuntime::RT_ITEM);
        Hilos::$sr = $this->previousSignalRouter;
        Hilos::$rt = null;

        parent::tearDown();
    }

    public function testTheAdmittedSessionIsToldTheModeDoesNotHoldItWhileTheWindowStaysOpen(): void
    {
        $this->daemon->admitProtectedModeSession(self::VERIFIER_SESSION);

        $frames = $this->sessionFrames();
        $this->assertCount(1, $frames);
        $this->assertSame(self::VERIFIER_SESSION, $frames[0]->targetSessionTokenHash);

        $state = $frames[0]->data;
        $this->assertInstanceOf(ProtectedModeStateSignalData::class, $state);
        // The personalized verdict, and the bit that keeps the client from reading it as a lift:
        // it calls the mode over only when both are false, and would reload the tab straight back
        // out of the window it was just let into.
        $this->assertFalse($state->active);
        $this->assertTrue($state->acceptsPass);
        $this->assertTrue($state->passIssued);
        $this->assertSame('restore', $state->operation);
        $this->assertNull($state->title);
        $this->assertNull($state->message);
        // The surface copy stays null because this browser leaves the stub, and the banner
        // sentence rides instead - the words it shows over the application it was just let into.
        // Resolved off the framework's own registry, which no fixture here overrides.
        $this->assertSame(
            ProtectedModeStubCopy::forOperation('restore')->bannerMessage,
            $state->bannerMessage,
        );
        $this->assertNotNull($state->bannerMessage);
    }

    public function testASecondTabOfTheSameBrowserPresentingTheCodeAgainWakesNobody(): void
    {
        $this->daemon->admitProtectedModeSession(self::VERIFIER_SESSION);
        $this->sessionFrames();

        $this->daemon->admitProtectedModeSession(self::VERIFIER_SESSION);

        $this->assertSame([], $this->sessionFrames());
        $this->assertSame(
            [self::VERIFIER_SESSION],
            Hilos::$rt?->hilosProtectedModeRuntime?->admittedSessionTokenHashes,
        );
    }

    public function testASecondBrowserGetsItsOwnFrame(): void
    {
        $this->daemon->admitProtectedModeSession(self::VERIFIER_SESSION);
        $this->sessionFrames();

        $this->daemon->admitProtectedModeSession('session-hash-second');

        $frames = $this->sessionFrames();
        $this->assertCount(1, $frames);
        $this->assertSame('session-hash-second', $frames[0]->targetSessionTokenHash);
    }

    /**
     * Drains the queue and keeps what the admission addressed to a browser session.
     *
     * Read by draining rather than by taking the head: writing the row queues its own RT sync
     * into the same queue and does it first, so a case reading the head would be looking at the
     * replication of the row instead of the announcement about it.
     *
     * @return list<WebSocketSignalData> Session-addressed protected-mode frames, in the order queued
     */
    private function sessionFrames(): array
    {
        $frames = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->signalType->getType() !== SignalTypeConstants::WS_SESSION) {
                continue;
            }

            $this->assertSame(SignalTypeConstants::PROTECTED_MODE, $signal->signalName->getName());
            $data = $signal->data;
            $this->assertInstanceOf(WebSocketSignalData::class, $data);
            $frames[] = $data;
        }

        return $frames;
    }
}

final class AdmissionFrameTestRtContext extends RtContext
{
    /**
     * Registers no project runtime state: the framework mount supplies the freeze row.
     */
    public function configure(): void
    {
    }
}

/**
 * A daemon with no servers at all: the admission path touches the runtime row and the queue.
 */
final class AdmissionFrameTestManager extends DaemonManager
{
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new AdmissionFrameTestAgentManagerDaemon();
    }
}

final class AdmissionFrameTestAgentManagerDaemon extends AgentManagerDaemon
{
    /**
     * @param string $agentType Agent type that was asked for
     * @param ?string $agentIndex Agent index that was asked for
     * @return AgentDaemonInterface Never returned; these cases start no agent
     * @throws AgentDaemonCreationFailedException Always
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new AgentDaemonCreationFailedException('not used in test');
    }
}
