<?php

declare(strict_types=1);

namespace Hilos\Tables\Daemon;

use Hilos\AdminViewMode\WireField;
use Hilos\API\Router\HttpRouteTally;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\Exception\TableSearchFieldUnknownException;
use Hilos\Core\Table\Exception\TableSearchNotSupportedException;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\DaemonSection\ClusterDaemonPicture;
use Hilos\DaemonSection\ClusterDaemonPictureMirror;
use Hilos\DaemonSection\DaemonPictureRowChanges;
use Hilos\DaemonSection\DaemonPictureViewportTable;
use Hilos\Pages\Daemon\AbstractHilosDaemonHttpServerPage;

/**
 * HTTP routes of one node, projected only from the page worker's daemon picture mirror in picture order.
 *
 * A viewport without a node filter is empty. The mirror has no row change source, so
 * {@see AbstractHilosDaemonHttpServerPage::onPictureChanged()} publishes picture mutations through the shared viewport classifier.
 */
final class HilosDaemonHttpRoutesTable extends TableDefinition implements DaemonPictureViewportTable
{
    public const string TABLE = 'hilosDaemonHttpRoutes';
    public const string FILTER_NODE = 'node';

    private const string ROW_SLOT = 'route';
    private const string ROW_KEY_SEPARATOR = '/';
    private const string UNROUTED_ROW_KEY = 'unrouted';
    private const int WINDOW_SIZE = 25;

    /** @return int Rows in the first window */
    public function windowSize(): int
    {
        return self::WINDOW_SIZE;
    }

    /**
     * The mirror raises no row source events.
     *
     * @param SourceChange $change Ignored source change
     * @return ?TableRowMutationDTO Always null
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        return null;
    }

    /**
     * @param ?ClusterDaemonPicture $before Previous immutable picture
     * @param TableQueryDTO $query Subject and viewer scope, independent of pagination and search membership
     * @return list<TableRowMutationDTO> Changes to the complete subject set
     * @throws TableRowKeyMissingException When a projected row carries no key
     */
    public function pictureMutations(?ClusterDaemonPicture $before, TableQueryDTO $query): array
    {
        return DaemonPictureRowChanges::between(
            $this->makeRows($this->collectRows($before, $query)),
            $this->makeRows($this->collectRows(ClusterDaemonPictureMirror::picture(), $query)),
        );
    }

    /**
     * @param AbstractTableRow $row HTTP route table row
     * @return array{rowKey: int|string, sources: array<string, mixed>} Browser row envelope
     * @throws TableRowKeyMissingException When a placeholder row has no key
     */
    public function browserRow(AbstractTableRow $row): array
    {
        return [
            BrowserPageSignalData::rowKey => $row->requireRowKey(),
            BrowserPageSignalData::sources => [self::ROW_SLOT => $row->toArray()],
        ];
    }

    /** @return array<string, WireField> Origins of every non-personal HTTP route field */
    public function wireFields(): array
    {
        return [
            HilosDaemonHttpRoutesTableRow::rowKey => WireField::notPersonal(),
            HilosDaemonHttpRoutesTableRow::method => WireField::notPersonal(),
            HilosDaemonHttpRoutesTableRow::path => WireField::notPersonal(),
            HilosDaemonHttpRoutesTableRow::agentType => WireField::notPersonal(),
            HilosDaemonHttpRoutesTableRow::requests => WireField::notPersonal(),
            HilosDaemonHttpRoutesTableRow::serverErrors => WireField::notPersonal(),
            HilosDaemonHttpRoutesTableRow::slowestMs => WireField::notPersonal(),
        ];
    }

    /**
     * @param string|int $rowKey Row key to find
     * @param TableQueryDTO $query Window filter and search scope
     * @return ?bool Whether the node's picture contains this row
     * @throws TableSearchNotSupportedException When search is requested
     * @throws TableSearchFieldUnknownException When a declared search field is absent
     */
    public function containsRow(string|int $rowKey, TableQueryDTO $query): ?bool
    {
        return $this->containsRowInMemory($this->collectRows(ClusterDaemonPictureMirror::picture(), $query), $rowKey, $query);
    }

    /**
     * @param TableQueryDTO $query Window query
     * @return TableSnapshotDTO Rows in picture order
     * @throws TableSearchNotSupportedException When search is requested
     * @throws TableSearchFieldUnknownException When a declared search field is absent
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        return $this->filterInMemory($this->collectRows(ClusterDaemonPictureMirror::picture(), $query), $query);
    }

    /** Configures the HTTP route row representation. */
    protected function init(): void
    {
        $this->setRowClass(HilosDaemonHttpRoutesTableRow::class);
    }

    /**
     * @param ?ClusterDaemonPicture $picture Immutable source picture
     * @param TableQueryDTO $query Window filter naming one node
     * @return list<array<string, mixed>> Routes in picture order, then the unrouted tally
     */
    private function collectRows(?ClusterDaemonPicture $picture, TableQueryDTO $query): array
    {
        $node = $query->filter[self::FILTER_NODE] ?? null;
        if (!is_string($node) || trim($node) === '') {
            return [];
        }

        $http = $picture?->node(trim($node))?->slot?->picture->http;
        if ($http === null) {
            return [];
        }

        $rows = [];
        foreach ($http->routes as $route) {
            $rows[] = self::routeRow($route->method, $route->path, $route->agentType, $route->tally);
        }
        $rows[] = self::routeRow(null, null, null, $http->unrouted);

        return $rows;
    }

    /**
     * @param ?string $method Registered HTTP method, or null for unrouted requests
     * @param ?string $path Registered path template, or null for unrouted requests
     * @param ?string $agentType Answering agent, if any
     * @param HttpRouteTally $tally Counts in the current HTTP window
     * @return array<string, mixed> Row payload
     */
    private static function routeRow(?string $method, ?string $path, ?string $agentType, HttpRouteTally $tally): array
    {
        return [
            HilosDaemonHttpRoutesTableRow::rowKey => $method === null
                ? self::UNROUTED_ROW_KEY : self::rowKey($method, $path),
            HilosDaemonHttpRoutesTableRow::method => $method,
            HilosDaemonHttpRoutesTableRow::path => $path,
            HilosDaemonHttpRoutesTableRow::agentType => $agentType,
            HilosDaemonHttpRoutesTableRow::requests => $tally->requests,
            HilosDaemonHttpRoutesTableRow::serverErrors => $tally->serverErrors,
            HilosDaemonHttpRoutesTableRow::slowestMs => $tally->slowestMs,
        ];
    }

    /**
     * @param string $method Registered HTTP method
     * @param string $path Registered path template
     * @return string Unambiguous encoded method/path key
     */
    private static function rowKey(string $method, string $path): string
    {
        return rawurlencode($method) . self::ROW_KEY_SEPARATOR . rawurlencode($path);
    }
}
