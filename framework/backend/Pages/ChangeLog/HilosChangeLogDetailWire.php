<?php

declare(strict_types=1);

namespace Hilos\Pages\ChangeLog;

use Hilos\AdminViewMode\WireField;
use Hilos\Database\ChangeLog\Section\ChangeLogEntry;
use Hilos\Database\ChangeLog\Section\ChangeLogReceipt;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Object\Item\User as ObjectUser;
use Hilos\Tables\ChangeLog\HilosChangeLogHistoryTableRow;
use Hilos\Tables\ChangeLog\HilosChangeLogTableParts;

/** Serializes typed journal detail at the action-reply boundary. */
final class HilosChangeLogDetailWire
{
    public const string receiptId = 'receiptId';
    public const string createdAt = 'createdAt';
    public const string actorId = 'actorId';
    public const string actorLabel = 'actorLabel';
    public const string actorDeleted = 'actorDeleted';
    public const string subjectId = 'subjectId';
    public const string subjectLabel = 'subjectLabel';
    public const string subjectDeleted = 'subjectDeleted';
    public const string sessionId = 'sessionId';
    public const string channel = 'channel';
    public const string action = 'action';
    public const string agent = 'agent';
    public const string source = 'source';
    public const string entryCount = 'entryCount';

    public const string entryId = 'entryId';
    public const string table = 'table';
    public const string recordKey = 'recordKey';
    public const string mutation = 'mutation';
    public const string changes = 'changes';

    /**
     * @param ChangeLogReceipt $receipt Header reread for this request
     * @return array<string, mixed> Current attribution and journal entry count
     */
    public static function receipt(ChangeLogReceipt $receipt): array
    {
        $actor = HilosChangeLogTableParts::person($receipt->actor);
        $subject = HilosChangeLogTableParts::person($receipt->subject);
        return [
            self::receiptId => $receipt->id,
            self::createdAt => HilosChangeLogTableParts::displayTime($receipt->createdAt),
            self::actorId => $actor['id'],
            self::actorLabel => $actor['label'],
            self::actorDeleted => $actor['deleted'],
            self::subjectId => $subject['id'],
            self::subjectLabel => $subject['label'],
            self::subjectDeleted => $subject['deleted'],
            self::sessionId => $receipt->sessionId,
            self::channel => $receipt->channel,
            self::action => $receipt->action,
            self::agent => $receipt->agent,
            self::source => $receipt->source,
            self::entryCount => $receipt->entryCount,
        ];
    }

    /** @return array<string, WireField> Header origins, with person labels judged by their column */
    public static function receiptFields(): array
    {
        return [
            self::receiptId => WireField::notPersonal(),
            self::createdAt => WireField::notPersonal(),
            self::actorId => WireField::notPersonal(),
            self::actorLabel => WireField::column(HilosDbContext::users, ObjectUser::name),
            self::actorDeleted => WireField::notPersonal(),
            self::subjectId => WireField::notPersonal(),
            self::subjectLabel => WireField::column(HilosDbContext::users, ObjectUser::name),
            self::subjectDeleted => WireField::notPersonal(),
            self::sessionId => WireField::notPersonal(),
            self::channel => WireField::notPersonal(),
            self::action => WireField::notPersonal(),
            self::agent => WireField::notPersonal(),
            self::source => WireField::notPersonal(),
            self::entryCount => WireField::notPersonal(),
        ];
    }

    /**
     * Personal fields arrive as facts; secrets and noise were omitted by journal policy.
     *
     * @param ChangeLogEntry $entry Full reader entry, including long bodies
     * @return array<string, mixed> One entry with presence flags and complete field values
     */
    public static function entry(ChangeLogEntry $entry): array
    {
        $changes = [];
        foreach ($entry->changes as $change) {
            $changes[] = [
                HilosChangeLogHistoryTableRow::changeField => $change->field,
                HilosChangeLogHistoryTableRow::changeKind => $change->kind,
                HilosChangeLogHistoryTableRow::changeOldPresent => $change->oldPresent,
                HilosChangeLogHistoryTableRow::changeNewPresent => $change->newPresent,
                HilosChangeLogHistoryTableRow::changeOldValue => $change->oldValue,
                HilosChangeLogHistoryTableRow::changeNewValue => $change->newValue,
                HilosChangeLogHistoryTableRow::changeBodyOmitted => $change->bodyOmitted,
            ];
        }
        return [
            self::entryId => $entry->id,
            self::createdAt => HilosChangeLogTableParts::displayTime($entry->createdAt),
            self::receiptId => $entry->receiptId,
            self::table => $entry->table,
            self::recordKey => $entry->recordKey,
            self::mutation => $entry->mutation,
            self::changes => $changes,
        ];
    }
}
