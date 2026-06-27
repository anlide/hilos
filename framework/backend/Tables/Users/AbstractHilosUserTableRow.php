<?php

declare(strict_types=1);

namespace Hilos\Tables\Users;

use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Runtime\View\DTO\HilosUserPresenceSummary;

/**
 * Base row for the Hilos users table.
 *
 * Owns the framework user fields every project inherits: the identity `id`, the
 * RBAC `admin`/`block` flags, and the computed presence summary. A project row
 * extends this with its own profile fields (display name, last activity, …) and
 * folds {@see self::baseFields()} into its serialized payload.
 */
abstract class AbstractHilosUserTableRow extends AbstractTableRow
{
    public const string id = 'id';
    public const string admin = 'admin';
    public const string block = 'block';
    public const string presence = HilosUserPresenceSummary::presence;
    public const string onlineSessionCount = HilosUserPresenceSummary::onlineSessionCount;

    public function __construct(
        public int $id,
        public bool $admin = false,
        public bool $block = false,
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
     * The framework user fields shared by every Hilos user row.
     *
     * A project row folds these into its own {@see self::toArray()} output
     * alongside its profile fields.
     *
     * @return array<string, mixed> Base identity, RBAC, and presence fields
     */
    protected function baseFields(): array
    {
        return [
            self::id => $this->id,
            self::admin => $this->admin,
            self::block => $this->block,
            self::onlineSessionCount => $this->onlineSessionCount,
            self::presence => $this->presence,
        ];
    }
}
