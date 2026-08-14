<?php

declare(strict_types=1);

namespace Demo\SimpleTodo\Tables\HilosUser;

use Demo\SimpleTodo\Database\Object\Item\User as ObjectUser;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Tables\Users\AbstractHilosUserTableRow;

/**
 * Backend row payload for the Hilos users table.
 *
 * Extends the framework base row (id, admin, block, presence, onlineSessionCount)
 * with the todo profile fields.
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
     * @return static Restored row
     * @throws InvalidFormatException When the payload is missing a field the row is rendered by
     */
    public static function fromArray(array $data): static
    {
        return new static(
            id: self::requireInt($data, self::id),
            admin: self::requireBool($data, self::admin),
            block: self::requireBool($data, self::block),
            name: self::requireString($data, self::name),
            lastActivity: self::optionalString($data, self::lastActivity),
            onlineSessionCount: self::requireInt($data, self::onlineSessionCount),
            presence: self::optionalString($data, self::presence),
        );
    }
}
