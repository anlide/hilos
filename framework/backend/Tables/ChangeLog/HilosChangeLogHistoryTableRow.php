<?php

declare(strict_types=1);

namespace Hilos\Tables\ChangeLog;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Database\ChangeLog\Section\ChangeLogHistoryRow;

/** One changed record in a table's journal history. */
final class HilosChangeLogHistoryTableRow extends AbstractTableRow
{
    public const string rowKey = 'rowKey';
    public const string createdAt = 'createdAt';
    public const string recordKey = 'recordKey';
    public const string mutation = 'mutation';
    public const string changes = 'changes';
    public const string receiptId = 'receiptId';
    public const string actorId = 'actorId';
    public const string actorLabel = 'actorLabel';
    public const string actorDeleted = 'actorDeleted';
    public const string subjectId = 'subjectId';
    public const string subjectLabel = 'subjectLabel';
    public const string subjectDeleted = 'subjectDeleted';
    public const string channel = 'channel';

    public const string changeField = 'field';
    public const string changeKind = 'kind';
    public const string changeOldPresent = 'oldPresent';
    public const string changeNewPresent = 'newPresent';
    public const string changeOldValue = 'oldValue';
    public const string changeNewValue = 'newValue';
    public const string changeBodyOmitted = 'bodyOmitted';

    /** @param list<array<string, mixed>> $changes Journal field changes */
    public function __construct(
        public int $rowKey,
        public string $createdAt,
        public ?array $recordKey,
        public string $mutation,
        public array $changes,
        public ?int $receiptId,
        public ?int $actorId,
        public ?string $actorLabel,
        public bool $actorDeleted,
        public ?int $subjectId,
        public ?string $subjectLabel,
        public bool $subjectDeleted,
        public ?string $channel,
    ) {
    }

    /** @return self Browser row for one typed reader item */
    public static function fromItem(ChangeLogHistoryRow $item): self
    {
        $entry = $item->entry;
        $receipt = $item->receipt;
        $changes = [];
        foreach ($entry->changes as $change) {
            $changes[] = [
                self::changeField => $change->field,
                self::changeKind => $change->kind,
                self::changeOldPresent => $change->oldPresent,
                self::changeNewPresent => $change->newPresent,
                self::changeOldValue => $change->oldValue,
                self::changeNewValue => $change->newValue,
                self::changeBodyOmitted => $change->bodyOmitted,
            ];
        }
        $actor = HilosChangeLogTableParts::person($receipt?->actor);
        $subject = HilosChangeLogTableParts::person($receipt?->subject);
        return new self(
            $entry->id,
            HilosChangeLogTableParts::displayTime($entry->createdAt),
            $entry->recordKey,
            $entry->mutation,
            $changes,
            $entry->receiptId,
            $actor['id'],
            $actor['label'],
            $actor['deleted'],
            $subject['id'],
            $subject['label'],
            $subject['deleted'],
            $receipt?->channel,
        );
    }

    /** @return int Stable journal row number */
    public function getRowKey(): int
    {
        return $this->rowKey;
    }

    /** @return string Wire field carrying the row key */
    public static function keyField(): string
    {
        return self::rowKey;
    }

    /** @return array<string, mixed> History slot payload */
    public function toArray(): array
    {
        return [
            self::rowKey => $this->rowKey,
            self::createdAt => $this->createdAt,
            self::recordKey => $this->recordKey,
            self::mutation => $this->mutation,
            self::changes => $this->changes,
            self::receiptId => $this->receiptId,
            self::actorId => $this->actorId,
            self::actorLabel => $this->actorLabel,
            self::actorDeleted => $this->actorDeleted,
            self::subjectId => $this->subjectId,
            self::subjectLabel => $this->subjectLabel,
            self::subjectDeleted => $this->subjectDeleted,
            self::channel => $this->channel,
        ];
    }

    /**
     * @param array<string, mixed> $data History slot payload
     * @return static Reconstructed row
     * @throws InvalidFormatException When a field or change item is malformed
     */
    public static function fromArray(array $data): static
    {
        $changes = self::requireArray($data, self::changes);
        if (!array_is_list($changes)) {
            throw new InvalidFormatException('History row carries no changes list');
        }
        $parsed = [];
        foreach ($changes as $change) {
            if (!is_array($change)) {
                throw new InvalidFormatException('History row carries a non-object change item');
            }
            $parsed[] = [
                self::changeField => self::requireString($change, self::changeField),
                self::changeKind => self::requireString($change, self::changeKind),
                self::changeOldPresent => self::requireBool($change, self::changeOldPresent),
                self::changeNewPresent => self::requireBool($change, self::changeNewPresent),
                self::changeOldValue => self::optionalString($change, self::changeOldValue),
                self::changeNewValue => self::optionalString($change, self::changeNewValue),
                self::changeBodyOmitted => self::requireBool($change, self::changeBodyOmitted),
            ];
        }
        return new static(
            self::requireInt($data, self::rowKey),
            self::requireString($data, self::createdAt),
            HilosChangeLogTableParts::readRecordKey($data[self::recordKey] ?? null),
            self::requireString($data, self::mutation),
            $parsed,
            self::optionalInt($data, self::receiptId),
            self::optionalInt($data, self::actorId),
            self::optionalString($data, self::actorLabel),
            self::requireBool($data, self::actorDeleted),
            self::optionalInt($data, self::subjectId),
            self::optionalString($data, self::subjectLabel),
            self::requireBool($data, self::subjectDeleted),
            self::optionalString($data, self::channel),
        );
    }
}
