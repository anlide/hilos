<?php

declare(strict_types=1);

namespace Hilos\Auth\OAuth;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;

/** Raw stand-only state; never a browser or synchronization payload. */
final class RawOAuthProviderSnapshot extends BaseDTO
{
    /** Number of fields in a complete snapshot. */
    private const int SNAPSHOT_FIELD_COUNT = 2;
    /** Number of fields in a present row. */
    private const int ROW_FIELD_COUNT = 6;

    /**
     * @param string $providerKey External directory key
     * @param ?int $id Captured primary id, or absence
     * @param ?string $clientId Raw stored client id
     * @param ?string $clientSecret Raw DB-only secret
     * @param ?string $scope Raw stored scope
     * @param ?int $createdAt Original epoch milliseconds, or absence
     * @param ?int $updatedAt Original epoch milliseconds, or absence
     * @throws InvalidFormatException When presence, identity or moments disagree
     */
    public function __construct(
        public readonly string $providerKey,
        public readonly ?int $id = null,
        public readonly ?string $clientId = null,
        public readonly ?string $clientSecret = null,
        public readonly ?string $scope = null,
        public readonly ?int $createdAt = null,
        public readonly ?int $updatedAt = null,
    ) {
        if (
            $providerKey === ''
            || ($id !== null && ($id <= 0 || $createdAt === null || $updatedAt === null))
            || ($id === null && [$clientId, $clientSecret, $scope, $createdAt, $updatedAt] !== [null, null, null, null, null])
        ) {
            throw new InvalidFormatException('Invalid raw OAuth provider snapshot.');
        }
    }

    /**
     * @param array<string, mixed> $data Complete snapshot payload
     * @return static Snapshot without coercion or normalization
     * @throws InvalidFormatException When fields are missing, extra or incorrectly typed
     */
    public static function fromArray(array $data): static
    {
        if (count($data) !== self::SNAPSHOT_FIELD_COUNT || !array_key_exists(OAuthProviderTestCommandConstants::FIELD_ROW, $data)) {
            throw new InvalidFormatException('Invalid raw OAuth provider snapshot.');
        }
        $providerKey = self::requireString($data, OAuthProviderTestCommandConstants::FIELD_PROVIDER_KEY);
        if ($data[OAuthProviderTestCommandConstants::FIELD_ROW] === null) {
            return new static($providerKey);
        }
        $row = self::requireArray($data, OAuthProviderTestCommandConstants::FIELD_ROW);
        if (count($row) !== self::ROW_FIELD_COUNT) {
            throw new InvalidFormatException('Invalid raw OAuth provider row.');
        }
        foreach ([
            OAuthProviderTestCommandConstants::FIELD_CLIENT_ID,
            OAuthProviderTestCommandConstants::FIELD_CLIENT_SECRET,
            OAuthProviderTestCommandConstants::FIELD_SCOPE,
        ] as $field) {
            if (!array_key_exists($field, $row)) {
                throw new InvalidFormatException('Incomplete raw OAuth provider row.');
            }
        }

        return new static(
            $providerKey,
            self::requireInt($row, OAuthProviderTestCommandConstants::FIELD_ID),
            self::optionalString($row, OAuthProviderTestCommandConstants::FIELD_CLIENT_ID),
            self::optionalString($row, OAuthProviderTestCommandConstants::FIELD_CLIENT_SECRET),
            self::optionalString($row, OAuthProviderTestCommandConstants::FIELD_SCOPE),
            self::requireInt($row, OAuthProviderTestCommandConstants::FIELD_CREATED_AT),
            self::requireInt($row, OAuthProviderTestCommandConstants::FIELD_UPDATED_AT),
        );
    }

    /** @return array<string, mixed> Exact raw state, for the closed command exchange only */
    public function toArray(): array
    {
        return [
            OAuthProviderTestCommandConstants::FIELD_PROVIDER_KEY => $this->providerKey,
            OAuthProviderTestCommandConstants::FIELD_ROW => $this->id === null ? null : [
                OAuthProviderTestCommandConstants::FIELD_ID => $this->id,
                OAuthProviderTestCommandConstants::FIELD_CLIENT_ID => $this->clientId,
                OAuthProviderTestCommandConstants::FIELD_CLIENT_SECRET => $this->clientSecret,
                OAuthProviderTestCommandConstants::FIELD_SCOPE => $this->scope,
                OAuthProviderTestCommandConstants::FIELD_CREATED_AT => $this->createdAt,
                OAuthProviderTestCommandConstants::FIELD_UPDATED_AT => $this->updatedAt,
            ],
        ];
    }
}
