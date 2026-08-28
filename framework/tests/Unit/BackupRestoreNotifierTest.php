<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Auth\Session\SessionIdentityRef;
use Hilos\Backup\BackupNotificationType;
use Hilos\Backup\BackupScope;
use Hilos\Backup\RestoreNotifier;
use Hilos\Database\Context\DbContext;
use Hilos\Environment\EnvAccessor;
use Hilos\Fs\FsPath;
use Hilos\Hilos;
use Hilos\Notification\DeferredNotificationQueue;
use Hilos\Notification\NotificationDraft;
use Hilos\Notification\NotificationSeverity;
use Hilos\Users\AdminAudience;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the announcement a finished restore raises (HIL-279).
 *
 * What is pinned here is everything the run cannot say for itself: who the outcome reaches
 * once the database it was decided in has been replaced, and what the two notifications say.
 * The recipients are the interesting half - the administrators are read out of the restored
 * database, and the person who asked is recognized by identity rather than by the user id
 * they had before the swap, because that id now belongs to whoever holds it in the archive.
 */
final class BackupRestoreNotifierTest extends TestCase
{
    /** Backup id every case replays. */
    private const string BACKUP_ID = '2026-08-15_10-30-00';

    /** SQL datetime the runs in these cases started at. */
    private const string STARTED_AT = '2026-08-15 10:30:00';

    /** @var string Directory this case's queue file lives in */
    private string $queueDirectory = '';

    /** @var string|false BACKUP_DIR the suite runs under, put back so this file does not decide what the next one reads */
    private string|false $previousBackupDir = false;

    /** @var ?EnvAccessor Env accessor to restore after the case */
    private ?EnvAccessor $previousEnv = null;

    protected function setUp(): void
    {
        $this->previousBackupDir = getenv('BACKUP_DIR');
        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        $this->queueDirectory = sys_get_temp_dir() . '/hilos-restore-notifier-' . getmypid() . '-' . uniqid();
        FsPath::ensureDirectory($this->queueDirectory);
    }

    protected function tearDown(): void
    {
        foreach ([
            $this->queueDirectory . '/' . DeferredNotificationQueue::FILE_NAME,
            $this->queueDirectory . '/' . DeferredNotificationQueue::FILE_NAME . '.taken',
        ] as $leftover) {
            if (is_file($leftover)) {
                FsPath::delete($leftover);
            }
        }
        rmdir($this->queueDirectory);
        $this->previousBackupDir === false ? putenv('BACKUP_DIR') : putenv('BACKUP_DIR=' . $this->previousBackupDir);
        Hilos::$env = $this->previousEnv;
        Hilos::$notify = null;
        Hilos::$db = null;
        // Restore the captured facade class to the base default for later cases.
        Hilos::initBrowser();
        Hilos::resetBrowser();

        parent::tearDown();
    }

    public function testTheOutcomeReachesEveryAdministratorAndTheInitiator(): void
    {
        $this->arrange(['email:boss@example.test' => 41]);

        $this->notifySuccess([new SessionIdentityRef('email', 'boss@example.test')]);

        self::assertSame([7, 12, 41], $this->recipients());
    }

    public function testAnAdministratorWhoStartedTheRestoreIsNotNotifiedTwice(): void
    {
        $this->arrange(['email:admin@example.test' => 12]);

        $this->notifySuccess([new SessionIdentityRef('email', 'admin@example.test')]);

        self::assertSame(
            [7, 12],
            $this->recipients(),
            'The initiator keeps their place in the audience instead of being appended a second time',
        );
    }

    public function testTheFirstIdentityThatResolvesNamesTheInitiator(): void
    {
        $this->arrange(['sms:+15550000002' => 41]);

        $this->notifySuccess([
            // The email is the account of somebody else's installation and is not in this database.
            new SessionIdentityRef('email', 'gone@example.test'),
            new SessionIdentityRef('sms', '+15550000002'),
        ]);

        self::assertSame([7, 12, 41], $this->recipients());
    }

    public function testAnInitiatorTheRestoredDatabaseDoesNotKnowIsSimplyDropped(): void
    {
        $this->arrange();

        $this->notifySuccess([new SessionIdentityRef('email', 'stranger@example.test')]);

        self::assertSame(
            [7, 12],
            $this->recipients(),
            'The archive holds a different set of people; the administrators of it are still told',
        );
    }

    public function testAnInstallationThatNamesNoAdministratorsIsToldNothing(): void
    {
        $this->arrange();
        RestoreNotifierSilentTestHilos::initBrowser();

        $this->notifySuccess([]);

        self::assertSame(
            [],
            $this->queued(),
            'A project that never declared its administrators sends nothing, rather than to a guess',
        );
    }

    public function testTheSuccessNotificationNamesTheArchiveAndHowLongItTook(): void
    {
        $this->arrange();

        $this->notifySuccess([]);

        $draft = $this->queued()[0];
        self::assertSame(BackupNotificationType::RESTORE_SUCCEEDED, $draft->type);
        self::assertSame(NotificationSeverity::SUCCESS, $draft->severity);
        self::assertSame('Restore completed', $draft->title);
        self::assertSame('Backup ' . self::BACKUP_ID . ' (full) was restored in 93s.', $draft->body);
    }

    public function testTheSuccessNotificationCarriesTheWholeRunInItsData(): void
    {
        $this->arrange();

        $this->notifySuccess([]);

        self::assertSame([
            'backupId' => self::BACKUP_ID,
            'scope' => 'full',
            'outcome' => 'succeeded',
            'startedAt' => self::STARTED_AT,
            'durationSeconds' => 93,
            'initiatedBy' => 'cli',
            'rehydrateComplete' => true,
            'failureSummary' => null,
        ], $this->queued()[0]->data);
    }

    public function testARunSomebodyAskedForSaysSoInItsData(): void
    {
        $this->arrange(['email:boss@example.test' => 41]);

        $this->notifySuccess([new SessionIdentityRef('email', 'boss@example.test')]);

        self::assertSame('ui', $this->queued()[0]->data['initiatedBy']);
    }

    public function testTheFailureNotificationCarriesOneLineAndSendsTheReaderToThePage(): void
    {
        $this->arrange();

        new RestoreNotifier()->notifyOutcome(
            self::BACKUP_ID,
            BackupScope::FULL,
            success: false,
            failureDetail: "child exited with code 1\nmysql: table users is marked as crashed",
            startedAt: self::STARTED_AT,
            durationSeconds: 12,
            rehydrateComplete: false,
            initiatorIdentities: [],
        );

        $draft = $this->queued()[0];
        self::assertSame(BackupNotificationType::RESTORE_FAILED, $draft->type);
        self::assertSame(NotificationSeverity::ERROR, $draft->severity);
        self::assertSame('Restore failed', $draft->title);
        self::assertSame(
            'child exited with code 1 Details are on the backups page.',
            $draft->body,
            'The second line names a table and stays in the log: this text goes out over an'
            . ' external SMTP into other people\'s mailboxes',
        );
        self::assertSame('failed', $draft->data['outcome']);
        self::assertSame('child exited with code 1', $draft->data['failureSummary']);
        self::assertFalse($draft->data['rehydrateComplete']);
    }

    public function testAFailureWithNothingToSayStillSendsTheReaderToThePage(): void
    {
        $this->arrange();

        new RestoreNotifier()->notifyOutcome(
            self::BACKUP_ID,
            BackupScope::SCHEMA_SEED,
            success: false,
            failureDetail: null,
            startedAt: self::STARTED_AT,
            durationSeconds: 12,
            rehydrateComplete: true,
            initiatorIdentities: [],
        );

        $draft = $this->queued()[0];
        self::assertSame('Details are on the backups page.', $draft->body);
        self::assertNull($draft->data['failureSummary']);
    }

    public function testTheFailureLineKeepsOnlyTheFirstLine(): void
    {
        self::assertSame(
            'dump failed',
            RestoreNotifier::failureLine("dump failed\nmysqldump: not found\nstack trace"),
        );
    }

    public function testTheFailureLineCapsALongReason(): void
    {
        $line = RestoreNotifier::failureLine(str_repeat('x', 500));

        self::assertSame(200, mb_strlen($line));
        self::assertStringEndsWith('…', $line);
    }

    public function testTheFailureLineOfAnEmptyDetailIsEmpty(): void
    {
        self::assertSame('', RestoreNotifier::failureLine("  \n  "));
    }

    /**
     * Points the queue at a directory of this case's own, and the facade at a fixture database.
     *
     * The announcement writes to {@see DeferredNotificationQueue} rather than to the emit seam
     * (HIL-771), so what a case reads back is the file - which is also the only way to see what
     * the restore actually left behind for the library to send.
     *
     * @param array<string, int> $identities User id keyed by "type:identifier" in the restored database
     */
    private function arrange(array $identities = []): void
    {
        putenv('BACKUP_DIR=' . $this->queueDirectory);
        Hilos::$env = new EnvAccessor();
        Hilos::$db = new RestoreNotifierTestDbContext($identities);
        RestoreNotifierTestHilos::initBrowser();
    }

    /**
     * @return list<NotificationDraft> Drafts the announcement left for the library, in order
     */
    private function queued(): array
    {
        return DeferredNotificationQueue::drain();
    }

    /**
     * Announces one successful run of the fixture backup.
     *
     * @param list<SessionIdentityRef> $initiatorIdentities Identities photographed before the swap
     */
    private function notifySuccess(array $initiatorIdentities): void
    {
        new RestoreNotifier()->notifyOutcome(
            self::BACKUP_ID,
            BackupScope::FULL,
            success: true,
            failureDetail: '',
            startedAt: self::STARTED_AT,
            durationSeconds: 93,
            rehydrateComplete: true,
            initiatorIdentities: $initiatorIdentities,
        );
    }

    /**
     * @return list<int> Recipient user ids, in the order they were queued
     */
    private function recipients(): array
    {
        return array_map(static fn(NotificationDraft $draft): int => $draft->userId, $this->queued());
    }
}

/**
 * Project facade fixture naming two administrators.
 */
final class RestoreNotifierTestHilos extends Hilos
{
    protected const string ADMIN_AUDIENCE = RestoreNotifierTestAudience::class;

    /**
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new RestoreNotifierTestDbContext();
    }
}

/**
 * Project facade fixture that never declared who administers it.
 */
final class RestoreNotifierSilentTestHilos extends Hilos
{
    /**
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new RestoreNotifierTestDbContext();
    }
}

/**
 * Audience of the restored database.
 */
final class RestoreNotifierTestAudience extends AdminAudience
{
    /**
     * @return list<int> Fixture admin user ids
     */
    protected static function userIds(): array
    {
        return [7, 12];
    }
}

/**
 * DB context answering identity lookups from a fixture map.
 */
final class RestoreNotifierTestDbContext extends DbContext
{
    /**
     * @param array<string, int> $userIdsByIdentity User id keyed by "type:identifier"
     */
    public function __construct(private readonly array $userIdsByIdentity = [])
    {
        parent::__construct();
    }

    /**
     * No-op DB configuration for the fixture.
     */
    public function configure(): void
    {
    }

    /**
     * Answers any collection name with the identities stand-in: it is the only one asked for.
     *
     * @param string $name Collection name
     * @return object Identities collection stand-in
     */
    public function __get(string $name)
    {
        return new class ($this->userIdsByIdentity) {
            /**
             * @param array<string, int> $userIdsByIdentity User id keyed by "type:identifier"
             */
            public function __construct(private readonly array $userIdsByIdentity)
            {
            }

            /**
             * @param string $type Identity type
             * @param string $identifier Normalized identifier
             * @return ?object Identity stand-in carrying the owning user id, or null when unknown
             */
            public function findByIdentity(string $type, string $identifier): ?object
            {
                $userId = $this->userIdsByIdentity["{$type}:{$identifier}"] ?? null;
                if ($userId === null) {
                    return null;
                }

                return new class ($userId) {
                    /**
                     * @param int $userId Owning user id
                     */
                    public function __construct(public readonly int $userId)
                    {
                    }
                };
            }
        };
    }
}
