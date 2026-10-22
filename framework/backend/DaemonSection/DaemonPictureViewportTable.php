<?php

declare(strict_types=1);

namespace Hilos\DaemonSection;

use Hilos\Core\Table\Definition\ViewportTable;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\HilosException;

/** A mirror table projects the same subject and viewer from two immutable pictures. */
interface DaemonPictureViewportTable extends ViewportTable
{
    /**
     * Diff the subject's complete rows, before pagination and search membership.
     * A surviving row leaving search is an update; only leaving the subject is a delete.
     *
     * @param ?ClusterDaemonPicture $before Picture before this portion, null before the first report
     * @param TableQueryDTO $query Current subject, search and viewer scope
     * @return list<TableRowMutationDTO> Changes against the current mirror, with current shown bodies
     * @throws TableRowKeyMissingException When a projected row carries no key
     * @throws HilosException When a concrete projection cannot read its picture, catalog or viewer scope
     */
    public function pictureMutations(?ClusterDaemonPicture $before, TableQueryDTO $query): array;
}
