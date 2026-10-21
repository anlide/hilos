<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\HttpConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use Hilos\Core\Page\PageErrorCode;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Hilos;
use Hilos\Socket\WebSocket\DTO\DeliveryCompleteSignalData;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUpdateSubscriptionSignalDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests the master's answer when no project page owns a subscription key.
 */
final class DaemonManagerUnservedSubscriptionTest extends TestCase
{
    /** @var ?SignalRouter Router in place before the case */
    private ?SignalRouter $previousRouter = null;

    protected function setUp(): void
    {
        $this->previousRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
    }

    protected function tearDown(): void
    {
        Hilos::$sr = $this->previousRouter;

        parent::tearDown();
    }

    public function testAnUnservedSubscriptionIsAnsweredWithNotFound(): void
    {
        new DaemonManagerUnservedSubscriptionTestManager()->answer($this->subscribeSignal('missing', 'ak-1'));

        $signal = Hilos::$sr->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertSame(SignalTypeConstants::WS_USER, $signal->signalType->getType());
        $this->assertSame(SignalConstants::SUBSCRIPTION_PAGE_ERROR, $signal->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertSame('ak-1', $signal->data->targetAcceptKey);
        $this->assertInstanceOf(PageSubscriptionErrorSignalData::class, $signal->data->data);
        $this->assertSame('missing', $signal->data->data->page);
        $this->assertSame(HttpConstants::HTTP_NOT_FOUND, $signal->data->data->httpCode);
        $this->assertSame(PageErrorCode::NOT_SERVED, $signal->data->data->errorCode);
        $this->assertSame('Page Not Found', $signal->data->data->title);
        $this->assertSame('The page you are looking for does not exist.', $signal->data->data->message);
    }

    public function testThePageNameFallsBackToTheSignalName(): void
    {
        $signal = new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::PAGE_SUBSCRIBE),
            new SignalName('missing'),
            new WebSocketPageSubscribeSignalDTO('ak-1'),
        );

        new DaemonManagerUnservedSubscriptionTestManager()->answer($signal);

        $targeting = Hilos::$sr->getNextQueuedSignal()?->data;
        $this->assertInstanceOf(WebSocketSignalData::class, $targeting);
        $this->assertInstanceOf(PageSubscriptionErrorSignalData::class, $targeting->data);
        $this->assertSame('missing', $targeting->data->page);
    }

    public function testANonSubscriptionIsAnsweredWithNothing(): void
    {
        $signal = new SignalDTO(
            new SignalSource(SignalSource::AGENT),
            new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            new SignalName('missing'),
            new SignalData(),
        );

        new DaemonManagerUnservedSubscriptionTestManager()->answer($signal);

        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    /** @return iterable<string, array{string, bool}> Every early refusal entry point on both request types */
    public static function refusals(): iterable
    {
        foreach (['unserved', 'start', 'unreachable'] as $path) {
            yield $path . '-subscribe' => [$path, false];
            yield $path . '-update' => [$path, true];
        }
    }

    #[DataProvider('refusals')]
    public function testTrackedEarlyRefusalQueuesCompletionAfterAnyError(string $path, bool $update): void
    {
        $signal = new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType($update ? SignalTypeConstants::PAGE_UPDATE_SUBSCRIPTION : SignalTypeConstants::PAGE_SUBSCRIBE),
            new SignalName('missing'),
            $update ? new WebSocketPageUpdateSubscriptionSignalDTO('ak-1', deliveryId: 'token')
                : new WebSocketPageSubscribeSignalDTO('ak-1', deliveryId: 'token'),
        );
        new DaemonManagerUnservedSubscriptionTestManager()->refuse($path, $signal);
        if (!$update) {
            self::assertSame(SignalConstants::SUBSCRIPTION_PAGE_ERROR, Hilos::$sr->getNextQueuedSignal()?->signalName->getName());
        }
        $marker = Hilos::$sr->getNextQueuedSignal();
        self::assertNotNull($marker);
        self::assertSame(SignalTypeConstants::WS_USER, $marker->signalType->getType());
        self::assertSame(SignalConstants::DELIVERY_COMPLETE, $marker->signalName->getName());
        self::assertInstanceOf(WebSocketSignalData::class, $marker->data);
        self::assertSame('ak-1', $marker->data->targetAcceptKey);
        self::assertInstanceOf(DeliveryCompleteSignalData::class, $marker->data->data);
        self::assertSame('missing', $marker->data->data->page);
        self::assertSame('token', $marker->data->data->deliveryId);
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    /**
     * @param string $page Page being subscribed to
     * @param string $acceptKey Connection that sent the subscription
     * @return SignalDTO Subscribe signal as the daemon routes it
     */
    private function subscribeSignal(string $page, string $acceptKey): SignalDTO
    {
        return new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::PAGE_SUBSCRIBE),
            new SignalName($page),
            new WebSocketPageSubscribeSignalDTO($acceptKey, $page),
        );
    }
}

/**
 * Daemon fixture exposing the unserved-subscription answer without opening sockets.
 */
final class DaemonManagerUnservedSubscriptionTestManager extends DaemonManager
{
    /**
     * @param SignalDTO $signal Signal with no page route
     */
    public function answer(SignalDTO $signal): void
    {
        $this->answerUnservedSubscription($signal);
    }

    public function refuse(string $path, SignalDTO $signal): void
    {
        match ($path) {
            'unserved' => $this->answerUnservedSubscription($signal),
            'start' => $this->answerStartRefusedSubscription($signal),
            'unreachable' => $this->answerUnreachableSubscription($signal),
        };
    }

    /**
     * @return SignalRouter Empty fixture router
     */
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    /**
     * @return AgentManagerDaemon Agent manager these cases never call
     */
    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new DaemonManagerUnservedSubscriptionTestAgentManagerDaemon();
    }
}

/**
 * Agent manager fixture these cases never reach.
 */
final class DaemonManagerUnservedSubscriptionTestAgentManagerDaemon extends AgentManagerDaemon
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
