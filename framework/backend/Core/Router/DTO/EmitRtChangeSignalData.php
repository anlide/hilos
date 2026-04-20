<?php

declare(strict_types=1);

namespace Hilos\Core\Router\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * Canonical RT change payload for {@see SignalTypeConstants::EMIT_RT_CHANGE} (skeleton for future use).
 */
final class EmitRtChangeSignalData extends BaseDTO implements SignalDataInterface
{
    public const string FIELD_COLLECTION_KEY = 'collectionKey';

    public const string FIELD_STATE_ID = 'stateId';

    public const string FIELD_PAYLOAD = 'payload';

    public const string FIELD_EXCLUDE_ACCEPT_KEY = 'excludeAcceptKey';

    /**
     * @param string $collectionKey RT collection identifier
     * @param string $stateId State / row identifier
     * @param array<string, mixed> $payload Serializable RT delta or snapshot
     * @param ?string $excludeAcceptKey Optional initiator skip for broadcast legs
     */
    public function __construct(
        public readonly string $collectionKey,
        public readonly string $stateId,
        public readonly array $payload = [],
        public readonly ?string $excludeAcceptKey = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            self::FIELD_COLLECTION_KEY => $this->collectionKey,
            self::FIELD_STATE_ID => $this->stateId,
            self::FIELD_PAYLOAD => $this->payload,
            self::FIELD_EXCLUDE_ACCEPT_KEY => $this->excludeAcceptKey,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new self(
            collectionKey: (string) ($data[self::FIELD_COLLECTION_KEY] ?? ''),
            stateId: (string) ($data[self::FIELD_STATE_ID] ?? ''),
            payload: $data[self::FIELD_PAYLOAD] ?? [],
            excludeAcceptKey: isset($data[self::FIELD_EXCLUDE_ACCEPT_KEY])
                ? (string) $data[self::FIELD_EXCLUDE_ACCEPT_KEY]
                : null,
        );
    }
}
