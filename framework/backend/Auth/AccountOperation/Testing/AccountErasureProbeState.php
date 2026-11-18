<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation\Testing;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Socket\Worker\DTO\WorkerAgentMessageDTO;

/** Immutable transport snapshot of the single worker-local capture. */
final class AccountErasureProbeState extends BaseDTO
{
    public const string ARMED = 'armed';
    public const string MATCHED = 'matched';
    public const string HELD = 'held';

    /**
     * @param bool $armed Whether the next matching frame will be held
     * @param int $matched Number of captures since clear
     * @param ?WorkerAgentMessageDTO $held Single complete held envelope
     * @throws InvalidFormatException When the snapshot is inconsistent or carries a foreign envelope
     */
    public function __construct(
        public readonly bool $armed,
        public readonly int $matched,
        public readonly ?WorkerAgentMessageDTO $held,
    ) {
        if ($matched < 0 || ($held !== null && ($armed || $matched === 0))) {
            throw new InvalidFormatException('Erasure probe state is inconsistent');
        }
        if ($held !== null) {
            AccountErasureProbeRequest::validateFrame($held);
        }
    }

    /** @return array<string, mixed> Complete state snapshot */
    public function toArray(): array
    {
        return [self::ARMED => $this->armed, self::MATCHED => $this->matched, self::HELD => $this->held?->toArray()];
    }

    /**
     * @param array<string, mixed> $data Worker state snapshot
     * @return static Strictly hydrated state
     * @throws InvalidFormatException When fields or the held envelope are invalid
     */
    public static function fromArray(array $data): static
    {
        if (array_diff(array_keys($data), [self::ARMED, self::MATCHED, self::HELD]) !== []) {
            throw new InvalidFormatException('Unknown erasure probe state field');
        }
        $held = self::requireNullableArray($data, self::HELD);
        return new static(
            self::requireBool($data, self::ARMED),
            self::requireInt($data, self::MATCHED),
            $held === null ? null : AccountErasureProbeRequest::restoreFrame($held),
        );
    }
}
