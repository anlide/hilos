<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Hilos\BaseDTO;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\AbstractPageFactory;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\Exception\PageNotFoundException;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Source\SourceChange;
use Hilos\Hilos;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use PHPUnit\Framework\TestCase;

/**
 * The receipt of an ask stamps the write it asks for with whoever asked (HIL-1001).
 *
 * A writer runs where no connection is served, so a write it performs carries no origin of its own
 * and reaches the asker's table as a stranger's change. The asker travels in the ask, and the
 * agent-signal dispatch reads it off the frame before the handler runs - so no library calls
 * ExecutionContext::withOrigin() by hand, and a frame that is not an ask is dispatched exactly as
 * it always was.
 */
final class HandoverAskReceiptTest extends TestCase
{
    protected function tearDown(): void
    {
        Hilos::$sr = null;
        HandoverReceiptTestAgent::$seen = [];
        HandoverReceiptTestAgent::$lastChange = null;

        parent::tearDown();
    }

    public function testAnAskRunsItsHandlerUnderTheOriginOfWhoeverAsked(): void
    {
        $manager = new HandoverReceiptTestManager();

        $this->deliver($manager, new HandoverReceiptTestAsk());

        $this->assertSame([['ak-asker', 'req-7']], HandoverReceiptTestAgent::$seen);
    }

    public function testAnUntrackedAskStampsTheAskerWithNoRequest(): void
    {
        $manager = new HandoverReceiptTestManager();

        $this->deliver($manager, new HandoverReceiptTestAsk(requestId: null));

        $this->assertSame([['ak-asker', null]], HandoverReceiptTestAgent::$seen);
    }

    public function testAFrameThatIsNoAskRunsItsHandlerWithNoOrigin(): void
    {
        $manager = new HandoverReceiptTestManager();

        $this->deliver($manager, new HandoverReceiptTestPlainFrame());

        $this->assertSame([[null, null]], HandoverReceiptTestAgent::$seen);
    }

    /**
     * The stamp is the handler's scope, not the worker's: the next frame the loop dispatches must
     * not inherit the previous asker.
     */
    public function testTheStampEndsWithTheHandler(): void
    {
        $manager = new HandoverReceiptTestManager();

        $this->deliver($manager, new HandoverReceiptTestAsk());

        $this->assertNull(ExecutionContext::currentAcceptKey());
        $this->assertNull(ExecutionContext::currentRequestId());
    }

    /**
     * What a table viewport reads: the change a write under the receipt publishes names the asker
     * as its author, which is what makes the row the asker's own on their screen.
     */
    public function testAWriteUnderTheReceiptNamesTheAskerAsItsAuthor(): void
    {
        $manager = new HandoverReceiptTestManager();

        $this->deliver($manager, new HandoverReceiptTestAsk());

        $change = HandoverReceiptTestAgent::$lastChange;
        $this->assertInstanceOf(SourceChange::class, $change);
        $this->assertSame('ak-asker', $change->origin);
        $this->assertSame('req-7', $change->originRequestId);
    }

    /**
     * Delivers one agent signal to the manager under test, the way the daemon does.
     *
     * @param WorkerManager $manager Manager under test
     * @param SignalDataInterface $payload Inner payload of the agent signal
     */
    private function deliver(WorkerManager $manager, SignalDataInterface $payload): void
    {
        $handle = Closure::bind(
            static function (WorkerManager $manager, SignalDTO $signal): void {
                $manager->handleAgentMessage(new DaemonAgentMessageDTO(HandoverReceiptTestManager::AGENT_ID, $signal));
            },
            null,
            WorkerManager::class,
        );

        $handle($manager, new SignalDTO(
            new SignalSource(SignalSource::AGENT, 'unit_gatekeeper'),
            new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            new SignalName('probe_write'),
            new AgentSignalData($payload),
        ));
    }
}

final class HandoverReceiptTestManager extends WorkerManager
{
    public const string AGENT_ID = 'unit_handover_receipt_agent';

    public function __construct()
    {
        parent::__construct(1);
        $this->agentManager->addAgent(self::AGENT_ID, new HandoverReceiptTestAgent());
    }

    /**
     * @return SignalRouter Plain router: the probe name declares no payload DTO
     */
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    /**
     * @return AgentManager Manager the test fills by hand
     */
    protected function createAgentManager(): AgentManager
    {
        return new HandoverReceiptTestAgentManager();
    }

    /**
     * @param AgentInterface $agent Agent receiving page signals (unused)
     * @return PageSignalRouter Router over a factory that knows no page
     */
    protected function createPageSignalRouter(AgentInterface $agent): PageSignalRouter
    {
        return new PageSignalRouter(new HandoverReceiptTestPageFactory($agent), new ActionRouteConfig());
    }
}

final class HandoverReceiptTestAgentManager extends AgentManager
{
    /**
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index
     * @return AgentInterface Fixture agent
     */
    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        return new HandoverReceiptTestAgent();
    }
}

/**
 * Writer fixture recording the origin its handler ran under, and the change a write would publish.
 */
final class HandoverReceiptTestAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'unit_handover_receipt';

    /** @var list<array{?string, ?string}> Accept key and request id each handler run saw */
    public static array $seen = [];

    /** @var ?SourceChange Change built the way an ORM write builds it, during the last run */
    public static ?SourceChange $lastChange = null;

    public function onStop(): void
    {
    }

    /**
     * Records the origin in force, and builds the change a row write would publish under it.
     *
     * @param AgentSignalData $data Signal data
     * @param string $sender Sender in full
     * @param string $name Signal name
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        self::$seen[] = [ExecutionContext::currentAcceptKey(), ExecutionContext::currentRequestId()];
        self::$lastChange = SourceChange::dbUpdated(
            'probe_rows',
            '1',
            ['label' => 'Renamed'],
            ExecutionContext::currentAcceptKey(),
            ExecutionContext::currentRequestId(),
        );
    }
}

/**
 * Page factory fixture knowing no page: an agent signal addressed to no page is dispatched to none.
 *
 * @extends AbstractPageFactory<HandoverReceiptTestAgent>
 */
final class HandoverReceiptTestPageFactory extends AbstractPageFactory
{
    /**
     * @param string $pageName Page name
     * @return bool Always false: the fixture holds no page
     */
    public function hasPage(string $pageName): bool
    {
        return false;
    }

    /**
     * @param string $pageName Page name
     * @return AbstractPage Never returns
     * @throws PageNotFoundException Always: the fixture holds no page
     */
    protected function createPage(string $pageName): AbstractPage
    {
        throw new PageNotFoundException($pageName);
    }
}

/**
 * Ask fixture naming an asker and a press.
 */
final class HandoverReceiptTestAsk extends BaseDTO implements HandoverAskInterface
{
    /**
     * @param string $replySignal Agent-signal name the writer reports back under
     * @param string $acceptKey Accept key of the connection that asked
     * @param ?string $requestId Request id of the tracked submit, or null when untracked
     * @param string $action Browser action name the ack is addressed to
     * @param ?string $successMessage Sentence to speak on success, or null
     */
    public function __construct(
        public readonly string $replySignal = 'probe_done',
        public readonly string $acceptKey = 'ak-asker',
        public readonly ?string $requestId = 'req-7',
        public readonly string $action = 'probe_action',
        public readonly ?string $successMessage = null,
    ) {
    }

    /**
     * @return array<string, mixed> Handover fields
     */
    public function toArray(): array
    {
        return [
            'replySignal' => $this->replySignal,
            'acceptKey' => $this->acceptKey,
            'requestId' => $this->requestId,
            'action' => $this->action,
            'successMessage' => $this->successMessage,
        ];
    }

    /**
     * @param array<string, mixed> $data Handover fields
     * @return static Ask fixture
     * @throws InvalidFormatException When a handover field the ask cannot do without is missing
     */
    public static function fromArray(array $data): static
    {
        return new static(
            self::requireString($data, 'replySignal'),
            self::requireString($data, 'acceptKey'),
            self::optionalString($data, 'requestId'),
            self::requireString($data, 'action'),
            self::optionalString($data, 'successMessage'),
        );
    }
}

/**
 * Agent-signal payload fixture that carries an accept key but is not an ask.
 */
final class HandoverReceiptTestPlainFrame extends BaseDTO implements SignalDataInterface
{
    /**
     * @param string $acceptKey Accept key the frame happens to carry
     */
    public function __construct(public readonly string $acceptKey = 'ak-asker')
    {
    }

    /**
     * @return array<string, mixed> Frame fields
     */
    public function toArray(): array
    {
        return ['acceptKey' => $this->acceptKey];
    }

    /**
     * @param array<string, mixed> $data Frame fields
     * @return static Frame fixture
     * @throws InvalidFormatException When the accept key is missing
     */
    public static function fromArray(array $data): static
    {
        return new static(self::requireString($data, 'acceptKey'));
    }
}
