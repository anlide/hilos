<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Tables\HilosUser;

use Demo\SimplePoll\Database\Object\Item\User as ObjectUser;
use Hilos\Tables\Users\AbstractHilosUserTableRow;

/**
 * Backend row payload for the Hilos users table.
 *
 * Extends the framework base row (id, admin, block, presence, onlineSessionCount)
 * with the poll profile fields.
 */
final class HilosUserTableRow extends AbstractHilosUserTableRow
{
    public const string name = ObjectUser::name;
    public const string lastActivity = ObjectUser::lastActivity;

    public function __construct(
        int $id,
        bool $admin,
        bool $block,
        public string $name,
        public ?string $lastActivity,
        int $onlineSessionCount = 0,
        ?string $presence = null,
    ) {
        parent::__construct($id, $admin, $block, $onlineSessionCount, $presence);
    }

    /**
     * Serializes the row to the frontend table payload shape.
     *
     * @return array{id: int, admin: bool, block: bool, onlineSessionCount: int, presence: ?string, name: string, lastActivity: ?string}
     */
    public function toArray(): array
    {
        return $this->baseFields() + [
            self::name => $this->name,
            self::lastActivity => $this->lastActivity,
        ];
    }

    /**
     * Builds a Hilos users row from raw table payload.
     *
     * @param array<string, mixed> $data Raw row payload
     */
    public static function fromArray(array $data): static
    {
        return new static(
            id: (int) ($data[self::id] ?? 0),
            admin: (bool) ($data[self::admin] ?? false),
            block: (bool) ($data[self::block] ?? false),
            name: (string) $data[self::name],
            lastActivity: isset($data[self::lastActivity]) ? (string) $data[self::lastActivity] : null,
            onlineSessionCount: (int) ($data[self::onlineSessionCount] ?? 0),
            presence: isset($data[self::presence]) ? (string) $data[self::presence] : null,
        );
    }
}
