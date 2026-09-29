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
use Hilos\Core\Page\Exception\ActionForbiddenException;
use Hilos\Core\Page\Exception\PageNotFoundException;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Database\DatabaseException;
use Hilos\Hilos as HilosFacade;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Tests\Unit\Fixtures\AdminViewModeTestNode;
use Hilos\Tests\Unit\Fixtures\IdentityTestBrowser;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Unit tests for the action reply payload on the central action dispatcher: a
 * tracked action's returned reply DTO rides its success ack, an action that
 * returns null omits the reply field, an untracked action's reply is dropped,
 * a throwing action produces a fail ack with no reply, and the per-action
 * success-message slot is scoped to the action that set it.
 *
 * And whose text a failure on an ADMIN page carries (HIL-1251): the class and
 * message only for a connection that proves an admin now - not for a viewer of
 * the admin view mode, not for a non-admin the gate refused - and the same rule
 * for the text a page puts into a frame of its own.
 */
final class PageSignalRouterActionReplyTest extends TestCase
{
    private ?SignalRouter $previousRouter = null;

    private ?RtContext $previousRt = null;

    public function setUp(): void
    {
        parent::setUp();

        $this->previousRouter = HilosFacade::$sr;
        $this->previousRt = HilosFacade::$rt;
        HilosFacade::$sr = new SignalRouter();
    }

    public function tearDown(): void
    {
        AdminViewModeTestNode::unmount();
        HilosFacade::$rt = $this->previousRt;
        HilosFacade::$sr = $this->previousRouter;
        HilosFacade::resetBrowser();

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

    public function testATrackedFailureOnAnAdminPageTellsAnAdminItsClassAndText(): void
    {
        HilosFacade::$browser = new IdentityTestBrowser(userId: 7, admin: true);
        $router = $this->makeRouter();

        $router->dispatchAction(
            new WebSocketActionSignalDTO('ak-1', ActionReplyTestAdminPage::FAIL_ACTION, [], 'req-5'),
            'websocket',
        );

        $error = $this->findByName($this->drainAll(), SignalConstants::ACTION_ERROR);
        $this->assertInstanceOf(PageActionErrorSignalData::class, $error);
        $this->assertSame(SignalConstants::ACTION_FAILED_REASON, $error->reason);
        $this->assertSame('DatabaseException', $error->errorType);
        $this->assertSame(ActionReplyTestAdminPage::FAILURE, $error->errorDetail);
    }

    public function testATrackedFailureOnAnAdminPageTellsAViewerNothingOfIt(): void
    {
        AdminViewModeTestNode::mount(true);
        HilosFacade::$browser = new IdentityTestBrowser(userId: 5, admin: false);
        $router = $this->makeRouter();

        // A reading action runs for the viewer and fails inside its handler.
        $router->dispatchAction(
            new WebSocketActionSignalDTO('ak-1', ActionReplyTestAdminPage::FAIL_ACTION, [], 'req-6'),
            'websocket',
        );

        $error = $this->findByName($this->drainAll(), SignalConstants::ACTION_ERROR);
        $this->assertInstanceOf(PageActionErrorSignalData::class, $error);
        $this->assertSame(SignalConstants::ACTION_FAILED_REASON, $error->reason);
        $this->assertNull($error->errorCode);
        $this->assertNull($error->errorType);
        $this->assertNull($error->errorDetail);
    }

    public function testANonAdminRefusedOnAnAdminPageIsNotToldTheTextOfTheRefusal(): void
    {
        AdminViewModeTestNode::mount(false);
        HilosFacade::$browser = new IdentityTestBrowser(userId: 5, admin: false);
        $router = $this->makeRouter();

        $router->dispatchAction(
            new WebSocketActionSignalDTO('ak-1', ActionReplyTestAdminPage::FAIL_ACTION, [], 'req-7'),
            'websocket',
        );

        $error = $this->findByName($this->drainAll(), SignalConstants::ACTION_ERROR);
        $this->assertInstanceOf(PageActionErrorSignalData::class, $error);
        $this->assertSame(ActionForbiddenException::ERROR_CODE, $error->errorCode);
        $this->assertNull($error->errorType);
        $this->assertNull($error->errorDetail);
    }

    public function testThePageBuiltTextOfAFailureIsTheMessageOnlyForAnAdmin(): void
    {
        $page = new ActionReplyTestAdminPage(new ActionReplyTestAgent());
        $failure = new DatabaseException(ActionReplyTestAdminPage::FAILURE);

        HilosFacade::$browser = new IdentityTestBrowser(userId: 7, admin: true);
        $this->assertSame(ActionReplyTestAdminPage::FAILURE, $page->textFor('ak-1', $failure));

        AdminViewModeTestNode::mount(true);
        HilosFacade::$browser = new IdentityTestBrowser(userId: null, admin: false);
        $this->assertSame(SignalConstants::ACTION_FAILED_REASON, $page->textFor('ak-1', $failure));
    }

    /**
     * Builds a router routing every fixture action to its reply test page.
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
                ActionReplyTestAdminPage::FAIL_ACTION => ActionReplyTestAdminPage::PAGE,
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
 * Admin page whose one action - declared reading - fails where nobody wrote for the person.
 */
final class ActionReplyTestAdminPage extends AbstractPage
{
    public const string PAGE = 'reply_admin';
    public const string FAIL_ACTION = 'reply_admin_fail_action';
    public const string FAILURE = 'SQLSTATE[HY000]: General error';

    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::ADMIN;

    public const array READING_ACTIONS = [self::FAIL_ACTION];

    /**
     * Fails the way a driver does.
     *
     * @param string $acceptKey WebSocket accept key (unused)
     * @param string $action Action name (unused)
     * @param ActionPayloadDTO $dto Action payload (unused)
     * @return ?ActionReplyDTO Never returns
     * @throws DatabaseException Always
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        throw new DatabaseException(self::FAILURE);
    }

    /**
     * Opens the page's failure text to the test.
     *
     * @param string $acceptKey Connection the frame would go to
     * @param Throwable $e Failure the frame would report
     * @return string Text that connection may read
     */
    public function textFor(string $acceptKey, Throwable $e): string
    {
        return $this->failureText($acceptKey, $e);
    }
}

/**
 * Page factory fixture exposing the reply test pages.
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
        return match ($pageName) {
            ActionReplyTestPage::PAGE => new ActionReplyTestPage($this->agent),
            ActionReplyTestAdminPage::PAGE => new ActionReplyTestAdminPage($this->agent),
            default => throw new PageNotFoundException($pageName),
        };
    }

    /**
     * Reports whether a reply test page is available.
     *
     * @param string $pageName Page name
     * @return bool True for the reply test pages
     */
    public function hasPage(string $pageName): bool
    {
        return in_array($pageName, [ActionReplyTestPage::PAGE, ActionReplyTestAdminPage::PAGE], true);
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
