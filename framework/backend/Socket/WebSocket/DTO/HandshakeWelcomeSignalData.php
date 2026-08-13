<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket\DTO;

use Hilos\Auth\Session\SessionRotationTicket;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\ProtectedMode\ProtectedModeStubCopy;

/**
 * HandshakeWelcomeSignalData - Payload of the framework handshake welcome frame.
 *
 * Outbound-only: sent by WebSocketClient as the first frame of every
 * connection, directly behind the 101 upgrade response. Carries the daemon
 * build timestamp (HILOS_BUILD_TIMESTAMP) that the frontend compares on every
 * (re)connect to force a refresh when the deployed build changed, and the
 * protected-mode block the master reads off the runtime row so a connection
 * caught by a cluster freeze learns at handshake that it is locked out — and,
 * with the copy resolved through {@see ProtectedModeStubCopy}, learns it in
 * words, so the maintenance surface is painted before any subscription is
 * attempted and no empty shell flashes.
 *
 * It also names the session cookie (HIL-582). The frontend never reads that cookie —
 * it is HttpOnly — but on a token rotation it has to WRITE the auxiliary one beside it,
 * whose name is derived from it ({@see SessionRotationTicket::cookieName()}). The name is
 * a property of the deployment rather than of the rotation, so it rides the welcome every
 * connection already gets instead of the rotation signal almost none of them ever see.
 */
class HandshakeWelcomeSignalData extends BaseDTO implements SignalDataInterface
{
    // Field name constants
    public const string BUILD = 'build';
    public const string SESSION_COOKIE_NAME = 'sessionCookieName';
    public const string PROTECTED_MODE = 'protectedMode';
    public const string PROTECTED_MODE_ACTIVE = 'active';
    public const string PROTECTED_MODE_OPERATION = 'operation';
    public const string PROTECTED_MODE_TITLE = 'title';
    public const string PROTECTED_MODE_MESSAGE = 'message';
    public const string PROTECTED_MODE_ACCEPTS_PASS = 'acceptsPass';

    /**
     * @param string $build Daemon build timestamp ('dev' when not configured)
     * @param string $sessionCookieName Name of this deployment's session cookie
     * @param bool $protectedModeActive Whether this connection is locked out by an active protected-mode freeze
     * @param ?string $protectedModeOperation Operation the freeze protects; null when no freeze holds
     * @param ?string $protectedModeTitle Heading of the maintenance surface; null when no freeze holds
     * @param ?string $protectedModeMessage Sentence under the heading; null when no freeze holds
     * @param bool $protectedModeAcceptsPass Whether the freeze is in its verification window; false
     *                                       whenever no freeze holds and whenever one holds but takes
     *                                       no code yet. A locked-out connection reads it as "the
     *                                       surface may offer a code field", an admitted verifier -
     *                                       whose $protectedModeActive is false, like everybody's
     *                                       once the mode is over - as "the window is still open"
     */
    public function __construct(
        public readonly string $build,
        public readonly string $sessionCookieName,
        public readonly bool $protectedModeActive = false,
        public readonly ?string $protectedModeOperation = null,
        public readonly ?string $protectedModeTitle = null,
        public readonly ?string $protectedModeMessage = null,
        public readonly bool $protectedModeAcceptsPass = false,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            self::BUILD => $this->build,
            self::SESSION_COOKIE_NAME => $this->sessionCookieName,
            self::PROTECTED_MODE => [
                self::PROTECTED_MODE_ACTIVE => $this->protectedModeActive,
                self::PROTECTED_MODE_OPERATION => $this->protectedModeOperation,
                self::PROTECTED_MODE_TITLE => $this->protectedModeTitle,
                self::PROTECTED_MODE_MESSAGE => $this->protectedModeMessage,
                self::PROTECTED_MODE_ACCEPTS_PASS => $this->protectedModeAcceptsPass,
            ],
        ];
    }

    /**
     * Restores the welcome from its wire payload.
     *
     * The block and both of its flags are required, and a lowered flag is read as
     * the answer it is: a welcome that does not say whether a freeze holds is not
     * a welcome saying none does. The three copy fields are null whenever no
     * freeze holds, so they are read as the optional text they are - and a value
     * of another type is refused rather than quietly dropped, which is what the
     * private reader this replaced used to do.
     *
     * @param array<string, mixed> $data
     * @throws InvalidFormatException When the welcome carries no build, session cookie name or protected-mode block
     */
    public static function fromArray(array $data): static
    {
        $protectedMode = self::requireArray($data, self::PROTECTED_MODE);

        return new static(
            build: self::requireString($data, self::BUILD),
            // Read without a fallback: a frame that does not name the session cookie is a
            // frame the frontend could not rotate on, and an empty name would produce an
            // auxiliary cookie called `_rotate` on every deployment that ever saw one.
            sessionCookieName: self::requireString($data, self::SESSION_COOKIE_NAME),
            protectedModeActive: self::requireBool($protectedMode, self::PROTECTED_MODE_ACTIVE),
            protectedModeOperation: self::optionalString($protectedMode, self::PROTECTED_MODE_OPERATION),
            protectedModeTitle: self::optionalString($protectedMode, self::PROTECTED_MODE_TITLE),
            protectedModeMessage: self::optionalString($protectedMode, self::PROTECTED_MODE_MESSAGE),
            protectedModeAcceptsPass: self::requireBool($protectedMode, self::PROTECTED_MODE_ACCEPTS_PASS),
        );
    }
}
