<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Page\DTO\PageActionSuccessSignalData;
use Hilos\Core\Page\Exception\PageForbiddenException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\DaemonSection\DTO\DaemonAgentRestartRequestSignalData;
use Hilos\Hilos;
use Hilos\Pages\Daemon\DTO\HilosDaemonAgentRestartActionDTO;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Tests\Unit\Fixtures\AdminViewModeTestNode;
use Hilos\Tests\Unit\Fixtures\IdentityTestBrowser;
use Hilos\Tests\Unit\Fixtures\PageAgentRestartTestPages;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Exercises the actual page topology, action dispatcher, admin gate and deferred outcome. */
final class HilosDaemonAgentRestartActionTest extends TestCase
{
    private ?SignalRouter $previousRouter;
    private ?RtContext $previousRt;
    private ?BrowserContext $previousBrowser;
    private PageAgentRestartTestPages $pages;

    protected function setUp(): void
    {
        $this->previousRouter = Hilos::$sr;
        $this->previousRt = Hilos::$rt;
        $this->previousBrowser = Hilos::$browser;
        Hilos::$sr = new SignalRouter();
        Hilos::$browser = new IdentityTestBrowser(7, true);
        AdminViewModeTestNode::mount(false);
        $this->pages = new PageAgentRestartTestPages();
        $this->subscribe(HilosPageConstants::HILOS_DAEMON_AGENTS);
    }

    protected function tearDown(): void
    {
        AdminViewModeTestNode::unmount();
        Hilos::$sr = $this->previousRouter;
        Hilos::$rt = $this->previousRt;
        Hilos::$browser = $this->previousBrowser;
        parent::tearDown();
    }

    public function testActionDtoParsesRawAndWrappedPayloadAndIgnoresBrowserReturnAddress(): void
    {
        $payload = ['agentId' => 'room:12', 'nodeId' => 'node-a', 'replyAgentType' => 'forged', 'acceptKey' => 'forged'];
        foreach ([$payload, [SignalPayloadConstants::FIELD_DATA => $payload]] as $data) {
            $dto = $this->pages->factory->createActionPayloadDTO(HilosSignalConstants::HILOS_DAEMON_AGENT_RESTART, $data);
            $this->assertInstanceOf(HilosDaemonAgentRestartActionDTO::class, $dto);
            $this->assertSame(['agentId' => 'room:12', 'nodeId' => 'node-a'], $dto->toArray());
            $this->assertSame(HilosSignalConstants::HILOS_DAEMON_AGENT_RESTART, $dto->getAction());
        }
        $this->assertSame([], HilosDaemonAgentRestartActionDTO::SECRET_FIELDS);
    }

    #[DataProvider('invalidPayloads')]
    public function testActionDtoRefusesMissingMistypedAndEmptyIdentities(array $payload, string $exception): void
    {
        $this->expectException($exception);
        HilosDaemonAgentRestartActionDTO::fromArray($payload);
    }

    public static function invalidPayloads(): array
    {
        return [
            [[], InvalidFormatException::class],
            [['agentId' => 12, 'nodeId' => 'node-a'], InvalidFormatException::class],
            [['agentId' => 'room', 'nodeId' => false], InvalidFormatException::class],
            [['data' => 'bad'], InvalidFormatException::class],
            [['agentId' => '', 'nodeId' => 'node-a'], EmptyValueException::class],
            [['agentId' => 'room', 'nodeId' => ' '], EmptyValueException::class],
        ];
    }

    public function testTrackedActionQueuesOneWorkerControlRequestWithNoPrematureAck(): void
    {
        $this->dispatch();
        $signal = Hilos::$sr->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertSame(SignalTypeConstants::DAEMON_AGENT_RESTART_REQUEST, $signal->signalType->getType());
        $this->assertSame(SignalTypeConstants::DAEMON_AGENT_RESTART_REQUEST, $signal->signalName->getName());
        $this->assertInstanceOf(DaemonAgentRestartRequestSignalData::class, $signal->data);
        $this->assertSame('daemon_pages', $signal->data->replyAgentType);
        $this->assertSame('2', $signal->data->replyAgentIndex);
        $this->assertSame('accept-1', $signal->data->acceptKey);
        $this->assertSame('submit-1', $signal->data->requestId);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal(), 'No action_success until the master answers');
    }

    public function testUntrackedActionDoesNotDeferAnAckItDoesNotOwe(): void
    {
        $page = $this->pages->factory->getPage(HilosPageConstants::HILOS_DAEMON_AGENTS);
        $page->beginActionDispatch();
        $page->onAction('accept-1', HilosSignalConstants::HILOS_DAEMON_AGENT_RESTART, new HilosDaemonAgentRestartActionDTO('room', 'node-a'));
        $this->assertFalse($page->actionReplyDeferred());
        $this->assertNull(Hilos::$sr->getNextQueuedSignal()->data->requestId);
    }

    #[DataProvider('wrongSubscriptions')]
    public function testMissingDifferentPageAndDifferentNodeSubscriptionsCannotSendARestart(?string $page, string $node): void
    {
        Hilos::$sr = new SignalRouter();
        if ($page !== null) {
            $this->subscribe($page, $node);
        }
        $this->dispatch();
        $error = $this->nextError();
        $this->assertSame('PageForbiddenException', $error->errorType);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public static function wrongSubscriptions(): array
    {
        return [[null, 'node-a'], [HilosPageConstants::HILOS_DAEMON_WORKERS, 'node-a'], [HilosPageConstants::HILOS_DAEMON_AGENTS, 'node-b']];
    }

    public function testViewModeRefusesBeforeForwardingAndDisclosesNoExceptionText(): void
    {
        AdminViewModeTestNode::mount(true);
        Hilos::$browser = new IdentityTestBrowser(5, false);
        $this->dispatch();
        $error = $this->nextError();
        $this->assertSame('view_mode', $error->errorCode);
        $this->assertNull($error->errorType);
        $this->assertNull($error->errorDetail);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testNonAdminCannotForwardTheActionWithViewModeOff(): void
    {
        Hilos::$browser = new IdentityTestBrowser(5, false);
        $this->dispatch();
        $this->assertSame('forbidden', $this->nextError()->errorCode);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testDoneSignalUsesDeclaredRouteAndProducesTheCorrelatedSuccess(): void
    {
        $this->answer(null, 'Agent room:12 restarted on node node-b.');
        $ack = Hilos::$sr->getNextQueuedSignal();
        $this->assertSame(SignalConstants::ACTION_SUCCESS, $ack->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $ack->data);
        $this->assertSame('accept-1', $ack->data->targetAcceptKey);
        $this->assertInstanceOf(PageActionSuccessSignalData::class, $ack->data->data);
        $this->assertSame('submit-1', $ack->data->data->requestId);
        $this->assertSame('Agent room:12 restarted on node node-b.', $ack->data->data->message);
    }

    public function testCommandRefusalIsAnsweredWithoutInventingExceptionDetails(): void
    {
        $this->answer('Node node-a is silent', null);
        $error = $this->nextError();
        $this->assertSame('Node node-a is silent', $error->reason);
        $this->assertSame('submit-1', $error->requestId);
        $this->assertNull($error->errorType);
        $this->assertNull($error->errorDetail);
    }

    private function subscribe(string $page, string $node = 'node-a'): void
    {
        Hilos::$sr->subscribeToPage($page, new WebSocketPageSubscribeSignalDTO(
            'accept-1', $page, [HilosPageRouteParams::HILOS_DAEMON_NODE_ID => $node],
        ));
    }

    private function dispatch(): void
    {
        $this->pages->router->dispatchAction(new WebSocketActionSignalDTO(
            'accept-1', HilosSignalConstants::HILOS_DAEMON_AGENT_RESTART,
            ['agentId' => 'room:12', 'nodeId' => 'node-a', 'replyAgentType' => 'forged'], 'submit-1',
        ), 'websocket');
    }

    private function answer(?string $error, ?string $message): void
    {
        $this->pages->router->dispatchAgentSignal(new AgentSignalData(new HandoverAnswerSignalData(
            'accept-1', 'submit-1', HilosSignalConstants::HILOS_DAEMON_AGENT_RESTART, $message, $error, null, null,
        )), 'daemon', HilosSignalConstants::DAEMON_AGENT_RESTART_DONE);
    }

    private function nextError(): PageActionErrorSignalData
    {
        $signal = Hilos::$sr->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertSame(SignalConstants::ACTION_ERROR, $signal->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertInstanceOf(PageActionErrorSignalData::class, $signal->data->data);
        return $signal->data->data;
    }
}
