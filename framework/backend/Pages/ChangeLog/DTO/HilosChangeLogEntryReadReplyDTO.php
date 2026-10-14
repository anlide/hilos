<?php

declare(strict_types=1);

namespace Hilos\Pages\ChangeLog\DTO;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Pages\ChangeLog\HilosChangeLogDetailWire;
use Hilos\Tables\ChangeLog\HilosChangeLogHistoryTableRow;
use Hilos\Tables\ChangeLog\HilosChangeLogTableParts;

/** Full entry on the existing tracked action acknowledgement. */
final class HilosChangeLogEntryReadReplyDTO extends ActionReplyDTO
{
    public const string entry = 'entry';

    private const array MUTATIONS = ['create', 'update', 'delete'];
    private const array CHANGE_KINDS = ['inline', 'fact', 'long'];

    /** @param array<string, mixed> $entry Serialized full entry */
    public function __construct(public readonly array $entry)
    {
    }

    /**
     * @param array<string, mixed> $data Reply payload
     * @return static Parsed full entry
     * @throws InvalidFormatException When an entry field or change is malformed
     */
    public static function fromArray(array $data): static
    {
        $entry = self::requireArray($data, self::entry);
        $changes = self::requireArray($entry, HilosChangeLogDetailWire::changes);
        if (!array_is_list($changes)) {
            throw new InvalidFormatException('Entry carries no changes list');
        }
        $parsed = [];
        foreach ($changes as $change) {
            if (!is_array($change)) {
                throw new InvalidFormatException('Entry carries a non-object field change');
            }
            $parsed[] = self::fieldChange($change);
        }
        $entryId = self::requireInt($entry, HilosChangeLogDetailWire::entryId);
        $receiptId = self::optionalInt($entry, HilosChangeLogDetailWire::receiptId);
        $mutation = self::requireString($entry, HilosChangeLogDetailWire::mutation);
        if ($entryId < 1 || ($receiptId !== null && $receiptId < 1) || !in_array($mutation, self::MUTATIONS, true)) {
            throw new InvalidFormatException('Entry carries an invalid address or mutation');
        }
        return new static([
            HilosChangeLogDetailWire::entryId => $entryId,
            HilosChangeLogDetailWire::createdAt => self::requireString($entry, HilosChangeLogDetailWire::createdAt),
            HilosChangeLogDetailWire::receiptId => $receiptId,
            HilosChangeLogDetailWire::table => self::requireString($entry, HilosChangeLogDetailWire::table),
            HilosChangeLogDetailWire::recordKey => HilosChangeLogTableParts::readRecordKey(
                self::requireNullableArray($entry, HilosChangeLogDetailWire::recordKey),
            ),
            HilosChangeLogDetailWire::mutation => $mutation,
            HilosChangeLogDetailWire::changes => $parsed,
        ]);
    }

    /** @return array<string, mixed> Entry reply on the action acknowledgement */
    public function toArray(): array
    {
        return [self::entry => $this->entry];
    }

    /**
     * @param array<string, mixed> $change Serialized field change
     * @return array<string, mixed> Typed values with distinct absent and SQL NULL sides
     * @throws InvalidFormatException When a field is mistyped or its kind is unknown
     */
    private static function fieldChange(array $change): array
    {
        $kind = self::requireString($change, HilosChangeLogHistoryTableRow::changeKind);
        if (!in_array($kind, self::CHANGE_KINDS, true)) {
            throw new InvalidFormatException('Entry carries an unknown field change kind');
        }
        return [
            HilosChangeLogHistoryTableRow::changeField => self::requireString($change, HilosChangeLogHistoryTableRow::changeField),
            HilosChangeLogHistoryTableRow::changeKind => $kind,
            HilosChangeLogHistoryTableRow::changeOldPresent => self::requireBool($change, HilosChangeLogHistoryTableRow::changeOldPresent),
            HilosChangeLogHistoryTableRow::changeNewPresent => self::requireBool($change, HilosChangeLogHistoryTableRow::changeNewPresent),
            HilosChangeLogHistoryTableRow::changeOldValue => self::optionalString($change, HilosChangeLogHistoryTableRow::changeOldValue),
            HilosChangeLogHistoryTableRow::changeNewValue => self::optionalString($change, HilosChangeLogHistoryTableRow::changeNewValue),
            HilosChangeLogHistoryTableRow::changeBodyOmitted => self::requireBool($change, HilosChangeLogHistoryTableRow::changeBodyOmitted),
        ];
    }
}
