<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database\Schema;

use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Backup\Anonymization\LiveTableSchema;
use Hilos\Backup\Anonymization\PiiRegistry;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Entity\Item\Identity;
use Hilos\Database\Entity\Item\SecondFactor;
use Hilos\Database\Exception\UnplacedJournalColumnException;
use Hilos\Database\Schema\JournalColumnMode;
use Hilos\Database\Schema\JournalColumnPolicy;
use PHPUnit\Framework\TestCase;

/** The shared placement policy judges live columns, including columns outside the ORM. */
final class JournalColumnPolicyTest extends TestCase
{
    public function testAllSixModesAndInheritedMetadata(): void
    {
        $schema = self::schema('journal_test', [
            'id' => 'int', 'plain' => 'varchar', 'personal' => 'varchar',
            'secret' => 'varchar', 'noise' => 'datetime', 'binary_data' => 'varbinary',
        ]);
        $pii = self::registry('journal_test', ['personal' => AnonymizationStrategy::MASK],
            ['id', 'plain', 'noise', 'binary_data']);

        $placements = JournalColumnPolicy::forTable(ProjectJournalEntity::class, $schema, $pii);

        $this->assertSame([
            'id' => JournalColumnMode::RECORD_KEY,
            'plain' => JournalColumnMode::VALUE,
            'personal' => JournalColumnMode::PERSONAL,
            'secret' => JournalColumnMode::SECRET,
            'noise' => JournalColumnMode::NOISE,
            'binary_data' => JournalColumnMode::BINARY,
        ], array_map(static fn($placement) => $placement->mode, $placements));
        $this->assertSame('Changes on every read', $placements['noise']->reason);
        $this->assertTrue(ProjectJournalEntity::_journaled);
        $this->assertSame(JournalEntity::_journalSecrets, ProjectJournalEntity::_journalSecrets);
    }

    public function testIdentityDbOnlySecretOverridesItsNullifyVerdict(): void
    {
        $schema = self::schema(Identity::_table, ['id' => 'int', 'secret' => 'varchar', 'updated_at' => 'datetime']);
        $pii = self::registry(Identity::_table, ['secret' => AnonymizationStrategy::NULLIFY], ['id', 'updated_at']);

        $placements = JournalColumnPolicy::forTable(Identity::class, $schema, $pii);

        $this->assertSame(JournalColumnMode::SECRET, $placements['secret']->mode);
        $this->assertSame(JournalColumnMode::NOISE, $placements['updated_at']->mode);
    }

    public function testPurgedTableKeepsOnlyKeySecretAndNoiseExceptions(): void
    {
        $schema = self::schema(SecondFactor::_table, [
            'id' => 'int', 'secret' => 'varchar', 'last_used_step' => 'int',
            'last_used_at' => 'datetime', 'label' => 'varchar',
        ]);
        $pii = new PiiRegistry([DatabaseConnectionDefaults::PRIMARY_INDEX => [
            SecondFactor::_table => AnonymizationStrategy::PURGE,
        ]]);

        $placements = JournalColumnPolicy::forTable(SecondFactor::class, $schema, $pii);

        $this->assertSame(JournalColumnMode::RECORD_KEY, $placements['id']->mode);
        $this->assertSame(JournalColumnMode::SECRET, $placements['secret']->mode);
        $this->assertSame(JournalColumnMode::NOISE, $placements['last_used_step']->mode);
        $this->assertSame(JournalColumnMode::PERSONAL, $placements['label']->mode);
    }

    public function testAColumnWithNoVerdictIsNotAssumedToHoldAValue(): void
    {
        $schema = self::schema('journal_test', ['id' => 'int', 'added_by_sql' => 'varchar']);
        $pii = self::registry('journal_test', [], ['id']);

        $this->expectException(UnplacedJournalColumnException::class);
        $this->expectExceptionMessage('journal_test.added_by_sql');

        JournalColumnPolicy::forTable(JournalEntity::class, $schema, $pii);
    }

    public function testDeclarationsAndPrimaryKeyProblemsAreCollectedTogether(): void
    {
        $schema = self::schema('journal_test', ['id' => 'int', 'secret' => 'varchar'], ['secret']);
        $pii = self::registry('journal_test', ['secret' => AnonymizationStrategy::MASK], ['id']);

        try {
            JournalColumnPolicy::forTable(BrokenJournalEntity::class, $schema, $pii);
            $this->fail('A secret record key and invalid noise declaration must be refused');
        } catch (UnplacedJournalColumnException $failure) {
            $message = $failure->getMessage();
            $this->assertStringContainsString('journal_test.absent', $message);
            $this->assertStringContainsString('journal_test.secret: both secret and noise', $message);
            $this->assertStringContainsString('journal_test.secret: noise reason is empty', $message);
            $this->assertStringContainsString('journal_test.secret: record key', $message);
        }
    }

    public function testAKeyWithoutPiiVerdictIsRefused(): void
    {
        $schema = self::schema('journal_test', ['id' => 'int'], []);
        $pii = new PiiRegistry([]);

        try {
            JournalColumnPolicy::forTable(JournalEntity::class, $schema, $pii);
            $this->fail('Missing primary key and PII verdict must be refused');
        } catch (UnplacedJournalColumnException $failure) {
            $this->assertStringContainsString('journal_test._pii', $failure->getMessage());
            $this->assertStringContainsString('journal_test._primary', $failure->getMessage());
        }
    }

    public function testAnEntityCannotPlaceAnotherTablesSchema(): void
    {
        $schema = self::schema('different_table', ['id' => 'int']);
        $pii = self::registry('different_table', [], ['id']);

        $this->expectException(UnplacedJournalColumnException::class);
        $this->expectExceptionMessage('different_table._table');

        JournalColumnPolicy::forTable(JournalEntity::class, $schema, $pii);
    }

    /**
     * @param array<string, string> $columns Live SQL types by column
     * @param list<string> $primaryKey Live primary key
     */
    private static function schema(string $table, array $columns, array $primaryKey = ['id']): LiveTableSchema
    {
        return new LiveTableSchema($table, array_fill_keys(array_keys($columns), false), $columns,
            array_fill_keys(array_keys($columns), null), $primaryKey, [], []);
    }

    /**
     * @param array<string, AnonymizationStrategy> $personal Personal columns
     * @param list<string> $notPersonal Explicitly non-personal columns
     */
    private static function registry(string $table, array $personal, array $notPersonal): PiiRegistry
    {
        $index = DatabaseConnectionDefaults::PRIMARY_INDEX;

        return new PiiRegistry([$index => [$table => $personal]], [$index => [$table => $notPersonal]]);
    }
}

class JournalEntity extends Entity
{
    public const string _table = 'journal_test';
    public const bool _journaled = true;
    public const array _journalSecrets = ['secret'];
    public const array _journalNoise = ['noise' => 'Changes on every read'];
}

final class ProjectJournalEntity extends JournalEntity
{
}

final class BrokenJournalEntity extends JournalEntity
{
    public const array _journalNoise = ['secret' => '', 'absent' => 'Does not exist'];
}
