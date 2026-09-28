<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Auth\Registration\RegistrationConsent;
use Hilos\Core\Exception\ValidationException;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * CompleteRegistrationPasskeyActionDTO - DTO for creating an account on the passkey the device just made (HIL-1104).
 *
 * The second submit of the guest's passkey door, relayed after navigator.credentials.create():
 * the identifier the options were asked for, the signed challenge they came with, and the
 * authenticator's answer in the shape the profile's enrollment sends it
 * ({@see PasskeyRegisterConfirmActionDTO}).
 *
 * The identifier must match the road sealed into the challenge: present on the road with a
 * code, absent on the road without one. A present address names this browser's proven hold;
 * it cannot land an account on somebody else's proof.
 */
final class CompleteRegistrationPasskeyActionDTO extends ActionPayloadDTO
{
    /**
     * Creates a registration passkey completion DTO.
     *
     * @param ?string $identifier Address as typed, or null on the road without an address
     * @param string $signedChallenge Signed challenge token from the options step
     * @param string $attestationObject base64url CBOR attestation object
     * @param string $clientDataJson base64url clientDataJSON bytes
     * @param list<string> $transports Reported authenticator transports (e.g. ['internal', 'hybrid'])
     * @param ?string $userAgent Registering device user agent, or null when the client sent none
     * @param ?array<string, string> $acceptedRevisions Accepted document-to-revision boundary map, or null for a repeat
     */
    public function __construct(
        public readonly ?string $identifier,
        public readonly string $signedChallenge,
        public readonly string $attestationObject,
        public readonly string $clientDataJson,
        public readonly array $transports,
        public readonly ?string $userAgent,
        public readonly ?array $acceptedRevisions = null,
    ) {
    }

    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_COMPLETE_REGISTRATION_PASSKEY;
    }

    /**
     * Create from array.
     *
     * @param array<string, mixed> $data Payload data
     * @return static Registration passkey completion DTO instance
     * @throws InvalidFormatException When a required string is missing or a present identifier has the wrong type
     * @throws ValidationException When the accepted revision map has an unknown document or invalid revision id
     */
    public static function fromArray(array $data): static
    {
        $reported = $data['transports'] ?? null;
        $transports = [];
        foreach (is_array($reported) ? $reported : [] as $transport) {
            if (is_string($transport) && $transport !== '') {
                $transports[] = $transport;
            }
        }

        $userAgent = $data['userAgent'] ?? null;

        return new static(
            identifier: self::optionalString($data, 'identifier'),
            signedChallenge: self::requireString($data, 'signedChallenge'),
            attestationObject: self::requireString($data, 'attestationObject'),
            clientDataJson: self::requireString($data, 'clientDataJson'),
            transports: $transports,
            userAgent: is_string($userAgent) && $userAgent !== '' ? $userAgent : null,
            acceptedRevisions: RegistrationConsent::readPayload(self::optionalArray($data, RegistrationConsent::PAYLOAD_KEY)),
        );
    }

    /**
     * Convert to array for transport.
     *
     * @return array<string, mixed> Action payload including the optional accepted revisions
     */
    public function toArray(): array
    {
        return [
            'identifier' => $this->identifier,
            'signedChallenge' => $this->signedChallenge,
            'attestationObject' => $this->attestationObject,
            'clientDataJson' => $this->clientDataJson,
            'transports' => $this->transports,
            'userAgent' => $this->userAgent,
            RegistrationConsent::PAYLOAD_KEY => $this->acceptedRevisions,
        ];
    }
}
