<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Session\DeferredSessionCarryoverQueue;
use Hilos\Auth\Session\DTO\DeferredSessionCarryoverHandoverSignalData;
use Hilos\Auth\Session\SessionCarryover;
use Hilos\Auth\Session\SessionIdentityRef;
use Hilos\Backup\Agent\DTO\DeferredNoticesSentSignalData;
use Hilos\Backup\Agent\DTO\DeferredSessionsCarriedSignalData;
use Hilos\Backup\DeferredQueueHandover;
use Hilos\Backup\DeferredQueueHandoverSink;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Environment\EnvAccessor;
use Hilos\Fs\FsPath;
use Hilos\Hilos;
use Hilos\Notification\DeferredNotificationQueue;
use Hilos\Notification\DTO\DeferredNotificationHandoverSignalData;
use Hilos\Notification\Library\AbstractNotificationsLibraryAgent;
use Hilos\Notification\NotificationDraft;
use Hilos\Notification\NotificationSeverity;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the holder's side of the deferred restore queues (HIL-846).
 *
 * The holder is what stands between a restore on one node and libraries that may run on another:
 * it offers each batch until a receipt names it. What is pinned here is that promise and nothing
 * around it - an empty queue offers nothing, an offer never removes a file, an unanswered batch is
 * offered again under the same id, only the receipt for that batch removes it, and a project in
 * which nothing receives the name is told so once and not offered to again. The sink is a fake, so
 * no agent and no router runs.
 */
final class DeferredQueueHandoverTest extends TestCase
{
    /** @var float A moment far enough from zero that the first pass is never throttled */
    private const float START = 1000.0;

    /** @var float Seconds after a pass at which the holder makes the next one */
    private const float NEXT_PASS = 1.0;

    /** @var float Seconds past the holder's one-minute threshold for a batch nobody answers */
    private const float LONG_WAIT = 61.0;

    /** @var string Directory this case's queue files live in */
    private string $directory = '';

    /** @var string|false BACKUP_DIR the suite runs under, put back so this file does not decide what the next one reads */
    private string|false $previousBackupDir = false;

    /** @var ?EnvAccessor Env accessor to restore after the case */
    private ?EnvAccessor $previousEnv = null;

    /** @var DeferredQueueHandoverTestSink Where the holder under test sends its frames */
    private DeferredQueueHandoverTestSink $sink;

    protected function setUp(): void
    {
        $this->previousBackupDir = getenv('BACKUP_DIR');
        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        $this->directory = sys_get_temp_dir() . '/hilos-deferred-handover-' . getmypid() . '-' . uniqid();
        FsPath::ensureDirectory($this->directory);
        putenv('BACKUP_DIR=' . $this->directory);
        Hilos::$env = new EnvAccessor();
        DeferredQueueHandoverTestHilos::initBrowser();
        $this->sink = new DeferredQueueHandoverTestSink();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $leftover) {
            FsPath::delete($leftover);
        }
        rmdir($this->directory);
        $this->previousBackupDir === false ? putenv('BACKUP_DIR') : putenv('BACKUP_DIR=' . $this->previousBackupDir);
        Hilos::$env = $this->previousEnv;
        // Restore the captured facade class to the base default for later cases.
        Hilos::initBrowser();
        Hilos::resetBrowser();

        parent::tearDown();
    }

    public function testAnEmptyQueueIsOfferedToNobody(): void
    {
        $handover = new DeferredQueueHandover($this->sink);

        $handover->tick(self::START);

        self::assertSame([], $this->sink->sent);
    }

    public function testABatchIsOfferedAndItsFileStaysOnDisk(): void
    {
        DeferredNotificationQueue::defer(self::draft(7));
        $handover = new DeferredQueueHandover($this->sink);

        $handover->tick(self::START);

        self::assertCount(1, $this->sink->sent);
        self::assertSame(HilosSignalConstants::HILOS_NOTIFICATION_HANDOVER, $this->sink->sent[0][0]);
        self::assertSame(
            [7],
            array_map(static fn(NotificationDraft $draft): int => $draft->userId, $this->noticeOffer(0)->notifications),
        );
        self::assertCount(
            1,
            $this->batchFiles(DeferredNotificationQueue::FILE_NAME),
            'An offer is not a receipt: removed here, a batch whose frame is dropped would be lost',
        );
    }

    public function testAnUnansweredBatchIsOfferedAgainUnderTheSameId(): void
    {
        DeferredNotificationQueue::defer(self::draft(7));
        $handover = new DeferredQueueHandover($this->sink);

        $handover->tick(self::START);
        $handover->tick(self::START + self::NEXT_PASS);

        self::assertCount(2, $this->sink->sent);
        self::assertSame($this->noticeOffer(0)->batch, $this->noticeOffer(1)->batch);
    }

    public function testAPassInsideTheIntervalOffersNothing(): void
    {
        DeferredNotificationQueue::defer(self::draft(7));
        $handover = new DeferredQueueHandover($this->sink);

        $handover->tick(self::START);
        $handover->tick(self::START + self::NEXT_PASS / 2);

        self::assertCount(1, $this->sink->sent);
    }

    public function testTheReceiptForTheBatchRemovesIt(): void
    {
        DeferredNotificationQueue::defer(self::draft(7));
        $handover = new DeferredQueueHandover($this->sink);
        $handover->tick(self::START);

        $handover->onNoticesSent(new DeferredNoticesSentSignalData($this->noticeOffer(0)->batch, 1, 0));
        $handover->tick(self::START + self::NEXT_PASS);

        self::assertSame([], $this->batchFiles(DeferredNotificationQueue::FILE_NAME));
        self::assertCount(1, $this->sink->sent, 'Nothing is left to offer once the batch has been answered for');
    }

    public function testAReceiptForAnotherBatchRemovesNothing(): void
    {
        DeferredNotificationQueue::defer(self::draft(7));
        $handover = new DeferredQueueHandover($this->sink);
        $handover->tick(self::START);

        $handover->onNoticesSent(new DeferredNoticesSentSignalData('0badc0de', 1, 0));
        $handover->tick(self::START + self::NEXT_PASS);

        self::assertCount(1, $this->batchFiles(DeferredNotificationQueue::FILE_NAME));
        self::assertCount(2, $this->sink->sent);
        self::assertSame(
            $this->noticeOffer(0)->batch,
            $this->noticeOffer(1)->batch,
            'A late receipt for an earlier batch must not close this one unread',
        );
    }

    public function testTheSessionBatchGoesToTheSessionsLibraryAndItsReceiptRemovesIt(): void
    {
        DeferredSessionCarryoverQueue::defer([self::session('token-a')]);
        $handover = new DeferredQueueHandover($this->sink);

        $handover->tick(self::START);

        self::assertCount(1, $this->sink->sent);
        self::assertSame(HilosSignalConstants::HILOS_SESSION_CARRYOVER_HANDOVER, $this->sink->sent[0][0]);
        $offer = $this->sink->sent[0][1];
        self::assertInstanceOf(DeferredSessionCarryoverHandoverSignalData::class, $offer);
        self::assertSame(['token-a'], array_map(static fn(SessionCarryover $session): string => $session->token, $offer->sessions));

        $handover->onSessionsCarried(new DeferredSessionsCarriedSignalData($offer->batch, 1, 0, 0));

        self::assertSame([], $this->batchFiles(DeferredSessionCarryoverQueue::FILE_NAME));
    }

    public function testAQueueNothingReceivesIsNamedOnceAndNotOfferedAgain(): void
    {
        DeferredQueueHandoverUnreceivedTestHilos::initBrowser();
        DeferredNotificationQueue::defer(self::draft(7));
        $handover = new DeferredQueueHandover($this->sink);

        // An agent's lines go to its output, where the daemon picks them up, and not to a log file.
        ob_start();
        $handover->tick(self::START);
        $handover->tick(self::START + self::NEXT_PASS);
        $output = ob_get_clean();

        self::assertSame([], $this->sink->sent, 'A name nothing declares would be sent every second into nowhere');
        self::assertIsString($output);
        self::assertSame(
            1,
            substr_count($output, 'no agent of this project receives ' . HilosSignalConstants::HILOS_NOTIFICATION_HANDOVER),
        );
        self::assertCount(
            1,
            $this->batchFiles(DeferredNotificationQueue::FILE_NAME),
            'The batch stays on disk for a process that has somebody to give it to',
        );
    }

    public function testSessionsQueueHasNoOwnerAfterOfferingIsGivenUp(): void
    {
        DeferredQueueHandoverUnreceivedTestHilos::initBrowser();
        DeferredSessionCarryoverQueue::defer([self::session('token-a')]);
        $handover = new DeferredQueueHandover($this->sink);

        ob_start();
        $handover->tick(self::START);
        ob_end_clean();

        self::assertTrue($handover->sessionsQueueHasNoOwner());
    }

    public function testSessionsQueueHasAnOwnerWhileItIsBeingOffered(): void
    {
        DeferredSessionCarryoverQueue::defer([self::session('token-a')]);
        $handover = new DeferredQueueHandover($this->sink);

        $handover->tick(self::START);

        self::assertFalse($handover->sessionsQueueHasNoOwner());
    }

    public function testABatchNobodyAnswersIsComplainedAboutOnce(): void
    {
        DeferredNotificationQueue::defer(self::draft(7));
        $handover = new DeferredQueueHandover($this->sink);

        ob_start();
        $handover->tick(self::START);
        $handover->tick(self::START + self::LONG_WAIT);
        $handover->tick(self::START + 2 * self::LONG_WAIT);
        $output = ob_get_clean();

        self::assertIsString($output);
        self::assertSame(1, substr_count($output, 'for its owner to answer'));
    }

    /**
     * @param int $offer Position of the offer among the frames sent
     * @return DeferredNotificationHandoverSignalData The notice batch that offer carried
     */
    private function noticeOffer(int $offer): DeferredNotificationHandoverSignalData
    {
        $data = $this->sink->sent[$offer][1];
        self::assertInstanceOf(DeferredNotificationHandoverSignalData::class, $data);

        return $data;
    }

    /**
     * @param string $fileName Queue file name the batches are set aside from
     * @return list<string> Batch files of that queue left in this case's directory
     */
    private function batchFiles(string $fileName): array
    {
        return glob($this->directory . '/' . $fileName . '.*.taken') ?: [];
    }

    /**
     * @param int $userId Recipient of the fixture draft
     * @return NotificationDraft One letter a restore could have left
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

    /**
     * @param string $token Session token of the fixture login
     * @return SessionCarryover One login a restore could have photographed
     */
    private static function session(string $token): SessionCarryover
    {
        return new SessionCarryover(
            token: $token,
            createdAt: '2026-09-12 10:00:00',
            expiresAt: null,
            identities: [new SessionIdentityRef('email', 'person@example.com')],
        );
    }
}

/**
 * Sink fixture that keeps every frame the holder sends instead of routing it.
 */
final class DeferredQueueHandoverTestSink implements DeferredQueueHandoverSink
{
    /** @var list<array{0: string, 1: SignalDataInterface}> Frames sent, as name and payload, in order */
    public array $sent = [];

    /**
     * @param string $signalName Hand-over signal name
     * @param SignalDataInterface $data The batch
     */
    public function handOverDeferredQueue(string $signalName, SignalDataInterface $data): void
    {
        $this->sent[] = [$signalName, $data];
    }
}

/**
 * Project facade fixture registering both libraries, so both hand-over names have a receiver.
 *
 * Abstract because only its registry constant is read.
 */
abstract class DeferredQueueHandoverTestHilos extends Hilos
{
    public const array AGENTS = [
        HilosAgentType::HILOS_SESSIONS_LIBRARY => [AgentRegistryKey::WORKER => AbstractSessionsLibraryAgent::class],
        HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY => [AgentRegistryKey::WORKER => AbstractNotificationsLibraryAgent::class],
    ];
}

/**
 * Project facade fixture registering neither library, so no agent receives a hand-over name.
 *
 * Abstract because only its registry constant is read.
 */
abstract class DeferredQueueHandoverUnreceivedTestHilos extends Hilos
{
}
