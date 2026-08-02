<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\BaseDTO;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\AbstractPageFactory;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Page\DTO\PageActionSuccessSignalData;
use Hilos\Core\Page\Exception\PageNotFoundException;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Hilos as HilosFacade;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the action reply payload on the central action dispatcher: a
 * tracked action's returned reply DTO rides its success ack, an action that
 * returns null omits the reply field, an untracked action's reply is dropped,
 * a throwing action produces a fail ack with no reply, and the per-action
 * success-message slot is scoped to the action that set it.
 */
final class PageSignalRouterActionReplyTest extends TestCase
{
    private ?SignalRouter $previousRouter = null;

    public function setUp(): void
    {
        parent::setUp();

        $this->previousRouter = HilosFacade::$sr;
        HilosFacade::$sr = new SignalRouter();
    }

    public function tearDown(): void
    {
        HilosFacade::$sr = $this->previousRouter;

        parent::tearDown();
    }

    public function testTrackedActionReplyRidesTheSuccessAck(): void
    {
        $router = $this->makeRouter();

        $router->dispatchAction(
            new WebSocketActionSignalDTO('ak-1', ActionReplyTestPage::REPLY_ACTION, [], 'req-1'),
            'websocket',
        );

        $ack = $this->successAck($this->drainAll());
        $this->assertNotNull($ack);
        $this->assertSame('req-1', $ack->requestId);
        $this->assertSame(['token' => 'abc'], $ack->reply);
    }

    public function testTrackedActionWithoutReplyOmitsTheReplyField(): void
    {
        $router = $this->makeRouter();

        $router->dispatchAction(
            new WebSocketActionSignalDTO('ak-1', ActionReplyTestPage::PLAIN_ACTION, [], 'req-2'),
            'websocket',
        );

        $ack = $this->successAck($this->drainAll());
        $this->assertNotNull($ack);
        $this->assertNull($ack->reply);
        $this->assertArrayNotHasKey('reply', $ack->toArray());
    }

    public function testUntrackedActionReplyIsDroppedWithNoAck(): void
    {
        $router = $this->makeRouter();

        $router->dispatchAction(
            new WebSocketActionSignalDTO('ak-1', ActionReplyTestPage::REPLY_ACTION),
            'websocket',
        );

        $this->assertNull($this->successAck($this->drainAll()));
    }

    public function testThrowingActionSendsFailAckWithoutReply(): void
    {
        $router = $this->makeRouter();

        $router->dispatchAction(
            new WebSocketActionSignalDTO('ak-1', ActionReplyTestPage::THROW_ACTION, [], 'req-3'),
            'websocket',
        );

        $signals = $this->drainAll();
        $this->assertNull($this->successAck($signals));
        $this->assertInstanceOf(
            PageActionErrorSignalData::class,
            $this->findByName($signals, SignalConstants::ACTION_ERROR),
        );
    }

    public function testSuccessMessageDoesNotLeakFromAnUntrackedAction(): void
    {
        $router = $this->makeRouter();

        // An untracked action sets a success message but sends no ack, so the
        // message would otherwise linger in the page's per-action slot.
        $router->dispatchAction(
            new WebSocketActionSignalDTO('ak-1', ActionReplyTestPage::MESSAGE_ACTION),
            'websocket',
        );
        // The next tracked action must ack without the stranded message.
        $router->dispatchAction(
            new WebSocketActionSignalDTO('ak-1', ActionReplyTestPage::PLAIN_ACTION, [], 'req-4'),
            'websocket',
        );

        $ack = $this->successAck($this->drainAll());
        $this->assertNotNull($ack);
        $this->assertNull($ack->message);
    }

    /**
     * Builds a router routing every fixture action to the reply test page.
     *
     * @return PageSignalRouter Router under test
     */
    private function makeRouter(): PageSignalRouter
    {
        $factory = new ActionReplyTestPageFactory(new ActionReplyTestAgent());

        return new PageSignalRouter(
            $factory,
            new ActionRouteConfig([
                ActionReplyTestPage::REPLY_ACTION => ActionReplyTestPage::PAGE,
                ActionReplyTestPage::PLAIN_ACTION => ActionReplyTestPage::PAGE,
                ActionReplyTestPage::MESSAGE_ACTION => ActionReplyTestPage::PAGE,
                ActionReplyTestPage::THROW_ACTION => ActionReplyTestPage::PAGE,
            ]),
        );
    }

    /**
     * Drains the whole signal queue into name/payload pairs.
     *
     * @return list<array{name: string, data: object}> Queued signals in order
     */
    private function drainAll(): array
    {
        $signals = [];
        while (($signal = HilosFacade::$sr->getNextQueuedSignal()) !== null) {
            $envelope = $signal->data;
            $this->assertInstanceOf(WebSocketSignalData::class, $envelope);
            $signals[] = ['name' => $signal->signalName->getName(), 'data' => $envelope->data];
        }

        return $signals;
    }

    /**
     * Finds the first payload delivered under a signal name.
     *
     * @param list<array{name: string, data: object}> $signals Drained signals
     * @param string $signalName Signal name to match (e.g. action_success)
     * @return ?object Wrapped signal payload, or null when the name is absent
     */
    private function findByName(array $signals, string $signalName): ?object
    {
        foreach ($signals as $signal) {
            if ($signal['name'] === $signalName) {
                return $signal['data'];
            }
        }

        return null;
    }

    /**
     * Finds the success ack payload among drained signals, if one was queued.
     *
     * @param list<array{name: string, data: object}> $signals Drained signals
     * @return ?PageActionSuccessSignalData Success ack payload, or null when none
     */
    private function successAck(array $signals): ?PageActionSuccessSignalData
    {
        $data = $this->findByName($signals, SignalConstants::ACTION_SUCCESS);
        $this->assertTrue($data === null || $data instanceof PageActionSuccessSignalData);

        return $data instanceof PageActionSuccessSignalData ? $data : null;
    }
}

/**
 * Concrete reply DTO carrying a fixed domain payload for the reply tests.
 */
final class ActionReplyStub extends ActionReplyDTO
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['token' => 'abc'];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): static
    {
        return new static();
    }
}

/**
 * Test page whose actions cover the reply, no-reply, message-leak, and throw paths.
 */
final class ActionReplyTestPage extends AbstractPage
{
    public const string PAGE = 'reply';
    public const string REPLY_ACTION = 'reply_action';
    public const string PLAIN_ACTION = 'plain_action';
    public const string MESSAGE_ACTION = 'message_action';
    public const string THROW_ACTION = 'throw_action';

    /**
     * Routes each fixture action to its reply, message, or failure behavior.
     *
     * @param string $acceptKey WebSocket accept key (unused)
     * @param string $action Action name
     * @param ActionPayloadDTO $dto Action payload (unused)
     * @return ?ActionReplyDTO Reply DTO for the reply action, else null
     * @throws ValidationException When the throw action runs
     * @throws AgentUnknownActionException When the action is unsupported
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case self::REPLY_ACTION:
                return new ActionReplyStub();

            case self::PLAIN_ACTION:
                return null;

            case self::MESSAGE_ACTION:
                $this->setActionSuccessMessage('Leaked message.');

                return null;

            case self::THROW_ACTION:
                throw new ValidationException('boom');

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }
    }
}

/**
 * Page factory fixture exposing the reply test page.
 *
 * @extends AbstractPageFactory<ActionReplyTestAgent>
 */
final class ActionReplyTestPageFactory extends AbstractPageFactory
{
    /**
     * Creates the reply test page.
     *
     * @param string $pageName Page name
     * @return AbstractPage Test page instance
     * @throws PageNotFoundException When an unexpected page is requested
     */
    protected function createPage(string $pageName): AbstractPage
    {
        if ($pageName === ActionReplyTestPage::PAGE) {
            return new ActionReplyTestPage($this->agent);
        }

        throw new PageNotFoundException($pageName);
    }

    /**
     * Reports whether the reply test page is available.
     *
     * @param string $pageName Page name
     * @return bool True for the reply test page
     */
    public function hasPage(string $pageName): bool
    {
        return $pageName === ActionReplyTestPage::PAGE;
    }
}

/**
 * Minimal agent fixture supplying the signal source page helpers need.
 */
final class ActionReplyTestAgent implements PageAgentInterface
{
    /**
     * Returns the fixture agent id.
     *
     * @return string Agent id
     */
    public function getId(): string
    {
        return 'test-agent';
    }

    /**
     * Returns the fixture signal source for page helpers.
     *
     * @return SignalSourceInterface Signal source
     */
    public function getAgentSignalSource(): SignalSourceInterface
    {
        return new SignalSource(SignalSource::AGENT, 'test');
    }
}
