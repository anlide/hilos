<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Session;

use Hilos\Auth\Session\DeferredSessionCarryoverQueue;
use Hilos\Auth\Session\SessionCarryover;
use Hilos\Auth\Session\SessionIdentityRef;
use Hilos\Environment\EnvAccessor;
use Hilos\Fs\FsPath;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the logins a restore leaves for the library that owns them (HIL-771).
 *
 * The queue is the whole of what stands between a restored node and everybody being signed out:
 * the sessions table has an owner now, and the restore runs with that owner stopped by the freeze.
 * What is pinned here is the round trip - a login has to come back with its lifetime and its
 * identity pairs intact, or it is re-created for the wrong person or with the wrong expiry - and
 * the two ways the queue is asked to survive damage: a line that is not a session, and a file a
 * previous drain left behind.
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

    public function testALoginComesBackWholeAndInOrder(): void
    {
        DeferredSessionCarryoverQueue::defer([$this->carryover('aaa'), $this->carryover('bbb')]);

        $queued = DeferredSessionCarryoverQueue::drain();

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

        self::assertNull(DeferredSessionCarryoverQueue::drain()[0]->expiresAt);
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

    public function testADrainedQueueIsEmptyAfterwards(): void
    {
        DeferredSessionCarryoverQueue::defer([$this->carryover('aaa')]);

        DeferredSessionCarryoverQueue::drain();

        self::assertSame(
            [],
            DeferredSessionCarryoverQueue::drain(),
            'A restore hands its logins over once, not on every start the library makes',
        );
    }

    public function testALineThatIsNotASessionIsDroppedAndTheRestSurvive(): void
    {
        DeferredSessionCarryoverQueue::defer([$this->carryover('aaa')]);
        FsPath::append($this->path(), "{\"token\":42}\nnot json at all\n");
        DeferredSessionCarryoverQueue::defer([$this->carryover('bbb')]);

        self::assertSame(
            ['aaa', 'bbb'],
            array_map(
                static fn(SessionCarryover $c): string => $c->token,
                DeferredSessionCarryoverQueue::drain(),
            ),
            'One unreadable line owes the logins behind it nothing',
        );
    }

    public function testAFileLeftByADrainThatDiedIsTakenFirst(): void
    {
        DeferredSessionCarryoverQueue::defer([$this->carryover('aaa')]);
        FsPath::move($this->path(), $this->path() . '.taken');
        DeferredSessionCarryoverQueue::defer([$this->carryover('bbb')]);

        self::assertSame(
            ['aaa', 'bbb'],
            array_map(
                static fn(SessionCarryover $c): string => $c->token,
                DeferredSessionCarryoverQueue::drain(),
            ),
            'The stranded file is read before the one being taken now, so the logins keep their order',
        );
    }

    public function testAnInstallationWithNoBackupDirectoryQueuesNothing(): void
    {
        putenv('BACKUP_DIR=');
        Hilos::$env = new EnvAccessor();

        DeferredSessionCarryoverQueue::defer([$this->carryover('aaa')]);

        self::assertSame([], DeferredSessionCarryoverQueue::drain());
        self::assertFalse(is_file($this->path()), 'Nothing is written where no backup directory is named');
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
