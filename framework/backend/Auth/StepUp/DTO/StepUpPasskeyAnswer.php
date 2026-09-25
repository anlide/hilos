<?php

declare(strict_types=1);

namespace Hilos\Auth\StepUp\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;

/**
 * WebAuthn assertion returned while confirming a protected operation (HIL-495).
 */
final class StepUpPasskeyAnswer extends BaseDTO
{
    public const string signedChallenge = 'signedChallenge';
    public const string credentialId = 'credentialId';
    public const string authenticatorData = 'authenticatorData';
    public const string clientDataJson = 'clientDataJson';
    public const string signature = 'signature';
    public const string userHandle = 'userHandle';

    /**
     * @param string $signedChallenge Signed stateless challenge token
     * @param string $credentialId Base64url credential id
     * @param string $authenticatorData Base64url authenticator data
     * @param string $clientDataJson Base64url clientDataJSON
     * @param string $signature Base64url assertion signature
     * @param ?string $userHandle Optional base64url user handle
     */
    public function __construct(
        public readonly string $signedChallenge,
        public readonly string $credentialId,
        public readonly string $authenticatorData,
        public readonly string $clientDataJson,
        public readonly string $signature,
        public readonly ?string $userHandle,
    ) {
    }

    /**
     * @param array<string, mixed> $data Assertion payload
     * @return static Parsed assertion
     * @throws InvalidFormatException When a required assertion field is missing or mistyped
     */
    public static function fromArray(array $data): static
    {
        return new static(
            signedChallenge: self::requireString($data, self::signedChallenge),
            credentialId: self::requireString($data, self::credentialId),
            authenticatorData: self::requireString($data, self::authenticatorData),
            clientDataJson: self::requireString($data, self::clientDataJson),
            signature: self::requireString($data, self::signature),
            userHandle: self::optionalString($data, self::userHandle),
        );
    }

    /**
     * @return array<string, mixed> Assertion payload
     */
    public function toArray(): array
    {
        return [
            self::signedChallenge => $this->signedChallenge,
            self::credentialId => $this->credentialId,
            self::authenticatorData => $this->authenticatorData,
            self::clientDataJson => $this->clientDataJson,
            self::signature => $this->signature,
            self::userHandle => $this->userHandle,
        ];
    }
}
