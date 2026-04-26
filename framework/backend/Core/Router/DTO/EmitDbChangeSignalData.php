<?php

declare(strict_types=1);

namespace Hilos\Core\Router\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Table\DTO\TableSourceEventDTO;
use Hilos\Core\Table\Mutation\TableMutationType;

/**
 * Canonical DB change payload for {@see SignalTypeConstants::EMIT_DB_CHANGE}.
 *
 * Serializable for worker-to-daemon transport. The payload carries a typed
 * source event; table routing and mutation construction happen on the daemon.
 */
final class EmitDbChangeSignalData extends BaseDTO implements SignalDataInterface
{
    public const string FIELD_SOURCE_EVENT = 'sourceEvent';

    public const string FIELD_EXCLUDE_ACCEPT_KEY = 'excludeAcceptKey';

    public const string FIELD_ACTOR_USER_ID = 'actorUserId';

    /**
     * @param TableSourceEventDTO $sourceEvent Source event that tables may project
     * @param ?string $excludeAcceptKey Initiator connection to skip on broadcast leg
     * @param ?int $actorUserId Optional acting user id (audit / future rules)
     */
    public function __construct(
        public readonly TableSourceEventDTO $sourceEvent,
        public readonly ?string $excludeAcceptKey = null,
        public readonly ?int $actorUserId = null,
    ) {
    }

    /**
     * Serializes the emit payload for worker-to-daemon transport.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            self::FIELD_SOURCE_EVENT => $this->sourceEvent->toArray(),
            self::FIELD_EXCLUDE_ACCEPT_KEY => $this->excludeAcceptKey,
            self::FIELD_ACTOR_USER_ID => $this->actorUserId,
        ];
    }

    /**
     * Rebuilds the emit payload from serialized transport data.
     *
     * @param array<string, mixed> $data Serialized emit payload
     */
    public static function fromArray(array $data): static
    {
        $sourceEventData = $data[self::FIELD_SOURCE_EVENT] ?? [];

        return new self(
            sourceEvent: is_array($sourceEventData)
                ? TableSourceEventDTO::fromArray($sourceEventData)
                : new TableSourceEventDTO('', '', TableMutationType::Update),
            excludeAcceptKey: isset($data[self::FIELD_EXCLUDE_ACCEPT_KEY])
                ? (string) $data[self::FIELD_EXCLUDE_ACCEPT_KEY]
                : null,
            actorUserId: isset($data[self::FIELD_ACTOR_USER_ID]) ? (int) $data[self::FIELD_ACTOR_USER_ID] : null,
        );
    }
}
