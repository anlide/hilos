<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\BaseDTO;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Action\ActionRefusal;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Page\DTO\PageActionSuccessSignalData;
use Hilos\Core\Page\HandoverGatekeeperTrait;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Database\DatabaseException;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the gatekeeper side of the handover: the ask it forwards and the ack it sends once
 * the writer has answered (HIL-1001).
 *
 * The detail gate is asked of the page's own level, so the same refusal is told in full to an admin
 * gatekeeper and byte for byte as before to any other.
 */
final class HandoverGatekeeperTraitTest extends TestCase
{
    protected function setUp(): void
    {
        Hilos::$sr = new SignalRouter();
    }

    protected function tearDown(): void
    {
        Hilos::$sr = null;

        parent::tearDown();
    }

    public function testATrackedSubmitIsForwardedAndItsAckDeferred(): void
    {
        $page = new HandoverGatekeeperTestAdminPage(new HandoverGatekeeperTestAgent());
        $page->beginActionDispatch('req-1');

        $page->ask(new HandoverGatekeeperTestAsk());

        $signal = $this->nextSignal();
        $this->assertSame(SignalTypeConstants::AGENT_SIGNAL, $signal->signalType->getType());
        $this->assertSame('probe_write', $signal->signalName->getName());
        $this->assertInstanceOf(AgentSignalData::class, $signal->data);
        $this->assertInstanceOf(HandoverGatekeeperTestAsk::class, $signal->data->data);
        $this->assertTrue($page->actionReplyDeferred());
    }

    public function testAnUntrackedSubmitIsForwardedWithNoAckOwed(): void
    {
        $page = new HandoverGatekeeperTestAdminPage(new HandoverGatekeeperTestAgent());
        $page->beginActionDispatch();

        $page->ask(new HandoverGatekeeperTestAsk(requestId: null));

        $this->assertSame('probe_write', $this->nextSignal()->signalName->getName());
        $this->assertFalse($page->actionReplyDeferred());
    }

    public function testATrackedSuccessIsAckedWithTheSentenceTheAskCarried(): void
    {
        $page = new HandoverGatekeeperTestAdminPage(new HandoverGatekeeperTestAgent());

        $page->answer(HandoverAnswerSignalData::to(new HandoverGatekeeperTestAsk(), null));

        $ack = $this->nextAck(SignalConstants::ACTION_SUCCESS);
        $this->assertInstanceOf(PageActionSuccessSignalData::class, $ack);
        $this->assertSame('probe_action', $ack->action);
        $this->assertSame('req-1', $ack->requestId);
        $this->assertSame('Probe saved.', $ack->message);
    }

    public function testATrackedSuccessWithNoSentenceIsAckedBare(): void
    {
        $page = new HandoverGatekeeperTestAdminPage(new HandoverGatekeeperTestAgent());

        $page->answer(HandoverAnswerSignalData::to(new HandoverGatekeeperTestAsk(successMessage: null), null));

        $ack = $this->nextAck(SignalConstants::ACTION_SUCCESS);
        $this->assertInstanceOf(PageActionSuccessSignalData::class, $ack);
        $this->assertSame('req-1', $ack->requestId);
        $this->assertNull($ack->message);
    }

    public function testATrackedRefusalOnAnAdminGatekeeperCarriesTheFailureBesideTheReason(): void
    {
        $page = new HandoverGatekeeperTestAdminPage(new HandoverGatekeeperTestAgent());

        $page->answer($this->internalRefusal());

        $ack = $this->nextAck(SignalConstants::ACTION_ERROR);
        $this->assertInstanceOf(PageActionErrorSignalData::class, $ack);
        $this->assertSame('probe_action', $ack->action);
        $this->assertSame('req-1', $ack->requestId);
        $this->assertSame(SignalConstants::ACTION_FAILED_REASON, $ack->reason);
        $this->assertSame('DatabaseException', $ack->errorType);
        $this->assertSame('SQLSTATE[23000]: Integrity constraint violation', $ack->errorDetail);
    }

    public function testTheSameRefusalOnAGatekeeperOfAnotherLevelCarriesNoDetail(): void
    {
        $page = new HandoverGatekeeperTestAuthenticatedPage(new HandoverGatekeeperTestAgent());

        $page->answer($this->internalRefusal());

        $ack = $this->nextAck(SignalConstants::ACTION_ERROR);
        $this->assertInstanceOf(PageActionErrorSignalData::class, $ack);
        $this->assertSame('req-1', $ack->requestId);
        $this->assertSame(SignalConstants::ACTION_FAILED_REASON, $ack->reason);
        $this->assertNull($ack->errorType);
        $this->assertNull($ack->errorDetail);
    }

    /**
     * An untracked refusal has no modal to be read in, so even an admin gatekeeper sends the
     * reason alone on the uncorrelated frame.
     */
    public function testAnUntrackedRefusalRidesTheUncorrelatedErrorFrameWithNoDetail(): void
    {
        $page = new HandoverGatekeeperTestAdminPage(new HandoverGatekeeperTestAgent());

        $page->answer(HandoverAnswerSignalData::to(
            new HandoverGatekeeperTestAsk(requestId: null),
            ActionRefusal::fromThrowable(new DatabaseException('SQLSTATE[23000]: Integrity constraint violation')),
        ));

        $ack = $this->nextAck(SignalConstants::ACTION_ERROR);
        $this->assertInstanceOf(PageActionErrorSignalData::class, $ack);
        $this->assertSame('probe_action', $ack->action);
        $this->assertNull($ack->requestId);
        $this->assertSame(SignalConstants::ACTION_FAILED_REASON, $ack->reason);
        $this->assertNull($ack->errorType);
        $this->assertNull($ack->errorDetail);
    }

    public function testAnUntrackedSuccessSaysNothing(): void
    {
        $page = new HandoverGatekeeperTestAdminPage(new HandoverGatekeeperTestAgent());

        $page->answer(HandoverAnswerSignalData::to(new HandoverGatekeeperTestAsk(requestId: null), null));

        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    /**
     * Builds the answer to a tracked ask whose write failed where nobody wrote for the person.
     *
     * @return HandoverAnswerSignalData Answer carrying an internal refusal
     */
    private function internalRefusal(): HandoverAnswerSignalData
    {
        return HandoverAnswerSignalData::to(
            new HandoverGatekeeperTestAsk(),
            ActionRefusal::fromThrowable(new DatabaseException('SQLSTATE[23000]: Integrity constraint violation')),
        );
    }

    /**
     * Takes the next queued signal, which the case expects to exist.
     *
     * @return SignalDTO Queued signal
     */
    private function nextSignal(): SignalDTO
    {
        $signal = Hilos::$sr->getNextQueuedSignal();
        $this->assertNotNull($signal);

        return $signal;
    }

    /**
     * Takes the next queued frame to the asking connection and returns its payload.
     *
     * @param string $name Signal name the frame must travel under
     * @return mixed Payload of the frame
     */
    private function nextAck(string $name): mixed
    {
        $signal = $this->nextSignal();
        $this->assertSame(SignalTypeConstants::WS_USER, $signal->signalType->getType());
        $this->assertSame($name, $signal->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertSame('ak-1', $signal->data->targetAcceptKey);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());

        return $signal->data->data;
    }
}

/**
 * Gatekeeper fixture opening the trait's two private halves to the test.
 */
abstract class HandoverGatekeeperTestPage extends AbstractPage
{
    use HandoverGatekeeperTrait;

    /**
     * @param HandoverGatekeeperTestAsk $ask Ask to forward
     */
    public function ask(HandoverGatekeeperTestAsk $ask): void
    {
        $this->forward('probe_write', $ask);
    }

    /**
     * @param HandoverAnswerSignalData $done Answer to turn into an ack
     */
    public function answer(HandoverAnswerSignalData $done): void
    {
        $this->answerHandover($done);
    }
}

final class HandoverGatekeeperTestAdminPage extends HandoverGatekeeperTestPage
{
    public const string PAGE = 'probe_admin_gatekeeper';

    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::ADMIN;
}

final class HandoverGatekeeperTestAuthenticatedPage extends HandoverGatekeeperTestPage
{
    public const string PAGE = 'probe_authenticated_gatekeeper';

    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::AUTHENTICATED;
}

final class HandoverGatekeeperTestAgent implements PageAgentInterface
{
    public function getId(): string
    {
        return 'test-agent';
    }

    public function getAgentSignalSource(): SignalSourceInterface
    {
        return new SignalSource(SignalSource::AGENT, 'test');
    }
}

/**
 * Ask fixture carrying the five handover fields and no domain of its own.
 */
final class HandoverGatekeeperTestAsk extends BaseDTO implements HandoverAskInterface
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
        public readonly string $acceptKey = 'ak-1',
        public readonly ?string $requestId = 'req-1',
        public readonly string $action = 'probe_action',
        public readonly ?string $successMessage = 'Probe saved.',
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
