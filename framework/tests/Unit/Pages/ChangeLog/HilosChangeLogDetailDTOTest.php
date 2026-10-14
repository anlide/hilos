<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Pages\ChangeLog;

use DateTimeImmutable;
use Hilos\AdminViewMode\HiddenValue;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Database\ChangeLog\Section\ChangeLogEntry;
use Hilos\Database\ChangeLog\Section\ChangeLogFieldChange;
use Hilos\Database\ChangeLog\Section\ChangeLogPerson;
use Hilos\Database\ChangeLog\Section\ChangeLogReceipt;
use Hilos\Pages\ChangeLog\DTO\HilosChangeLogEntryReadActionDTO;
use Hilos\Pages\ChangeLog\DTO\HilosChangeLogEntryReadReplyDTO;
use Hilos\Pages\ChangeLog\DTO\HilosChangeLogReceiptReadActionDTO;
use Hilos\Pages\ChangeLog\DTO\HilosChangeLogReceiptReadReplyDTO;
use Hilos\Pages\ChangeLog\HilosChangeLogDetailWire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Pins the approved detail shapes and their strict request parsing. */
final class HilosChangeLogDetailDTOTest extends TestCase
{
    public function testRequestsRoundtripWithOmittedAndExplicitCursors(): void
    {
        $first = HilosChangeLogReceiptReadActionDTO::fromArray(['receiptId' => 12]);
        self::assertNull($first->afterEntryId);
        self::assertSame(['receiptId' => 12, 'afterEntryId' => null], $first->toArray());
        self::assertSame($first->toArray(), HilosChangeLogReceiptReadActionDTO::fromArray($first->toArray())->toArray());
        self::assertSame(HilosSignalConstants::HILOS_CHANGE_LOG_RECEIPT_READ, $first->getAction());
        $next = HilosChangeLogReceiptReadActionDTO::fromArray(['receiptId' => 12, 'afterEntryId' => 51]);
        self::assertSame(51, $next->afterEntryId);
        self::assertSame($next->toArray(), HilosChangeLogReceiptReadActionDTO::fromJson($next->toJson())->toArray());
        $entry = HilosChangeLogEntryReadActionDTO::fromArray(['entryId' => 52]);
        self::assertSame(['entryId' => 52], $entry->toArray());
        self::assertSame($entry->toArray(), HilosChangeLogEntryReadActionDTO::fromArray($entry->toArray())->toArray());
        self::assertSame(HilosSignalConstants::HILOS_CHANGE_LOG_ENTRY_READ, $entry->getAction());
        self::assertSame([], HilosChangeLogReceiptReadActionDTO::SECRET_FIELDS);
        self::assertSame([], HilosChangeLogEntryReadActionDTO::SECRET_FIELDS);
    }

    #[DataProvider('invalidRequests')]
    public function testInvalidRequestAddressesAreRefused(string $dtoClass, array $payload): void
    {
        $this->expectException(InvalidFormatException::class);
        $dtoClass::fromArray($payload);
    }

    /** @return iterable<string, array{class-string, array<string, mixed>}> Missing and non-integer addresses */
    public static function invalidRequests(): iterable
    {
        foreach ([null, false, '1', 1.0, 0, -1, [], new DateTimeImmutable()] as $number => $value) {
            yield 'receipt ' . $number => [HilosChangeLogReceiptReadActionDTO::class, ['receiptId' => $value]];
            yield 'entry ' . $number => [HilosChangeLogEntryReadActionDTO::class, ['entryId' => $value]];
            if ($value !== null) {
                yield 'cursor ' . $number => [HilosChangeLogReceiptReadActionDTO::class, ['receiptId' => 1, 'afterEntryId' => $value]];
            }
        }
        yield 'receipt absent' => [HilosChangeLogReceiptReadActionDTO::class, []];
        yield 'entry absent' => [HilosChangeLogEntryReadActionDTO::class, []];
    }

    public function testFullEntryRoundtripPreservesNullPresenceAndAllFieldKinds(): void
    {
        $entry = HilosChangeLogDetailWire::entry(new ChangeLogEntry(
            51, new DateTimeImmutable('2026-10-14 12:13:14.123456+02:00'), 12, 'bot', [3, 'key', null], 'update', [
                new ChangeLogFieldChange('style', 'inline', true, true, null, 'friendly', false),
                new ChangeLogFieldChange('name', 'fact', true, true, null, null, false),
                new ChangeLogFieldChange('description', 'long', true, true, str_repeat('before', 500), str_repeat('after', 500), false),
                new ChangeLogFieldChange('nullable', 'inline', true, false, null, null, false),
            ],
        ));
        self::assertSame('2026-10-14T10:13:14.123Z', $entry['createdAt']);
        self::assertSame(['entryId', 'createdAt', 'receiptId', 'table', 'recordKey', 'mutation', 'changes'], array_keys($entry));
        $payload = ['entry' => $entry];
        self::assertSame($payload, HilosChangeLogEntryReadReplyDTO::fromArray($payload)->toArray());
        self::assertSame($payload, HilosChangeLogEntryReadReplyDTO::fromJson(
            new HilosChangeLogEntryReadReplyDTO($entry)->toJson(),
        )->toArray());
        self::assertTrue($entry['changes'][0]['newPresent']);
        self::assertFalse($entry['changes'][3]['newPresent']);
        self::assertNull($entry['changes'][0]['oldValue']);
        self::assertNull($entry['changes'][3]['newValue']);
    }

    public function testReceiptHeaderAndHiddenLabelsRoundtrip(): void
    {
        $header = HilosChangeLogDetailWire::receipt(new ChangeLogReceipt(
            12, new DateTimeImmutable('2026-10-14T10:13:14.123456Z'),
            new ChangeLogPerson(7, 'Current actor', false), new ChangeLogPerson(8, 'Deleted user #8', true),
            9, 'web', 'bot.update', 'library', 'session #9', 2, [],
        ));
        self::assertSame([
            'receiptId' => 12, 'createdAt' => '2026-10-14T10:13:14.123Z',
            'actorId' => 7, 'actorLabel' => 'Current actor', 'actorDeleted' => false,
            'subjectId' => 8, 'subjectLabel' => 'Deleted user #8', 'subjectDeleted' => true,
            'sessionId' => 9, 'channel' => 'web', 'action' => 'bot.update', 'agent' => 'library',
            'source' => 'session #9', 'entryCount' => 2,
        ], $header);
        $entry = HilosChangeLogDetailWire::entry(new ChangeLogEntry(
            51, new DateTimeImmutable('2026-10-14T10:13:14Z'), 12, 'bot', null, 'delete', [],
        ));
        foreach ([false, true] as $hidden) {
            if ($hidden) {
                $header['actorLabel'] = HiddenValue::mark();
                $header['subjectLabel'] = HiddenValue::mark();
            }
            $reply = new HilosChangeLogReceiptReadReplyDTO($header, [$entry], 51);
            self::assertSame($reply->toArray(), HilosChangeLogReceiptReadReplyDTO::fromArray($reply->toArray())->toArray());
            self::assertSame($reply->toArray(), HilosChangeLogReceiptReadReplyDTO::fromJson($reply->toJson())->toArray());
        }
        self::assertSame(array_keys($header), array_keys(HilosChangeLogDetailWire::receiptFields()));
    }

    public function testBareCreateAndMaskedRecordKeysRoundtrip(): void
    {
        foreach ([null, [null], [1, null]] as $key) {
            $entry = HilosChangeLogDetailWire::entry(new ChangeLogEntry(
                51, new DateTimeImmutable('2026-10-14T10:13:14Z'), null, 'hilos_user', $key, 'create', [],
            ));
            $reply = new HilosChangeLogEntryReadReplyDTO($entry);
            self::assertSame($reply->toArray(), HilosChangeLogEntryReadReplyDTO::fromArray($reply->toArray())->toArray());
        }
        $header = HilosChangeLogDetailWire::receipt(new ChangeLogReceipt(
            12, new DateTimeImmutable('2026-10-14T10:13:14Z'), null, null, null, 'migration', 'migration.up.95', null, '95.sql', 1, [],
        ));
        $reply = new HilosChangeLogReceiptReadReplyDTO($header, [], null);
        self::assertSame($reply->toArray(), HilosChangeLogReceiptReadReplyDTO::fromArray($reply->toArray())->toArray());
    }

    #[DataProvider('invalidReplies')]
    public function testReplyReadersRejectMalformedShapes(string $dtoClass, array $payload): void
    {
        $this->expectException(InvalidFormatException::class);
        $dtoClass::fromArray($payload);
    }

    /** @return iterable<string, array{class-string, array<string, mixed>}> Malformed detail replies */
    public static function invalidReplies(): iterable
    {
        $entry = ['entryId' => 1, 'createdAt' => '2026-10-14T10:13:14Z', 'receiptId' => null,
            'table' => 'bot', 'recordKey' => [1], 'mutation' => 'update', 'changes' => []];
        foreach (['entryId' => '1', 'recordKey' => [true], 'mutation' => 'unknown', 'changes' => [false]] as $key => $value) {
            yield $key => [HilosChangeLogEntryReadReplyDTO::class, ['entry' => array_replace($entry, [$key => $value])]];
        }
        yield 'missing entry' => [HilosChangeLogEntryReadReplyDTO::class, []];
        yield 'missing record key' => [HilosChangeLogEntryReadReplyDTO::class, ['entry' => array_diff_key($entry, ['recordKey' => true])]];
        yield 'invalid cursor' => [HilosChangeLogReceiptReadReplyDTO::class, ['entries' => [], 'nextAfterEntryId' => 0]];
        yield 'mistyped cursor' => [HilosChangeLogReceiptReadReplyDTO::class, ['entries' => [], 'nextAfterEntryId' => '1']];
        yield 'invalid entries' => [HilosChangeLogReceiptReadReplyDTO::class, ['entries' => [false]]];
        yield 'missing header' => [HilosChangeLogReceiptReadReplyDTO::class, ['entries' => [], 'nextAfterEntryId' => null]];
    }
}
