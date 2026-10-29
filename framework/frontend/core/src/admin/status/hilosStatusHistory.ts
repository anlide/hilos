import { z } from 'zod'
import { HilosPages } from '../../routing/hilosPages.js'
import { type ScopeManager } from '../../state/ScopeManager.js'
import { type TableRow } from '../../state/TableRowsStore.js'
import {
  createSignal,
  subscribeSignal,
  type ReadonlySignal,
} from '../../state/signal.js'
import { bindTableViewport } from '../../subscription/bindTableViewport.js'
import { type HilosTableColumnOf } from '../../table/hilosTableColumn.js'
import { TableViewportController } from '../../table/TableViewportController.js'
import { hilosTimeZone } from '../../timeZone/timeZoneState.js'
import { type HilosStatusContext } from './hilosStatus.js'

export const HILOS_STATUS_HISTORY_DATA = 'statusHistory'
export const HILOS_STATUS_HISTORY_TABLE = 'hilosStatusHistory'
export const HILOS_STATUS_HISTORY_SLOT = 'history'
export const STATUS_HISTORY_FILTER_COMPONENT_KEY = 'componentKey'
export const STATUS_HISTORY_FILTER_OUTCOME = 'outcome'
export const STATUS_HISTORY_FILTER_FROM = 'from'
export const STATUS_HISTORY_FILTER_TO = 'to'
export const STATUS_HISTORY_FILTER_TIME_ZONE = 'timeZone'

/** Row payload key of the namespaced identity. */
const STATUS_HISTORY_ID_FIELD = 'id'
/** Row payload key of the source namespace. */
const STATUS_HISTORY_KIND_FIELD = 'kind'
/** Row payload key of the numeric source identity. */
const STATUS_HISTORY_SOURCE_ID_FIELD = 'sourceId'
/** Row payload key of the recorded start. */
export const STATUS_HISTORY_STARTED_AT_FIELD = 'startedAt'
/** Row payload key of the proven exact end. */
export const STATUS_HISTORY_ENDED_AT_FIELD = 'endedAt'
/** Row payload key of the maintenance completion observation. */
export const STATUS_HISTORY_COMPLETION_OBSERVED_AT_FIELD =
  'completionObservedAt'
/** Row payload key of the incident publication. */
export const STATUS_HISTORY_PUBLISHED_AT_FIELD = 'publishedAt'
/** Row payload key of the retained completion reason. */
export const STATUS_HISTORY_OUTCOME_FIELD = 'outcome'
/** Row payload key of the saved component name or maintenance title. */
export const STATUS_HISTORY_TITLE_FIELD = 'title'
/** Row payload key of the saved component keys. */
const STATUS_HISTORY_COMPONENT_KEYS_FIELD = 'componentKeys'
/** Row payload key of the saved publication severity. */
export const STATUS_HISTORY_SEVERITY_FIELD = 'severity'
/** Row payload key of the saved announcement knowledge. */
export const STATUS_HISTORY_ANNOUNCEMENT_KNOWN_FIELD = 'announcementKnown'
/** Row payload key of the saved public announcement identity. */
export const STATUS_HISTORY_WORK_PUBLIC_ID_FIELD = 'workPublicId'

/** Complete page-owned component catalog; null means either source is unavailable. */
export const statusHistoryDataSchema = z
  .strictObject({
    components: z
      .array(
        z.strictObject({ componentKey: z.string().min(1), name: z.string() }),
      )
      .readonly(),
  })
  .readonly()
  .nullable()
export type HilosStatusHistoryData = z.infer<typeof statusHistoryDataSchema>

const momentSchema = z.number().int().nonnegative()
const historyFields = {
  [STATUS_HISTORY_ID_FIELD]: z.string().min(1),
  [STATUS_HISTORY_SOURCE_ID_FIELD]: z.number().int().positive(),
  [STATUS_HISTORY_STARTED_AT_FIELD]: momentSchema,
  [STATUS_HISTORY_TITLE_FIELD]: z.string(),
  [STATUS_HISTORY_COMPONENT_KEYS_FIELD]: z.array(z.string().min(1)).readonly(),
}

/** Exact completed facts, with source-specific nullable fields kept explicit. */
export const statusHistoryRowSchema = z
  .discriminatedUnion(STATUS_HISTORY_KIND_FIELD, [
    z.strictObject({
      ...historyFields,
      [STATUS_HISTORY_KIND_FIELD]: z.literal('incident'),
      [STATUS_HISTORY_ENDED_AT_FIELD]: momentSchema,
      [STATUS_HISTORY_COMPLETION_OBSERVED_AT_FIELD]: z.null(),
      [STATUS_HISTORY_PUBLISHED_AT_FIELD]: momentSchema.nullable(),
      [STATUS_HISTORY_OUTCOME_FIELD]: z.enum([
        'resolved',
        'recovered_before_publication',
        'canceled_publication',
      ]),
      [STATUS_HISTORY_SEVERITY_FIELD]: z.enum([
        'partial_outage',
        'major_outage',
      ]),
      [STATUS_HISTORY_ANNOUNCEMENT_KNOWN_FIELD]: z.null(),
      [STATUS_HISTORY_WORK_PUBLIC_ID_FIELD]: z.null(),
    }),
    z.strictObject({
      ...historyFields,
      [STATUS_HISTORY_KIND_FIELD]: z.literal('maintenance'),
      [STATUS_HISTORY_ENDED_AT_FIELD]: momentSchema.nullable(),
      [STATUS_HISTORY_COMPLETION_OBSERVED_AT_FIELD]: momentSchema,
      [STATUS_HISTORY_PUBLISHED_AT_FIELD]: z.null(),
      [STATUS_HISTORY_OUTCOME_FIELD]: z.literal('maintenance_completed'),
      [STATUS_HISTORY_SEVERITY_FIELD]: z.null(),
      [STATUS_HISTORY_ANNOUNCEMENT_KNOWN_FIELD]: z.boolean(),
      [STATUS_HISTORY_WORK_PUBLIC_ID_FIELD]: z.string().min(1).nullable(),
    }),
  ])
  .refine((row) => row.id === `${row.kind}:${row.sourceId}`)
  .readonly()
export type HilosStatusHistoryRow = z.infer<typeof statusHistoryRowSchema>

/**
 * Resolve a normalized fact only when its row and entity identities agree.
 *
 * @param row The normalizer's row reference.
 * @param scopes The current page's entity stores.
 */
export function resolveHilosStatusHistoryRow(
  row: TableRow,
  scopes: ScopeManager,
): HilosStatusHistoryRow {
  const reference = z
    .strictObject({
      type: z.literal(HILOS_STATUS_HISTORY_SLOT),
      id: z.string().regex(/^(incident|maintenance):[1-9]\d*$/),
    })
    .parse(row.slots[HILOS_STATUS_HISTORY_SLOT])
  const fact = statusHistoryRowSchema.parse(
    scopes.entitySignal(reference).get()?.fields,
  )
  if (row.rowKey !== fact.id || reference.id !== row.rowKey)
    throw new Error('Status history row key does not match its slot.')
  return fact
}

export const HILOS_STATUS_HISTORY_COPY = {
  history: 'History',
  link: 'View history',
  count: 'Completed events',
  unavailable: 'History data is unavailable.',
  startedAt: 'Start',
  duration: 'Duration',
  title: 'What',
  outcome: 'Outcome',
  search: 'Search by component or maintenance title…',
  empty: 'No completed events yet.',
  unknownDuration: 'Unknown',
  unknownEnd: 'The exact end time is unknown.',
  outcomes: {
    resolved: 'Resolved outage',
    recovered_before_publication: 'Recovered before publication',
    canceled_publication: 'Publication canceled manually',
    maintenance_completed: 'Completed maintenance',
  },
} as const

/**
 * Format only the proven interval, never the completion observation.
 *
 * @param row The saved completed fact.
 */
export function hilosStatusHistoryDuration(row: HilosStatusHistoryRow): string {
  if (row.endedAt === null) return HILOS_STATUS_HISTORY_COPY.unknownDuration
  const seconds = Math.floor((row.endedAt - row.startedAt) / 1000)
  const hours = Math.floor(seconds / 3600)
  const minutes = Math.floor((seconds % 3600) / 60)
  return [
    hours > 0 ? `${hours} h` : null,
    minutes > 0 ? `${minutes} min` : null,
    `${seconds % 60} s`,
  ]
    .filter((part) => part !== null)
    .join(' ')
}

export interface HilosStatusHistoryState {
  readonly data: ReadonlySignal<HilosStatusHistoryData>
  start(): void
  dispose(): void
}

/**
 * Observe page data independently of the held viewport and its pending changes.
 *
 * @param context The project's existing page scopes and connection.
 */
export function createHilosStatusHistoryState(
  context: HilosStatusContext,
): HilosStatusHistoryState {
  const data = createSignal<HilosStatusHistoryData>(null)
  const source = context.scopes.pageDataSignal(HILOS_STATUS_HISTORY_DATA)
  let off: (() => void) | undefined
  let disposed = false
  const dispose = (): void => {
    if (disposed) return
    disposed = true
    off?.()
    off = undefined
    data.set(null)
  }
  return {
    data,
    start() {
      if (disposed || off !== undefined) return
      const scope = context.scopes.page()
      const update = (): void => {
        if (context.scopes.page() !== scope) {
          dispose()
          return
        }
        const parsed = statusHistoryDataSchema.safeParse(source.get())
        data.set(
          scope?.key === HilosPages.STATUS_HISTORY && parsed.success
            ? parsed.data
            : null,
        )
      }
      off = subscribeSignal(
        { get: () => ({ scope: context.scopes.page(), data: source.get() }) },
        update,
      )
      update()
    },
    dispose,
  }
}

export interface HilosStatusHistoryTable {
  readonly controller: TableViewportController<HilosStatusHistoryRow>
  start(): void
  dispose(): void
}

/**
 * Bind the read-only merged table to the standard viewport lifecycle.
 *
 * @param context The project's existing page scopes and connection.
 */
export function createHilosStatusHistoryTable(
  context: HilosStatusContext,
): HilosStatusHistoryTable {
  const page = HilosPages.STATUS_HISTORY
  const source = context.scopes.pageDataSignal(HILOS_STATUS_HISTORY_DATA)
  const scope = context.scopes.page()
  const columns: readonly HilosTableColumnOf<HilosStatusHistoryRow>[] = [
    {
      key: STATUS_HISTORY_STARTED_AT_FIELD,
      label: HILOS_STATUS_HISTORY_COPY.startedAt,
      sortable: true,
      source: HILOS_STATUS_HISTORY_SLOT,
    },
    {
      key: STATUS_HISTORY_ENDED_AT_FIELD,
      label: HILOS_STATUS_HISTORY_COPY.duration,
      reads: [STATUS_HISTORY_STARTED_AT_FIELD],
      source: HILOS_STATUS_HISTORY_SLOT,
    },
    {
      key: STATUS_HISTORY_TITLE_FIELD,
      label: HILOS_STATUS_HISTORY_COPY.title,
      card: 'title',
      reads: [
        STATUS_HISTORY_KIND_FIELD,
        STATUS_HISTORY_SEVERITY_FIELD,
        STATUS_HISTORY_ANNOUNCEMENT_KNOWN_FIELD,
        STATUS_HISTORY_WORK_PUBLIC_ID_FIELD,
      ],
      source: HILOS_STATUS_HISTORY_SLOT,
    },
    {
      key: STATUS_HISTORY_OUTCOME_FIELD,
      label: HILOS_STATUS_HISTORY_COPY.outcome,
      reads: [
        STATUS_HISTORY_PUBLISHED_AT_FIELD,
        STATUS_HISTORY_COMPLETION_OBSERVED_AT_FIELD,
        STATUS_HISTORY_ENDED_AT_FIELD,
      ],
      source: HILOS_STATUS_HISTORY_SLOT,
    },
  ]
  const controller = new TableViewportController<HilosStatusHistoryRow>({
    resolve: (row) => resolveHilosStatusHistoryRow(row, context.scopes),
    sendViewport: (descriptor) => {
      // The unfiltered opening answer needs no calendar zone or preset round trip.
      // Add it through the controller before sending a chosen period, so reconnects
      // report the same complete descriptor and one user change sends one request.
      const filter = descriptor.filter
      if (
        (filter[STATUS_HISTORY_FILTER_FROM] !== undefined ||
          filter[STATUS_HISTORY_FILTER_TO] !== undefined) &&
        filter[STATUS_HISTORY_FILTER_TIME_ZONE] !== hilosTimeZone.get()
      ) {
        controller.setFilters({
          [STATUS_HISTORY_FILTER_TIME_ZONE]: hilosTimeZone.get(),
        })
        return
      }
      context.connection.sendTableViewport(
        page,
        HILOS_STATUS_HISTORY_TABLE,
        descriptor,
      )
    },
    sendRendered: (rendered) =>
      context.connection.sendTableRendered(
        page,
        HILOS_STATUS_HISTORY_TABLE,
        rendered,
      ),
    sendFocus: (key) =>
      context.connection.sendTableRowFocus(
        page,
        HILOS_STATUS_HISTORY_TABLE,
        key,
      ),
    sendFacets: (facets) =>
      context.connection.sendTableFacets(
        page,
        HILOS_STATUS_HISTORY_TABLE,
        facets,
      ),
    frame: {
      columns,
      search: { placeholder: HILOS_STATUS_HISTORY_COPY.search },
      filters: [
        {
          kind: 'select',
          key: STATUS_HISTORY_FILTER_COMPONENT_KEY,
          label: 'Component',
          options: () => {
            const parsed = statusHistoryDataSchema.safeParse(source.get())
            return parsed.success &&
              context.scopes.page() === scope &&
              scope?.key === page
              ? (parsed.data?.components.map((component) => ({
                  value: component.componentKey,
                  label: component.name,
                })) ?? [])
              : []
          },
        },
        {
          kind: 'select',
          key: STATUS_HISTORY_FILTER_OUTCOME,
          label: 'Outcome',
          options: () =>
            Object.entries(HILOS_STATUS_HISTORY_COPY.outcomes).map(
              ([value, label]) => ({ value, label }),
            ),
        },
        {
          kind: 'date_range',
          fromKey: STATUS_HISTORY_FILTER_FROM,
          toKey: STATUS_HISTORY_FILTER_TO,
          label: 'Period',
        },
      ],
      empty: { title: HILOS_STATUS_HISTORY_COPY.empty },
    },
  })
  let off: (() => void) | undefined
  let offScope: (() => void) | undefined
  let offZone: (() => void) | undefined
  let offData: (() => void) | undefined
  let disposed = false
  const syncTimeZone = (timeZone: string): void => {
    const filter = controller.filter.get()
    if (
      (filter[STATUS_HISTORY_FILTER_FROM] !== undefined ||
        filter[STATUS_HISTORY_FILTER_TO] !== undefined) &&
      filter[STATUS_HISTORY_FILTER_TIME_ZONE] !== timeZone
    )
      controller.setFilters({ [STATUS_HISTORY_FILTER_TIME_ZONE]: timeZone })
  }
  const dispose = (): void => {
    if (disposed) return
    disposed = true
    off?.()
    offScope?.()
    offZone?.()
    offData?.()
    controller.dispose()
  }
  return {
    controller,
    start() {
      if (disposed || off !== undefined) return
      offScope = subscribeSignal(
        { get: () => context.scopes.page() },
        (current) => {
          if (current !== scope) dispose()
        },
      )
      syncTimeZone(hilosTimeZone.get())
      offZone = subscribeSignal(hilosTimeZone, syncTimeZone)
      offData = subscribeSignal(source, (value) => {
        const parsed = statusHistoryDataSchema.safeParse(value)
        if (!parsed.success || parsed.data === null) controller.releaseFocus()
      })
      off = bindTableViewport(
        context.connection,
        context.scopes,
        { page, tableKey: HILOS_STATUS_HISTORY_TABLE },
        controller,
      )
    },
    dispose,
  }
}
