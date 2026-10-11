// SCAFFOLD: The browser-session page mounts this viewport in HIL-1419.

import { readNumberOrNull, readStringOrNull } from '../../state/fieldReaders.js'
import { type TableRow } from '../../state/TableRowsStore.js'
import { bindTableViewport } from '../../subscription/bindTableViewport.js'
import { type HilosTableColumnOf } from '../../table/hilosTableColumn.js'
import { type HilosTableFrame } from '../../table/tableFrame.js'
import { TableViewportController } from '../../table/TableViewportController.js'
import { type HilosAnalyticsContext } from './hilosAnalytics.js'

const ANALYTICS_ACTIONS_TABLE = 'hilosAnalyticsActions'
const ANALYTICS_ACTIONS_SLOT = 'action'

/** Filter key selecting the browser session from the page address. */
export const ANALYTICS_ACTIONS_FILTER_BROWSER_SESSION = 'browserSession'

/** Row payload key of the action's source moment. */
export const ANALYTICS_ACTION_CREATED_TS_FIELD = 'createdTs'
/** Row payload key of the recorded page name. */
export const ANALYTICS_ACTION_PAGE_NAME_FIELD = 'pageName'
/** Row payload key of the recorded action name. */
export const ANALYTICS_ACTION_NAME_FIELD = 'actionName'

/** One action recorded in a numbered browser session. */
export interface HilosAnalyticsActionRow {
  readonly rowKey: number
  readonly createdTs: number
  readonly pageName: string | null
  readonly actionName: string
}

/** Browser controller and mount lifecycle of the session's actions. */
export interface HilosAnalyticsActionsTable {
  readonly controller: TableViewportController<HilosAnalyticsActionRow>
  start(): void
  dispose(): void
}

/** Page address and numeric browser-session preset supplied by the page. */
export interface HilosAnalyticsActionsOptions {
  readonly page: string
  readonly browserSessionId: number
}

/** Read an inline slot, or an empty record when absent or malformed. */
function recordSlot(slot: unknown): Record<string, unknown> {
  return typeof slot === 'object' && slot !== null && !Array.isArray(slot)
    ? (slot as Record<string, unknown>)
    : {}
}

/**
 * Resolve one inline action slot, folding hidden fields into safe defaults.
 *
 * @param row Table row in the page scope; the fragment owns its row key.
 */
export function resolveHilosAnalyticsActionRow(
  row: TableRow,
): HilosAnalyticsActionRow {
  const slot = recordSlot(row.slots[ANALYTICS_ACTIONS_SLOT])
  return {
    rowKey: Number(row.rowKey),
    createdTs: readNumberOrNull(slot, ANALYTICS_ACTION_CREATED_TS_FIELD) ?? 0,
    pageName: readStringOrNull(slot, ANALYTICS_ACTION_PAGE_NAME_FIELD),
    actionName: readStringOrNull(slot, ANALYTICS_ACTION_NAME_FIELD) ?? '',
  }
}

/**
 * Build the frozen actions viewport with the page's browser-session preset.
 *
 * @param context Connection and page scope.
 * @param options Page address and selected browser-session number.
 */
export function createHilosAnalyticsActionsTable(
  context: HilosAnalyticsContext,
  options: HilosAnalyticsActionsOptions,
): HilosAnalyticsActionsTable {
  const columns: HilosTableColumnOf<HilosAnalyticsActionRow>[] = [
    {
      key: ANALYTICS_ACTION_CREATED_TS_FIELD,
      label: 'When',
      sortable: true,
      card: 'title',
      cellClass: 'text-nowrap',
    },
    { key: ANALYTICS_ACTION_PAGE_NAME_FIELD, label: 'Page' },
    { key: ANALYTICS_ACTION_NAME_FIELD, label: 'Action' },
  ]
  const frame: HilosTableFrame = {
    columns,
    empty: { title: 'No actions recorded in this browser session yet' },
  }
  const controller = new TableViewportController<HilosAnalyticsActionRow>({
    resolve: resolveHilosAnalyticsActionRow,
    sendViewport: (descriptor) =>
      context.connection.sendTableViewport(
        options.page,
        ANALYTICS_ACTIONS_TABLE,
        descriptor,
      ),
    sendRendered: (rendered) =>
      context.connection.sendTableRendered(
        options.page,
        ANALYTICS_ACTIONS_TABLE,
        rendered,
      ),
    initialFilter: {
      [ANALYTICS_ACTIONS_FILTER_BROWSER_SESSION]: options.browserSessionId,
    },
    frame,
  })
  const teardown: Array<() => void> = []

  return {
    controller,
    start() {
      teardown.push(
        bindTableViewport(
          context.connection,
          context.scopes,
          { page: options.page, tableKey: ANALYTICS_ACTIONS_TABLE },
          controller,
        ),
      )
    },
    dispose() {
      for (const off of teardown.splice(0)) {
        off()
      }
    },
  }
}
