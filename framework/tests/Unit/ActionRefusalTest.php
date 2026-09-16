<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\BaseDTO;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Action\ActionRefusal;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Database\DatabaseException;
use PHPUnit\Framework\TestCase;

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
