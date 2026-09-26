<?php

declare(strict_types=1);

namespace Hilos\Files\Download;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Database\View\Item\Session;
use Hilos\Files\FileVisibility;
use Hilos\Files\Library\AbstractFilesLibraryAgent;

/**
 * What the visibility of a registry file answers the browser asking for it (HIL-138).
 *
 * The decision is kept out of the library agent so the whole matrix can be read and tested
 * without a daemon or a database; {@see AbstractFilesLibraryAgent} asks it and then asks the
 * project's extension when the answer is not {@see self::ALLOW}.
 */
enum FileAccess
{
    /** The file is served */
    case ALLOW;

    /** Nobody is signed in and the file is not public: 401 */
    case SIGN_IN;

    /** Somebody is signed in and the file is not theirs to see: 403 */
    case FORBIDDEN;

    /**
     * Tells who is signed in behind a session, by the rule the handshake applies.
     *
     * A session with a user and no expiry, or an expiry still ahead, is signed in; an expired
     * one is not, though its row still names the user - the handshake drops it to anonymous
     * ({@see AbstractSessionsLibraryAgent}), and a download does not see further than that.
     * The session is only read here, never downgraded: the download is not the handshake. A
     * guest session is not signed in.
     *
     * @param ?Session $session Session the request presented, or null when it presented none
     * @param string $nowSql The present moment, as SQL datetime
     * @return ?int Id of the signed-in user, or null when nobody is signed in
     */
    public static function signedInUserId(?Session $session, string $nowSql): ?int
    {
        $userId = $session?->userId;
        if ($userId === null) {
            return null;
        }

        $expiresAt = $session->expiresAt;

        return $expiresAt === null || $expiresAt > $nowSql ? $userId : null;
    }

    /**
     * Judges one request by the file's visibility alone.
     *
     * @param FileVisibility $visibility Visibility of the file's row
     * @param int $ownerUserId User the file belongs to
     * @param ?int $viewerUserId Signed-in user asking, or null when nobody is signed in
     * @return self Whether to serve, or which refusal
     */
    public static function judge(FileVisibility $visibility, int $ownerUserId, ?int $viewerUserId): self
    {
        return match ($visibility) {
            FileVisibility::PUBLIC => self::ALLOW,
            FileVisibility::AUTHENTICATED => $viewerUserId !== null ? self::ALLOW : self::SIGN_IN,
            FileVisibility::OWNER => match (true) {
                $viewerUserId === null => self::SIGN_IN,
                $viewerUserId === $ownerUserId => self::ALLOW,
                default => self::FORBIDDEN,
            },
        };
    }
}
