<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\Exception\TableSearchNotSupportedException;
use Hilos\DaemonSection\LocalNodeEnvironment;
use Hilos\DaemonSection\NodeEnvironmentKey;
use Hilos\DaemonSection\NodeEnvironmentOrphan;
use Hilos\DaemonSection\NodeEnvironmentReading;
use Hilos\Environment\EnvResolution;
use Hilos\Environment\EnvSource;
use Hilos\Tables\Daemon\HilosDaemonEnvOrphansTable;
use Hilos\Tables\Daemon\HilosDaemonEnvOrphansTableRow;
use PHPUnit\Framework\TestCase;

/** Orphan windows reveal no raw dotenv value and suggest only near catalog names. */
final class HilosDaemonEnvOrphansTableTest extends TestCase
{
    protected function tearDown(): void
    {
        LocalNodeEnvironment::forget('n1');
        parent::tearDown();
    }

    public function testNodeScopeOrderMaskAndSuggestions(): void
    {
        self::assertSame([], $this->rows('n1'));
        LocalNodeEnvironment::hold($this->reading());
        self::assertSame([], new HilosDaemonEnvOrphansTable()->getPage(new TableQueryDTO())->rows);
        self::assertSame([], $this->rows('n2'));
        self::assertSame([], new HilosDaemonEnvOrphansTable()->getPage(new TableQueryDTO(
            filter: [HilosDaemonEnvOrphansTable::FILTER_NODE => 7],
        ))->rows);

        $rows = $this->rows('n1');
        self::assertSame(['DB_PASSWD', 'db_password', 'HILOS_LOG_LEVEL', 'ABC'],
            array_map(static fn(HilosDaemonEnvOrphansTableRow $row): string => $row->rowKey, $rows));
        self::assertSame(['DB_PASSWORD', 'DB_PASSWORD', null, 'ABD'],
            array_map(static fn(HilosDaemonEnvOrphansTableRow $row): ?string => $row->similarTo, $rows));
        self::assertSame(['kind' => 'secret', 'length' => 6], $rows[0]->value);
        self::assertSame(['_hidden' => true], $this->rows('n1', ['key'])[0]->value);
        self::assertSame(array_keys($rows[0]->toArray()), array_keys(new HilosDaemonEnvOrphansTable()->wireFields()));
        self::assertSame($rows[0]->toArray(), HilosDaemonEnvOrphansTableRow::fromArray($rows[0]->toArray())->toArray());
        self::assertTrue(new HilosDaemonEnvOrphansTable()->containsRow('DB_PASSWD', $this->query('n1')));
        self::assertFalse(new HilosDaemonEnvOrphansTable()->containsRow('UNKNOWN', $this->query('n1')));
    }

    public function testSearchIsRefused(): void
    {
        LocalNodeEnvironment::hold($this->reading());
        $this->expectException(TableSearchNotSupportedException::class);
        new HilosDaemonEnvOrphansTable()->getPage(new TableQueryDTO(
            search: 'DB', filter: [HilosDaemonEnvOrphansTable::FILTER_NODE => 'n1'],
        ));
    }

    /** @return list<HilosDaemonEnvOrphansTableRow> Rows of the chosen node */
    private function rows(string $node, ?array $shownFields = null): array
    {
        /** @var list<HilosDaemonEnvOrphansTableRow> $rows */
        $rows = new HilosDaemonEnvOrphansTable()->getPage($this->query($node, $shownFields))->rows;
        return $rows;
    }

    /** @return TableQueryDTO Node window query */
    private function query(string $node, ?array $shownFields = null): TableQueryDTO
    {
        return new TableQueryDTO(filter: [HilosDaemonEnvOrphansTable::FILTER_NODE => $node], shownFields: $shownFields);
    }

    /** @return NodeEnvironmentReading Catalog order and dotenv file order */
    private function reading(): NodeEnvironmentReading
    {
        $keys = [];
        foreach (['DB_PASSWORD', 'ABD', 'ABE'] as $key) {
            $keys[] = new NodeEnvironmentKey($key, 'string', false, true, false, false, false,
                new EnvResolution(EnvSource::MISSING, null), null);
        }
        return new NodeEnvironmentReading('n1', 10, $keys, [
            new NodeEnvironmentOrphan('DB_PASSWD', 'secret'),
            new NodeEnvironmentOrphan('db_password', 'another'),
            new NodeEnvironmentOrphan('HILOS_LOG_LEVEL', 'debug'),
            new NodeEnvironmentOrphan('ABC', 'tie'),
        ]);
    }
}
