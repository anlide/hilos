import { z } from 'zod'
import { hideable } from '../../state/hideableSchema.js'
import { type Hideable } from '../../state/hiddenValue.js'
import { type TableRow } from '../../state/TableRowsStore.js'
import { bindTableViewport } from '../../subscription/bindTableViewport.js'
import { type HilosTableColumnOf } from '../../table/hilosTableColumn.js'
import { type HilosTableFrame } from '../../table/tableFrame.js'
import { TableViewportController } from '../../table/TableViewportController.js'
import { type HilosAnalyticsContext } from './hilosAnalytics.js'

const ANALYTICS_BROWSER_SESSIONS_TABLE = 'hilosAnalyticsBrowserSessions'
const ANALYTICS_BROWSER_SESSIONS_SLOT = 'browserSession'

/** Filter key selecting the last signed-in account from the page address. */
export const ANALYTICS_BROWSER_SESSIONS_FILTER_USER = 'user'

/** Row payload key of the browser session's first source moment. */
export const ANALYTICS_BROWSER_SESSION_FIRST_SEEN_TS_FIELD = 'firstSeenTs'
/** Row payload key of the browser session's last source moment. */
export const ANALYTICS_BROWSER_SESSION_LAST_SEEN_TS_FIELD = 'lastSeenTs'
/** Row payload key of the short browser and operating-system label. */
export const ANALYTICS_BROWSER_SESSION_BROWSER_FIELD = 'browser'
/** Row payload key of the count of recorded actions. */
export const ANALYTICS_BROWSER_SESSION_ACTION_COUNT_FIELD = 'actionCount'

/** One browser session in which the selected person signed in last. */
export interface HilosAnalyticsBrowserSessionRow {
  readonly rowKey: number
  readonly firstSeenTs: Hideable<number>
  readonly lastSeenTs: Hideable<number>
  readonly browser: Hideable<string | null>
  readonly actionCount: Hideable<number>
}

/** Browser controller and mount lifecycle of a person's browser sessions. */
export interface HilosAnalyticsBrowserSessionsTable {
  readonly controller: TableViewportController<HilosAnalyticsBrowserSessionRow>
  start(): void
  dispose(): void
}

/** Page address and numeric account preset supplied by the page. */
export interface HilosAnalyticsBrowserSessionsOptions {
  readonly page: string
  readonly userId: number
}

const browserSessionSchema = z.object({
  [ANALYTICS_BROWSER_SESSION_FIRST_SEEN_TS_FIELD]: hideable(z.number()).catch(
    0,
  ),
  [ANALYTICS_BROWSER_SESSION_LAST_SEEN_TS_FIELD]: hideable(z.number()).catch(0),
  [ANALYTICS_BROWSER_SESSION_BROWSER_FIELD]: hideable(
    z.string().nullable(),
  ).catch(null),
  [ANALYTICS_BROWSER_SESSION_ACTION_COUNT_FIELD]: hideable(z.number()).catch(0),
})

/** Read an inline slot, or an empty record when absent or malformed. */
function recordSlot(slot: unknown): Record<string, unknown> {
  return typeof slot === 'object' && slot !== null && !Array.isArray(slot)
    ? (slot as Record<string, unknown>)
    : {}
}

/**
 * Resolve one inline session slot, preserving hidden fields and defaulting malformed values.
 *
 * @param row Table row in the page scope; the fragment owns its row key.
 */
export function resolveHilosAnalyticsBrowserSessionRow(
  row: TableRow,
): HilosAnalyticsBrowserSessionRow {
  const slot = recordSlot(row.slots[ANALYTICS_BROWSER_SESSIONS_SLOT])
  return {
    rowKey: Number(row.rowKey),
    ...browserSessionSchema.parse(slot),
  }
}

/**
 * Name a browser session by its numeric analytics row key.
 *
 * @param id Numeric browser-session row key, never a browser token.
 */
export function formatAnalyticsSessionNumber(id: number): string {
  return `#${id}`
}

/**
 * Build the frozen browser-sessions viewport with the page's account preset.
 *
 * @param context Connection and page scope.
 * @param options Page address and the account that signed in last.
 */
export function createHilosAnalyticsBrowserSessionsTable(
  context: HilosAnalyticsContext,
  options: HilosAnalyticsBrowserSessionsOptions,
): HilosAnalyticsBrowserSessionsTable {
  const columns: HilosTableColumnOf<HilosAnalyticsBrowserSessionRow>[] = [
    { key: 'rowKey', label: 'Session', card: 'title' },
    {
      key: ANALYTICS_BROWSER_SESSION_FIRST_SEEN_TS_FIELD,
      label: 'First seen',
    },
    {
      key: ANALYTICS_BROWSER_SESSION_LAST_SEEN_TS_FIELD,
      label: 'Last seen',
      sortable: true,
    },
    { key: ANALYTICS_BROWSER_SESSION_BROWSER_FIELD, label: 'Browser' },
    { key: ANALYTICS_BROWSER_SESSION_ACTION_COUNT_FIELD, label: 'Actions' },
  ]
  const frame: HilosTableFrame = {
    columns,
    empty: { title: 'No browser sessions where this person signed in last' },
  }
  const controller =
    new TableViewportController<HilosAnalyticsBrowserSessionRow>({
      resolve: resolveHilosAnalyticsBrowserSessionRow,
      sendViewport: (descriptor) =>
        context.connection.sendTableViewport(
          options.page,
          ANALYTICS_BROWSER_SESSIONS_TABLE,
          descriptor,
        ),
      sendRendered: (rendered) =>
        context.connection.sendTableRendered(
          options.page,
          ANALYTICS_BROWSER_SESSIONS_TABLE,
          rendered,
        ),
      initialFilter: {
        [ANALYTICS_BROWSER_SESSIONS_FILTER_USER]: options.userId,
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
          { page: options.page, tableKey: ANALYTICS_BROWSER_SESSIONS_TABLE },
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
