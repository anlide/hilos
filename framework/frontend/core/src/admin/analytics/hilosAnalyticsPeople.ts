import { z } from 'zod'
import { formatSnapshotMoment } from '../../format/moment.js'
import { hideable } from '../../state/hideableSchema.js'
import { isHiddenValue, type Hideable } from '../../state/hiddenValue.js'
import { subscribeSignal } from '../../state/signal.js'
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

export const ANALYTICS_PEOPLE_TABLE = 'hilosAnalyticsPeople'
export const ANALYTICS_PEOPLE_SLOT = 'person'

/** Row payload key of the fragment's numeric person address. */
export const ANALYTICS_PERSON_ROW_KEY_FIELD = 'rowKey'
/** Row payload key of the current account name, or null after deletion. */
export const ANALYTICS_PERSON_NAME_FIELD = 'name'
/** Row payload key of the browser-session count in the chosen period. */
export const ANALYTICS_PERSON_SESSION_COUNT_FIELD = 'sessionCount'
/** Row payload key of the last matching browser-session moment. */
export const ANALYTICS_PERSON_LAST_SEEN_TS_FIELD = 'lastSeenTs'

/** A person grouped from matching browser sessions, addressed by fragment identity. */
export interface HilosAnalyticsPersonRow {
  readonly rowKey: number
  readonly name: Hideable<string | null>
  readonly sessionCount: Hideable<number>
  readonly lastSeenTs: Hideable<number>
}

const safeCountSchema = z
  .number()
  .int()
  .nonnegative()
  .max(Number.MAX_SAFE_INTEGER)
const personSchema = z.object({
  [ANALYTICS_PERSON_NAME_FIELD]: hideable(z.string().nullable()),
  [ANALYTICS_PERSON_SESSION_COUNT_FIELD]: hideable(safeCountSchema),
  [ANALYTICS_PERSON_LAST_SEEN_TS_FIELD]: hideable(safeCountSchema),
})
const personRowKeySchema = z
  .string()
  .refine((key) => String(Number(key)) === key)
  .transform(Number)
  .pipe(z.number().int().positive().max(Number.MAX_SAFE_INTEGER))

/**
 * Read the inline person without substituting zeros for malformed data.
 * Only the fragment supplies identity; extra slot fields never supply an id.
 *
 * @param row Normalized table row whose numeric fragment key became a string.
 * @throws ZodError When the fragment key or required inline fields are invalid.
 */
export function resolveHilosAnalyticsPersonRow(
  row: TableRow,
): HilosAnalyticsPersonRow {
  return {
    rowKey: personRowKeySchema.parse(row.rowKey),
    ...personSchema.parse(row.slots[ANALYTICS_PEOPLE_SLOT]),
  }
}

/**
 * Name the person link, leaving the viewer's hidden mark for its own renderer.
 *
 * @param row The person whose public number remains available even with a hidden name.
 */
export function formatAnalyticsPersonName(
  row: HilosAnalyticsPersonRow,
): string {
  if (isHiddenValue(row.name)) return `#${row.rowKey}`

  return row.name === null
    ? `Deleted user #${row.rowKey}`
    : `${row.name} (#${row.rowKey})`
}

/**
 * Format Last seen in the table's captured zone, independent of later reader changes.
 *
 * @param ms The unhidden source moment in epoch milliseconds.
 * @param periodContext The context held for this table opening.
 */
export function formatAnalyticsPeopleTime(
  ms: number,
  periodContext: AnalyticsPeriodContext,
): string {
  return (
    formatSnapshotMoment(ms, periodContext.timeZone, { kind: 'dateTime' }) ??
    ANALYTICS_NO_VALUE
  )
}

/** People page address and optional deterministic opening context. */
export interface HilosAnalyticsPeopleOptions {
  readonly page: string
  readonly initialPeriod?: AnalyticsPeriod
  readonly periodContext?: AnalyticsPeriodContext
}

/** Standard table controller and the calendar context its cells format against. */
export interface HilosAnalyticsPeopleTable {
  readonly controller: TableViewportController<HilosAnalyticsPersonRow>
  readonly periodContext: AnalyticsPeriodContext
  start(): void
  dispose(): void
}

/**
 * Build People with a context-only reset baseline and a separate opening choice.
 * The outgoing callback stays closed until binding: the first real viewport
 * already asks for Today, while Reset and Any time retain the same context.
 *
 * @param context Connection and current page scope.
 * @param options Page address, opening choice and optional captured context.
 */
export function createHilosAnalyticsPeopleTable(
  context: HilosAnalyticsContext,
  options: HilosAnalyticsPeopleOptions,
): HilosAnalyticsPeopleTable {
  const period = createHilosAnalyticsPeriod(options)
  const columns: HilosTableColumnOf<HilosAnalyticsPersonRow>[] = [
    {
      key: ANALYTICS_PERSON_ROW_KEY_FIELD,
      label: 'Person',
      card: 'title',
      reads: [ANALYTICS_PERSON_NAME_FIELD],
    },
    { key: ANALYTICS_PERSON_SESSION_COUNT_FIELD, label: 'Sessions' },
    {
      key: ANALYTICS_PERSON_LAST_SEEN_TS_FIELD,
      label: 'Last seen',
      sortable: true,
    },
  ]
  let bound = false
  const controller = new TableViewportController<HilosAnalyticsPersonRow>({
    resolve: resolveHilosAnalyticsPersonRow,
    initialFilter: period.initialFilter,
    frame: {
      columns,
      filters: [period.filter],
      empty: { title: 'No people in the selected period' },
    },
    sendViewport: (descriptor) => {
      if (bound)
        context.connection.sendTableViewport(
          options.page,
          ANALYTICS_PEOPLE_TABLE,
          descriptor,
        )
    },
    sendRendered: (rendered) => {
      if (bound)
        context.connection.sendTableRendered(
          options.page,
          ANALYTICS_PEOPLE_TABLE,
          rendered,
        )
    },
    sendFacets: (facets) => {
      if (bound)
        context.connection.sendTableFacets(
          options.page,
          ANALYTICS_PEOPLE_TABLE,
          facets,
        )
    },
  })
  controller.setFilter(ANALYTICS_PERIOD_FILTER, period.initialPeriod)
  // The suppressed initialization ask must leave no pending request or timer.
  controller.resetWindowRequests()
  let off: (() => void) | undefined
  let offScope: (() => void) | undefined
  let disposed = false
  const dispose = (): void => {
    if (disposed) return
    disposed = true
    bound = false
    off?.()
    off = undefined
    offScope?.()
    offScope = undefined
    controller.dispose()
  }

  return {
    controller,
    periodContext: period.context,
    start() {
      if (disposed || off !== undefined) return
      const scope = context.scopes.page()
      controller.resetWindowRequests()
      // on(projectSignal) replays a buffered page answer synchronously. Its
      // first Today request must already be able to leave during binding.
      bound = true
      off = bindTableViewport(
        context.connection,
        context.scopes,
        { page: options.page, tableKey: ANALYTICS_PEOPLE_TABLE },
        controller,
      )
      offScope = subscribeSignal(
        { get: () => context.scopes.page() },
        (current) => {
          if (current !== scope) dispose()
        },
      )
    },
    dispose,
  }
}
