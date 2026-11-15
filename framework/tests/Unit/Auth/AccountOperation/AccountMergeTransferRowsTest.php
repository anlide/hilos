<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\AccountOperation;

use Hilos\Auth\AccountOperation\AccountMergeTransferRows;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/** Strict, independent row selections for an account merge transfer. */
final class AccountMergeTransferRowsTest extends TestCase
{
    /** All four lists normalize their own ids in ascending order without duplicates. */
    public function testSelectionsAreNormalized(): void
    {
        $rows = new AccountMergeTransferRows(
            identities: [9, 3, 9, 4],
            passkeyCredentials: [8, 2, 8],
            secondFactors: [7, 1, 7, 5],
            secondFactorBackupCodes: [6, 4, 6],
        );

        self::assertSame([3, 4, 9], $rows->identities);
        self::assertSame([2, 8], $rows->passkeyCredentials);
        self::assertSame([1, 5, 7], $rows->secondFactors);
        self::assertSame([4, 6], $rows->secondFactorBackupCodes);
    }

    /** An omitted or explicitly empty selection authorizes no row. */
    public function testSelectionsMayAllBeEmpty(): void
    {
        foreach ([new AccountMergeTransferRows(), new AccountMergeTransferRows([], [], [], [])] as $rows) {
            self::assertSame([], $rows->identities);
            self::assertSame([], $rows->passkeyCredentials);
            self::assertSame([], $rows->secondFactors);
            self::assertSame([], $rows->secondFactorBackupCodes);
        }
    }

    /** The same primary id in different collections remains selected in each one. */
    public function testSelectionsStayIndependentOfEachOtherAndTheInput(): void
    {
        $ids = [3, 1, 3];
        $rows = new AccountMergeTransferRows(
            identities: $ids,
            passkeyCredentials: [1],
            secondFactors: $ids,
            secondFactorBackupCodes: [3],
        );
        self::assertSame([3, 1, 3], $ids);
        $ids[] = 9;

        self::assertSame([1, 3], $rows->identities);
        self::assertSame([1], $rows->passkeyCredentials);
        self::assertSame([1, 3], $rows->secondFactors);
        self::assertSame([3], $rows->secondFactorBackupCodes);
    }

    /** @return iterable<string, array{string, array}> Each list refuses invalid types, ids and array keys */
    public static function invalidSelections(): iterable
    {
        foreach ([
            'identities', 'passkeyCredentials', 'secondFactors', 'secondFactorBackupCodes',
        ] as $selection) {
            foreach ([
                'zero' => [0],
                'negative' => [-1],
                'string' => ['1'],
                'float' => [1.0],
                'boolean' => [true],
                'null' => [null],
                'array' => [[]],
                'object' => [new stdClass()],
                'non-list' => [1 => 2],
                'associative' => ['id' => 2],
            ] as $case => $ids) {
                yield "{$selection}, {$case}" => [$selection, $ids];
            }
        }
    }

    /**
     * @param string $selection Named constructor argument being checked
     * @param array $ids Invalid selection that must not be coerced
     */
    #[DataProvider('invalidSelections')]
    public function testInvalidSelectionIsRefused(string $selection, array $ids): void
    {
        $this->expectException(InvalidFormatException::class);

        new AccountMergeTransferRows(...[$selection => $ids]);
    }
}
