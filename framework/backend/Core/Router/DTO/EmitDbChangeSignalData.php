<?php

declare(strict_types=1);

namespace Hilos\Core\Router\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Projection\SourceChange;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Table\Mutation\TableMutationType;

/**
 * Canonical DB change payload for {@see \Hilos\Constants\SignalTypeConstants::EMIT_DB_CHANGE}.
 *
 * Serializable for worker-to-daemon transport. The payload carries one source
 * change fact; table routing and mutation construction happen on the receiving
 * worker through the projection layer.
 */
final class EmitDbChangeSignalData extends BaseDTO implements SignalDataInterface
{
    public const string FIELD_SOURCE_CHANGE = 'sourceChange';

    public const string FIELD_EXCLUDE_ACCEPT_KEY = 'excludeAcceptKey';

    public const string FIELD_ACTOR_USER_ID = 'actorUserId';

    /**
     * @param SourceChange $sourceChange Source change that tables and projections may consume
     * @param ?string $excludeAcceptKey Initiator connection to skip on broadcast leg
     * @param ?int $actorUserId Optional acting user id (audit / future rules)
     */
    public function __construct(
        public readonly SourceChange $sourceChange,
        public readonly ?string $excludeAcceptKey = null,
        public readonly ?int $actorUserId = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            self::FIELD_SOURCE_CHANGE => $this->sourceChange->toArray(),
            self::FIELD_EXCLUDE_ACCEPT_KEY => $this->excludeAcceptKey,
            self::FIELD_ACTOR_USER_ID => $this->actorUserId,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): static
    {
        $sourceChangeData = $data[self::FIELD_SOURCE_CHANGE] ?? [];

        return new self(
            sourceChange: is_array($sourceChangeData)
                ? SourceChange::fromArray($sourceChangeData)
                : new SourceChange(SourceChange::KIND_DB, '', '', TableMutationType::Update),
            excludeAcceptKey: isset($data[self::FIELD_EXCLUDE_ACCEPT_KEY])
                ? (string) $data[self::FIELD_EXCLUDE_ACCEPT_KEY]
                : null,
            actorUserId: isset($data[self::FIELD_ACTOR_USER_ID]) ? (int) $data[self::FIELD_ACTOR_USER_ID] : null,
        );
    }
}
