<?php

declare(strict_types=1);

namespace Hilos\Moderation\DTO;

use Hilos\BaseDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * Whether one connection is waiting on a moderation check of one kind (HIL-1625).
 *
 * Sent as {@see HilosSignalConstants::HILOS_MODERATION_CHECK} by the initiator of the check.
 */
final class ModerationCheckSignalData extends BaseDTO implements SignalDataInterface
{
    public const string kind = 'kind';
    public const string checking = 'checking';

    /**
     * @param string $kind Moderation kind key the frame is about
     * @param bool $checking Whether the check is still in progress
     */
    public function __construct(
        public readonly string $kind,
        public readonly bool $checking,
    ) {
    }

    /** @return array{kind: string, checking: bool} Transport payload */
    public function toArray(): array
    {
        return [
            self::kind => $this->kind,
            self::checking => $this->checking,
        ];
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Restored signal
     * @throws InvalidFormatException When a field is absent or malformed
     */
    public static function fromArray(array $data): static
    {
        return new static(
            self::requireString($data, self::kind),
            self::requireBool($data, self::checking),
        );
    }
}
