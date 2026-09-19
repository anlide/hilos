<?php

declare(strict_types=1);

namespace Hilos\Pages\Security\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * DTO for the security_oauth_provider_reset action payload (HIL-286).
 *
 * Names one provider and one of its fields to take back to its env/recipe value. The
 * handler clears the stored value, so the resolver falls back to env, then the recipe.
 */
final class HilosOAuthProviderResetActionDTO extends ActionPayloadDTO
{
    /** Payload key: the target provider key, e.g. 'oauth:github'. */
    public const string providerKey = 'providerKey';

    /** Payload key: the target field name. */
    public const string field = 'field';

    /**
     * @param string $providerKey Target provider key
     * @param string $field Target field name
     */
    public function __construct(
        public readonly string $providerKey,
        public readonly string $field,
    ) {
    }

    /**
     * @return string Action name constant
     */
    public function getAction(): string
    {
        return HilosSignalConstants::SECURITY_OAUTH_PROVIDER_RESET;
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
        );
    }

    /**
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
