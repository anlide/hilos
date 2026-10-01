<?php

declare(strict_types=1);

namespace Hilos\Pages\Security\DTO;

use Hilos\Auth\Impersonation\ImpersonationScopeRule;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * DTO for the security_impersonation_scope_set action payload (HIL-1170).
 *
 * Carries what may be done inside someone else's account as the dialog chose it; the value is
 * judged by the setting's own rule on the way in ({@see ImpersonationScopeRule}).
 */
final class HilosImpersonationScopeSetActionDTO extends ActionPayloadDTO
{
    /** Payload key: the scope chosen. */
    public const string scope = 'scope';

    public const array SECRET_FIELDS = [];

    /**
     * @param string $scope Scope chosen
     */
    public function __construct(
        public readonly string $scope,
    ) {
    }

    /**
     * @return string Action name constant
     */
    public function getAction(): string
    {
        return HilosSignalConstants::SECURITY_IMPERSONATION_SCOPE_SET;
    }

    /**
     * @param array<string, mixed> $data Raw payload (may contain a FIELD_DATA wrapper)
     * @return static Instance
     * @throws InvalidFormatException When the scope is absent or not a string
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            $inner = [];
        }

        return new static(scope: trim(self::requireString($inner, self::scope)));
    }

    /**
     * @return array{scope: string} Data with the scope
     */
    public function toArray(): array
    {
        return [self::scope => $this->scope];
    }
}
