<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * PasskeyLoginConfirmActionDTO - DTO for submitting a WebAuthn login assertion (HIL-284).
 *
 * Public (anonymous-reachable) submit relayed by the client after
 * navigator.credentials.get(): the signed challenge token from the options step,
 * the base64url credential id the authenticator chose, and the base64url
 * authenticatorData, clientDataJSON and (DER) signature it returned. The binary
 * fields stay base64url on the wire and are decoded by the handler; every failure
 * collapses to one generic message so nothing about the account leaks.
 *
 * The optional `userHandle` is the base64url WebAuthn user handle a discoverable
 * (usernameless) assertion carries (HIL-400); an authenticator that holds none
 * sends an empty one, which arrives as null. When present the handler cross-checks
 * it against the credential owner as defense-in-depth; the credential id stays
 * authoritative.
 */
final class PasskeyLoginConfirmActionDTO extends ActionPayloadDTO
{
    /**
     * Creates a passkey login confirmation DTO.
     *
     * @param string $signedChallenge Signed challenge token from the options step
     * @param string $credentialId base64url credential id the authenticator asserted
     * @param string $authenticatorData base64url authenticatorData bytes
     * @param string $clientDataJson base64url clientDataJSON bytes
     * @param string $signature base64url ECDSA (DER) signature bytes
     * @param ?string $userHandle base64url WebAuthn user handle from a discoverable assertion, or null when the authenticator holds none (HIL-400)
     */
    public function __construct(
        public readonly string $signedChallenge,
        public readonly string $credentialId,
        public readonly string $authenticatorData,
        public readonly string $clientDataJson,
        public readonly string $signature,
        public readonly ?string $userHandle = null,
    ) {
    }

    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_PASSKEY_LOGIN_CONFIRM;
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
        $userHandle = $data['userHandle'] ?? null;

        return new static(
            signedChallenge: self::requireString($data, 'signedChallenge'),
            credentialId: self::requireString($data, 'credentialId'),
            authenticatorData: self::requireString($data, 'authenticatorData'),
            clientDataJson: self::requireString($data, 'clientDataJson'),
            signature: self::requireString($data, 'signature'),
            userHandle: is_string($userHandle) && $userHandle !== '' ? $userHandle : null,
        );
    }

    /**
     * Convert to array for transport.
     *
     * @return array{signedChallenge: string, credentialId: string, authenticatorData: string,
     *     clientDataJson: string, signature: string, userHandle: ?string} Confirm payload
     */
    public function toArray(): array
    {
        return [
            'signedChallenge' => $this->signedChallenge,
            'credentialId' => $this->credentialId,
            'authenticatorData' => $this->authenticatorData,
            'clientDataJson' => $this->clientDataJson,
            'signature' => $this->signature,
            'userHandle' => $this->userHandle,
        ];
    }
}
