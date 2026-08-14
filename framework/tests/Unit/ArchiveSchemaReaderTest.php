<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\Anonymization\ArchiveSchemaReader;
use Hilos\Backup\Exception\RestoreFailedException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for reading the table names out of an archive's dump text.
 *
 * The dump fixtures are shaped the way mysqldump writes them - the noise lines, the
 * trailing `ENGINE=` clause, the key clauses after the columns - because that shape is
 * the reader's whole premise: it parses a known writer's output, not arbitrary SQL.
 */
final class ArchiveSchemaReaderTest extends TestCase
{
    public function testATableBlockIsReadByName(): void
    {
        $tables = ArchiveSchemaReader::parse(
            "CREATE TABLE `hilos_identity` (\n"
            . "  `id` int NOT NULL AUTO_INCREMENT,\n"
            . "  `email` varchar(255) NOT NULL,\n"
            . "  `phone` varchar(32) DEFAULT NULL,\n"
            . "  PRIMARY KEY (`id`)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n",
        );

        $this->assertSame(['hilos_identity'], $tables);
    }

    public function testEveryTableOfADumpIsRead(): void
    {
        $tables = ArchiveSchemaReader::parse(
            "-- MySQL dump 10.13\n"
            . "/*!40101 SET @saved_cs_client = @@character_set_client */;\n"
            . "DROP TABLE IF EXISTS `first`;\n"
            . "CREATE TABLE `first` (\n"
            . "  `id` int NOT NULL,\n"
            . "  PRIMARY KEY (`id`)\n"
            . ") ENGINE=InnoDB;\n"
            . "INSERT INTO `first` VALUES (1);\n"
            . "DROP TABLE IF EXISTS `second`;\n"
            . "CREATE TABLE `second` (\n"
            . "  `id` int NOT NULL,\n"
            . "  PRIMARY KEY (`id`)\n"
            . ") ENGINE=InnoDB;\n",
        );

        $this->assertSame(['first', 'second'], $tables);
    }

    public function testAnUnclosedBlockCarriesNoTable(): void
    {
        // A truncated dump is a corrupt archive, and the coverage gate must not read one as
        // a table that happens to be classified; the block only counts once it closes.
        $tables = ArchiveSchemaReader::parse(
            "CREATE TABLE `closed` (\n"
            . "  `id` int NOT NULL\n"
            . ") ENGINE=InnoDB;\n"
            . "CREATE TABLE `truncated` (\n"
            . "  `id` int NOT NULL\n",
        );

        $this->assertSame(['closed'], $tables);
    }

    public function testTheStreamedFileReadSeesWhatTheTextParseSees(): void
    {
        // Production only ever calls read(), which walks the file a line at a time rather than
        // holding it; every other case here goes through parse(), so without this one the
        // streaming loop would be uncovered.
        $sql = "CREATE TABLE `streamed` (\n"
            . "  `id` int NOT NULL,\n"
            . "  `email` varchar(255) DEFAULT NULL,\n"
            . "  PRIMARY KEY (`id`)\n"
            . ") ENGINE=InnoDB;\n"
            . 'INSERT INTO `streamed` VALUES ' . implode(', ', array_map(
                static fn (int $row): string => "({$row}, 'row{$row}@example.test')",
                range(1, 200),
            )) . ";\n";
        $path = (string)tempnam(sys_get_temp_dir(), 'hilos-dump-');
        file_put_contents($path, $sql);

        try {
            $streamed = ArchiveSchemaReader::read($path);
        } finally {
            unlink($path);
        }

        $this->assertSame(['streamed'], $streamed);
        $this->assertSame(ArchiveSchemaReader::parse($sql), $streamed);
    }

    public function testAMissingDumpFileIsARefusal(): void
    {
        $this->expectException(RestoreFailedException::class);

        ArchiveSchemaReader::read(sys_get_temp_dir() . '/hilos-no-such-dump-' . getmypid() . '.sql');
    }
}
