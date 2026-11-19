<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Users;

use Hilos\AdminViewMode\ViewerFields;
use Hilos\AdminViewMode\WireField;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Legal\LegalSettings;
use Hilos\Tables\Users\HilosMergeCandidateTableRow;
use Hilos\Tables\Users\HilosUserTableRow;
use Hilos\Users\AccountMergeAvailability;
use Hilos\Users\AccountStandingResolver;
use Hilos\Users\DTO\AccountStandingStateSignalData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Strict occupancy wire boundary, including raw readers whose occupancy is still unknown. */
final class AccountMergeAvailabilityTest extends TestCase
{
    public function testBothBooleanFactsSurviveTheExactRoundTrip(): void
    {
        foreach ([false, true] as $reserved) {
            foreach ([false, true] as $available) {
                $data = ['reserved' => $reserved, 'available' => $available];
                self::assertSame($data, AccountMergeAvailability::fromArray($data)->toArray());
            }
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> Missing, mistyped and extra facts */
    public static function invalid(): iterable
    {
        yield 'absent' => [[]];
        yield 'missing reserved' => [['available' => true]];
        yield 'missing available' => [['reserved' => false]];
        yield 'null' => [['reserved' => null, 'available' => true]];
        yield 'integer' => [['reserved' => false, 'available' => 1]];
        yield 'string' => [['reserved' => 'false', 'available' => true]];
        yield 'extra' => [['reserved' => false, 'available' => true, 'operationId' => 7]];
    }

    /** @param array<string, mixed> $data Invalid public projection */
    #[DataProvider('invalid')]
    public function testInvalidFactsNeverBecomePermissionToChoose(array $data): void
    {
        $this->expectException(InvalidFormatException::class);
        AccountMergeAvailability::fromArray($data);
    }

    public function testRowsKeepTypedAvailabilityOutOfCandidateUserFields(): void
    {
        $availability = new AccountMergeAvailability(true, false);
        $user = new HilosUserTableRow(7, accountMergeAvailability: $availability);
        $candidate = new HilosMergeCandidateTableRow([HilosUserTableRow::id => 7], [], false, false,
            accountMergeAvailability: $availability);
        $restoredUser = HilosUserTableRow::fromArray($user->toArray());
        $restoredCandidate = HilosMergeCandidateTableRow::fromArray($candidate->toArray());
        self::assertSame(['reserved' => true, 'available' => false], $restoredUser->accountMergeAvailability->toArray());
        self::assertSame($candidate->toArray(), $restoredCandidate->toArray());
        self::assertArrayNotHasKey(HilosUserTableRow::FIELD_ACCOUNT_MERGE_AVAILABILITY, $restoredCandidate->userFields);
        self::assertSame(7, $restoredCandidate->getRowKey());
    }

    public function testAbsentOrNullRawAvailabilityRemainsUnknown(): void
    {
        foreach ([new HilosUserTableRow(7), new HilosMergeCandidateTableRow(['id' => 7], [], false, false)] as $row) {
            $data = $row->toArray();
            self::assertNull($row::fromArray($data)->accountMergeAvailability);
            unset($data[HilosUserTableRow::FIELD_ACCOUNT_MERGE_AVAILABILITY]);
            self::assertNull($row::fromArray($data)->accountMergeAvailability);
        }
    }

    public function testPublicNestedFactsSurviveViewerFiltering(): void
    {
        $data = ['accountMergeAvailability' => ['reserved' => true, 'available' => false]];
        $fields = [HilosUserTableRow::FIELD_ACCOUNT_MERGE_AVAILABILITY => WireField::each(AccountMergeAvailability::wireFields())];
        self::assertSame(['reserved', 'available'], array_keys(AccountMergeAvailability::wireFields()));
        self::assertSame($data, ViewerFields::hide($data, $fields, static fn (string $collection, string $field): bool => false));
    }

    public function testCardCarrierKeepsOldArgumentsAndRoundTripsNullableNestedAvailability(): void
    {
        $standing = AccountStandingResolver::compose(false, null, null, false, null, [], [], LegalSettings::REFUSAL_FREEZE)->toArray();
        $old = new AccountStandingStateSignalData(7, $standing, null, null);
        $oldWire = $old->toArray();
        unset($oldWire['accountMergeAvailability']);
        self::assertNull(AccountStandingStateSignalData::fromArray($oldWire)->accountMergeAvailability);
        foreach ([null, ['reserved' => true, 'available' => false], ['reserved' => false, 'available' => true]] as $availability) {
            $frame = new AccountStandingStateSignalData(7, $standing, null, null, $availability);
            self::assertSame($availability, $frame->toArray()['accountMergeAvailability']);
            self::assertSame($frame->toArray(), AccountStandingStateSignalData::fromJson($frame->toJson())->toArray());
        }
        self::assertEquals(AccountMergeAvailability::wireFields(),
            AccountStandingStateSignalData::wireFields()['accountMergeAvailability']->fields);
    }
}
