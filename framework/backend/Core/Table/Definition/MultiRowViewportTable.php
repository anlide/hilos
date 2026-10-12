<?php

declare(strict_types=1);

namespace Hilos\Core\Table\Definition;

use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Throwable;

/**
 * A viewport table whose source change reaches several rows, identical in every window.
 *
 * Each mutation takes the ordinary viewport delivery path. Rows that depend on the window's
 * subject instead use {@see WindowScopedViewportTable}.
 */
interface MultiRowViewportTable extends ViewportTable
{
    /**
     * @param SourceChange $change Source change that may affect this table
     * @return list<TableRowMutationDTO> Row mutations, empty when the source change touches no row
     * @throws Throwable Whatever the concrete table's row build raises
     */
    public function buildMutationsForSourceEvent(SourceChange $change): array;
}
