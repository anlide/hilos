<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\Anonymization\ArchiveSchemaReader;
use Hilos\Backup\Anonymization\ArchiveTableSchema;
use Hilos\Backup\Exception\RestoreFailedException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for reading table schemas out of an archive's dump text.
 *
 * The dump fixtures are shaped the way mysqldump writes them - the noise lines, the
 * trailing `ENGINE=` clause, the key clauses after the columns - because that shape is
 * the reader's whole premise: it parses a known writer's output, not arbitrary SQL.
 */
final class ArchiveSchemaReaderTest extends TestCase
{
    public function testColumnsCarryTheirNullability(): void
    {
        $schema = $this->onlySchema(
            "CREATE TABLE `hilos_identity` (\n"
            . "  `id` int NOT NULL AUTO_INCREMENT,\n"
            . "  `email` varchar(255) NOT NULL,\n"
            . "  `phone` varchar(32) DEFAULT NULL,\n"
            . "  PRIMARY KEY (`id`)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n",
        );

        $this->assertSame('hilos_identity', $schema->table);
        $this->assertSame(['id' => false, 'email' => false, 'phone' => true], $schema->columns);
        $this->assertTrue($schema->isNullable('phone'));
        $this->assertFalse($schema->isNullable('email'));
    }

    public function testOnlyLengthBearingTypesReportALength(): void
    {
        $schema = $this->onlySchema(
            "CREATE TABLE `hilos_session` (\n"
            . "  `id` int NOT NULL,\n"
            . "  `token` char(64) NOT NULL,\n"
            . "  `label` varchar(32) DEFAULT NULL,\n"
            . "  `rate` decimal(10,2) NOT NULL,\n"
            . "  `payload` text,\n"
            . "  PRIMARY KEY (`id`)\n"
            . ") ENGINE=InnoDB;\n",
        );

        $this->assertSame(64, $schema->lengthOf('token'));
        $this->assertSame(32, $schema->lengthOf('label'));
        // A display width and a precision are not character lengths, and truncating a
        // hash to one of them would be arithmetic dressed as a length.
        $this->assertNull($schema->lengthOf('id'));
        $this->assertNull($schema->lengthOf('rate'));
        $this->assertNull($schema->lengthOf('payload'));
    }

    public function testCompositePrimaryKeyIsReadInKeyOrder(): void
    {
        $schema = $this->onlySchema(
            "CREATE TABLE `hilos_notification_preference` (\n"
            . "  `user_id` int NOT NULL,\n"
            . "  `channel` varchar(32) NOT NULL,\n"
            . "  PRIMARY KEY (`user_id`,`channel`)\n"
            . ") ENGINE=InnoDB;\n",
        );

        $this->assertSame(['user_id', 'channel'], $schema->primaryKey);
        $this->assertNull($schema->singlePrimaryKey());
    }

    public function testTableWithoutPrimaryKeyReportsNone(): void
    {
        $schema = $this->onlySchema(
            "CREATE TABLE `hilos_change_log` (\n"
            . "  `entity` varchar(64) NOT NULL,\n"
            . "  KEY `idx_entity` (`entity`)\n"
            . ") ENGINE=InnoDB;\n",
        );

        $this->assertSame([], $schema->primaryKey);
        $this->assertNull($schema->singlePrimaryKey());
        $this->assertSame(['entity' => false], $schema->columns);
    }

    public function testKeyAndConstraintClausesAreNotColumns(): void
    {
        $schema = $this->onlySchema(
            "CREATE TABLE `hilos_session` (\n"
            . "  `id` int NOT NULL,\n"
            . "  `identity_id` int NOT NULL,\n"
            . "  `token` varchar(128) NOT NULL,\n"
            . "  PRIMARY KEY (`id`),\n"
            . "  UNIQUE KEY `token` (`token`),\n"
            . "  KEY `idx_identity` (`identity_id`),\n"
            . "  CONSTRAINT `fk_identity` FOREIGN KEY (`identity_id`) REFERENCES `hilos_identity` (`id`)\n"
            . ") ENGINE=InnoDB;\n",
        );

        $this->assertSame(['id', 'identity_id', 'token'], array_keys($schema->columns));
    }

    public function testAQuotedDefaultCannotFakeNullability(): void
    {
        $schema = $this->onlySchema(
            "CREATE TABLE `hilos_setting` (\n"
            . "  `value` varchar(64) DEFAULT 'NOT NULL' COMMENT 'not null by default',\n"
            . ") ENGINE=InnoDB;\n",
        );

        $this->assertTrue($schema->isNullable('value'), 'Only the declaration itself may say NOT NULL');
    }

    public function testEveryTableOfADumpIsRead(): void
    {
        $schemas = ArchiveSchemaReader::parse(
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

        $this->assertSame(['first', 'second'], array_map(
            static fn (ArchiveTableSchema $schema): string => $schema->table,
            $schemas,
        ));
    }

    public function testAPrimaryKeyOfAnEarlierTableDoesNotLeakIntoTheNext(): void
    {
        $schemas = ArchiveSchemaReader::parse(
            "CREATE TABLE `keyed` (\n"
            . "  `id` int NOT NULL,\n"
            . "  PRIMARY KEY (`id`)\n"
            . ") ENGINE=InnoDB;\n"
            . "CREATE TABLE `keyless` (\n"
            . "  `label` varchar(8) NOT NULL\n"
            . ") ENGINE=InnoDB;\n",
        );

        $this->assertSame(['id'], $schemas[0]->primaryKey);
        $this->assertSame([], $schemas[1]->primaryKey);
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

        $this->assertEquals(ArchiveSchemaReader::parse($sql), $streamed);
        $this->assertSame(['id' => false, 'email' => true], $streamed[0]->columns);
        $this->assertSame(['id'], $streamed[0]->primaryKey);
    }

    public function testAMissingDumpFileIsARefusal(): void
    {
        $this->expectException(RestoreFailedException::class);

        ArchiveSchemaReader::read(sys_get_temp_dir() . '/hilos-no-such-dump-' . getmypid() . '.sql');
    }

    /**
     * @param string $sql Dump text carrying exactly one table
     * @return ArchiveTableSchema The single table the text declares
     */
    private function onlySchema(string $sql): ArchiveTableSchema
    {
        $schemas = ArchiveSchemaReader::parse($sql);
        $this->assertCount(1, $schemas);

        return $schemas[0];
    }
}
