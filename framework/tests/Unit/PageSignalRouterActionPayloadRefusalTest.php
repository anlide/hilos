<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalConstants;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\AbstractPageFactory;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
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
 * Unit tests for a payload the action DTO refuses (HIL-545).
 *
 * An action DTO reads the fields its action is defined by and throws when the
 * client sent none, instead of standing an empty string in their place. That
 * throw happens while the DTO is built, which is why the dispatcher builds it
 * inside the try: a tracked action gets the same fail ack any other failure
 * produces, and an untracked one is logged like any other. The one thing the
 * failure cannot reach is onActionException(), which is handed the DTO that
 * could not be built — so the hook is skipped rather than fed a stand-in.
 */
final class PageSignalRouterActionPayloadRefusalTest extends TestCase
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

    public function testTrackedActionWithARefusedPayloadGetsTheFailAck(): void
    {
        $router = $this->makeRouter();

        $router->dispatchAction(
            new WebSocketActionSignalDTO('ak-1', RefusalTestPage::TOKEN_ACTION, [], 'req-1'),
            'websocket',
        );

        $error = $this->errorSignal($this->drainAll());
        $this->assertNotNull($error);
        $this->assertSame('req-1', $error->requestId);
    }

    public function testUntrackedActionWithARefusedPayloadReachesNoExceptionHook(): void
    {
        $router = $this->makeRouter();

        $router->dispatchAction(
            new WebSocketActionSignalDTO('ak-1', RefusalTestPage::TOKEN_ACTION),
            'websocket',
        );

        $this->assertNull($this->errorSignal($this->drainAll()));
    }

    public function testUntrackedActionThatThrowsStillReachesTheExceptionHook(): void
    {
        $router = $this->makeRouter();

        $router->dispatchAction(
            new WebSocketActionSignalDTO('ak-1', RefusalTestPage::THROW_ACTION, ['token' => 'ok']),
            'websocket',
        );

        $error = $this->errorSignal($this->drainAll());
        $this->assertNotNull($error);
        $this->assertNull($error->requestId);
    }

    public function testBlankFieldIsNotARefusalAndReachesTheHandler(): void
    {
        $factory = new RefusalTestPageFactory(new RefusalTestAgent());
        $router = new PageSignalRouter($factory, $this->routes());

        $router->dispatchAction(
            new WebSocketActionSignalDTO('ak-1', RefusalTestPage::TOKEN_ACTION, ['token' => '']),
            'websocket',
        );

        $page = $factory->getPage(RefusalTestPage::PAGE);
        $this->assertInstanceOf(RefusalTestPage::class, $page);
        $this->assertNotNull($page->received);
        $this->assertSame('', $page->received->token);
        $this->assertNull($this->errorSignal($this->drainAll()));
    }

    /**
     * Builds a router routing both fixture actions to the refusal test page.
     *
     * @return PageSignalRouter Router under test
     */
    private function makeRouter(): PageSignalRouter
    {
        return new PageSignalRouter(new RefusalTestPageFactory(new RefusalTestAgent()), $this->routes());
    }

    /**
     * Routes both fixture actions to the refusal test page.
     *
     * @return ActionRouteConfig Action routes for the fixture page
     */
    private function routes(): ActionRouteConfig
    {
        return new ActionRouteConfig([
            RefusalTestPage::TOKEN_ACTION => RefusalTestPage::PAGE,
            RefusalTestPage::THROW_ACTION => RefusalTestPage::PAGE,
        ]);
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
     * Finds the action-error payload among drained signals, if one was queued.
     *
     * @param list<array{name: string, data: object}> $signals Drained signals
     * @return ?PageActionErrorSignalData Action-error payload, or null when none
     */
    private function errorSignal(array $signals): ?PageActionErrorSignalData
    {
        foreach ($signals as $signal) {
            if ($signal['name'] === SignalConstants::ACTION_ERROR) {
                $this->assertInstanceOf(PageActionErrorSignalData::class, $signal['data']);

                return $signal['data'];
            }
        }

        return null;
    }
}

/**
 * Action payload fixture defined by a token the client must send.
 */
final class RefusalTestActionDTO extends ActionPayloadDTO
{
    /** Payload key: the token the action works on. */
    public const string token = 'token';

    /**
     * @param string $token Token the action was asked to work on
     */
    public function __construct(public readonly string $token)
    {
    }

    /**
     * @return string Action name
     */
    public function getAction(): string
    {
        return RefusalTestPage::TOKEN_ACTION;
    }

    /**
     * @return array<string, mixed> Payload with the token
     */
    public function toArray(): array
    {
        return [self::token => $this->token];
    }

    /**
     * @param array<string, mixed> $data Payload data
     * @return static Token DTO instance
     * @throws InvalidFormatException When the payload carries no token string
     */
    public static function fromArray(array $data): static
    {
        return new static(self::requireString($data, self::token));
    }
}

/**
 * Test page recording the payload it was handed, and failing on demand.
 */
final class RefusalTestPage extends AbstractPage
{
    public const string PAGE = 'refusal';
    public const string TOKEN_ACTION = 'token_action';
    public const string THROW_ACTION = 'throw_action';

    /** @var ?RefusalTestActionDTO Payload the last dispatch handed over, if any */
    public ?RefusalTestActionDTO $received = null;

    /**
     * Records the payload of the token action, or fails for the throwing one.
     *
     * @param string $acceptKey WebSocket accept key (unused)
     * @param string $action Action name
     * @param ActionPayloadDTO $dto Action payload
     * @return ?ActionReplyDTO Always null; the fixture answers nothing
     * @throws ValidationException When the throwing action runs
     * @throws AgentUnknownActionException When the action is unsupported
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case self::TOKEN_ACTION:
                if ($dto instanceof RefusalTestActionDTO) {
                    $this->received = $dto;
                }

                return null;

            case self::THROW_ACTION:
                throw new ValidationException('boom');

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }
    }
}

/**
 * Page factory fixture parsing every action into the token payload DTO.
 *
 * @extends AbstractPageFactory<RefusalTestAgent>
 */
final class RefusalTestPageFactory extends AbstractPageFactory
{
    /**
     * Creates the refusal test page.
     *
     * @param string $pageName Page name
     * @return AbstractPage Test page instance
     * @throws PageNotFoundException When an unexpected page is requested
     */
    protected function createPage(string $pageName): AbstractPage
    {
        if ($pageName === RefusalTestPage::PAGE) {
            return new RefusalTestPage($this->agent);
        }

        throw new PageNotFoundException($pageName);
    }

    /**
     * Reports whether the refusal test page is available.
     *
     * @param string $pageName Page name
     * @return bool True for the refusal test page
     */
    public function hasPage(string $pageName): bool
    {
        return $pageName === RefusalTestPage::PAGE;
    }

    /**
     * Parses every fixture action into the token payload DTO.
     *
     * @param string $action Action name
     * @param array<string, mixed> $data Payload data
     * @return ActionPayloadDTO Parsed payload
     * @throws InvalidFormatException When the payload carries no token string
     */
    public function createActionPayloadDTO(string $action, array $data): ActionPayloadDTO
    {
        return RefusalTestActionDTO::fromArray($data);
    }
}

/**
 * Minimal agent fixture supplying the signal source page helpers need.
 */
final class RefusalTestAgent implements PageAgentInterface
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
