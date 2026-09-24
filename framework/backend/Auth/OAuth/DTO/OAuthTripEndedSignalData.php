<?php

declare(strict_types=1);

namespace Hilos\Auth\OAuth\DTO;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\BaseDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * OAuth agent or users library → session holder: a provider sign-in ended without a sign-in
 * (HIL-1044).
 *
 * The payload of {@see HilosSignalConstants::HILOS_OAUTH_TRIP_ENDED}, and the body of the
 * {@see OAuthResultSignalData} the holder then sends the tab ({@see AbstractSessionsLibraryAgent}).
 * It says which trip and how it ended; to whom it goes is the holder's to decide, because the tab
 * may be on another connection by now.
 *
 * The accept key is the one the trip was started from. The holder uses it only when it has no
 * record of the trip at all - an order it did not expect must end in a delivered outcome, not in
 * a spinner nobody answers.
 */
final class OAuthTripEndedSignalData extends BaseDTO implements SignalDataInterface
{
    public const string tripKeyHash = 'tripKeyHash';
    public const string acceptKey = 'acceptKey';
    public const string provider = 'provider';
    public const string reason = 'reason';
    public const string email = 'email';
    public const string linkToken = 'linkToken';

    /**
     * @param string $tripKeyHash Hash of the key the tab minted
     * @param string $acceptKey Accept key of the connection that sent the callback
     * @param string $provider Provider key the sign-in went through
     * @param string $reason How it ended, one of the {@see OAuthResultSignalData} reasons
     * @param ?string $email Colliding address to pre-fill, on the re-authentication ending alone
     * @param ?string $linkToken Signed link capability to redeem, on the re-authentication ending alone
     */
    public function __construct(
        public readonly string $tripKeyHash,
        public readonly string $acceptKey,
        public readonly string $provider,
        public readonly string $reason,
        public readonly ?string $email = null,
        public readonly ?string $linkToken = null,
    ) {
    }

    /**
     * @return array<string, ?string> DTO payload for transport
     */
    public function toArray(): array
    {
        return [
            self::tripKeyHash => $this->tripKeyHash,
            self::acceptKey => $this->acceptKey,
            self::provider => $this->provider,
            self::reason => $this->reason,
            self::email => $this->email,
            self::linkToken => $this->linkToken,
        ];
    }

    /**
     * The reason is required although a failure is the common case: a reason lost in transit
     * read as a failure would tell a tab its accomplished link went wrong.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload names no trip, connection, provider or reason
     */
    public static function fromArray(array $data): static
    {
        return new static(
            tripKeyHash: self::requireString($data, self::tripKeyHash),
            acceptKey: self::requireString($data, self::acceptKey),
            provider: self::requireString($data, self::provider),
            reason: self::requireString($data, self::reason),
            email: self::optionalString($data, self::email),
            linkToken: self::optionalString($data, self::linkToken),
        );
    }
}
