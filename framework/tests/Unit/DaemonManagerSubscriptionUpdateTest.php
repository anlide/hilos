<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Hilos;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupUpdateSubscriptionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUpdateSubscriptionSignalDTO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for subscription-update signals the daemon cannot apply.
 *
 * A browser sends update_subscription for whatever it believes it holds, so an
 * unknown or mismatched subscription is a client-reachable input, not a router
 * defect: the daemon logs it, drops the signal, and keeps draining the queue.
 */
final class DaemonManagerSubscriptionUpdateTest extends TestCase
{
    private const string PAGE = 'chat';
    private const string GROUP = 'room';
    private const string LIVE_ACCEPT_KEY = 'unit-sub-live';
    private const string UNKNOWN_ACCEPT_KEY = 'unit-sub-unknown';

    public function tearDown(): void
    {
        Hilos::$sr = null;

        parent::tearDown();
    }

    public function testUnknownPageSubscriptionUpdateIsDroppedAndTheDrainContinues(): void
    {
        $manager = new DaemonManagerSubscriptionUpdateTestManager();
        Hilos::$sr->subscribeToPage(
            self::PAGE,
            new WebSocketPageSubscribeSignalDTO(self::LIVE_ACCEPT_KEY, self::PAGE, ['room' => '1']),
        );

        $this->queuePageUpdate(self::UNKNOWN_ACCEPT_KEY, self::PAGE, ['room' => '7']);
        $this->queuePageUpdate(self::LIVE_ACCEPT_KEY, self::PAGE, ['room' => '9']);

        $this->assertSame(2, $manager->drainSubscriptionUpdates());
        $this->assertSame(
            ['room' => '9'],
            Hilos::$sr->getPageSubscriptions()[self::LIVE_ACCEPT_KEY]['params'],
        );
        $this->assertArrayNotHasKey(self::UNKNOWN_ACCEPT_KEY, Hilos::$sr->getPageSubscriptions());
    }

    public function testMismatchedPageSubscriptionUpdateIsDroppedAndTheDrainContinues(): void
    {
        $manager = new DaemonManagerSubscriptionUpdateTestManager();
        Hilos::$sr->subscribeToPage(
            self::PAGE,
            new WebSocketPageSubscribeSignalDTO(self::LIVE_ACCEPT_KEY, self::PAGE, ['room' => '1']),
        );

        $this->queuePageUpdate(self::LIVE_ACCEPT_KEY, 'admin_users', ['room' => '7']);
        $this->queuePageUpdate(self::LIVE_ACCEPT_KEY, self::PAGE, ['room' => '9']);

        $this->assertSame(2, $manager->drainSubscriptionUpdates());
        $this->assertSame(
            ['room' => '9'],
            Hilos::$sr->getPageSubscriptions()[self::LIVE_ACCEPT_KEY]['params'],
        );
    }

    public function testUnknownGroupSubscriptionUpdateIsDroppedAndTheDrainContinues(): void
    {
        $manager = new DaemonManagerSubscriptionUpdateTestManager();
        Hilos::$sr->subscribeToGroup(
            self::GROUP,
            new WebSocketGroupSubscribeSignalDTO(self::LIVE_ACCEPT_KEY, self::GROUP, ['seat' => '1']),
        );

        $this->queueGroupUpdate(self::UNKNOWN_ACCEPT_KEY, self::GROUP, ['seat' => '7']);
        $this->queueGroupUpdate(self::LIVE_ACCEPT_KEY, self::GROUP, ['seat' => '9']);

        $this->assertSame(2, $manager->drainSubscriptionUpdates());
        $this->assertSame(
            [self::LIVE_ACCEPT_KEY],
            Hilos::$sr->getSubscribedAcceptKeys(),
        );
    }

    /**
     * @param string $acceptKey Accept key the update claims to hold a page subscription for
     * @param string $page Page the update targets
     * @param array<string, string> $params Params the update merges
     */
    private function queuePageUpdate(string $acceptKey, string $page, array $params): void
    {
        $this->queueUpdate(
            SignalTypeConstants::PAGE_UPDATE_SUBSCRIPTION,
            $page,
            new WebSocketPageUpdateSubscriptionSignalDTO($acceptKey, $page, $params),
        );
    }

    /**
     * @param string $acceptKey Accept key the update claims to hold a group subscription for
     * @param string $group Group the update targets
     * @param array<string, string> $params Params the update merges
     */
    private function queueGroupUpdate(string $acceptKey, string $group, array $params): void
    {
        $this->queueUpdate(
            SignalTypeConstants::GROUP_UPDATE_SUBSCRIPTION,
            $group,
            new WebSocketGroupUpdateSubscriptionSignalDTO($acceptKey, $group, $params),
        );
    }

    /**
     * @param string $signalType Subscription-update signal type
     * @param string $signalName Page or group name the signal is named after
     * @param SignalDataInterface $data Update payload as the WebSocket layer parsed it
     */
    private function queueUpdate(string $signalType, string $signalName, SignalDataInterface $data): void
    {
        Hilos::$sr->queueSignal(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType($signalType),
            new SignalName($signalName),
            $data,
        );
    }
}

/**
 * Daemon manager exposing the subscription-update step of the queue drain.
 */
final class DaemonManagerSubscriptionUpdateTestManager extends DaemonManager
{
    /**
     * Drains the queued signals through the subscription-update step alone, the way
     * dispatchSignals() does before routing.
     *
     * @return int How many queued signals the drain got through
     */
    public function drainSubscriptionUpdates(): int
    {
        $drained = 0;
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $this->updateSubscriptions($signal);
            $drained++;
        }

        return $drained;
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new DaemonManagerSubscriptionUpdateTestAgentManagerDaemon();
    }
}

final class DaemonManagerSubscriptionUpdateTestAgentManagerDaemon extends AgentManagerDaemon
{
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new AgentDaemonCreationFailedException('not used in test');
    }
}
