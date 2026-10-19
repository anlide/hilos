<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\BaseDTO;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Action\ActionRefusal;
use Hilos\Core\Action\ActionReply;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Page\HandoverGatekeeperTrait;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageException;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Database\DatabaseException;
use Hilos\Hilos;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Tests\Unit\Fixtures\AdminViewModeTestNode;
use Hilos\Tests\Unit\Fixtures\IdentityTestBrowser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * Unit tests for the three ways a writer refuses an ask, and for the answer carrying the refusal
 * back to the gatekeeper whole (HIL-1001).
 *
 * The third shape is the one the leaf restores: a failure nobody wrote for the person used to be
 * reduced to the placeholder before it left the writer, and the class and text an administrator
 * quotes in a ticket were lost on the way.
 */
final class ActionRefusalTest extends TestCase
{
    private ?RtContext $previousRt = null;

    /** Mounts the existing admin-view fixture for the real handover detail gate. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousRt = Hilos::$rt;
        Hilos::$sr = new SignalRouter();
        AdminViewModeTestNode::mount(true);
    }

    /** Restores the node and drops the test identity and queued replies. */
    protected function tearDown(): void
    {
        AdminViewModeTestNode::unmount();
        Hilos::$rt = $this->previousRt;
        Hilos::$sr = null;
        Hilos::resetBrowser();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{Throwable, string}> Internal failures and their diagnostic class names
     */
    public static function internalFailures(): iterable
    {
        yield 'page' => [new PageException('Diagnostic: /private/config.php'), 'PageException'];
        yield 'runtime' => [new RuntimeException('Diagnostic: /private/config.php'), 'RuntimeException'];
        yield 'database' => [new DatabaseException('Diagnostic: /private/config.php'), 'DatabaseException'];
    }

    /**
     * @param PageException $failure Guard refusal carrying a person-facing sentence
     * @param string $message Exact public sentence
     * @param string $errorCode Existing tracked machine code
     * @param int $httpCode Existing exception status
     * @param ?int $retryAfter Existing tracked retry hint
     */
    #[DataProviderExternal(ActionFailureReasonTest::class, 'publicRefusals')]
    public function testPublicRefusalsAgreeAcrossTrackedUntrackedAndHandoverReplies(
        PageException $failure,
        string $message,
        string $errorCode,
        int $httpCode,
        ?int $retryAfter,
    ): void {
        $refusal = ActionRefusal::fromThrowable($failure);
        $this->assertSame($message, $refusal->reason);
        $this->assertNull($refusal->errorType);
        $this->assertNull($refusal->errorDetail);
        $this->assertFalse($refusal->isInternal());
        $this->assertSame($httpCode, $failure->getCode());

        $page = $this->replyPage();
        $reply = new ActionReply($page);
        foreach ([false, true] as $admin) {
            Hilos::$browser = new IdentityTestBrowser(userId: 7, admin: $admin);

            $reply->sendFailure('ak-1', 'probe_action', 'req-1', $failure, $admin);
            $tracked = $this->takeError($message, 'req-1');
            $this->assertSame($errorCode, $tracked->errorCode);
            $this->assertSame($retryAfter, $tracked->retryAfter);

            $reply->sendException('ak-1', 'probe_action', $failure);
            $untracked = $this->takeError($message, null);
            // The untracked throttle frame already omits both metadata fields.
            $this->assertSame($errorCode === 'rate_limited' ? null : $errorCode, $untracked->errorCode);
            $this->assertNull($untracked->retryAfter);

            foreach (['req-1', null] as $requestId) {
                $answer = HandoverAnswerSignalData::to(new ActionRefusalTestAsk(requestId: $requestId), $refusal);
                $page->answer(HandoverAnswerSignalData::fromArray($answer->toArray()));
                $handover = $this->takeError($message, $requestId);
                $this->assertNull($handover->errorCode);
                $this->assertNull($handover->retryAfter);
            }
        }
    }

    /**
     * @param Throwable $failure Unmarked internal exception
     * @param string $errorType Expected diagnostic class name
     */
    #[DataProvider('internalFailures')]
    public function testInternalTextIsWithheldOnEveryPathAndDetailOnlyReachesATrackedAdmin(
        Throwable $failure,
        string $errorType,
    ): void {
        $refusal = ActionRefusal::fromThrowable($failure);
        $this->assertSame(SignalConstants::ACTION_FAILED_REASON, $refusal->reason);
        $this->assertSame($errorType, $refusal->errorType);
        $this->assertSame('Diagnostic: /private/config.php', $refusal->errorDetail);
        $this->assertTrue($refusal->isInternal());

        $page = $this->replyPage();
        $reply = new ActionReply($page);
        foreach ([false, true] as $admin) {
            Hilos::$browser = new IdentityTestBrowser(userId: 7, admin: $admin);

            $reply->sendFailure('ak-1', 'probe_action', 'req-1', $failure, $admin);
            $this->takeError(
                SignalConstants::ACTION_FAILED_REASON,
                'req-1',
                $admin ? $errorType : null,
                $admin ? 'Diagnostic: /private/config.php' : null,
            );

            $reply->sendException('ak-1', 'probe_action', $failure);
            $this->takeError(SignalConstants::ACTION_FAILED_REASON, null);

            foreach (['req-1', null] as $requestId) {
                $answer = HandoverAnswerSignalData::to(new ActionRefusalTestAsk(requestId: $requestId), $refusal);
                $page->answer(HandoverAnswerSignalData::fromArray($answer->toArray()));
                $this->takeError(
                    SignalConstants::ACTION_FAILED_REASON,
                    $requestId,
                    $admin && $requestId !== null ? $errorType : null,
                    $admin && $requestId !== null ? 'Diagnostic: /private/config.php' : null,
                );
            }
        }
    }

    public function testASentenceTheWriterComposedTravelsAlone(): void
    {
        $refusal = ActionRefusal::said('Only failed deliveries can be retried');

        $this->assertSame('Only failed deliveries can be retried', $refusal->reason);
        $this->assertNull($refusal->errorType);
        $this->assertNull($refusal->errorDetail);
        $this->assertFalse($refusal->isInternal());
    }

    /**
     * A refusal written for the person is already shown in full, so nothing is held back and no
     * detail rides beside it - the same call the page dispatcher makes.
     */
    public function testAPersonFacingFailureTravelsInItsOwnWordsWithNoDetail(): void
    {
        $refusal = ActionRefusal::fromThrowable(new TableActionException('This user is already a moderator'));

        $this->assertSame('This user is already a moderator', $refusal->reason);
        $this->assertNull($refusal->errorType);
        $this->assertNull($refusal->errorDetail);
        $this->assertFalse($refusal->isInternal());
    }

    public function testAnInternalFailureBecomesThePlaceholderWithTheFailureBesideIt(): void
    {
        $refusal = ActionRefusal::fromThrowable(new DatabaseException('SQLSTATE[23000]: Integrity constraint violation'));

        $this->assertSame(SignalConstants::ACTION_FAILED_REASON, $refusal->reason);
        $this->assertSame('DatabaseException', $refusal->errorType);
        $this->assertSame('SQLSTATE[23000]: Integrity constraint violation', $refusal->errorDetail);
        $this->assertTrue($refusal->isInternal());
    }

    public function testTheAnswerEchoesTheAskAndCarriesTheRefusalWhole(): void
    {
        $answer = HandoverAnswerSignalData::to(
            new ActionRefusalTestAsk(),
            ActionRefusal::fromThrowable(new DatabaseException('SQLSTATE[23000]: Integrity constraint violation')),
        );

        $this->assertSame(
            [
                'acceptKey' => 'ak-1',
                'requestId' => 'req-1',
                'action' => 'probe_action',
                'successMessage' => 'Probe saved.',
                'error' => SignalConstants::ACTION_FAILED_REASON,
                'errorType' => 'DatabaseException',
                'errorDetail' => 'SQLSTATE[23000]: Integrity constraint violation',
            ],
            $answer->toArray(),
        );
        $this->assertEquals($answer, HandoverAnswerSignalData::fromArray($answer->toArray()));
    }

    public function testAnAnswerWithNoRefusalSaysItWentThrough(): void
    {
        $answer = HandoverAnswerSignalData::to(new ActionRefusalTestAsk(), null);

        $this->assertNull($answer->error);
        $this->assertNull($answer->errorType);
        $this->assertNull($answer->errorDetail);
        $this->assertSame('Probe saved.', $answer->successMessage);
    }

    /**
     * @return ActionRefusalTestPage Admin page answering through the existing reply and handover machinery
     */
    private function replyPage(): ActionRefusalTestPage
    {
        $agent = $this->createStub(PageAgentInterface::class);
        $agent->method('getAgentSignalSource')->willReturn(new SignalSource(SignalSource::AGENT, 'test'));

        return new ActionRefusalTestPage($agent);
    }

    /**
     * @param string $reason Expected public sentence
     * @param ?string $requestId Expected correlation, or null when untracked
     * @param ?string $errorType Expected diagnostic class, or null when withheld
     * @param ?string $errorDetail Expected diagnostic text, or null when withheld
     * @return PageActionErrorSignalData The only queued error, after checking its public and diagnostic fields
     */
    private function takeError(
        string $reason,
        ?string $requestId,
        ?string $errorType = null,
        ?string $errorDetail = null,
    ): PageActionErrorSignalData {
        $signal = Hilos::$sr->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertSame(SignalConstants::ACTION_ERROR, $signal->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertSame('ak-1', $signal->data->targetAcceptKey);
        $this->assertInstanceOf(PageActionErrorSignalData::class, $signal->data->data);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());

        $error = $signal->data->data;
        $this->assertSame('probe_action', $error->action);
        $this->assertSame($reason, $error->reason);
        $this->assertSame($requestId, $error->requestId);
        $this->assertSame($requestId, $error->getEnvelopeRequestId());
        $this->assertSame('fail', $error->getEnvelopeOutcome());
        $this->assertSame($errorType, $error->errorType);
        $this->assertSame($errorDetail, $error->errorDetail);
        if ($errorType === null) {
            $this->assertArrayNotHasKey('errorType', $error->toArray());
        }
        if ($errorDetail === null) {
            $this->assertArrayNotHasKey('errorDetail', $error->toArray());
        }

        return $error;
    }
}

/** Admin page fixture exposing the existing handover answer path. */
final class ActionRefusalTestPage extends AbstractPage
{
    use HandoverGatekeeperTrait;

    public const string PAGE = 'action_refusal_test_page';

    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::ADMIN;

    /**
     * @param HandoverAnswerSignalData $answer Writer's answer to deliver
     * @throws InvalidArgumentException When the error frame cannot be named
     */
    public function answer(HandoverAnswerSignalData $answer): void
    {
        $this->answerHandover($answer);
    }
}

/**
 * Ask fixture carrying the five handover fields and no domain of its own.
 */
final class ActionRefusalTestAsk extends BaseDTO implements HandoverAskInterface
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
