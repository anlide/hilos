<?php

declare(strict_types=1);

namespace Hilos\Core\Table\Definition;

use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Throwable;

/**
 * Contract for a viewport table whose rows depend on the subject its window is opened on.
 *
 * An ordinary viewport table builds one mutation per source change and knows nothing of the
 * window that will receive it ({@see ViewportTable::buildMutationForSourceEvent()}): a row of the
 * users table is the same row for every tab that shows it. A table of names is not like that. Its
 * window is opened on one language or one country, carried in the window's filter, and the row of
 * a language in the window of English is a different row from the row of the same language in the
 * window of German. One change also reaches several rows at once — a country renamed in the
 * default language relabels every language that has a locale of that country.
 *
 * Such a table builds the change for ONE window, reading the subject from that window's query, and
 * answers with a list. The BrowserContext asks it once per open window in place of the single-row
 * build, and runs every mutation of the list through the same live road a single one takes: the
 * marks of the live table — updated in place, will move, will leave, arriving — stay intact, which
 * is the whole difference from sending the window again.
 *
 * {@see ViewportTable::buildMutationForSourceEvent()} stays what TableDefinition makes it, null:
 * without a window there is no subject, and no row to build.
 */
interface WindowScopedViewportTable extends ViewportTable
{
    /**
     * Builds the row mutations one source change makes in one window of this table.
     *
     * A throw freezes this window alone, the way a refused single-row build does
     * ({@see BrowserContext}); the windows of other subjects go on living.
     *
     * @param SourceChange $change Source change that may affect this table
     * @param TableQueryDTO $window Query the window was served by; its filter names the subject
     * @return list<TableRowMutationDTO> Row mutations for this window, empty when the change does not touch it
     * @throws Throwable Whatever the concrete table's row build raises
     */
    public function buildMutationsForWindow(SourceChange $change, TableQueryDTO $window): array;
}
