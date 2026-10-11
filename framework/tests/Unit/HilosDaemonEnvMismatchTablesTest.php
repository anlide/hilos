<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\AdminViewMode\HiddenValue;
use Hilos\Cluster\NodeRole;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\Exception\TableSearchNotSupportedException;
use Hilos\DaemonSection\ClusterDaemonNodeSlot;
use Hilos\DaemonSection\ClusterDaemonNodeView;
use Hilos\DaemonSection\ClusterDaemonPictureMirror;
use Hilos\DaemonSection\ClusterEnvironmentCell;
use Hilos\DaemonSection\ClusterEnvironmentCellState;
use Hilos\DaemonSection\ClusterEnvironmentKeyComparison;
use Hilos\DaemonSection\DTO\DaemonClusterPicturePortionSignalData;
use Hilos\DaemonSection\NodeDaemonPicture;
use Hilos\DaemonSection\NodeEnvironmentFingerprint;
use Hilos\DaemonSection\NodeEnvironmentSummary;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogConstants;
use Hilos\Environment\EnvSource;
use Hilos\Hilos;
use Hilos\Tables\Daemon\HilosDaemonEnvDivergedTable;
use Hilos\Tables\Daemon\HilosDaemonEnvMismatchTableRow;
use Hilos\Tables\Daemon\HilosDaemonEnvSourcesDifferTable;
use PHPUnit\Framework\TestCase;

/** Both comparison tables project the same value-free verdict with only eligible local values. */
final class HilosDaemonEnvMismatchTablesTest extends TestCase
{
    private const string TEXT = 'HILOS_MISMATCH_TEST_TEXT';
    private const string SECRET = 'HILOS_MISMATCH_TEST_SECRET';
    private const string VISIBLE = 'HILOS_MISMATCH_TEST_VISIBLE';
    private const string MISSING = 'HILOS_MISMATCH_TEST_MISSING';
    private const string ABSENT = 'HILOS_MISMATCH_TEST_ABSENT';

    private ?EnvAccessor $previousEnv = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousEnv = Hilos::$env;
        Hilos::$env = new EnvAccessor(DaemonEnvMismatchTestCatalog::class);
        ClusterDaemonPictureMirror::forgetPicture();
    }

    protected function tearDown(): void
    {
        ClusterDaemonPictureMirror::forgetPicture();
        Hilos::$env = $this->previousEnv;
        parent::tearDown();
    }

    public function testEmptyMirrorAndUnsupportedSearch(): void
    {
        foreach ([new HilosDaemonEnvDivergedTable(), new HilosDaemonEnvSourcesDifferTable()] as $table) {
            self::assertSame([], $table->getPage(new TableQueryDTO())->rows);
            self::assertNull($table->containsRow(self::TEXT, new TableQueryDTO()));
            self::assertNull($table->defaultSort());
            self::assertSame(25, $table->windowSize());
            try {
                $table->getPage(new TableQueryDTO(search: self::TEXT));
                self::fail('A mismatch table accepted search');
            } catch (TableSearchNotSupportedException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testMirrorSeparatesBucketsAndKeepsNodeCellOrder(): void
    {
        ClusterDaemonPictureMirror::applyPortion(new DaemonClusterPicturePortionSignalData(true, [
            $this->node('z', false, null),
            $this->node('b', true, [
                $this->fingerprint(self::TEXT, EnvSource::ENV_FILE, 'bbbbbbbbbbbbbbbb'),
                $this->fingerprint(self::VISIBLE, EnvSource::EXAMPLE, '1111111111111111'),
                $this->fingerprint(self::MISSING, EnvSource::PROCESS, '2222222222222222'),
                $this->fingerprint('PER_NODE', EnvSource::PROCESS, '3333333333333333', true),
            ]),
            $this->node('a', true, [
                $this->fingerprint(self::TEXT, EnvSource::PROCESS, 'aaaaaaaaaaaaaaaa'),
                $this->fingerprint(self::VISIBLE, EnvSource::PROCESS, '1111111111111111'),
                $this->fingerprint(self::SECRET, EnvSource::PROCESS, '4444444444444444'),
                $this->fingerprint(self::MISSING, EnvSource::MISSING, null),
                $this->fingerprint('PER_NODE', EnvSource::PROCESS, '5555555555555555', true),
            ]),
        ]));

        $diverged = $this->rows(new HilosDaemonEnvDivergedTable());
        $sources = $this->rows(new HilosDaemonEnvSourcesDifferTable());
        self::assertSame([self::TEXT, self::SECRET, self::MISSING], array_map(
            static fn (HilosDaemonEnvMismatchTableRow $row): string => $row->key,
            $diverged,
        ));
        self::assertSame([self::VISIBLE], array_map(
            static fn (HilosDaemonEnvMismatchTableRow $row): string => $row->key,
            $sources,
        ));
        self::assertSame([
            ['nodeId' => 'a', 'state' => 'known', 'type' => 'string', 'source' => 'process', 'digest' => 'aaaaaaaaaaaaaaaa'],
            ['nodeId' => 'b', 'state' => 'known', 'type' => 'string', 'source' => 'env', 'digest' => 'bbbbbbbbbbbbbbbb'],
            ['nodeId' => 'z', 'state' => 'unknown', 'type' => null, 'source' => null, 'digest' => null],
        ], $diverged[0]->cells);
        self::assertSame('undeclared', $diverged[1]->cells[1]['state']);
        self::assertSame(['nodeId' => 'b', 'state' => 'undeclared', 'type' => null, 'source' => null, 'digest' => null],
            $diverged[1]->cells[1]);
        self::assertSame('missing', $diverged[2]->cells[0]['source']);
        self::assertNull($diverged[2]->cells[0]['digest']);
        self::assertSame('example', $sources[0]->cells[1]['source']);
    }

    public function testOwnValueDependsOnServingCellCatalogAndViewer(): void
    {
        $keys = [
            $this->key(self::TEXT, ClusterEnvironmentCellState::Known),
            $this->key(self::SECRET, ClusterEnvironmentCellState::Known),
            $this->key(self::VISIBLE, ClusterEnvironmentCellState::Known),
            $this->key(self::MISSING, ClusterEnvironmentCellState::Known, EnvSource::MISSING),
            $this->key(self::TEXT, ClusterEnvironmentCellState::Unknown),
            $this->key(self::ABSENT, ClusterEnvironmentCellState::Known),
        ];
        $env = Hilos::$env;
        self::assertInstanceOf(EnvAccessor::class, $env);

        $admin = HilosDaemonEnvDivergedTable::rowsOf($keys, 'a', $env, false);
        self::assertSame(['kind' => 'text', 'text' => 'local-value'], $admin[0]['value']);
        self::assertTrue($admin[1]['sensitive']);
        self::assertNull($admin[1]['value']);
        self::assertSame(['kind' => 'none'], $admin[3]['value']);
        self::assertNull($admin[4]['value']);
        self::assertFalse($admin[5]['sensitive']);
        self::assertNull($admin[5]['value']);

        $viewer = HilosDaemonEnvSourcesDifferTable::rowsOf($keys, 'a', $env, true);
        self::assertSame(HiddenValue::mark(), $viewer[0]['value']);
        self::assertSame(['kind' => 'text', 'text' => 'visible-value'], $viewer[2]['value']);
    }

    public function testWireFieldsAndRowRoundTripCoverEveryCellField(): void
    {
        $table = new HilosDaemonEnvDivergedTable();
        $row = new HilosDaemonEnvMismatchTableRow(self::TEXT, self::TEXT, false, ['kind' => 'text', 'text' => 'v'], [
            ['nodeId' => 'a', 'state' => 'known', 'type' => 'string', 'source' => 'process', 'digest' => 'abcd'],
        ]);
        self::assertSame(array_keys($row->toArray()), array_keys($table->wireFields()));
        self::assertSame(array_keys($row->cells[0]), array_keys($table->wireFields()[HilosDaemonEnvMismatchTableRow::cells]->fields));
        self::assertSame($row->toArray(), HilosDaemonEnvMismatchTableRow::fromArray($row->toArray())->toArray());
        self::assertSame(self::TEXT, $table->browserRow($row)['rowKey']);
    }

    public function testMalformedCellStateIsRejected(): void
    {
        $data = $this->keyRow();
        $data['cells'][0]['state'] = 'other';
        $this->expectException(InvalidFormatException::class);
        HilosDaemonEnvMismatchTableRow::fromArray($data);
    }

    public function testMalformedCellNodeIdIsRejected(): void
    {
        $data = $this->keyRow();
        $data['cells'][0]['nodeId'] = 1;
        $this->expectException(InvalidFormatException::class);
        HilosDaemonEnvMismatchTableRow::fromArray($data);
    }

    /** @return array<string, mixed> Valid wire row */
    private function keyRow(): array
    {
        return (new HilosDaemonEnvMismatchTableRow(self::TEXT, self::TEXT, false, null, [
            ['nodeId' => 'a', 'state' => 'known', 'type' => 'string', 'source' => 'process', 'digest' => 'abcd'],
        ]))->toArray();
    }

    /** @return list<HilosDaemonEnvMismatchTableRow> First window rows */
    private function rows(HilosDaemonEnvDivergedTable|HilosDaemonEnvSourcesDifferTable $table): array
    {
        /** @var list<HilosDaemonEnvMismatchTableRow> $rows */
        $rows = $table->getPage(new TableQueryDTO())->rows;

        return $rows;
    }

    /**
     * @param string $id Node id
     * @param bool $online Whether the node currently answers
     * @param ?list<NodeEnvironmentFingerprint> $fingerprints Null before an environment report
     * @return ClusterDaemonNodeView Node view
     */
    private function node(string $id, bool $online, ?array $fingerprints): ClusterDaemonNodeView
    {
        return new ClusterDaemonNodeView($id, $online, new ClusterDaemonNodeSlot($id, new NodeDaemonPicture(
            $id,
            NodeRole::Master,
            1,
            environment: $fingerprints === null ? null : new NodeEnvironmentSummary(count($fingerprints), 0, 0, 0, 0, $fingerprints),
        ), 1));
    }

    /** @return NodeEnvironmentFingerprint Collector label for a catalog key */
    private function fingerprint(string $key, EnvSource $source, ?string $digest, bool $perNode = false): NodeEnvironmentFingerprint
    {
        return new NodeEnvironmentFingerprint($key, EnvCatalogConstants::TYPE_STRING, $source, $perNode, $digest);
    }

    /** @return ClusterEnvironmentKeyComparison One key with a serving cell */
    private function key(
        string $key,
        ClusterEnvironmentCellState $state,
        EnvSource $source = EnvSource::PROCESS,
    ): ClusterEnvironmentKeyComparison {
        return new ClusterEnvironmentKeyComparison($key, [
            new ClusterEnvironmentCell('a', $state,
                $state === ClusterEnvironmentCellState::Known ? $this->fingerprint($key, $source, 'abcd') : null),
        ]);
    }
}

/** Catalog defaults let the row test read its own value without a disk fixture. */
final class DaemonEnvMismatchTestCatalog implements CatalogProviderInterface
{
    /** @return array<string, array<string, mixed>> Test environment declarations */
    public static function getCatalog(): array
    {
        return [
            'HILOS_MISMATCH_TEST_TEXT' => [
                EnvCatalogConstants::CATALOG_ENTRY_TYPE => EnvCatalogConstants::TYPE_STRING,
                EnvCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => 'local-value',
            ],
            'HILOS_MISMATCH_TEST_SECRET' => [
                EnvCatalogConstants::CATALOG_ENTRY_TYPE => EnvCatalogConstants::TYPE_STRING,
                EnvCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => 'secret-value',
                EnvCatalogConstants::CATALOG_ENTRY_SENSITIVE => true,
            ],
            'HILOS_MISMATCH_TEST_VISIBLE' => [
                EnvCatalogConstants::CATALOG_ENTRY_TYPE => EnvCatalogConstants::TYPE_STRING,
                EnvCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => 'visible-value',
                EnvCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE => true,
            ],
            'HILOS_MISMATCH_TEST_MISSING' => [
                EnvCatalogConstants::CATALOG_ENTRY_TYPE => EnvCatalogConstants::TYPE_STRING,
            ],
        ];
    }
}
