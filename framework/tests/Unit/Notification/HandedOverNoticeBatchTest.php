<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Notification;

use Hilos\Backup\Agent\DTO\DeferredNoticesSentSignalData;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Database\DatabaseException;
use Hilos\Hilos;
use Hilos\Notification\DTO\DeferredNotificationHandoverSignalData;
use Hilos\Notification\Library\AbstractNotificationsLibraryAgent;
use Hilos\Notification\NotificationDraft;
use Hilos\Notification\NotificationSeverity;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for deduplication and receipt handling of handed-over notification batches (HIL-1030).
 */
final class HandedOverNoticeBatchTest extends TestCase
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

    public function testARepeatedFrameOfOneBatchSendsItsLettersOnce(): void
    {
        $agent = new HandedOverNoticeBatchTestAgent();
        $draftA = self::draft(1);
        $draftB = self::draft(2);

        $this->sendHandover($agent, 'a1b2c3d4', [$draftA, $draftB]);
        $this->sendHandover($agent, 'a1b2c3d4', [$draftA, $draftB]);

        self::assertCount(2, $agent->emitted);

        $receipt1 = $this->consumeReceipt();
        self::assertSame('a1b2c3d4', $receipt1->batch);
        self::assertSame(2, $receipt1->sent);
        self::assertSame(0, $receipt1->dropped);

        $receipt2 = $this->consumeReceipt();
        self::assertSame('a1b2c3d4', $receipt2->batch);
        self::assertSame(0, $receipt2->sent);
        self::assertSame(0, $receipt2->dropped);

        self::assertNull(Hilos::$sr->getNextQueuedSignal(), 'No third signal queued');
    }

    public function testTheNextBatchIsAppliedAsUsual(): void
    {
        $agent = new HandedOverNoticeBatchTestAgent();
        $draftA = self::draft(1);
        $draftB = self::draft(2);
        $draftC = self::draft(3);

        $this->sendHandover($agent, 'a1b2c3d4', [$draftA, $draftB]);
        $this->sendHandover($agent, '0badc0de', [$draftC]);

        self::assertCount(3, $agent->emitted);

        $receipt1 = $this->consumeReceipt();
        self::assertSame('a1b2c3d4', $receipt1->batch);
        self::assertSame(2, $receipt1->sent);
        self::assertSame(0, $receipt1->dropped);

        $receipt2 = $this->consumeReceipt();
        self::assertSame('0badc0de', $receipt2->batch);
        self::assertSame(1, $receipt2->sent);
        self::assertSame(0, $receipt2->dropped);

        self::assertNull(Hilos::$sr->getNextQueuedSignal(), 'No third signal queued');
    }

    public function testALetterDroppedOnTheFirstPassIsNotRetriedByTheRepeat(): void
    {
        $agent = new HandedOverNoticeBatchTestAgent();
        $draftA = self::draft(10);
        $draftB = self::draft(20);
        $agent->failFor = 20;

        ob_start();
        try {
            $this->sendHandover($agent, 'a1b2c3d4', [$draftA, $draftB]);
            $this->sendHandover($agent, 'a1b2c3d4', [$draftA, $draftB]);
        } finally {
            ob_end_clean();
        }

        self::assertCount(1, $agent->emitted);

        $receipt1 = $this->consumeReceipt();
        self::assertSame('a1b2c3d4', $receipt1->batch);
        self::assertSame(1, $receipt1->sent);
        self::assertSame(1, $receipt1->dropped);

        $receipt2 = $this->consumeReceipt();
        self::assertSame('a1b2c3d4', $receipt2->batch);
        self::assertSame(0, $receipt2->sent);
        self::assertSame(0, $receipt2->dropped);

        self::assertNull(Hilos::$sr->getNextQueuedSignal(), 'No third signal queued');
    }

    /**
     * Sends a handed-over batch frame to the test agent.
     *
     * @param HandedOverNoticeBatchTestAgent $agent Agent under test
     * @param string $batch Batch id
     * @param list<NotificationDraft> $drafts Notification drafts
     */
    private function sendHandover(
        HandedOverNoticeBatchTestAgent $agent,
        string $batch,
        array $drafts,
    ): void {
        $agent->onSignalAgent(
            new AgentSignalData(data: new DeferredNotificationHandoverSignalData($batch, $drafts)),
            '',
            HilosSignalConstants::HILOS_NOTIFICATION_HANDOVER,
        );
    }

    /**
     * Consumes and verifies the queued receipt signal.
     *
     * @return DeferredNoticesSentSignalData Queued receipt payload
     */
    private function consumeReceipt(): DeferredNoticesSentSignalData
    {
        $signal = Hilos::$sr->getNextQueuedSignal();
        self::assertNotNull($signal, 'Expected a queued receipt signal');
        self::assertSame(HilosSignalConstants::BACKUP_AGENT_NOTICES_SENT, $signal->signalName->getName());
        self::assertInstanceOf(AgentSignalData::class, $signal->data);
        self::assertInstanceOf(DeferredNoticesSentSignalData::class, $signal->data->data);

        return $signal->data->data;
    }

    /**
     * @param int $userId Recipient of the fixture draft
     * @return NotificationDraft Fixture draft
     */
    private static function draft(int $userId): NotificationDraft
    {
        return new NotificationDraft(
            userId: $userId,
            type: 'backup.restore.failed',
            title: 'Restore failed',
            severity: NotificationSeverity::ERROR,
            body: 'the body',
            data: ['backupId' => 'b-1'],
        );
    }
}

/**
 * Test agent subclass that fakes emit without a database.
 */
final class HandedOverNoticeBatchTestAgent extends AbstractNotificationsLibraryAgent
{
    /** @var list<NotificationDraft> Emitted notification drafts */
    public array $emitted = [];

    /** @var ?int Recipient user id for which emit should throw */
    public ?int $failFor = null;

    /**
     * @param NotificationDraft $draft Notification draft to emit
     * @return int Fake persisted notification id
     * @throws DatabaseException When the draft matches the configured recipient failure
     */
    public function emit(NotificationDraft $draft): int
    {
        if ($this->failFor !== null && $draft->userId === $this->failFor) {
            throw new DatabaseException("Simulated database failure for user {$draft->userId}");
        }

        $this->emitted[] = $draft;

        return count($this->emitted);
    }
}
