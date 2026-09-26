<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Files;

use Hilos\Database\Entity\Item\Session as EntitySession;
use Hilos\Database\Object\Item\Session as ObjectSession;
use Hilos\Database\View\Item\Session;
use Hilos\Files\Download\FileAccess;
use Hilos\Files\FileVisibility;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a file's visibility answers each kind of viewer (HIL-138).
 *
 * The whole matrix: three visibilities against the viewers a request can present - none, a
 * guest session, a signed-in one, an expired one, the owner, somebody else. An expired session
 * still names its user on the row and must not count as signed in, the way the handshake does
 * not count it.
 */
final class FileAccessTest extends TestCase
{
    /** Owner of the fixture file */
    private const int OWNER_ID = 7;

    /** A signed-in user who does not own the fixture file */
    private const int STRANGER_ID = 8;

    /** The present moment every case is judged at */
    private const string NOW = '2026-09-26 12:00:00';

    /**
     * @return array<string, array{?array<string, mixed>, FileVisibility, FileAccess}> Session row, visibility, verdict
     */
    public static function viewers(): array
    {
        $guest = [EntitySession::user_id => null, EntitySession::expires_at => '2027-01-01 00:00:00'];
        $owner = [EntitySession::user_id => self::OWNER_ID, EntitySession::expires_at => '2027-01-01 00:00:00'];
        $ownerForever = [EntitySession::user_id => self::OWNER_ID, EntitySession::expires_at => null];
        $ownerExpired = [EntitySession::user_id => self::OWNER_ID, EntitySession::expires_at => '2026-09-26 11:59:59'];
        $stranger = [EntitySession::user_id => self::STRANGER_ID, EntitySession::expires_at => '2027-01-01 00:00:00'];

        return [
            'public, no session' => [null, FileVisibility::PUBLIC, FileAccess::ALLOW],
            'public, guest' => [$guest, FileVisibility::PUBLIC, FileAccess::ALLOW],
            'public, expired' => [$ownerExpired, FileVisibility::PUBLIC, FileAccess::ALLOW],
            'public, stranger' => [$stranger, FileVisibility::PUBLIC, FileAccess::ALLOW],
            'authenticated, no session' => [null, FileVisibility::AUTHENTICATED, FileAccess::SIGN_IN],
            'authenticated, guest' => [$guest, FileVisibility::AUTHENTICATED, FileAccess::SIGN_IN],
            'authenticated, expired' => [$ownerExpired, FileVisibility::AUTHENTICATED, FileAccess::SIGN_IN],
            'authenticated, stranger' => [$stranger, FileVisibility::AUTHENTICATED, FileAccess::ALLOW],
            'authenticated, open-ended' => [$ownerForever, FileVisibility::AUTHENTICATED, FileAccess::ALLOW],
            'owner, no session' => [null, FileVisibility::OWNER, FileAccess::SIGN_IN],
            'owner, guest' => [$guest, FileVisibility::OWNER, FileAccess::SIGN_IN],
            'owner, expired owner' => [$ownerExpired, FileVisibility::OWNER, FileAccess::SIGN_IN],
            'owner, stranger' => [$stranger, FileVisibility::OWNER, FileAccess::FORBIDDEN],
            'owner, owner' => [$owner, FileVisibility::OWNER, FileAccess::ALLOW],
            'owner, open-ended owner' => [$ownerForever, FileVisibility::OWNER, FileAccess::ALLOW],
        ];
    }

    /**
     * @param ?array<string, mixed> $sessionRow Columns of the presented session, or null for none
     * @param FileVisibility $visibility Visibility of the file
     * @param FileAccess $expected Verdict the viewer gets
     */
    #[DataProvider('viewers')]
    public function testTheVisibilityAnswersEachViewer(?array $sessionRow, FileVisibility $visibility, FileAccess $expected): void
    {
        $session = $sessionRow === null ? null : new Session(ObjectSession::fromEntity(EntitySession::fromRow(
            [EntitySession::token => str_repeat('a', 32)] + $sessionRow,
        )));

        $this->assertSame(
            $expected,
            FileAccess::judge($visibility, self::OWNER_ID, FileAccess::signedInUserId($session, self::NOW)),
        );
    }

    public function testASessionExpiringThisVerySecondIsNoLongerSignedIn(): void
    {
        $session = new Session(ObjectSession::fromEntity(EntitySession::fromRow([
            EntitySession::token => str_repeat('a', 32),
            EntitySession::user_id => self::OWNER_ID,
            EntitySession::expires_at => self::NOW,
        ])));

        $this->assertNull(FileAccess::signedInUserId($session, self::NOW));
    }
}
