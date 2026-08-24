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
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Hilos;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use PHPUnit\Framework\TestCase;

/**
 * What a browser is told when the node serving its page cannot be reached (HIL-668).
 *
 * A subscription is the one dropped signal with somebody waiting on it: the tab has a loading
 * flag up, and if nothing comes back it stays up forever. The node holding that socket is also
 * the only one able to say so — the node that would have answered is precisely the one out of
 * reach — so it answers with the ordinary subscription error a page raises when it refuses a
 * subscription, and the frontend clears the flag and offers a retry.
 *
 * The cases that answer NOTHING carry as much weight. A late push has nobody waiting on it, and
 * an error invented for it would put a failure on screen for something the person never asked
 * for.
 */
final class DaemonManagerUnreachableSubscriptionTest extends TestCase
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

    public function testAnUndeliveredSubscriptionIsAnsweredWithAnError(): void
    {
        new DaemonManagerUnreachableSubscriptionTestManager()->answer($this->subscribeSignal('room', 'ak-1'));

        $signal = Hilos::$sr->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertSame(SignalTypeConstants::WS_USER, $signal->signalType->getType());
        $this->assertSame(SignalConstants::SUBSCRIPTION_PAGE_ERROR, $signal->signalName->getName());
        $targeting = $signal->data;
        $this->assertInstanceOf(WebSocketSignalData::class, $targeting);
        $this->assertSame('ak-1', $targeting->targetAcceptKey);
    }

    /**
     * The payload is the one the frontend already knows how to render, down to the field names:
     * this path exists to reuse the refusal a page raises, not to introduce a second kind of it.
     * 503 says the page is fine and the serving node is not, which is what makes a retry sensible.
     */
    public function testTheAnswerCarriesTheOrdinarySubscriptionErrorPayload(): void
    {
        new DaemonManagerUnreachableSubscriptionTestManager()->answer($this->subscribeSignal('room', 'ak-1'));

        $data = Hilos::$sr->getNextQueuedSignal()?->data;
        $this->assertInstanceOf(WebSocketSignalData::class, $data);
        $error = $data->data;
        $this->assertInstanceOf(PageSubscriptionErrorSignalData::class, $error);
        $this->assertSame('room', $error->page);
        $this->assertSame(HttpConstants::HTTP_SERVICE_UNAVAILABLE, $error->httpCode);
        $this->assertSame('node_unreachable', $error->errorCode);
        $this->assertNotSame('', $error->message);
    }

    /**
     * A subscribe frame that leaves the page out is naming it through the signal name instead,
     * and the error has to name the page the client asked for — it is what the tab shows and
     * what a retry would be about.
     */
    public function testThePageNameIsTakenFromTheSignalWhenThePayloadOmitsIt(): void
    {
        $signal = new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::PAGE_SUBSCRIBE),
            new SignalName('room'),
            new WebSocketPageSubscribeSignalDTO('ak-1'),
        );

        new DaemonManagerUnreachableSubscriptionTestManager()->answer($signal);

        $data = Hilos::$sr->getNextQueuedSignal()?->data;
        $this->assertInstanceOf(WebSocketSignalData::class, $data);
        $error = $data->data;
        $this->assertInstanceOf(PageSubscriptionErrorSignalData::class, $error);
        $this->assertSame('room', $error->page);
    }

    /**
     * A push that could not be delivered is dropped with its log line and nothing else. Nobody
     * is waiting on it, so there is no flag to clear — only a surprise error to invent.
     */
    public function testAnUndeliveredPushIsAnsweredWithNothing(): void
    {
        $signal = new SignalDTO(
            new SignalSource(SignalSource::AGENT),
            new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            new SignalName('room_renamed'),
            new SignalData(['room' => 'Ada']),
        );

        new DaemonManagerUnreachableSubscriptionTestManager()->answer($signal);

        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    /**
     * Builds the subscribe signal a browser's frame becomes inside the daemon.
     *
     * @param string $page Page being subscribed to
     * @param string $acceptKey Connection the subscription came from
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
 * A daemon with nothing registered on it: the answer is queued, not written to a socket.
 */
final class DaemonManagerUnreachableSubscriptionTestManager extends DaemonManager
{
    /**
     * Runs the answer the dispatch gives an undeliverable signal.
     *
     * @param SignalDTO $signal Signal that could not be carried to the node hosting its agent
     */
    public function answer(SignalDTO $signal): void
    {
        $this->answerUnreachableSubscription($signal);
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new DaemonManagerUnreachableSubscriptionTestAgentManagerDaemon();
    }
}

/**
 * An agent manager these cases never reach.
 */
final class DaemonManagerUnreachableSubscriptionTestAgentManagerDaemon extends AgentManagerDaemon
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
