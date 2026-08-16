<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\DTO\Main;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Pages\DTO\ChatActionPayloadDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Push\DTO\PushSubscribeActionDTO;

/**
 * PasskeyRegisterConfirmActionDTO - DTO for submitting a WebAuthn registration (HIL-284).
 *
 * Authenticated submit relayed by the client after navigator.credentials.create():
 * the signed challenge token minted at options, the base64url attestationObject and
 * clientDataJSON returned by the authenticator, and the authenticator's reported
 * transports (stored to seed a credential's allowCredentials on later logins). The
 * binary fields stay base64url on the wire and are decoded by the handler.
 *
 * The `userAgent` is the enrolling device's, mirroring {@see PushSubscribeActionDTO}:
 * it names the key in the profile (HIL-418) and nothing else — it authorizes
 * nothing, so a client that sends none simply gets an unnamed key.
 */
final class PasskeyRegisterConfirmActionDTO extends ChatActionPayloadDTO
{
    /**
     * Creates a passkey registration confirmation DTO.
     *
     * @param string $signedChallenge Signed challenge token from the options step
     * @param string $attestationObject base64url CBOR attestation object
     * @param string $clientDataJson base64url clientDataJSON bytes
     * @param list<string> $transports Reported authenticator transports (e.g. ['internal', 'hybrid'])
     * @param ?string $userAgent Enrolling device user agent, or null when the client sent none
     */
    public function __construct(
        public readonly string $signedChallenge,
        public readonly string $attestationObject,
        public readonly string $clientDataJson,
        public readonly array $transports,
        public readonly ?string $userAgent,
    ) {
    }

    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return ChatSignalConstants::PASSKEY_REGISTER_CONFIRM;
    }

    /**
     * Create from array.
     *
     * @param array<string, mixed> $data Payload data
     * @return static Confirm DTO instance
     * @throws InvalidFormatException When a field the action needs is absent or not a string
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
            signedChallenge: self::requireString($data, 'signedChallenge'),
            attestationObject: self::requireString($data, 'attestationObject'),
            clientDataJson: self::requireString($data, 'clientDataJson'),
            transports: $transports,
            userAgent: is_string($userAgent) && $userAgent !== '' ? $userAgent : null,
        );
    }

    /**
     * Convert to array for transport.
     *
     * @return array{signedChallenge: string, attestationObject: string, clientDataJson: string,
     *     transports: list<string>, userAgent: ?string} Confirm payload
     */
    public function toArray(): array
    {
        return [
            'signedChallenge' => $this->signedChallenge,
            'attestationObject' => $this->attestationObject,
            'clientDataJson' => $this->clientDataJson,
            'transports' => $this->transports,
            'userAgent' => $this->userAgent,
        ];
    }
}
