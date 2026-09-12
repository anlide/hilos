<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Session;

use Hilos\Auth\Session\DeferredSessionCarryoverBatch;
use Hilos\Auth\Session\DeferredSessionCarryoverQueue;
use Hilos\Auth\Session\SessionCarryover;
use Hilos\Auth\Session\SessionIdentityRef;
use Hilos\Environment\EnvAccessor;
use Hilos\Fs\FsPath;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the logins a restore leaves for the library that owns them (HIL-771, HIL-846).
 *
 * The queue is the whole of what stands between a restored node and everybody being signed out:
 * the sessions table has an owner now, and the restore runs with that owner stopped by the freeze.
 * What is pinned here is the round trip - a login has to come back with its lifetime and its
 * identity pairs intact, or it is re-created for the wrong person or with the wrong expiry - and
 * the batch that stands for the file while its owner has not answered: it is handed out again
 * until a receipt names it, and a receipt naming any other batch removes nothing.
 */
final class DeferredSessionCarryoverQueueTest extends TestCase
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
        $this->directory = sys_get_temp_dir() . '/hilos-deferred-sessions-' . getmypid() . '-' . uniqid();
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

    public function testALoginComesBackWholeAndInOrder(): void
    {
        DeferredSessionCarryoverQueue::defer([$this->carryover('aaa'), $this->carryover('bbb')]);

        $queued = $this->take()->sessions;

        self::assertSame(['aaa', 'bbb'], array_map(static fn(SessionCarryover $c): string => $c->token, $queued));
        self::assertSame('2026-08-01 09:15:00', $queued[0]->createdAt);
        self::assertSame('2036-09-01 09:15:00', $queued[0]->expiresAt);
        self::assertCount(1, $queued[0]->identities);
        self::assertSame('password', $queued[0]->identities[0]->type);
        self::assertSame('ann@example.test', $queued[0]->identities[0]->identifier);
    }

    /**
     * An open-ended session is not the same as one that expires now, and the difference is a
     * person staying signed in or being thrown out by the restore that was meant to save them.
     */
    public function testAnOpenEndedLoginKeepsItsAbsentExpiry(): void
    {
        DeferredSessionCarryoverQueue::defer([
            new SessionCarryover('ccc', '2026-08-01 09:15:00', null, [new SessionIdentityRef('password', 'a@b.test')]),
        ]);

        self::assertNull($this->take()->sessions[0]->expiresAt);
    }

    /**
     * What the count is for: the restore reports it to its master, which holds the lift for those
     * logins. Zero on a snapshot that queued nothing, so a debt nobody can pay is never taken on
     * and no lift is held for it (HIL-771).
     */
    public function testTheQueueSaysHowManyLoginsItActuallyTook(): void
    {
        self::assertSame(2, DeferredSessionCarryoverQueue::defer([$this->carryover('aaa'), $this->carryover('bbb')]));
        self::assertSame(0, DeferredSessionCarryoverQueue::defer([]));
    }

    public function testAnEmptyQueueHasNoBatch(): void
    {
        self::assertNull(DeferredSessionCarryoverQueue::take());
    }

    /**
     * Taking is not receiving: until the owner says it has the batch, the logins in it are still
     * owed, and a holder that asks again - on its next tick, or after a restart - is handed the
     * same batch under the same id.
     */
    public function testATakenBatchIsHandedOutAgainUntilItIsReleased(): void
    {
        DeferredSessionCarryoverQueue::defer([$this->carryover('aaa')]);

        $first = $this->take();
        $second = $this->take();

        self::assertSame($first->batch, $second->batch);
        self::assertSame(['aaa'], $this->tokens($second));
    }

    public function testAReleasedBatchIsGone(): void
    {
        DeferredSessionCarryoverQueue::defer([$this->carryover('aaa')]);

        DeferredSessionCarryoverQueue::release($this->take()->batch);

        self::assertNull(
            DeferredSessionCarryoverQueue::take(),
            'A restore hands its logins over once, not on every tick of the holder',
        );
        self::assertSame([], glob($this->directory . '/*') ?: [], 'The released batch leaves no file behind');
    }

    /**
     * The reason a batch has an id at all: a receipt for an earlier batch that arrived late would
     * otherwise remove the batch in flight now, unread - the silent loss the hand-over exists to end.
     */
    public function testALateReceiptForAnEarlierBatchRemovesNothing(): void
    {
        DeferredSessionCarryoverQueue::defer([$this->carryover('aaa')]);
        $earlier = $this->take()->batch;
        DeferredSessionCarryoverQueue::release($earlier);
        DeferredSessionCarryoverQueue::defer([$this->carryover('bbb')]);
        $later = $this->take();

        DeferredSessionCarryoverQueue::release($earlier);

        $offered = $this->take();
        self::assertNotSame($earlier, $later->batch);
        self::assertSame($later->batch, $offered->batch, 'A receipt naming another batch leaves this one in flight');
        self::assertSame(['bbb'], $this->tokens($offered));
    }

    public function testWhatArrivesWhileABatchIsInFlightWaitsBehindIt(): void
    {
        DeferredSessionCarryoverQueue::defer([$this->carryover('aaa')]);
        $first = $this->take();
        DeferredSessionCarryoverQueue::defer([$this->carryover('bbb')]);

        self::assertSame(['aaa'], $this->tokens($this->take()), 'The batch in flight is handed out again, alone');

        DeferredSessionCarryoverQueue::release($first->batch);
        $next = $this->take();

        self::assertNotSame($first->batch, $next->batch);
        self::assertSame(['bbb'], $this->tokens($next), 'The fresh logins become the next batch once the first is released');
    }

    public function testALineThatIsNotASessionIsDroppedAndTheRestSurvive(): void
    {
        DeferredSessionCarryoverQueue::defer([$this->carryover('aaa')]);
        FsPath::append($this->path(), "{\"token\":42}\nnot json at all\n");
        DeferredSessionCarryoverQueue::defer([$this->carryover('bbb')]);

        self::assertSame(['aaa', 'bbb'], $this->tokens($this->take()), 'One unreadable line owes the logins behind it nothing');
    }

    public function testAnInstallationWithNoBackupDirectoryQueuesNothing(): void
    {
        putenv('BACKUP_DIR=');
        Hilos::$env = new EnvAccessor();

        DeferredSessionCarryoverQueue::defer([$this->carryover('aaa')]);

        self::assertNull(DeferredSessionCarryoverQueue::take());
        self::assertFalse(is_file($this->path()), 'Nothing is written where no backup directory is named');
    }

    /**
     * @return DeferredSessionCarryoverBatch The batch the queue hands out now, asserted to exist
     */
    private function take(): DeferredSessionCarryoverBatch
    {
        $batch = DeferredSessionCarryoverQueue::take();
        self::assertNotNull($batch, 'The queue holds a batch to take');

        return $batch;
    }

    /**
     * @param DeferredSessionCarryoverBatch $batch Batch to read
     * @return list<string> Tokens of its sessions, in batch order
     */
    private function tokens(DeferredSessionCarryoverBatch $batch): array
    {
        return array_map(static fn(SessionCarryover $c): string => $c->token, $batch->sessions);
    }

    /**
     * @param string $token Session token of the fixture login
     * @return SessionCarryover One captured session with every field filled
     */
    private function carryover(string $token): SessionCarryover
    {
        return new SessionCarryover(
            token: $token,
            createdAt: '2026-08-01 09:15:00',
            expiresAt: '2036-09-01 09:15:00',
            identities: [new SessionIdentityRef('password', 'ann@example.test')],
        );
    }

    /**
     * @return string Absolute path of this case's queue file
     */
    private function path(): string
    {
        return $this->directory . '/' . DeferredSessionCarryoverQueue::FILE_NAME;
    }
}
