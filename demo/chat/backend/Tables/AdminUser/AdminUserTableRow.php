<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\AdminUser;

use Demo\Chat\Database\Object\Item\User as ObjectUser;
use Hilos\Core\Table\Row\AbstractTableRow;

/**
 * Backend row payload for the admin users table.
 */
final class AdminUserTableRow extends AbstractTableRow
{
    public const string id = ObjectUser::id;
    public const string name = ObjectUser::name;
    public const string lastActivity = ObjectUser::lastActivity;
    public const string presence = 'presence';

    public function __construct(
        public int $id,
        public string $name,
        public ?string $lastActivity,
        public ?string $presence = null,
    ) {
    }

    /**
     * Returns the stable row id used by the admin users table.
     */
    public function getRowId(): int
    {
        return $this->id;
    }

    /**
     * Serializes the row to the frontend table payload shape.
     *
     * @return array{id: int, name: string, lastActivity: ?string, presence: ?string}
     */
    public function toArray(): array
    {
        return [
            self::id => $this->id,
            self::name => $this->name,
            self::lastActivity => $this->lastActivity,
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
            presence: isset($data[self::presence]) ? (string) $data[self::presence] : null,
        );
    }
}
