<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Router\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\DTO\FrontendChangesDTO;

/**
 * Payload nested inside the RT emit for user presence fan-out.
 */
final class UserPresenceEmitPayload extends BaseDTO
{
    private const string FIELD_USER_ID = 'userId';

    private const string FIELD_FRONTEND = 'frontend';

    private const string FIELD_STATS_FRONTEND = 'statsFrontend';

    /**
     * @param int $userId User whose runtime presence changed
     * @param array<string, mixed> $frontend Serialized lightweight frontend update
     * @param array<string, mixed> $statsFrontend Serialized frontend update with connection stats
     */
    private function __construct(
        public readonly int $userId,
        private readonly array $frontend,
        private readonly array $statsFrontend,
    ) {
    }

    /**
     * Creates the emit payload before worker-daemon serialization.
     *
     * @param int $userId User whose runtime presence changed
     * @param FrontendChangesDTO $frontend Presence update for regular chat pages
     * @param FrontendChangesDTO $statsFrontend Presence update for admin pages with online session counts
     * @return self
     */
    public static function fromFrontendChanges(
        int $userId,
        FrontendChangesDTO $frontend,
        FrontendChangesDTO $statsFrontend,
    ): self {
        return new self($userId, $frontend->toArray(), $statsFrontend->toArray());
    }

    /**
     * Frontend update for pages that do not expose connection counts.
     *
     * @return FrontendChangesDTO Presence-only frontend update
     */
    public function frontend(): FrontendChangesDTO
    {
        return FrontendChangesDTO::fromArray($this->frontend);
    }

    /**
     * Frontend update for pages that expose connection counts.
     *
     * @return FrontendChangesDTO Presence and online session count frontend update
     */
    public function statsFrontend(): FrontendChangesDTO
    {
        return FrontendChangesDTO::fromArray($this->statsFrontend);
    }

    /**
     * Converts DTO to the nested emit payload array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            self::FIELD_USER_ID => $this->userId,
            self::FIELD_FRONTEND => $this->frontend,
            self::FIELD_STATS_FRONTEND => $this->statsFrontend,
        ];
    }

    /**
     * Rebuilds the payload after worker-daemon serialization.
     *
     * @param array<string, mixed> $data Source payload
     * @return static
     */
    public static function fromArray(array $data): static
    {
        return new self(
            userId: isset($data[self::FIELD_USER_ID]) && is_int($data[self::FIELD_USER_ID])
                ? $data[self::FIELD_USER_ID]
                : 0,
            frontend: isset($data[self::FIELD_FRONTEND]) && is_array($data[self::FIELD_FRONTEND])
                ? $data[self::FIELD_FRONTEND]
                : [],
            statsFrontend: isset($data[self::FIELD_STATS_FRONTEND]) && is_array($data[self::FIELD_STATS_FRONTEND])
                ? $data[self::FIELD_STATS_FRONTEND]
                : [],
        );
    }
}
