<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\AdminUser;

use Demo\Chat\Database\Object\Item\User as ObjectUser;
use Demo\Chat\Database\View\Item\User as DbUser;
use Hilos\Core\Table\Row\AbstractTableRow;

/**
 * Backend row payload for the admin users table.
 */
final class AdminUserTableRow extends AbstractTableRow
{
    public const string id = ObjectUser::id;
    public const string name = ObjectUser::name;
    public const string lastActivity = ObjectUser::lastActivity;
    public const string onlineSessionCount = 'onlineSessionCount';
    public const string presence = 'presence';

    public function __construct(
        public int $id,
        public string $name,
        public ?string $lastActivity,
        public int $onlineSessionCount = 0,
        public ?string $presence = null,
    ) {
    }

    /**
     * Returns the stable row key used by the admin users table.
     */
    public function getRowKey(): int
    {
        return $this->id;
    }

    /**
     * Builds an admin users table row from the DB user plus runtime presence state.
     *
     * @param DbUser $user DB user view item
     */
    public static function fromDbUser(DbUser $user): static
    {
        $onlineSessionCount = $user->onlineSessionCount;

        return new self(
            id: (int) $user->id,
            name: $user->name,
            lastActivity: $user->lastActivity,
            onlineSessionCount: $onlineSessionCount,
            presence: $onlineSessionCount > 0 ? 'online' : 'offline',
        );
    }

    /**
     * Serializes the row to the frontend table payload shape.
     *
     * @return array{id: int, name: string, lastActivity: ?string, onlineSessionCount: int, presence: ?string}
     */
    public function toArray(): array
    {
        return [
            self::id => $this->id,
            self::name => $this->name,
            self::lastActivity => $this->lastActivity,
            self::onlineSessionCount => $this->onlineSessionCount,
            self::presence => $this->presence,
        ];
    }

    /**
     * Builds an admin users row from raw table payload.
     *
     * @param array<string, mixed> $data Raw row payload
     */
    public static function fromArray(array $data): static
    {
        return new self(
            id: (int) ($data[self::id] ?? 0),
            name: (string) ($data[self::name] ?? ''),
            lastActivity: isset($data[self::lastActivity]) ? (string) $data[self::lastActivity] : null,
            onlineSessionCount: (int) ($data[self::onlineSessionCount] ?? 0),
            presence: isset($data[self::presence]) ? (string) $data[self::presence] : null,
        );
    }
}
