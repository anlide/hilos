<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database;

use Hilos\Database\Exception\SqlRuntime\ForeignKeyConstraintException;
use PHPUnit\Framework\TestCase;

/** The table a refused parent delete names, read from the MySQL 1451 text and nothing else (HIL-1942). */
final class ForeignKeyConstraintExceptionTest extends TestCase
{
    /** A refused parent delete names the first referencing table after the constraint words. */
    public function testRowIsReferencedNamesTheReferencingTable(): void
    {
        $refusal = self::refusal(
            1451,
            'Cannot delete or update a parent row: a foreign key constraint fails (`hilos_test`.`hilos_user_rename`, '
            . 'CONSTRAINT `fk_user_rename_user` FOREIGN KEY (`user_id`) REFERENCES `hilos_user` (`id`))',
        );

        self::assertSame('hilos_user_rename', $refusal->referencingTable());
    }

    /** A refused child insert names the parent it misses, never a referencing table. */
    public function testNoReferencedRowNamesNoTable(): void
    {
        $refusal = self::refusal(
            1452,
            'Cannot add or update a child row: a foreign key constraint fails (`hilos_test`.`hilos_user_rename`, '
            . 'CONSTRAINT `fk_user_rename_user` FOREIGN KEY (`user_id`) REFERENCES `hilos_user` (`id`))',
        );

        self::assertNull($refusal->referencingTable());
    }

    /** A 1451 whose text does not carry the constraint words and a quoted table names nothing. */
    public function testUnlikeTextNamesNoTable(): void
    {
        $refusal = self::refusal(1451, 'Cannot delete or update a parent row: a foreign key constraint fails');

        self::assertNull($refusal->referencingTable());
    }

    /** A table name with anything but letters, digits and underscores is not kept. */
    public function testUnsafeTableNameNamesNoTable(): void
    {
        $refusal = self::refusal(
            1451,
            'Cannot delete or update a parent row: a foreign key constraint fails (`hilos_test`.`user rename<b>`, '
            . 'CONSTRAINT `fk_user_rename_user` FOREIGN KEY (`user_id`) REFERENCES `hilos_user` (`id`))',
        );

        self::assertNull($refusal->referencingTable());
    }

    /**
     * @param int $code MySQL error code
     * @param string $text MySQL error message
     * @return ForeignKeyConstraintException Refusal as the exception mapper builds it
     */
    private static function refusal(int $code, string $text): ForeignKeyConstraintException
    {
        $refusal = new ForeignKeyConstraintException("MySQL Error [{$code}]: {$text}", $code);
        $refusal->setMysqlError($code, $text);

        return $refusal;
    }
}
