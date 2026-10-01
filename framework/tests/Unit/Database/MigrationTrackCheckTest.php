<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database;

use Hilos\Database\MigrationTrackCheck;
use PHPUnit\Framework\TestCase;

/**
 * The two ways a migration track goes unapplied in silence, judged without a database (HIL-1238).
 *
 * A number taken by two files of one direction is a duplicate, an up and a down file of one number
 * are a pair. A file is skipped when the `migration` table has no row for it and it lies strictly
 * between the lowest row and the level; the cases that decide that rule - a database a schema
 * archive restored before the rule, a failed row, an empty table - are staged here, because the
 * shared test database carries real rows and cannot stage them honestly.
 */
final class MigrationTrackCheckTest extends TestCase
{
    public function testTwoUpFilesOfOneNumberAreADuplicateNamedWithEveryFileOfIt(): void
    {
        $this->assertSame(
            [63 => [
                '063_add_legal_acceptance_document_index.sql',
                '063_add_legal_acceptance_document_index_down.sql',
                '063_create_hilos_file_variant.sql',
                '063_create_hilos_file_variant_down.sql',
            ]],
            MigrationTrackCheck::takenTwice([
                '.',
                '..',
                '062_create_hilos_legal_acceptance.sql',
                '063_create_hilos_file_variant_down.sql',
                '063_create_hilos_file_variant.sql',
                '063_add_legal_acceptance_document_index_down.sql',
                '063_add_legal_acceptance_document_index.sql',
                '064_move_user_to_hilos_user.sql',
            ]),
        );
    }

    public function testTwoDownFilesOfOneNumberAreADuplicate(): void
    {
        $this->assertSame(
            [7 => ['007_a_down.sql', '007_b_down.sql', '007_create.sql']],
            MigrationTrackCheck::takenTwice(['007_create.sql', '007_b_down.sql', '007_a_down.sql']),
        );
    }

    public function testAnUpAndADownFileOfOneNumberAreAPair(): void
    {
        $this->assertSame(
            [],
            MigrationTrackCheck::takenTwice(['001_create_users.sql', '001_create_users_down.sql', '002_add_index.sql']),
        );
    }

    public function testTheNamesTheCreateCommandWritesAreAPairToo(): void
    {
        $this->assertSame([], MigrationTrackCheck::takenTwice(['1_up.sql', '1_down.sql', '2_up.sql', '2_down.sql']));
    }

    public function testEveryDuplicatedNumberIsNamedInAscendingOrder(): void
    {
        $this->assertSame(
            [9 => ['9_a.sql', '9_b.sql'], 70 => ['070_x.sql', '070_y.sql']],
            MigrationTrackCheck::takenTwice(['070_y.sql', '9_b.sql', '070_x.sql', '9_a.sql', '010_z.sql']),
        );
    }

    public function testAnEmptyTrackHasNoDuplicate(): void
    {
        $this->assertSame([], MigrationTrackCheck::takenTwice([]));
    }

    public function testAFileWithoutARowBetweenTheLowestRowAndTheLevelIsSkipped(): void
    {
        $this->assertSame([59], MigrationTrackCheck::skipped([57, 58, 59, 60, 61], [57, 58, 60, 61], 61));
    }

    public function testEverySkippedFileIsNamedInAscendingOrder(): void
    {
        $this->assertSame([3, 5], MigrationTrackCheck::skipped([5, 4, 3, 2, 1, 6], [6, 4, 2, 1], 6));
    }

    public function testFilesBelowTheLowestRowAreHistoryARestoreDeclared(): void
    {
        // A schema archive restored before HIL-1238 declared its level with one row: 40 here.
        $this->assertSame([], MigrationTrackCheck::skipped(range(1, 42), [40, 41, 42], 42));
    }

    public function testAFailedRowBelowTheLevelIsNotASkip(): void
    {
        // The failed row 2 is not in the level, and is still a row: retry owns it, not this check.
        $this->assertSame([], MigrationTrackCheck::skipped([1, 2, 3], [1, 2, 3], 3));
    }

    public function testAnEmptyMigrationTableSkipsNothing(): void
    {
        $this->assertSame([], MigrationTrackCheck::skipped([1, 2, 3], [], 0));
    }

    public function testAFileAboveTheLevelIsPendingRatherThanSkipped(): void
    {
        $this->assertSame([], MigrationTrackCheck::skipped([1, 2, 3, 4], [1, 2], 2));
    }

    public function testAFileAtTheLevelIsNotSkipped(): void
    {
        // The bound is strict on both sides; only 2 lies between the lowest row 1 and the level 3.
        $this->assertSame([2], MigrationTrackCheck::skipped([1, 2, 3], [1], 3));
    }
}
