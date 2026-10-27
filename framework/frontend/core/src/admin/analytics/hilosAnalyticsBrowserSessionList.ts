import { z } from 'zod'
import { formatSnapshotMoment } from '../../format/moment.js'
import { resolveHilosPath } from '../../routing/hilosAdmin.js'
import { HilosPages } from '../../routing/hilosPages.js'
import { type PageRouteMatch } from '../../routing/PageRouter.js'
import { hideable } from '../../state/hideableSchema.js'
import {
  HIDDEN_VALUE,
  isHiddenValue,
  type Hideable,
} from '../../state/hiddenValue.js'
import { subscribeSignal, type ReadonlySignal } from '../../state/signal.js'
import { type TableRow } from '../../state/TableRowsStore.js'
import { bindTableViewport } from '../../subscription/bindTableViewport.js'
import { type HilosTableColumnOf } from '../../table/hilosTableColumn.js'
import { TableViewportController } from '../../table/TableViewportController.js'
import {
  ANALYTICS_NO_VALUE,
  type HilosAnalyticsContext,
} from './hilosAnalytics.js'
import {
  ANALYTICS_PERIOD_FILTER,
  createHilosAnalyticsPeriod,
  type AnalyticsPeriod,
  type AnalyticsPeriodContext,
} from './hilosAnalyticsPeriod.js'

export const ANALYTICS_BROWSER_SESSION_LIST_TABLE =
  'hilosAnalyticsBrowserSessionList'
export const ANALYTICS_BROWSER_SESSION_LIST_SLOT = 'browserSession'
export const ANALYTICS_BROWSER_SESSIONS_PERIOD_ROUTE_PARAM = 'period'

/** Row payload key of the public browser-session fragment identity. */
export const ANALYTICS_BROWSER_SESSION_LIST_ROW_KEY_FIELD = 'rowKey'
/** Row payload key of the last signed-in account, or null for a guest. */
export const ANALYTICS_BROWSER_SESSION_LIST_LAST_SIGNED_IN_USER_ID_FIELD =
  'lastSignedInUserId'
/** Row payload key of the current account name, or null after deletion. */
export const ANALYTICS_BROWSER_SESSION_LIST_LAST_SIGNED_IN_USER_NAME_FIELD =
  'lastSignedInUserName'
/** Row payload key of the short browser and operating-system label. */
export const ANALYTICS_BROWSER_SESSION_LIST_BROWSER_FIELD = 'browser'
/** Row payload key of the browser session's first source moment. */
export const ANALYTICS_BROWSER_SESSION_LIST_FIRST_SEEN_TS_FIELD = 'firstSeenTs'
/** Row payload key of the browser session's last source moment. */
export const ANALYTICS_BROWSER_SESSION_LIST_LAST_SEEN_TS_FIELD = 'lastSeenTs'
/** Row payload key of the count of recorded actions. */
export const ANALYTICS_BROWSER_SESSION_LIST_ACTION_COUNT_FIELD = 'actionCount'

/** One session overlapping the chosen period; only the fragment supplies identity. */
export interface HilosAnalyticsBrowserSessionListRow {
  readonly rowKey: number
  readonly lastSignedInUserId: Hideable<number | null>
  readonly lastSignedInUserName: Hideable<string | null>
  readonly browser: Hideable<string | null>
  readonly firstSeenTs: Hideable<number>
  readonly lastSeenTs: Hideable<number>
  readonly actionCount: Hideable<number>
}

const safeCountSchema = z
  .number()
  .int()
  .nonnegative()
  .max(Number.MAX_SAFE_INTEGER)
const safeIdSchema = z.number().int().positive().max(Number.MAX_SAFE_INTEGER)
const sessionSchema = z.object({
  [ANALYTICS_BROWSER_SESSION_LIST_LAST_SIGNED_IN_USER_ID_FIELD]: hideable(
    safeIdSchema.nullable(),
  ),
  [ANALYTICS_BROWSER_SESSION_LIST_LAST_SIGNED_IN_USER_NAME_FIELD]: hideable(
    z.string().nullable(),
  ),
  [ANALYTICS_BROWSER_SESSION_LIST_BROWSER_FIELD]: hideable(
    z.string().nullable(),
  ),
  [ANALYTICS_BROWSER_SESSION_LIST_FIRST_SEEN_TS_FIELD]:
    hideable(safeCountSchema),
  [ANALYTICS_BROWSER_SESSION_LIST_LAST_SEEN_TS_FIELD]:
    hideable(safeCountSchema),
  [ANALYTICS_BROWSER_SESSION_LIST_ACTION_COUNT_FIELD]:
    hideable(safeCountSchema),
})
const rowKeySchema = z
  .string()
  .refine((key) => String(Number(key)) === key)
  .transform(Number)
  .pipe(safeIdSchema)

/**
 * Resolve an inline browser session without filling malformed fields with defaults.
 *
 * @param row Normalized table row with a string fragment key.
 * @throws ZodError When the fragment key or any required field is invalid.
 */
export function resolveHilosAnalyticsBrowserSessionListRow(
  row: TableRow,
): HilosAnalyticsBrowserSessionListRow {
  return {
    rowKey: rowKeySchema.parse(row.rowKey),
    ...sessionSchema.parse(row.slots[ANALYTICS_BROWSER_SESSION_LIST_SLOT]),
  }
}

/**
 * Name a guest, deleted account or current account without revealing hidden data.
 *
 * @param row The resolved session whose two account fields determine the label.
 */
export function formatAnalyticsBrowserSessionListPerson(
  row: HilosAnalyticsBrowserSessionListRow,
): Hideable<string> {
  const { lastSignedInUserId: id, lastSignedInUserName: name } = row
  if (isHiddenValue(id) || isHiddenValue(name)) return HIDDEN_VALUE
  if (id === null) return 'Not signed in'
  return name === null ? `Deleted user #${id}` : `${name} (#${id})`
}

/**
 * Format a source moment with seconds in this opening's captured reader zone.
 *
 * @param ms The visible source moment in epoch milliseconds.
 * @param periodContext The immutable calendar context held by this table.
 */
export function formatAnalyticsBrowserSessionListTime(
  ms: number,
  periodContext: AnalyticsPeriodContext,
): string {
  return (
    formatSnapshotMoment(ms, periodContext.timeZone, { kind: 'dateTime' }) ??
    ANALYTICS_NO_VALUE
  )
}

/** The structural navigator seam used for same-page period changes and history. */
export interface HilosAnalyticsBrowserSessionListAddress {
  readonly currentRoute: ReadonlySignal<PageRouteMatch>
  /**
   * Rewrite the current URL while keeping the page subscription.
   *
   * @param pathname The canonical path of the selected period.
   */
  replacePath(pathname: string): void
}

/** Optional deterministic calendar context for one page opening. */
export interface HilosAnalyticsBrowserSessionListOptions {
  readonly periodContext?: AnalyticsPeriodContext
}

/** The standard table controller and the calendar context its cells format against. */
export interface HilosAnalyticsBrowserSessionListTable {
  readonly controller: TableViewportController<HilosAnalyticsBrowserSessionListRow>
  readonly periodContext: AnalyticsPeriodContext
  start(): void
  dispose(): void
}

/**
 * Read a decoded list address, defaulting bare and unknown tails to Today.
 *
 * @param params The router's decoded path parameters.
 */
export function readAnalyticsBrowserSessionsAddress(
  params: Readonly<Record<string, string>>,
): AnalyticsPeriod {
  const period = params[ANALYTICS_BROWSER_SESSIONS_PERIOD_ROUTE_PARAM]
  switch (period) {
    case 'any-time':
    case 'this-hour':
    case 'this-week':
      return period
    default:
      return 'today'
  }
}

/**
 * Address a selected period explicitly, including Any time after a filter reset.
 *
 * @param period The visible Period choice.
 */
export function analyticsBrowserSessionsPath(period: AnalyticsPeriod): string {
  return resolveHilosPath(HilosPages.ANALYTICS_BROWSER_SESSIONS, {
    [ANALYTICS_BROWSER_SESSIONS_PERIOD_ROUTE_PARAM]: period,
  })
}

/**
 * Build one page opening with a context-only reset baseline and its actual URL preset.
 * The controller owns UI choice; createHilosAnalyticsPeriod owns the captured
 * calendar. No other process reads either, so no backend store or Hilos::$rt is needed.
 *
 * @param context Connection and current page scope.
 * @param address Navigator carrying the actual route and silent URL replacement.
 * @param options Optional deterministic opening context.
 */
export function createHilosAnalyticsBrowserSessionListTable(
  context: HilosAnalyticsContext,
  address: HilosAnalyticsBrowserSessionListAddress,
  options: HilosAnalyticsBrowserSessionListOptions = {},
): HilosAnalyticsBrowserSessionListTable {
  const period = createHilosAnalyticsPeriod({
    ...options,
    initialPeriod: readAnalyticsBrowserSessionsAddress(
      address.currentRoute.get().params,
    ),
  })
  const columns: HilosTableColumnOf<HilosAnalyticsBrowserSessionListRow>[] = [
    {
      key: ANALYTICS_BROWSER_SESSION_LIST_ROW_KEY_FIELD,
      label: 'Session',
      card: 'title',
    },
    {
      key: ANALYTICS_BROWSER_SESSION_LIST_LAST_SIGNED_IN_USER_ID_FIELD,
      label: 'Signed in',
      reads: [ANALYTICS_BROWSER_SESSION_LIST_LAST_SIGNED_IN_USER_NAME_FIELD],
    },
    { key: ANALYTICS_BROWSER_SESSION_LIST_BROWSER_FIELD, label: 'Browser' },
    {
      key: ANALYTICS_BROWSER_SESSION_LIST_FIRST_SEEN_TS_FIELD,
      label: 'First seen',
    },
    {
      key: ANALYTICS_BROWSER_SESSION_LIST_LAST_SEEN_TS_FIELD,
      label: 'Last seen',
      sortable: true,
    },
    {
      key: ANALYTICS_BROWSER_SESSION_LIST_ACTION_COUNT_FIELD,
      label: 'Actions',
    },
  ]
  let bound = false
  const controller =
    new TableViewportController<HilosAnalyticsBrowserSessionListRow>({
      resolve: resolveHilosAnalyticsBrowserSessionListRow,
      initialFilter: period.initialFilter,
      frame: {
        columns,
        filters: [period.filter],
        empty: { title: 'No browser sessions in the selected period' },
      },
      sendViewport: (descriptor) => {
        if (bound)
          context.connection.sendTableViewport(
            HilosPages.ANALYTICS_BROWSER_SESSIONS,
            ANALYTICS_BROWSER_SESSION_LIST_TABLE,
            descriptor,
          )
      },
      sendRendered: (rendered) => {
        if (bound)
          context.connection.sendTableRendered(
            HilosPages.ANALYTICS_BROWSER_SESSIONS,
            ANALYTICS_BROWSER_SESSION_LIST_TABLE,
            rendered,
          )
      },
      sendFacets: (facets) => {
        if (bound)
          context.connection.sendTableFacets(
            HilosPages.ANALYTICS_BROWSER_SESSIONS,
            ANALYTICS_BROWSER_SESSION_LIST_TABLE,
            facets,
          )
      },
    })
  controller.setFilter(ANALYTICS_PERIOD_FILTER, period.initialPeriod)
  controller.resetWindowRequests()
  const selectedPeriod = (): AnalyticsPeriod =>
    (controller.filter.get()[ANALYTICS_PERIOD_FILTER] as
      AnalyticsPeriod | undefined) ?? 'any-time'
  const syncRoute = (route: PageRouteMatch): void => {
    if (route.page !== HilosPages.ANALYTICS_BROWSER_SESSIONS) return
    const next = readAnalyticsBrowserSessionsAddress(route.params)
    if (next !== selectedPeriod())
      controller.setFilter(
        ANALYTICS_PERIOD_FILTER,
        next === 'any-time' ? undefined : next,
      )
  }
  const teardown: Array<() => void> = []
  let started = false
  let disposed = false
  const dispose = (): void => {
    if (disposed) return
    disposed = true
    bound = false
    for (const off of teardown.splice(0)) off()
    controller.dispose()
  }

  return {
    controller,
    periodContext: period.context,
    start() {
      if (disposed || started) return
      started = true
      const scope = context.scopes.page()
      // A route may have moved between construction and mount.
      syncRoute(address.currentRoute.get())
      controller.resetWindowRequests()
      teardown.push(
        subscribeSignal(address.currentRoute, syncRoute),
        subscribeSignal(controller.filter, () => {
          const route = address.currentRoute.get()
          if (
            route.page === HilosPages.ANALYTICS_BROWSER_SESSIONS &&
            selectedPeriod() !==
              readAnalyticsBrowserSessionsAddress(route.params)
          ) {
            address.replacePath(analyticsBrowserSessionsPath(selectedPeriod()))
          }
        }),
      )
      // Binding can replay a buffered page answer synchronously.
      bound = true
      teardown.push(
        bindTableViewport(
          context.connection,
          context.scopes,
          {
            page: HilosPages.ANALYTICS_BROWSER_SESSIONS,
            tableKey: ANALYTICS_BROWSER_SESSION_LIST_TABLE,
          },
          controller,
        ),
      )
      teardown.push(
        subscribeSignal({ get: () => context.scopes.page() }, (current) => {
          if (current !== scope) dispose()
        }),
      )
    },
    dispose,
  }
}
