<?php

declare(strict_types=1);

namespace Hilos\Auth\Session;

use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\Library\DTO\AuthPasswordChangedSignalData;
use Hilos\Auth\Library\DTO\AuthRecoveryGrantedSignalData;
use Hilos\Auth\Library\DTO\AuthRegistrationAbandonedSignalData;
use Hilos\Auth\Library\DTO\AuthRegistrationLandedSignalData;
use Hilos\Auth\Library\DTO\AuthSessionGrantSignalData;
use Hilos\Auth\Session\Exception\SessionTokenExhaustedException;
use Hilos\Constants\HilosSignalConstants;
use Hilos\HilosException;
use Random\RandomException;

/**
 * The agent of a project that holds sessions, named as a contract (HIL-622).
 *
 * Sessions, live connections and the parked sign-in surfaces belong to whoever accepted the
 * handshake, and a handshake is routed to exactly one agent - so the sign-in commands, which
 * live in {@see AbstractUsersLibraryAgent}, cannot take them. A command therefore ends in a
 * frame addressed to this holder: bind this session to this user, converge these tabs, drop
 * this wait. What the holder is asked to do is exactly this list.
 *
 * Implemented by {@see HilosSessionHost}, the trait a project's agent already mixes in, so a
 * project that hosts sessions gains the contract by declaring it rather than by writing
 * anything. The framework finds the holder by this interface instead of asking a project to
 * name it twice: an agent that mixes the trait in IS the holder, and a second declaration
 * beside that fact could only ever disagree with it.
 */
interface HilosSessionHostInterface
{
    /**
     * The frames a holder is addressed by, ready to be spread into an agent's AGENT_SIGNALS.
     *
     * Routing needs the name and the DTO beside it, and both are the framework's answer
     * rather than the project's: a project that re-listed them could only ever list them
     * differently. Spreading this map is therefore the whole of what hosting the library's
     * frames asks a project to write, and {@see HilosSessionHost::handleSessionHostFrame()}
     * is what the one branch under it calls.
     *
     * @var array<string, class-string> Frame payload DTO by signal name
     */
    public const array SESSION_HOST_SIGNALS = [
        HilosSignalConstants::HILOS_AUTH_SESSION_GRANT => AuthSessionGrantSignalData::class,
        HilosSignalConstants::HILOS_AUTH_REGISTRATION_LANDED => AuthRegistrationLandedSignalData::class,
        HilosSignalConstants::HILOS_AUTH_RECOVERY_GRANTED => AuthRecoveryGrantedSignalData::class,
        HilosSignalConstants::HILOS_AUTH_PASSWORD_CHANGED => AuthPasswordChangedSignalData::class,
        HilosSignalConstants::HILOS_AUTH_REGISTRATION_ABANDONED => AuthRegistrationAbandonedSignalData::class,
    ];

    /**
     * Binds a live session to a user, rotating its token and re-pointing its connections.
     *
     * @param string $sessionToken Session cookie token to authenticate
     * @param int $userId Durable user id to bind the session to
     * @param ?string $initiatorAcceptKey Accept key of the connection that signed in, or null when there is none
     * @throws HilosException On database or runtime failure
     * @throws RandomException When the platform's secure random source refuses a mint
     * @throws SessionTokenExhaustedException When three minted tokens in a row were already taken
     */
    public function authenticateSession(string $sessionToken, int $userId, ?string $initiatorAcceptKey): void;

    /**
     * Reverts every other session of one user to anonymous, keeping the named one signed in.
     *
     * @param int $userId User whose other sessions are dropped
     * @param string $keepSessionToken Session token that stays signed in
     * @throws HilosException On database or runtime failure
     */
    public function deauthenticateOtherSessions(int $userId, string $keepSessionToken): void;

    /**
     * Shows an outcome mark on every live connection of one session.
     *
     * @param string $sessionToken Session cookie token whose sockets are marked
     * @param string $ack Ack kind to show (a {@see SessionAck} value)
     * @throws HilosException On database or runtime failure
     */
    public function markSessionAck(string $sessionToken, string $ack): void;

    /**
     * Moves the other tabs of one session onto the recovery step its code just unlocked.
     *
     * @param string $identifier Normalized address being recovered
     * @param string $sessionToken Session token that proved the code
     * @param string $initiatorAcceptKey Accept key that submitted it, answered by its own reply
     * @throws HilosException On runtime failure
     */
    public function grantRecoveryToSession(string $identifier, string $sessionToken, string $initiatorAcceptKey): void;

    /**
     * Settles every surface waiting on a registration that has just landed.
     *
     * @param string $identifier Normalized identifier that was confirmed
     * @param int $userId User the confirmation created
     * @param string $initiatorAcceptKey Accept key that submitted the proof
     * @param string $winnerSessionToken Session token of the browser whose registration this was
     * @param list<string> $losingSessionTokens Session tokens whose hold on the identifier is dropped
     * @throws HilosException On runtime, database, or session failure
     * @throws RandomException When the platform CSPRNG cannot mint a rotated session token
     */
    public function convergeRegistration(
        string $identifier,
        int $userId,
        string $initiatorAcceptKey,
        string $winnerSessionToken,
        array $losingSessionTokens,
    ): void;

    /**
     * Settles every surface waiting on a password recovery that has just completed.
     *
     * @param string $identifier Normalized address whose password was saved
     * @param string $sessionToken Session token that saved it
     * @param string $initiatorAcceptKey Accept key that submitted the password
     * @throws HilosException On runtime failure
     */
    public function convergeRecovery(string $identifier, string $sessionToken, string $initiatorAcceptKey): void;

    /**
     * Drops one session's pending registration and the waits standing on it.
     *
     * @param string $sessionToken Session token abandoning its registration
     * @param string $initiatorAcceptKey Accept key that abandoned it, answered by its own reply
     * @throws HilosException On runtime or database failure
     */
    public function abandonRegistration(string $sessionToken, string $initiatorAcceptKey): void;
}
