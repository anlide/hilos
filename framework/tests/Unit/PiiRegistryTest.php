<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Backup\Anonymization\FrameworkPiiDeclaration;
use Hilos\Backup\Anonymization\PiiRegistry;
use Hilos\Backup\Exception\AnonymizationConfigException;
use Hilos\Database\Entity\Item\Entity;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the merged PII registry.
 *
 * The two things worth pinning here are the ones a reader of the catalog cannot verify
 * for themselves: that a project row replaces a framework row whole rather than merging
 * into it, and that the empty column map is a classification rather than an absence.
 *
 * The framework's own declaration is checked here too, on the one axis that needs no
 * database: that it is a well-formed registry at all. Whether it covers a real archive
 * is a question about a schema, and demo/chat asks it against a live one.
 */
final class PiiRegistryTest extends TestCase
{
    /**
     * Lower bound on the framework declaration, so a row list that lost its contents
     * cannot pass as a healthy one. The count is the framework's share of the demo/chat
     * schema at the time of writing (15 archive tables, 24 analytics, one push table the
     * demo does not create); it is a floor, not the exact number, because a new framework
     * table adds a row here and must not fail this test for it.
     */
    private const int MIN_FRAMEWORK_ROWS = 40;

    public function testTheFrameworkDeclarationIsAWellFormedRegistry(): void
    {
        $registry = PiiRegistry::fromDeclarations(FrameworkPiiDeclaration::rows());
        $tables = $registry->declaredTables(0);

        $this->assertGreaterThanOrEqual(
            self::MIN_FRAMEWORK_ROWS,
            count($tables),
            'The framework declaration lost rows; an emptied one would leave every framework '
            . 'table for a project to classify',
        );
        foreach ($tables as $table) {
            $this->assertStringNotContainsString(
                '\\',
                $table,
                "Declared key [{$table}] stayed a class name, so it names a class that no longer exists",
            );
        }
    }

    public function testAnEntityKeyResolvesToItsTable(): void
    {
        $registry = PiiRegistry::fromDeclarations([
            0 => [PiiEntityFixture::class => ['email' => AnonymizationStrategy::FAKE_EMAIL]],
        ]);

        $this->assertSame(['pii_entity'], $registry->declaredTables(0));
        $this->assertSame(
            ['email' => AnonymizationStrategy::FAKE_EMAIL],
            $registry->strategiesFor(0, 'pii_entity'),
        );
    }

    public function testARawTableNameIsTakenVerbatim(): void
    {
        $registry = PiiRegistry::fromDeclarations([
            0 => ['hilos_change_log' => ['payload' => AnonymizationStrategy::MASK]],
        ]);

        $this->assertSame(['hilos_change_log'], $registry->declaredTables(0));
        $this->assertSame(
            ['payload' => AnonymizationStrategy::MASK],
            $registry->strategiesFor(0, 'hilos_change_log'),
        );
    }

    public function testAnUndeclaredTableIsToldApartFromOneDeclaredClean(): void
    {
        $registry = PiiRegistry::fromDeclarations([0 => ['clean_table' => []]]);

        $this->assertSame([], $registry->strategiesFor(0, 'clean_table'));
        $this->assertNull($registry->strategiesFor(0, 'never_mentioned'));
        $this->assertNull($registry->strategiesFor(1, 'clean_table'), 'Rows belong to one connection');
    }

    public function testAProjectRowReplacesTheFrameworkRowWhole(): void
    {
        $registry = PiiRegistry::fromDeclarations(
            [0 => [PiiEntityFixture::class => [
                'email' => AnonymizationStrategy::FAKE_EMAIL,
                'phone' => AnonymizationStrategy::FAKE_PHONE,
            ]]],
            [0 => [PiiEntityFixture::class => ['email' => AnonymizationStrategy::HASH]]],
        );

        $this->assertSame(
            ['email' => AnonymizationStrategy::HASH],
            $registry->strategiesFor(0, 'pii_entity'),
            'A column the project did not write about must not survive from the framework row',
        );
    }

    public function testTheOverrideMatchesOnTheTableRatherThanTheSpelling(): void
    {
        $registry = PiiRegistry::fromDeclarations(
            [0 => [PiiEntityFixture::class => ['email' => AnonymizationStrategy::FAKE_EMAIL]]],
            [0 => ['pii_entity' => []]],
        );

        $this->assertSame(['pii_entity'], $registry->declaredTables(0));
        $this->assertSame([], $registry->strategiesFor(0, 'pii_entity'));
    }

    public function testRowsOfDifferentTablesAreKeptSideBySide(): void
    {
        $registry = PiiRegistry::fromDeclarations(
            [0 => ['framework_only' => ['a' => AnonymizationStrategy::MASK]]],
            [0 => ['project_only' => ['b' => AnonymizationStrategy::MASK]], 1 => ['other_connection' => []]],
        );

        $this->assertSame(['framework_only', 'project_only'], $registry->declaredTables(0));
        $this->assertSame(['other_connection'], $registry->declaredTables(1));
    }

    public function testAPurgedTableIsDeclaredAndCarriesNoColumnWork(): void
    {
        $registry = PiiRegistry::fromDeclarations([
            0 => ['hilos_session' => AnonymizationStrategy::PURGE],
        ]);

        $this->assertTrue($registry->isPurged(0, 'hilos_session'));
        $this->assertSame([], $registry->strategiesFor(0, 'hilos_session'));
        $this->assertFalse($registry->isPurged(0, 'never_mentioned'));
    }

    public function testAnEmptyDeclarationIsRecognizedAsUnconfigured(): void
    {
        $this->assertTrue(PiiRegistry::fromDeclarations()->isEmpty());
        $this->assertTrue(PiiRegistry::fromDeclarations([0 => []])->isEmpty());
        $this->assertFalse(PiiRegistry::fromDeclarations([0 => ['clean_table' => []]])->isEmpty());
    }

    public function testAKeyNamingAClassThatIsNoTableIsRefused(): void
    {
        $this->expectException(AnonymizationConfigException::class);

        PiiRegistry::fromDeclarations([0 => [self::class => []]]);
    }

    public function testATableLevelStrategyOtherThanPurgeIsRefused(): void
    {
        $this->expectException(AnonymizationConfigException::class);
        $this->expectExceptionMessage('table-level');

        PiiRegistry::fromDeclarations([0 => ['hilos_session' => AnonymizationStrategy::HASH]]);
    }

    public function testPurgeOnAColumnIsRefused(): void
    {
        $this->expectException(AnonymizationConfigException::class);
        $this->expectExceptionMessage('hilos_session.token');

        PiiRegistry::fromDeclarations([
            0 => ['hilos_session' => ['token' => AnonymizationStrategy::PURGE]],
        ]);
    }

    public function testAValueThatNamesNoStrategyIsRefused(): void
    {
        $this->expectException(AnonymizationConfigException::class);

        PiiRegistry::fromDeclarations([0 => ['hilos_session' => ['token' => 'hash']]]);
    }

    public function testARowThatIsNeitherShapeIsRefused(): void
    {
        $this->expectException(AnonymizationConfigException::class);

        PiiRegistry::fromDeclarations([0 => ['hilos_session' => 'purge']]);
    }
}

/**
 * Minimal Entity fixture exposing only the table name the registry resolves keys to.
 */
final class PiiEntityFixture extends Entity
{
    public const string _table = 'pii_entity';
}
