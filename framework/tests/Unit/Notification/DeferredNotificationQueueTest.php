<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Notification;

use Hilos\Environment\EnvAccessor;
use Hilos\Fs\FsPath;
use Hilos\Hilos;
use Hilos\Notification\DeferredNotificationBatch;
use Hilos\Notification\DeferredNotificationQueue;
use Hilos\Notification\NotificationDraft;
use Hilos\Notification\NotificationSeverity;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the notices a restore leaves behind when nobody can be told (HIL-771, HIL-846).
 *
 * The queue is the whole of what stands between a restore outcome and silence: the emit seam is a
 * door to an agent now, and the two paths that announce a restore run with that agent stopped or
 * the daemon down. What is pinned here is the round trip, a line that is not a notification, and
 * the batch that stands for the file while the library has not answered: it is handed out again
 * until a receipt names it, and a receipt naming any other batch removes nothing.
 */
final class DeferredNotificationQueueTest extends TestCase
{
    /** @var string Directory this case's queue file lives in */
    private string $directory = '';

    /** @var string|false BACKUP_DIR the suite runs under, put back so this file does not decide what the next one reads */
    private string|false $previousBackupDir = false;

    /** @var ?EnvAccessor Env accessor to restore after the case */
    private ?EnvAccessor $previousEnv = null;

    protected function setUp(): void
    {
        $this->previousBackupDir = getenv('BACKUP_DIR');
        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        $this->directory = sys_get_temp_dir() . '/hilos-deferred-notifications-' . getmypid() . '-' . uniqid();
        FsPath::ensureDirectory($this->directory);
        putenv('BACKUP_DIR=' . $this->directory);
        Hilos::$env = new EnvAccessor();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $leftover) {
            FsPath::delete($leftover);
        }
        rmdir($this->directory);
        $this->previousBackupDir === false ? putenv('BACKUP_DIR') : putenv('BACKUP_DIR=' . $this->previousBackupDir);
        Hilos::$env = $this->previousEnv;

        parent::tearDown();
    }

    public function testADraftComesBackWholeAndInOrder(): void
    {
        DeferredNotificationQueue::defer($this->draft(7, 'first'));
        DeferredNotificationQueue::defer($this->draft(12, 'second'));

        $drafts = $this->take()->drafts;

        self::assertCount(2, $drafts);
        self::assertSame([7, 12], array_map(static fn(NotificationDraft $d): int => $d->userId, $drafts));
        self::assertSame('first', $drafts[0]->title);
        self::assertSame(NotificationSeverity::ERROR, $drafts[0]->severity);
        self::assertSame('the body', $drafts[0]->body);
        self::assertSame(['backupId' => 'b-1'], $drafts[0]->data);
    }

    public function testAnEmptyQueueHasNoBatch(): void
    {
        self::assertNull(DeferredNotificationQueue::take());
    }

    /**
     * Taking is not sending: until the library says it has the batch, the letters in it are still
     * owed, and a holder that asks again - on its next tick, or after a restart - is handed the
     * same batch under the same id.
     */
    public function testATakenBatchIsHandedOutAgainUntilItIsReleased(): void
    {
        DeferredNotificationQueue::defer($this->draft(7, 'first'));

        $first = $this->take();
        $second = $this->take();

        self::assertSame($first->batch, $second->batch);
        self::assertSame([7], $this->recipients($second));
    }

    public function testAReleasedBatchIsGone(): void
    {
        DeferredNotificationQueue::defer($this->draft(7, 'first'));

        DeferredNotificationQueue::release($this->take()->batch);

        self::assertNull(DeferredNotificationQueue::take(), 'A notice is handed over once, not on every tick of the holder');
        self::assertSame([], glob($this->directory . '/*') ?: [], 'The released batch leaves no file behind');
    }

    /**
     * The reason a batch has an id at all: a receipt for an earlier batch that arrived late would
     * otherwise remove the batch in flight now, unread - the silent loss the hand-over exists to end.
     */
    public function testALateReceiptForAnEarlierBatchRemovesNothing(): void
    {
        DeferredNotificationQueue::defer($this->draft(7, 'earlier'));
        $earlier = $this->take()->batch;
        DeferredNotificationQueue::release($earlier);
        DeferredNotificationQueue::defer($this->draft(12, 'later'));
        $later = $this->take();

        DeferredNotificationQueue::release($earlier);

        $offered = $this->take();
        self::assertNotSame($earlier, $later->batch);
        self::assertSame($later->batch, $offered->batch, 'A receipt naming another batch leaves this one in flight');
        self::assertSame([12], $this->recipients($offered));
    }

    public function testWhatArrivesWhileABatchIsInFlightWaitsBehindIt(): void
    {
        DeferredNotificationQueue::defer($this->draft(7, 'stranded'));
        $first = $this->take();
        DeferredNotificationQueue::defer($this->draft(12, 'fresh'));

        self::assertSame([7], $this->recipients($this->take()), 'The batch in flight is handed out again, alone');

        DeferredNotificationQueue::release($first->batch);
        $next = $this->take();

        self::assertNotSame($first->batch, $next->batch);
        self::assertSame([12], $this->recipients($next), 'The fresh notices become the next batch once the first is released');
    }

    public function testALineThatIsNotANotificationIsDroppedAndTheRestSurvive(): void
    {
        DeferredNotificationQueue::defer($this->draft(7, 'first'));
        FsPath::append($this->path(), "{\"userId\":\"not a number\"}\nnot json at all\n");
        DeferredNotificationQueue::defer($this->draft(12, 'second'));

        self::assertSame([7, 12], $this->recipients($this->take()), 'One unreadable line owes the letters behind it nothing');
    }

    public function testAnInstallationWithNoBackupDirectoryQueuesNothing(): void
    {
        putenv('BACKUP_DIR=');
        Hilos::$env = new EnvAccessor();

        DeferredNotificationQueue::defer($this->draft(7, 'first'));

        self::assertNull(DeferredNotificationQueue::take());
        self::assertFalse(is_file($this->path()), 'Nothing is written where no backup directory is named');
    }

    /**
     * @return DeferredNotificationBatch The batch the queue hands out now, asserted to exist
     */
    private function take(): DeferredNotificationBatch
    {
        $batch = DeferredNotificationQueue::take();
        self::assertNotNull($batch, 'The queue holds a batch to take');

        return $batch;
    }

    /**
     * @param DeferredNotificationBatch $batch Batch to read
     * @return list<int> Recipients of its drafts, in batch order
     */
    private function recipients(DeferredNotificationBatch $batch): array
    {
        return array_map(static fn(NotificationDraft $d): int => $d->userId, $batch->drafts);
    }

    /**
     * @param int $userId Recipient of the fixture draft
     * @param string $title Title of the fixture draft
     * @return NotificationDraft One draft with every field filled, so the round trip is measured whole
     */
    private function draft(int $userId, string $title): NotificationDraft
    {
        return new NotificationDraft(
            userId: $userId,
            type: 'backup.restore.failed',
            title: $title,
            severity: NotificationSeverity::ERROR,
            body: 'the body',
            data: ['backupId' => 'b-1'],
        );
    }

    /**
     * @return string Absolute path of this case's queue file
     */
    private function path(): string
    {
        return $this->directory . '/' . DeferredNotificationQueue::FILE_NAME;
    }
}
