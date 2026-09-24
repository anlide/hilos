<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Auth\Code\AuthCodeAgent;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * Code agent → the session holder: a code went out to a free number, and this session now waits
 * on registering it (HIL-1044).
 *
 * The holder writes what a browser waits on ({@see AbstractSessionsLibraryAgent}); the code agent
 * ({@see AuthCodeAgent}) is the one that learns a code really went out, so it says so here instead
 * of writing the session row under a claim it borrowed. The session is named by its token, as
 * {@see AuthRegistrationWaitMovedSignalData} names it: the agent is handed the token with the
 * order, and there is no lookup of a session by anything shorter.
 */
final class AuthRegistrationWaitHeldSignalData extends BaseDTO implements SignalDataInterface
{
    public const string sessionToken = 'sessionToken';
    public const string identifier = 'identifier';

    /**
     * @param string $sessionToken Session token of the browser that asked for the code
     * @param string $identifier Normalized number the code went to
     */
    public function __construct(
        public readonly string $sessionToken,
        public readonly string $identifier,
    ) {
    }

    /**
     * @return array<string, string> DTO payload for transport
     */
    public function toArray(): array
    {
        return [
            self::sessionToken => $this->sessionToken,
            self::identifier => $this->identifier,
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload names no session or no number
     */
    public static function fromArray(array $data): static
    {
        return new static(
            sessionToken: self::requireString($data, self::sessionToken),
            identifier: self::requireString($data, self::identifier),
        );
    }
}
