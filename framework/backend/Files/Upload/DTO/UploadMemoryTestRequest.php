<?php

declare(strict_types=1);

namespace Hilos\Files\Upload\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;

/** Strict test command boundary; no fixture bytes are allocated by this reader. */
final class UploadMemoryTestRequest extends BaseDTO
{
    public const string INSPECT = 'inspect';
    public const string LIMIT = 'limit';
    public const string ENQUEUE = 'enqueue';
    public const int MAX_BYTES = 65_536;

    /**
     * @param string $operation Test operation
     * @param ?int $memoryPoolBytes Test capacity override
     * @param ?string $targetNodeId Existing test agent node
     * @param ?string $agentType Existing test agent type
     * @param ?string $agentIndex Existing test agent instance
     * @param ?int $bytes Fixture length
     * @throws InvalidFormatException When required operation arguments are absent or out of range
     */
    public function __construct(
        public readonly string $operation,
        public readonly ?int $memoryPoolBytes,
        public readonly ?string $targetNodeId,
        public readonly ?string $agentType,
        public readonly ?string $agentIndex,
        public readonly ?int $bytes,
    ) {
        if (!in_array($operation, [self::INSPECT, self::LIMIT, self::ENQUEUE], true)) {
            throw new InvalidFormatException('operation must be inspect, limit or enqueue');
        }
        if ($memoryPoolBytes !== null && $memoryPoolBytes < 0) {
            throw new InvalidFormatException('memoryPoolBytes must be nonnegative');
        }
        if ($operation === self::LIMIT && $memoryPoolBytes === null) {
            throw new InvalidFormatException('limit requires memoryPoolBytes');
        }
        if (($bytes !== null && ($bytes < 1 || $bytes > self::MAX_BYTES))
            || ($operation === self::ENQUEUE && ($bytes === null || $targetNodeId === null || $targetNodeId === ''
                || $agentType === null || $agentType === '' || $agentIndex === ''))) {
            throw new InvalidFormatException('enqueue requires targetNodeId, agentType and bytes in 1..65536');
        }
    }

    /** @return array<string, mixed> Command wire boundary */
    public function toArray(): array
    {
        return array_filter([
            'operation' => $this->operation, 'memoryPoolBytes' => $this->memoryPoolBytes,
            'targetNodeId' => $this->targetNodeId, 'agentType' => $this->agentType,
            'agentIndex' => $this->agentIndex, 'bytes' => $this->bytes,
        ], static fn(mixed $value): bool => $value !== null);
    }

    /**
     * @param array<string, mixed> $data Command request boundary
     * @return static Validated test demand
     * @throws InvalidFormatException When fields are absent, mistyped or out of range
     */
    public static function fromArray(array $data): static
    {
        return new static(self::requireString($data, 'operation'), self::optionalInt($data, 'memoryPoolBytes'),
            self::optionalString($data, 'targetNodeId'), self::optionalString($data, 'agentType'),
            self::optionalString($data, 'agentIndex'), self::optionalInt($data, 'bytes'));
    }
}
