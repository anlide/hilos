<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Router\DTO;

use Demo\Chat\Constants\ChatSignalConstants;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Socket\WebSocket\DTO\WebSocketAcceptKeySignalDTO;

/**
 * PasskeyOptionsSignalData - the WebAuthn ceremony options reply (HIL-284).
 *
 * The `passkey_*_options` page actions build the publicKey options and a signed
 * challenge token synchronously (CPU-only, no I/O), but the framework's
 * `action_success` carries no domain payload, so it cannot hand the options to the
 * browser. This signal ({@see ChatSignalConstants::PASSKEY_OPTIONS}) is delivered
 * WS_USER to the initiating connection's accept key instead; the client feeds
 * {@see $publicKeyOptions} to navigator.credentials.create/get and returns
 * {@see $signedChallenge} on confirm (the "client action = loading + signal, never
 * fire-forget" pattern). The {@see $ceremony} discriminator tells the client which
 * ceremony (register / login) the options are for.
 *
 * {@see $publicKeyOptions} is the WebAuthn PublicKeyCredential{Creation,Request}Options
 * wire shape (spec-defined keys, binary fields base64url) — a boundary structure
 * translated to the native browser API by the client passkey wrapper.
 */
final class PasskeyOptionsSignalData extends BaseDTO implements SignalDataInterface, WebSocketAcceptKeySignalDTO
{
    /**
     * Creates a passkey options signal payload.
     *
     * @param string $acceptKey Initiating connection accept key the signal targets
     * @param string $ceremony Ceremony the options are for (WebAuthnChallengeSigner::PURPOSE_REGISTER | PURPOSE_LOGIN)
     * @param array<string, mixed> $publicKeyOptions WebAuthn publicKey options wire shape for the client
     * @param string $signedChallenge Signed challenge token to hand back on confirm
     */
    public function __construct(
        public readonly string $acceptKey,
        public readonly string $ceremony,
        public readonly array $publicKeyOptions,
        public readonly string $signedChallenge,
    ) {
    }

    /**
     * @return string Accept key the options signal is delivered to
     */
    public function getAcceptKey(): string
    {
        return $this->acceptKey;
    }

    /**
     * Convert DTO to array for transport.
     *
     * @return array<string, mixed> DTO payload for transport
     */
    public function toArray(): array
    {
        return [
            'acceptKey' => $this->acceptKey,
            'ceremony' => $this->ceremony,
            'publicKeyOptions' => $this->publicKeyOptions,
            'signedChallenge' => $this->signedChallenge,
        ];
    }

    /**
     * Create DTO from array.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload is missing the ceremony, its options or the challenge
     */
    public static function fromArray(array $data): static
    {
        return new static(
            acceptKey: self::requireString($data, 'acceptKey'),
            ceremony: self::requireString($data, 'ceremony'),
            publicKeyOptions: self::requireArray($data, 'publicKeyOptions'),
            signedChallenge: self::requireString($data, 'signedChallenge'),
        );
    }
}
