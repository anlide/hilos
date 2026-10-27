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
 * the RBAC `admin`/`block` flags, the name and the last activity - the presence summary
 * computed from the project's runtime connections, and whether the account was folded into
 * another one by a merge (HIL-1292), read off the merge table. The people table is the
 * framework's, so the row is too: no project extends it, and a project that ever wants a column
 * of its own on the list opens that as work of its own.
 */
final class HilosUserTableRow extends AbstractTableRow
{
    public const string id = 'id';
    public const string admin = 'admin';
    public const string block = 'block';
    public const string merged = 'merged';
    public const string FIELD_DELETION_EFFECTIVE_AT = 'deletionEffectiveAt';
    public const string FIELD_DELETION_GRACE_DAYS = 'deletionGraceDays';
    /** Computed field of a person's row: the live end of the merge chain the account leads to, or null (HIL-1292). */
    public const string FIELD_MERGED_INTO = 'mergedInto';
    /** Computed field of a person's row: the name of that live end, or null when there is none to name (HIL-1292). */
    public const string FIELD_MERGED_INTO_NAME = 'mergedIntoName';
    public const string presence = HilosUserPresenceSummary::presence;
    public const string onlineSessionCount = HilosUserPresenceSummary::onlineSessionCount;
    public const string name = ObjectUser::name;
    public const string lastActivity = ObjectUser::lastActivity;

    /**
     * @param int $id Person ID
     * @param bool $admin Panel operator flag
     * @param bool $block Account block flag
     * @param string $name Display name
     * @param ?int $lastActivity Last activity in epoch milliseconds UTC, or null
     * @param int $onlineSessionCount Open session count
     * @param ?string $presence Connection presence
     * @param bool $merged Whether the account was merged
     */
    public function __construct(
        public int $id,
        public bool $admin = false,
        public bool $block = false,
        public string $name = '',
        public ?int $lastActivity = null,
        public int $onlineSessionCount = 0,
        public ?string $presence = null,
        public bool $merged = false,
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
     * The key order is part of the wire: the presence pair sits between the flags and the name, and
     * the merge flag closes the row.
     *
     * @return array{id: int, admin: bool, block: bool, onlineSessionCount: int, presence: ?string, name: string,
     *     lastActivity: ?int, merged: bool}
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
            self::merged => $this->merged,
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
            lastActivity: self::optionalInt($data, self::lastActivity),
            onlineSessionCount: self::requireInt($data, self::onlineSessionCount),
            presence: self::optionalString($data, self::presence),
            merged: self::requireBool($data, self::merged),
        );
    }
}
