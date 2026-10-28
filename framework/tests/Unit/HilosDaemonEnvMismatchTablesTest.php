<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\AdminViewMode\HiddenValue;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\NodeRole;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableAnchorDTO;
use Hilos\Core\Table\Exception\TableSearchNotSupportedException;
use Hilos\DaemonSection\ClusterDaemonNodeSlot;
use Hilos\DaemonSection\ClusterDaemonNodeView;
use Hilos\DaemonSection\ClusterDaemonPictureMirror;
use Hilos\DaemonSection\ClusterEnvironmentCell;
use Hilos\DaemonSection\ClusterEnvironmentCellState;
use Hilos\DaemonSection\ClusterEnvironmentKeyComparison;
use Hilos\DaemonSection\ClusterEnvironmentComparison;
use Hilos\DaemonSection\DTO\DaemonClusterPicturePortionSignalData;
use Hilos\DaemonSection\NodeDaemonPicture;
use Hilos\DaemonSection\NodeEnvironmentFingerprint;
use Hilos\DaemonSection\NodeEnvironmentSummary;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogConstants;
use Hilos\Environment\EnvSource;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Tables\Daemon\HilosDaemonEnvMismatchTable;
use Hilos\Tables\Daemon\HilosDaemonEnvMismatchTableRow;
use PHPUnit\Framework\TestCase;

/** The combined comparison table projects the same value-free verdict with only eligible local values. */
final class HilosDaemonEnvMismatchTablesTest extends TestCase
{
    private const string TEXT = 'HILOS_MISMATCH_TEST_TEXT';
    private const string SECRET = 'HILOS_MISMATCH_TEST_SECRET';
    private const string VISIBLE = 'HILOS_MISMATCH_TEST_VISIBLE';
    private const string MISSING = 'HILOS_MISMATCH_TEST_MISSING';
    private const string ABSENT = 'HILOS_MISMATCH_TEST_ABSENT';

    private ?EnvAccessor $previousEnv = null;
    private ?ClusterContext $previousCluster = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousEnv = Hilos::$env;
        $this->previousCluster = Hilos::$cluster;
        Hilos::$cluster = null;
        Hilos::$env = new EnvAccessor(DaemonEnvMismatchTestCatalog::class);
        ClusterDaemonPictureMirror::forgetPicture();
    }

    protected function tearDown(): void
    {
        ClusterDaemonPictureMirror::forgetPicture();
        Hilos::$env = $this->previousEnv;
        Hilos::$cluster = $this->previousCluster;
        parent::tearDown();
    }

    public function testPictureDiffMovesKeysBetweenBucketsAndKeepsViewerMasking(): void
    {
        $first = $this->node('a', true, [$this->fingerprint(self::TEXT, EnvSource::PROCESS, 'aaaaaaaaaaaaaaaa')]);
        ClusterDaemonPictureMirror::applyPortion(new DaemonClusterPicturePortionSignalData(true, [
            $first, $this->node('b', true, [$this->fingerprint(self::TEXT, EnvSource::PROCESS, 'bbbbbbbbbbbbbbbb')]),
        ]));
        $before = ClusterDaemonPictureMirror::picture();
        ClusterDaemonPictureMirror::applyPortion(new DaemonClusterPicturePortionSignalData(true, [
            $first, $this->node('b', true, [$this->fingerprint(self::TEXT, EnvSource::ENV_FILE, 'aaaaaaaaaaaaaaaa')]),
        ]));
        $query = new TableQueryDTO(limit: 1, pageIndex: 10, shownFields: []);
        $mutations = new HilosDaemonEnvMismatchTable()->pictureMutations($before, $query);
        self::assertCount(1, $mutations);
        self::assertSame('update', $mutations[0]->type->value);
        self::assertSame(self::TEXT, $mutations[0]->rowKey);
        self::assertSame('sourcesDiffer', $mutations[0]->row->toArray()['kind']);
        self::assertSame(0, $mutations[0]->row->toArray()['position']);
        self::assertNull($mutations[0]->row->toArray()['value']);
        self::assertStringNotContainsString('local-value', json_encode($mutations[0]->toArray(), JSON_THROW_ON_ERROR));
        self::assertSame([], new HilosDaemonEnvMismatchTable()->pictureMutations(ClusterDaemonPictureMirror::picture(), $query));
        $before = ClusterDaemonPictureMirror::picture();
        ClusterDaemonPictureMirror::applyPortion(new DaemonClusterPicturePortionSignalData(true, []));
        $deleted = new HilosDaemonEnvMismatchTable()->pictureMutations($before, $query);
        self::assertCount(1, $deleted);
        self::assertSame('delete', $deleted[0]->type->value);
        self::assertSame(self::TEXT, $deleted[0]->rowKey);
    }

    public function testUnknownMirrorRefusesWindowsAndUnsupportedSearch(): void
    {
        foreach ([new HilosDaemonEnvMismatchTable()] as $table) {
            try {
                $table->getPage(new TableQueryDTO());
                self::fail('An unknown comparison answered with an empty window');
            } catch (HilosException $failure) {
                self::assertSame('Environment comparison has no source picture yet', $failure->getMessage());
            }
            self::assertNull($table->containsRow(self::TEXT, new TableQueryDTO()));
            self::assertSame([['field' => 'position', 'direction' => 'asc']], $table->defaultSort()->toArray());
            self::assertSame(25, $table->windowSize());
            try {
                $table->getPage(new TableQueryDTO(search: self::TEXT));
                self::fail('A mismatch table accepted search');
            } catch (TableSearchNotSupportedException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testKnownEmptyPictureHasAuthoritativeEmptyBuckets(): void
    {
        ClusterDaemonPictureMirror::applyPortion(new DaemonClusterPicturePortionSignalData(true, []));
        foreach ([new HilosDaemonEnvMismatchTable()] as $table) {
            self::assertSame([], $table->getPage(new TableQueryDTO())->rows);
            self::assertSame(0, $table->getPage(new TableQueryDTO())->totalCount);
            self::assertTrue($table->getPage(new TableQueryDTO())->totalExact);
            self::assertFalse($table->containsRow(self::TEXT, new TableQueryDTO()));
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

        $rows = $this->rows(new HilosDaemonEnvMismatchTable());
        self::assertSame(['diverged', 'diverged', 'diverged', 'sourcesDiffer'], array_column($rows, 'kind'));
        self::assertSame([0, 1, 2, 3], array_column($rows, 'position'));
        $diverged = array_slice($rows, 0, 3);
        $sources = array_slice($rows, 3);
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

    public function testPagingCrossesTheCombinedBucketBoundaryBeforeSlicing(): void
    {
        $first = [];
        $second = [];
        for ($index = 0; $index < 30; $index++) {
            $key = 'KEY_' . $index;
            $first[] = $this->fingerprint($key, EnvSource::PROCESS, 'a');
            $second[] = $this->fingerprint($key, $index < 23 ? EnvSource::PROCESS : EnvSource::EXAMPLE, $index < 23 ? 'b' : 'a');
        }
        $first[] = $this->fingerprint('MATCHED', EnvSource::PROCESS, 'same');
        $second[] = $this->fingerprint('MATCHED', EnvSource::PROCESS, 'same');
        $first[] = $this->fingerprint('PER_NODE', EnvSource::PROCESS, 'a', true);
        $second[] = $this->fingerprint('PER_NODE', EnvSource::PROCESS, 'b', true);
        ClusterDaemonPictureMirror::applyPortion(new DaemonClusterPicturePortionSignalData(true, [
            $this->node('a', true, $first), $this->node('b', true, $second),
        ]));
        $table = new HilosDaemonEnvMismatchTable();
        $sort = $table->defaultSort();
        $window = $table->getPage(new TableQueryDTO(sort: $sort, limit: 25));
        self::assertSame(30, $window->totalCount);
        self::assertCount(25, $window->rows);
        self::assertSame(range(0, 24), array_column($window->rows, 'position'));
        self::assertSame('diverged', $window->rows[22]->kind);
        self::assertSame('sourcesDiffer', $window->rows[23]->kind);
        self::assertSame(['position' => 24, 'rowKey' => 'KEY_24'], $window->lastAnchor->toArray());
        $tail = $table->getPage(new TableQueryDTO(sort: $sort, limit: 25, anchor: $window->lastAnchor));
        self::assertSame(range(25, 29), array_column($tail->rows, 'position'));
        self::assertSame(25, $tail->rowsBefore);
        self::assertSame(30, $tail->totalCount);
        self::assertSame('KEY_25', $tail->rows[0]->rowKey);
        self::assertTrue($table->containsRow('KEY_29', new TableQueryDTO()));
        self::assertFalse($table->containsRow('MATCHED', new TableQueryDTO()));
        self::assertFalse($table->containsRow('PER_NODE', new TableQueryDTO()));
        $jump = $table->getPage(new TableQueryDTO(sort: $sort, limit: 25, pageIndex: 1));
        self::assertSame(array_column($tail->rows, 'rowKey'), array_column($jump->rows, 'rowKey'));
        self::assertInstanceOf(TableAnchorDTO::class, $tail->firstAnchor);
    }

    public function testSourcesOnlySetHasItsOwnCountAndZeroBasedPositions(): void
    {
        ClusterDaemonPictureMirror::applyPortion(new DaemonClusterPicturePortionSignalData(true, [
            $this->node('a', true, [$this->fingerprint(self::TEXT, EnvSource::PROCESS, 'a')]),
            $this->node('b', true, [$this->fingerprint(self::TEXT, EnvSource::EXAMPLE, 'a')]),
        ]));
        $table = new HilosDaemonEnvMismatchTable();
        $window = $table->getPage(new TableQueryDTO(sort: $table->defaultSort(), limit: 25));
        self::assertSame(1, $window->totalCount);
        self::assertSame(['sourcesDiffer'], array_column($window->rows, 'kind'));
        self::assertSame([0], array_column($window->rows, 'position'));
    }

    public function testMalformedKindAndPositionAreRejected(): void
    {
        foreach ([['kind', 'other'], ['kind', null], ['position', -1], ['position', 1.5], ['position', '1']] as [$field, $value]) {
            $data = $this->keyRow();
            $data[$field] = $value;
            try {
                HilosDaemonEnvMismatchTableRow::fromArray($data);
                self::fail('A malformed verdict kind or position was accepted');
            } catch (InvalidFormatException) {
                $this->addToAssertionCount(1);
            }
        }
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

        $admin = HilosDaemonEnvMismatchTable::rowsOf(new ClusterEnvironmentComparison(['a'], ['a'], $keys, [], []), 'a', $env, false);
        self::assertSame(['kind' => 'text', 'text' => 'local-value'], $admin[0]['value']);
        self::assertTrue($admin[1]['sensitive']);
        self::assertNull($admin[1]['value']);
        self::assertSame(['kind' => 'none'], $admin[3]['value']);
        self::assertNull($admin[4]['value']);
        self::assertFalse($admin[5]['sensitive']);
        self::assertNull($admin[5]['value']);

        $viewer = HilosDaemonEnvMismatchTable::rowsOf(new ClusterEnvironmentComparison(['a'], ['a'], $keys, [], []), 'a', $env, true);
        self::assertSame(HiddenValue::mark(), $viewer[0]['value']);
        self::assertSame(['kind' => 'text', 'text' => 'visible-value'], $viewer[2]['value']);
    }

    public function testWireFieldsAndRowRoundTripCoverEveryCellField(): void
    {
        $table = new HilosDaemonEnvMismatchTable();
        $row = new HilosDaemonEnvMismatchTableRow(self::TEXT, self::TEXT, 'diverged', 0, false, ['kind' => 'text', 'text' => 'v'], [
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
        return (new HilosDaemonEnvMismatchTableRow(self::TEXT, self::TEXT, 'diverged', 0, false, null, [
            ['nodeId' => 'a', 'state' => 'known', 'type' => 'string', 'source' => 'process', 'digest' => 'abcd'],
        ]))->toArray();
    }

    /** @return list<HilosDaemonEnvMismatchTableRow> First window rows */
    private function rows(HilosDaemonEnvMismatchTable $table): array
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
