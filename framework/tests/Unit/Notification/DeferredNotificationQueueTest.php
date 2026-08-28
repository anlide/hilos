<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Notification;

use Hilos\Environment\EnvAccessor;
use Hilos\Fs\FsPath;
use Hilos\Hilos;
use Hilos\Notification\DeferredNotificationQueue;
use Hilos\Notification\NotificationDraft;
use Hilos\Notification\NotificationSeverity;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the notices a restore leaves behind when nobody can be told (HIL-771).
 *
 * The queue is the whole of what stands between a restore outcome and silence: the emit seam is a
 * door to an agent now, and the two paths that announce a restore run with that agent stopped or
 * the daemon down. What is pinned here is the round trip and the two ways it is asked to survive
 * damage - a line that is not a notification, and a file a previous drain left behind.
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
        foreach ([$this->path(), $this->path() . '.taken'] as $leftover) {
            if (is_file($leftover)) {
                FsPath::delete($leftover);
            }
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

        $drafts = DeferredNotificationQueue::drain();

        self::assertCount(2, $drafts);
        self::assertSame([7, 12], array_map(static fn(NotificationDraft $d): int => $d->userId, $drafts));
        self::assertSame('first', $drafts[0]->title);
        self::assertSame(NotificationSeverity::ERROR, $drafts[0]->severity);
        self::assertSame('the body', $drafts[0]->body);
        self::assertSame(['backupId' => 'b-1'], $drafts[0]->data);
    }

    public function testADrainedQueueIsEmptyAfterwards(): void
    {
        DeferredNotificationQueue::defer($this->draft(7, 'first'));

        DeferredNotificationQueue::drain();

        self::assertSame([], DeferredNotificationQueue::drain(), 'A notice is sent once, not on every start');
    }

    public function testALineThatIsNotANotificationIsDroppedAndTheRestSurvive(): void
    {
        DeferredNotificationQueue::defer($this->draft(7, 'first'));
        FsPath::append($this->path(), "{\"userId\":\"not a number\"}\nnot json at all\n");
        DeferredNotificationQueue::defer($this->draft(12, 'second'));

        $drafts = DeferredNotificationQueue::drain();

        self::assertSame(
            [7, 12],
            array_map(static fn(NotificationDraft $d): int => $d->userId, $drafts),
            'One unreadable line owes the letters behind it nothing',
        );
    }

    public function testAFileLeftByADrainThatDiedIsTakenFirst(): void
    {
        DeferredNotificationQueue::defer($this->draft(7, 'stranded'));
        FsPath::move($this->path(), $this->path() . '.taken');
        DeferredNotificationQueue::defer($this->draft(12, 'fresh'));

        $drafts = DeferredNotificationQueue::drain();

        self::assertSame(
            [7, 12],
            array_map(static fn(NotificationDraft $d): int => $d->userId, $drafts),
            'The stranded file is read before the one being taken now, so the notices keep their order',
        );
    }

    public function testAnInstallationWithNoBackupDirectoryQueuesNothing(): void
    {
        putenv('BACKUP_DIR=');
        Hilos::$env = new EnvAccessor();

        DeferredNotificationQueue::defer($this->draft(7, 'first'));

        self::assertSame([], DeferredNotificationQueue::drain());
        self::assertFalse(is_file($this->path()), 'Nothing is written where no backup directory is named');
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
