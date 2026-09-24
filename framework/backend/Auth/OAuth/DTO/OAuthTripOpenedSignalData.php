<?php

declare(strict_types=1);

namespace Hilos\Auth\OAuth\DTO;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\BaseDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Runtime\State\Item\HilosOAuthTrip;
use Hilos\Runtime\State\Item\OAuthPendingLogin;

/**
 * Users library → session holder: a tab is now waiting on a provider sign-in (HIL-1044).
 *
 * The payload of {@see HilosSignalConstants::HILOS_OAUTH_TRIP_OPENED}: everything the holder
 * needs to open the trip ({@see AbstractSessionsLibraryAgent}) and to judge a later presentation
 * of its key - the session the callback came in under, the connection the outcome is owed to,
 * and the mode that names which agents the sign-in goes through.
 *
 * Both secrets arrive hashed. The key is for the tab to present, the cookie token for the tab to
 * carry; a runtime row that held either in the clear would be one more place to steal it from.
 */
final class OAuthTripOpenedSignalData extends BaseDTO implements SignalDataInterface
{
    public const string tripKeyHash = 'tripKeyHash';
    public const string sessionTokenHash = 'sessionTokenHash';
    public const string acceptKey = 'acceptKey';
    public const string mode = 'mode';
    public const string provider = 'provider';

    /**
     * @param string $tripKeyHash Hash of the key the tab minted ({@see HilosOAuthTrip::hashKey()})
     * @param string $sessionTokenHash Hash of the session cookie token the callback came in under
     * @param string $acceptKey Accept key of the connection that sent the callback
     * @param string $mode Flow mode ({@see OAuthPendingLogin::MODE_LOGIN} or {@see OAuthPendingLogin::MODE_LINK})
     * @param string $provider Provider key, e.g. 'oauth:github'
     */
    public function __construct(
        public readonly string $tripKeyHash,
        public readonly string $sessionTokenHash,
        public readonly string $acceptKey,
        public readonly string $mode,
        public readonly string $provider,
    ) {
    }

    /**
     * @return array<string, string> DTO payload for transport
     */
    public function toArray(): array
    {
        return [
            self::tripKeyHash => $this->tripKeyHash,
            self::sessionTokenHash => $this->sessionTokenHash,
            self::acceptKey => $this->acceptKey,
            self::mode => $this->mode,
            self::provider => $this->provider,
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload lost a field the trip is opened with
     */
    public static function fromArray(array $data): static
    {
        return new static(
            tripKeyHash: self::requireString($data, self::tripKeyHash),
            sessionTokenHash: self::requireString($data, self::sessionTokenHash),
            acceptKey: self::requireString($data, self::acceptKey),
            mode: self::requireString($data, self::mode),
            provider: self::requireString($data, self::provider),
        );
    }
}
