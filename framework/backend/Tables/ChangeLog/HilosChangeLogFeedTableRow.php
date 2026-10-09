<?php

declare(strict_types=1);

namespace Hilos\Tables\ChangeLog;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Database\ChangeLog\Section\ChangeLogFeedItem;
use Hilos\Database\ChangeLog\Section\ChangeLogFeedItemKind;

/** One receipt or one journal write made without a receipt. */
final class HilosChangeLogFeedTableRow extends AbstractTableRow
{
    public const string rowKey = 'rowKey';
    public const string kind = 'kind';
    public const string receiptId = 'receiptId';
    public const string entryId = 'entryId';
    public const string createdAt = 'createdAt';
    public const string actorId = 'actorId';
    public const string actorLabel = 'actorLabel';
    public const string actorDeleted = 'actorDeleted';
    public const string subjectId = 'subjectId';
    public const string subjectLabel = 'subjectLabel';
    public const string subjectDeleted = 'subjectDeleted';
    public const string channel = 'channel';
    public const string action = 'action';
    public const string touched = 'touched';

    public const string touchedTable = 'table';
    public const string touchedRecords = 'records';
    public const string touchedRecordKey = 'recordKey';
    public const string touchedMutation = 'mutation';
    public const string touchedChangedFields = 'changedFields';

    /** @param list<array{table: string, records: int, recordKey: ?array, mutation: ?string, changedFields: ?int}> $touched */
    public function __construct(
        public string $rowKey,
        public string $kind,
        public ?int $receiptId,
        public ?int $entryId,
        public string $createdAt,
        public ?int $actorId,
        public ?string $actorLabel,
        public bool $actorDeleted,
        public ?int $subjectId,
        public ?string $subjectLabel,
        public bool $subjectDeleted,
        public ?string $channel,
        public ?string $action,
        public array $touched,
    ) {
    }

    /** @return self Browser row for one typed reader item */
    public static function fromItem(ChangeLogFeedItem $item): self
    {
        $receipt = $item->receipt;
        $entry = $item->entry;
        $touched = [];
        if ($receipt !== null) {
            foreach ($receipt->touched as $table) {
                $touched[] = [
                    self::touchedTable => $table->table,
                    self::touchedRecords => $table->records,
                    self::touchedRecordKey => $table->single?->recordKey,
                    self::touchedMutation => $table->single?->mutation,
                    self::touchedChangedFields => $table->single?->changedFields,
                ];
            }
        } elseif ($entry !== null) {
            $touched[] = [
                self::touchedTable => $entry->table,
                self::touchedRecords => 1,
                self::touchedRecordKey => $entry->recordKey,
                self::touchedMutation => $entry->mutation,
                self::touchedChangedFields => count($entry->changes),
            ];
        }
        $actor = HilosChangeLogTableParts::person($receipt?->actor);
        $subject = HilosChangeLogTableParts::person($receipt?->subject);
        $id = $item->kind === ChangeLogFeedItemKind::RECEIPT ? $receipt?->id : $entry?->id;

        return new self(
            $item->kind->value . ':' . $id,
            $item->kind->value,
            $receipt?->id,
            $entry?->id,
            HilosChangeLogTableParts::displayTime($item->createdAt),
            $actor['id'],
            $actor['label'],
            $actor['deleted'],
            $subject['id'],
            $subject['label'],
            $subject['deleted'],
            $receipt?->channel,
            $receipt?->action,
            $touched,
        );
    }

    /** @return string Stable kind-prefixed row key */
    public function getRowKey(): string
    {
        return $this->rowKey;
    }

    /** @return string Wire field carrying the row key */
    public static function keyField(): string
    {
        return self::rowKey;
    }

    /** @return array<string, mixed> Feed slot payload */
    public function toArray(): array
    {
        return [
            self::rowKey => $this->rowKey,
            self::kind => $this->kind,
            self::receiptId => $this->receiptId,
            self::entryId => $this->entryId,
            self::createdAt => $this->createdAt,
            self::actorId => $this->actorId,
            self::actorLabel => $this->actorLabel,
            self::actorDeleted => $this->actorDeleted,
            self::subjectId => $this->subjectId,
            self::subjectLabel => $this->subjectLabel,
            self::subjectDeleted => $this->subjectDeleted,
            self::channel => $this->channel,
            self::action => $this->action,
            self::touched => $this->touched,
        ];
    }

    /**
     * @param array<string, mixed> $data Feed slot payload
     * @return static Reconstructed row
     * @throws InvalidFormatException When a field or touched item is malformed
     */
    public static function fromArray(array $data): static
    {
        $touched = self::requireArray($data, self::touched);
        if (!array_is_list($touched)) {
            throw new InvalidFormatException('Feed row carries no touched list');
        }
        $parsed = [];
        foreach ($touched as $item) {
            if (!is_array($item)) {
                throw new InvalidFormatException('Feed row carries a non-object touched item');
            }
            $parsed[] = [
                self::touchedTable => self::requireString($item, self::touchedTable),
                self::touchedRecords => self::requireInt($item, self::touchedRecords),
                self::touchedRecordKey => HilosChangeLogTableParts::readRecordKey($item[self::touchedRecordKey] ?? null),
                self::touchedMutation => self::optionalString($item, self::touchedMutation),
                self::touchedChangedFields => self::optionalInt($item, self::touchedChangedFields),
            ];
        }
        return new static(
            self::requireString($data, self::rowKey),
            self::requireString($data, self::kind),
            self::optionalInt($data, self::receiptId),
            self::optionalInt($data, self::entryId),
            self::requireString($data, self::createdAt),
            self::optionalInt($data, self::actorId),
            self::optionalString($data, self::actorLabel),
            self::requireBool($data, self::actorDeleted),
            self::optionalInt($data, self::subjectId),
            self::optionalString($data, self::subjectLabel),
            self::requireBool($data, self::subjectDeleted),
            self::optionalString($data, self::channel),
            self::optionalString($data, self::action),
            $parsed,
        );
    }
}
