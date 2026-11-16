<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\Auth\WebAuthn\Base64Url;
use Hilos\Auth\WebAuthn\PasskeyAlgorithm;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Database\Identity\IdentityType;

/** Verified passkey identity material; no external proof is performed by its receiver. */
final class PasskeyIdentityAddData extends IdentityAddData
{
    public const string credentialId = 'credentialId';
    public const string publicKeyPem = 'publicKeyPem';
    public const string algorithm = 'algorithm';
    public const string signCount = 'signCount';
    public const string transports = 'transports';
    public const string aaguid = 'aaguid';
    public const string userHandle = 'userHandle';
    public const string deviceName = 'deviceName';

    /**
     * @param string $credentialId Verified credentialId material
     * @param string $publicKeyPem Verified publicKeyPem material
     * @param int $algorithm Verified algorithm material
     * @param int $signCount Verified signCount material
     * @param list<string> $transports Verified transports material
     * @param string $aaguid Verified aaguid material
     * @param string $userHandle Verified userHandle material
     * @param ?string $deviceName Verified deviceName material
     * @throws InvalidFormatException When the algorithm, counter, transports or binary encoding is invalid
     */
    public function __construct(
        public readonly string $credentialId,
        public readonly string $publicKeyPem,
        public readonly int $algorithm,
        public readonly int $signCount,
        public readonly array $transports,
        public readonly string $aaguid,
        public readonly string $userHandle,
        public readonly ?string $deviceName,
    ) {
        if (PasskeyAlgorithm::tryFrom($algorithm) === null || $signCount < 0) {
            throw new InvalidFormatException('Invalid passkey algorithm or counter');
        }
        if (!array_is_list($transports) || array_any($transports, static fn (mixed $value): bool => !is_string($value))) {
            throw new InvalidFormatException('Passkey transports must be a string list');
        }
        foreach ([$credentialId, $userHandle] as $encoded) {
            $decoded = Base64Url::decode($encoded);
            if ($decoded === null || $decoded === '' || Base64Url::encode($decoded) !== $encoded) {
                throw new InvalidFormatException('Passkey binary fields must be canonical base64url');
            }
        }
    }

    /**
     * @param array<string, mixed> $data Tagged transport payload
     * @return static Verified identity material
     * @throws InvalidFormatException When a required field has the wrong type or the tag is foreign
     */
    public static function fromArray(array $data): static
    {
        self::requireType($data, IdentityType::PASSKEY);

        return new static(
            credentialId: self::requireString($data, self::credentialId),
            publicKeyPem: self::requireString($data, self::publicKeyPem),
            algorithm: self::requireInt($data, self::algorithm),
            signCount: self::requireInt($data, self::signCount),
            transports: self::requireArray($data, self::transports),
            aaguid: self::requireString($data, self::aaguid),
            userHandle: self::requireString($data, self::userHandle),
            deviceName: self::requireNullableString($data, self::deviceName),
        );
    }

    /** @return array<string, mixed> Tagged transport payload */
    public function toArray(): array
    {
        return [
            self::type => IdentityType::PASSKEY,
            self::credentialId => $this->credentialId,
            self::publicKeyPem => $this->publicKeyPem,
            self::algorithm => $this->algorithm,
            self::signCount => $this->signCount,
            self::transports => $this->transports,
            self::aaguid => $this->aaguid,
            self::userHandle => $this->userHandle,
            self::deviceName => $this->deviceName,
        ];
    }
}
