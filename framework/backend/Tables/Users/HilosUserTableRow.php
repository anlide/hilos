<?php

declare(strict_types=1);

namespace Hilos\Tables\Users;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Database\Object\Item\User as ObjectUser;
use Hilos\Runtime\View\DTO\HilosUserPresenceSummary;

/**
 * Row of the Hilos users table - one shape for every project.
 *
 * Carries the person as the framework's own `hilos_user` table holds them - the identity `id`,
 * the RBAC `admin`/`block` flags, the name and the last activity - and the presence summary
 * computed from the project's runtime connections. The people table is the framework's, so the
 * row is too: no project extends it, and a project that ever wants a column of its own on the
 * list opens that as work of its own.
 */
final class HilosUserTableRow extends AbstractTableRow
{
    public const string id = 'id';
    public const string admin = 'admin';
    public const string block = 'block';
    public const string FIELD_DELETION_EFFECTIVE_AT = 'deletionEffectiveAt';
    public const string presence = HilosUserPresenceSummary::presence;
    public const string onlineSessionCount = HilosUserPresenceSummary::onlineSessionCount;
    public const string name = ObjectUser::name;
    public const string lastActivity = ObjectUser::lastActivity;

    public function __construct(
        public int $id,
        public bool $admin = false,
        public bool $block = false,
        public string $name = '',
        public ?string $lastActivity = null,
        public int $onlineSessionCount = 0,
        public ?string $presence = null,
    ) {
    }

    /**
     * Returns the stable row key used by the Hilos users table.
     */
    public function getRowKey(): int
    {
        return $this->id;
    }

    /**
     * @return string Payload key the row key travels under
     */
    public static function keyField(): string
    {
        return self::id;
    }

    /**
     * Serializes the row to the frontend table payload shape.
     *
     * The key order is part of the wire: the presence pair sits between the flags and the name.
     *
     * @return array{id: int, admin: bool, block: bool, onlineSessionCount: int, presence: ?string, name: string, lastActivity: ?string}
     */
    public function toArray(): array
    {
        return [
            self::id => $this->id,
            self::admin => $this->admin,
            self::block => $this->block,
            self::onlineSessionCount => $this->onlineSessionCount,
            self::presence => $this->presence,
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
