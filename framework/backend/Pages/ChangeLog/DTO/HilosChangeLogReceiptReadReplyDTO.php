<?php

declare(strict_types=1);

namespace Hilos\Pages\ChangeLog\DTO;

use Hilos\AdminViewMode\HiddenValue;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Pages\ChangeLog\HilosChangeLogDetailWire;

/** Receipt header and one portion of entries on the tracked action acknowledgement. */
final class HilosChangeLogReceiptReadReplyDTO extends ActionReplyDTO
{
    public const string receipt = 'receipt';
    public const string entries = 'entries';
    public const string nextAfterEntryId = 'nextAfterEntryId';

    /**
     * @param array<string, mixed> $receipt Serialized header, with hidden labels for a viewer
     * @param list<array<string, mixed>> $entries Full journal entries
     * @param ?int $nextAfterEntryId Last returned entry only when another portion exists
     */
    public function __construct(
        public readonly array $receipt,
        public readonly array $entries,
        public readonly ?int $nextAfterEntryId,
    ) {
    }

    /**
     * @param array<string, mixed> $data Reply payload
     * @return static Parsed receipt portion
     * @throws InvalidFormatException When the header, entries, or cursor is malformed
     */
    public static function fromArray(array $data): static
    {
        $entries = self::requireArray($data, self::entries);
        if (!array_is_list($entries)) {
            throw new InvalidFormatException('Receipt reply carries no entries list');
        }
        $parsed = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                throw new InvalidFormatException('Receipt reply carries a non-object entry');
            }
            $parsed[] = HilosChangeLogEntryReadReplyDTO::fromArray([HilosChangeLogEntryReadReplyDTO::entry => $entry])->entry;
        }
        $next = self::optionalInt($data, self::nextAfterEntryId);
        if ($next !== null && $next < 1) {
            throw new InvalidFormatException('Receipt reply carries an invalid cursor');
        }
        return new static(self::header(self::requireArray($data, self::receipt)), $parsed, $next);
    }

    /** @return array<string, mixed> Receipt portion on the action acknowledgement */
    public function toArray(): array
    {
        return [self::receipt => $this->receipt, self::entries => $this->entries, self::nextAfterEntryId => $this->nextAfterEntryId];
    }

    /**
     * @param array<string, mixed> $header Serialized receipt attribution
     * @return array<string, mixed> Header preserving the viewer's hidden labels
     * @throws InvalidFormatException When a header field is absent or mistyped
     */
    private static function header(array $header): array
    {
        return [
            HilosChangeLogDetailWire::receiptId => self::requireInt($header, HilosChangeLogDetailWire::receiptId),
            HilosChangeLogDetailWire::createdAt => self::requireString($header, HilosChangeLogDetailWire::createdAt),
            HilosChangeLogDetailWire::actorId => self::optionalInt($header, HilosChangeLogDetailWire::actorId),
            HilosChangeLogDetailWire::actorLabel => self::personLabel($header, HilosChangeLogDetailWire::actorLabel),
            HilosChangeLogDetailWire::actorDeleted => self::requireBool($header, HilosChangeLogDetailWire::actorDeleted),
            HilosChangeLogDetailWire::subjectId => self::optionalInt($header, HilosChangeLogDetailWire::subjectId),
            HilosChangeLogDetailWire::subjectLabel => self::personLabel($header, HilosChangeLogDetailWire::subjectLabel),
            HilosChangeLogDetailWire::subjectDeleted => self::requireBool($header, HilosChangeLogDetailWire::subjectDeleted),
            HilosChangeLogDetailWire::sessionId => self::optionalInt($header, HilosChangeLogDetailWire::sessionId),
            HilosChangeLogDetailWire::channel => self::requireString($header, HilosChangeLogDetailWire::channel),
            HilosChangeLogDetailWire::action => self::requireString($header, HilosChangeLogDetailWire::action),
            HilosChangeLogDetailWire::agent => self::optionalString($header, HilosChangeLogDetailWire::agent),
            HilosChangeLogDetailWire::source => self::optionalString($header, HilosChangeLogDetailWire::source),
            HilosChangeLogDetailWire::entryCount => self::requireInt($header, HilosChangeLogDetailWire::entryCount),
        ];
    }

    /**
     * @param array<string, mixed> $header Receipt attribution
     * @param string $key Person label key
     * @return string|array|null Current name, deleted-person label, null, or the hidden mark
     * @throws InvalidFormatException When the value is neither a label nor the hidden mark
     */
    private static function personLabel(array $header, string $key): string|array|null
    {
        return HiddenValue::isMark($header[$key] ?? null) ? HiddenValue::mark() : self::optionalString($header, $key);
    }
}
