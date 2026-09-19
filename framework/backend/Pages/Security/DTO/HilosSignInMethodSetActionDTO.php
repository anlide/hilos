<?php

declare(strict_types=1);

namespace Hilos\Pages\Security\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * DTO for the security_sign_in_method_set action payload (HIL-427).
 *
 * Names one sign-in method and whether it should be on. The page turns it into the one
 * stored list of switched-off methods; the whole list never crosses the wire from a browser,
 * so two administrators switching two different methods do not overwrite each other's
 * choice with a list read before it.
 */
final class HilosSignInMethodSetActionDTO extends ActionPayloadDTO
{
    /** Payload key: the method to switch (see AuthMethodKey). */
    public const string methodKey = 'methodKey';

    /** Payload key: whether the method should be on. */
    public const string enabled = 'enabled';

    /**
     * @param string $methodKey Method to switch
     * @param bool $enabled Whether the method should be on
     */
    public function __construct(
        public readonly string $methodKey,
        public readonly bool $enabled,
    ) {
    }

    /**
     * @return string Action name constant
     */
    public function getAction(): string
    {
        return HilosSignalConstants::SECURITY_SIGN_IN_METHOD_SET;
    }

    /**
     * @param array<string, mixed> $data Raw payload (may contain a FIELD_DATA wrapper)
     * @return static Instance
     * @throws InvalidFormatException When the method key is absent or not a string, or the flag is not a boolean
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            $inner = [];
        }

        return new static(
            methodKey: trim(self::requireString($inner, self::methodKey)),
            enabled: self::requireBool($inner, self::enabled),
        );
    }

    /**
     * @return array{methodKey: string, enabled: bool} Data with the method key and the flag
     */
    public function toArray(): array
    {
        return [
            self::methodKey => $this->methodKey,
            self::enabled => $this->enabled,
        ];
    }
}
