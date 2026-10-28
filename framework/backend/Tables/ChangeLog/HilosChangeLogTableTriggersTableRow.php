<?php

declare(strict_types=1);

namespace Hilos\Tables\ChangeLog;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Database\ChangeLog\Section\ChangeLogTrigger;

/** One service trigger's public metadata, without its SQL body. */
final class HilosChangeLogTableTriggersTableRow extends AbstractTableRow
{
    public const string name = 'name';
    public const string owner = 'owner';
    public const string event = 'event';
    public const string table = 'table';
    public const string writesTo = 'writesTo';
    public const string validFromMigration = 'validFromMigration';

    /**
     * @param string $name Service trigger name and row key
     * @param string $owner Service ownership projected from the trigger kind
     * @param string $event INSERT, UPDATE or DELETE
     * @param string $table Live table on which the trigger runs
     * @param string $writesTo Journal destination projected from the trigger kind
     * @param int $validFromMigration Migration declared in the trigger file header
     */
    public function __construct(
        public string $name,
        public string $owner,
        public string $event,
        public string $table,
        public string $writesTo,
        public int $validFromMigration,
    ) {
    }

    /**
     * @param ChangeLogTrigger $trigger Existing service trigger declaration
     * @return self Inline service metadata with its journal destination
     */
    public static function fromTrigger(ChangeLogTrigger $trigger): self
    {
        return new self($trigger->name, 'service', $trigger->event, $trigger->table, 'journal', $trigger->validFromMigration);
    }

    /** @return string Service trigger name */
    public function getRowKey(): string
    {
        return $this->name;
    }

    /** @return string Payload field carrying the trigger key */
    public static function keyField(): string
    {
        return self::name;
    }

    /** @return array<string, mixed> Inline trigger slot without an entity id or SQL body */
    public function toArray(): array
    {
        return [
            self::name => $this->name,
            self::owner => $this->owner,
            self::event => $this->event,
            self::table => $this->table,
            self::writesTo => $this->writesTo,
            self::validFromMigration => $this->validFromMigration,
        ];
    }

    /**
     * @param array<string, mixed> $data Trigger slot payload
     * @return static Reconstructed service trigger metadata
     * @throws InvalidFormatException When a field is missing or has the wrong type
     */
    public static function fromArray(array $data): static
    {
        return new static(
            self::requireString($data, self::name),
            self::requireString($data, self::owner),
            self::requireString($data, self::event),
            self::requireString($data, self::table),
            self::requireString($data, self::writesTo),
            self::requireInt($data, self::validFromMigration),
        );
    }
}
