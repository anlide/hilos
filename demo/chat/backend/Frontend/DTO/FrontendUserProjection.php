<?php

declare(strict_types=1);

namespace Demo\Chat\Frontend\DTO;

use Demo\Chat\Database\View\Item\User as DbUser;
use Hilos\BaseDTO;

/**
 * Public frontend projection for one chat user.
 */
final class FrontendUserProjection extends BaseDTO
{
    public const string id = 'id';
    public const string name = 'name';
    public const string lastActivity = 'lastActivity';

    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ?string $lastActivity,
    ) {
    }

    public static function fromDbUser(DbUser $user): self
    {
        return new self(
            id: (int) $user->id,
            name: $user->name,
            lastActivity: $user->lastActivity,
        );
    }

    /**
     * Converts DTO to frontend payload.
     *
     * @return array{id: int, name: string, lastActivity: ?string}
     */
    public function toArray(): array
    {
        return [
            self::id => $this->id,
            self::name => $this->name,
            self::lastActivity => $this->lastActivity,
        ];
    }

    /**
     * Creates DTO from frontend payload.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new static(
            id: isset($data[self::id]) && is_int($data[self::id]) ? $data[self::id] : 0,
            name: isset($data[self::name]) && is_string($data[self::name]) ? $data[self::name] : '',
            lastActivity: isset($data[self::lastActivity]) && is_string($data[self::lastActivity])
                ? $data[self::lastActivity]
                : null,
        );
    }
}
