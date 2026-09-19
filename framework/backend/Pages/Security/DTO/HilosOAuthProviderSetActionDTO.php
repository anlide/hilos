<?php

declare(strict_types=1);

namespace Hilos\Pages\Security\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * DTO for the security_oauth_provider_set action payload (HIL-286).
 *
 * Names one provider, one of its fields (`client_id`, `scope`, `client_secret`) and the
 * value to store. The value is carried as sent; the handler trims and judges it. A secret
 * rides this payload in and is never echoed back out.
 */
final class HilosOAuthProviderSetActionDTO extends ActionPayloadDTO
{
    /** Payload key: the target provider key, e.g. 'oauth:github'. */
    public const string providerKey = 'providerKey';

    /** Payload key: the target field name. */
    public const string field = 'field';

    /** Payload key: the new value. */
    public const string value = 'value';

    /**
     * @param string $providerKey Target provider key
     * @param string $field Target field name
     * @param string $value New value, as sent
     */
    public function __construct(
        public readonly string $providerKey,
        public readonly string $field,
        public readonly string $value,
    ) {
    }

    /**
     * @return string Action name constant
     */
    public function getAction(): string
    {
        return HilosSignalConstants::SECURITY_OAUTH_PROVIDER_SET;
    }

    /**
     * @param array<string, mixed> $data Raw payload (may contain a FIELD_DATA wrapper)
     * @return static Instance
     * @throws InvalidFormatException When a field the action needs is absent or not a string
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            $inner = [];
        }

        return new static(
            providerKey: trim(self::requireString($inner, self::providerKey)),
            field: trim(self::requireString($inner, self::field)),
            value: self::requireString($inner, self::value),
        );
    }

    /**
     * The payload with the value left out: a secret must not travel back in a reply or a log.
     *
     * @return array<string, mixed> Data with the provider key and the field
     */
    public function toArray(): array
    {
        return [
            self::providerKey => $this->providerKey,
            self::field => $this->field,
        ];
    }
}
