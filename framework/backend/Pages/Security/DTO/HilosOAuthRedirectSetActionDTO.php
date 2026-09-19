<?php

declare(strict_types=1);

namespace Hilos\Pages\Security\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * DTO for the security_oauth_redirect_set action payload (HIL-286).
 *
 * Carries the shared return address every provider redirects back to. The handler trims
 * it and judges its form before handing it to the owner of the settings collection.
 */
final class HilosOAuthRedirectSetActionDTO extends ActionPayloadDTO
{
    /** Payload key: the new return address. */
    public const string value = 'value';

    /**
     * @param string $value New return address, as sent
     */
    public function __construct(
        public readonly string $value,
    ) {
    }

    /**
     * @return string Action name constant
     */
    public function getAction(): string
    {
        return HilosSignalConstants::SECURITY_OAUTH_REDIRECT_SET;
    }

    /**
     * @param array<string, mixed> $data Raw payload (may contain a FIELD_DATA wrapper)
     * @return static Instance
     * @throws InvalidFormatException When the value is absent or not a string
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            $inner = [];
        }

        return new static(
            value: self::requireString($inner, self::value),
        );
    }

    /**
     * @return array<string, mixed> Data with the return address
     */
    public function toArray(): array
    {
        return [
            self::value => $this->value,
        ];
    }
}
