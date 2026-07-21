<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\DTO\Main;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Pages\DTO\ChatActionPayloadDTO;

/**
 * PasskeyRegisterConfirmActionDTO - DTO for submitting a WebAuthn registration (HIL-284).
 *
 * Authenticated submit relayed by the client after navigator.credentials.create():
 * the signed challenge token minted at options, the base64url attestationObject and
 * clientDataJSON returned by the authenticator, and the authenticator's reported
 * transports (stored to seed a credential's allowCredentials on later logins). The
 * binary fields stay base64url on the wire and are decoded by the handler.
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
     */
    public function __construct(
        public readonly string $signedChallenge,
        public readonly string $attestationObject,
        public readonly string $clientDataJson,
        public readonly array $transports,
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
     */
    public static function fromArray(array $data): static
    {
        $signedChallenge = $data['signedChallenge'] ?? null;
        $attestationObject = $data['attestationObject'] ?? null;
        $clientDataJson = $data['clientDataJson'] ?? null;
        $transports = $data['transports'] ?? null;

        return new static(
            signedChallenge: is_string($signedChallenge) ? $signedChallenge : '',
            attestationObject: is_string($attestationObject) ? $attestationObject : '',
            clientDataJson: is_string($clientDataJson) ? $clientDataJson : '',
            transports: is_array($transports)
                ? array_values(array_filter(array_map(
                    static fn(mixed $transport): string => is_string($transport) ? $transport : '',
                    $transports,
                ), static fn(string $transport): bool => $transport !== ''))
                : [],
        );
    }

    /**
     * Convert to array for transport.
     *
     * @return array{signedChallenge: string, attestationObject: string, clientDataJson: string, transports: list<string>} Confirm payload
     */
    public function toArray(): array
    {
        return [
            'signedChallenge' => $this->signedChallenge,
            'attestationObject' => $this->attestationObject,
            'clientDataJson' => $this->clientDataJson,
            'transports' => $this->transports,
        ];
    }
}
