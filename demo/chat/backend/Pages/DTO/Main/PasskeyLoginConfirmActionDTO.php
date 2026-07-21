<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\DTO\Main;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Pages\DTO\ChatActionPayloadDTO;

/**
 * PasskeyLoginConfirmActionDTO - DTO for submitting a WebAuthn login assertion (HIL-284).
 *
 * Public (anonymous-reachable) submit relayed by the client after
 * navigator.credentials.get(): the signed challenge token from the options step,
 * the base64url credential id the authenticator chose, and the base64url
 * authenticatorData, clientDataJSON and (DER) signature it returned. The binary
 * fields stay base64url on the wire and are decoded by the handler; every failure
 * collapses to one generic message so nothing about the account leaks.
 */
final class PasskeyLoginConfirmActionDTO extends ChatActionPayloadDTO
{
    /**
     * Creates a passkey login confirmation DTO.
     *
     * @param string $signedChallenge Signed challenge token from the options step
     * @param string $credentialId base64url credential id the authenticator asserted
     * @param string $authenticatorData base64url authenticatorData bytes
     * @param string $clientDataJson base64url clientDataJSON bytes
     * @param string $signature base64url ECDSA (DER) signature bytes
     */
    public function __construct(
        public readonly string $signedChallenge,
        public readonly string $credentialId,
        public readonly string $authenticatorData,
        public readonly string $clientDataJson,
        public readonly string $signature,
    ) {
    }

    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return ChatSignalConstants::PASSKEY_LOGIN_CONFIRM;
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
        $credentialId = $data['credentialId'] ?? null;
        $authenticatorData = $data['authenticatorData'] ?? null;
        $clientDataJson = $data['clientDataJson'] ?? null;
        $signature = $data['signature'] ?? null;

        return new static(
            signedChallenge: is_string($signedChallenge) ? $signedChallenge : '',
            credentialId: is_string($credentialId) ? $credentialId : '',
            authenticatorData: is_string($authenticatorData) ? $authenticatorData : '',
            clientDataJson: is_string($clientDataJson) ? $clientDataJson : '',
            signature: is_string($signature) ? $signature : '',
        );
    }

    /**
     * Convert to array for transport.
     *
     * @return array{signedChallenge: string, credentialId: string, authenticatorData: string, clientDataJson: string, signature: string} Confirm payload
     */
    public function toArray(): array
    {
        return [
            'signedChallenge' => $this->signedChallenge,
            'credentialId' => $this->credentialId,
            'authenticatorData' => $this->authenticatorData,
            'clientDataJson' => $this->clientDataJson,
            'signature' => $this->signature,
        ];
    }
}
