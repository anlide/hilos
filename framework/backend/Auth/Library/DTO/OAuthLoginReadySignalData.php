<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * OAuth agent → users library: the provider answered, now resolve the account.
 *
 * The OAuth agent pipelines network round-trips and must not own the user set; the library
 * owns the set and must not sit on a socket waiting for a provider. So the exchange ends
 * with plain facts about who the provider says this is, and the library turns them into an
 * account and finishes the login with {@see AuthSessionGrantSignalData}
 * ({@see AbstractUsersLibraryAgent}).
 */
final class OAuthLoginReadySignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param string $provider Provider key the login went through
     * @param string $subject Provider's own id for this person, stable across their renames
     * @param ?string $email Address the provider reports, or null when it reports none
     * @param string $displayName Name to give a freshly created account
     * @param string $acceptKey Accept key of the connection that started the login
     * @param string $sessionToken Session token of that browser
     * @param ?string $requestId Request id of the action that started it, or null when untracked
     * @param ?string $action Action name to answer, or null when nothing is waiting on an answer
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $subject,
        public readonly ?string $email,
        public readonly string $displayName,
        public readonly string $acceptKey,
        public readonly string $sessionToken,
        public readonly ?string $requestId = null,
        public readonly ?string $action = null,
    ) {
    }

    /**
     * Convert DTO to array for transport.
     *
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'subject' => $this->subject,
            'email' => $this->email,
            'displayName' => $this->displayName,
            'acceptKey' => $this->acceptKey,
            'sessionToken' => $this->sessionToken,
            'requestId' => $this->requestId,
            'action' => $this->action,
        ];
    }

    /**
     * Create DTO from array.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload names no provider, no subject, or no connection to answer
     */
    public static function fromArray(array $data): static
    {
        return new static(
            provider: self::requireString($data, 'provider'),
            subject: self::requireString($data, 'subject'),
            email: self::optionalString($data, 'email'),
            displayName: self::requireString($data, 'displayName'),
            acceptKey: self::requireString($data, 'acceptKey'),
            sessionToken: self::requireString($data, 'sessionToken'),
            requestId: self::optionalString($data, 'requestId'),
            action: self::optionalString($data, 'action'),
        );
    }
}
