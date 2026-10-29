import { z } from 'zod'
import { HilosPages } from '../../routing/hilosPages.js'
import { type ScopeManager } from '../../state/ScopeManager.js'
import { type TableRow } from '../../state/TableRowsStore.js'
import {
  createSignal,
  subscribeSignal,
  type ReadonlySignal,
} from '../../state/signal.js'
import { publicStatusWorkSchema } from '../../status/statusFiles.js'
import { bindTableViewport } from '../../subscription/bindTableViewport.js'
import { type HilosTableColumnOf } from '../../table/hilosTableColumn.js'
import { TableViewportController } from '../../table/TableViewportController.js'
import { type HilosStatusContext } from './hilosStatus.js'

/** Page-data key for the catalog and independent summary. */
export const HILOS_STATUS_WORKS_DATA = 'statusWorks'
export const HILOS_STATUS_WORKS_TABLE = 'hilosStatusWorks'
export const HILOS_STATUS_WORK_SLOT = 'work'
/** Component facet key, independent of the row's list of selected components. */
export const STATUS_WORK_FILTER_COMPONENT_KEY = 'componentKey'
/** Row payload key of the work's id field. */
export const STATUS_WORK_ID_FIELD = 'id'
/** Row payload key of the work's publicId field. */
export const STATUS_WORK_PUBLIC_ID_FIELD = 'publicId'
/** Row payload key of the work's title field. */
export const STATUS_WORK_TITLE_FIELD = 'title'
/** Row payload key of the work's description field. */
export const STATUS_WORK_DESCRIPTION_FIELD = 'description'
/** Row payload key of the work's componentKeys field. */
export const STATUS_WORK_COMPONENT_KEYS_FIELD = 'componentKeys'
/** Row payload key of the work's startsAt field. */
export const STATUS_WORK_STARTS_AT_FIELD = 'startsAt'
/** Row payload key of the work's endsAt field. */
export const STATUS_WORK_ENDS_AT_FIELD = 'endsAt'
/** Row payload key of the work's version field. */
export const STATUS_WORK_VERSION_FIELD = 'version'
/** Row payload key of the work's canceledAt field. */
export const STATUS_WORK_CANCELED_AT_FIELD = 'canceledAt'
/** Row payload key of the work's consumedAt field. */
export const STATUS_WORK_CONSUMED_AT_FIELD = 'consumedAt'
/** Row payload key of the work's expiredAt field. */
export const STATUS_WORK_EXPIRED_AT_FIELD = 'expiredAt'
/** Row payload key of the work's lastMailQueuedAt field. */
export const STATUS_WORK_LAST_MAIL_QUEUED_AT_FIELD = 'lastMailQueuedAt'

/** Same public six-field Work used by the static reader, kept in the lazy section. */
export const statusPublicWorkSchema = publicStatusWorkSchema.readonly()
export type HilosStatusPublicWork = z.infer<typeof statusPublicWorkSchema>

/** A known empty set differs from an unavailable summary. */
export const statusScheduledWorksSchema = z
  .strictObject({
    count: z.number().int().nonnegative(),
    nextWork: statusPublicWorkSchema.nullable(),
  })
  .readonly()

/** Complete first-response form data, with explicit source unavailability. */
export const statusWorksDataSchema = z
  .strictObject({
    components: z
      .array(z.strictObject({ componentKey: z.string(), name: z.string() }))
      .readonly(),
    upcomingCount: z.number().int().nonnegative(),
    nextWork: statusPublicWorkSchema.nullable(),
  })
  .readonly()
  .nullable()
export type HilosStatusWorksData = z.infer<typeof statusWorksDataSchema>

/** Exact nonpersonal administration slot. */
export const statusWorkSchema = z
  .strictObject({
    [STATUS_WORK_ID_FIELD]: z.number().int().positive(),
    [STATUS_WORK_PUBLIC_ID_FIELD]: z.string().min(1),
    [STATUS_WORK_TITLE_FIELD]: z.string().min(1),
    [STATUS_WORK_DESCRIPTION_FIELD]: z.string().nullable(),
    [STATUS_WORK_COMPONENT_KEYS_FIELD]: z.array(z.string()).readonly(),
    [STATUS_WORK_STARTS_AT_FIELD]: z.number().int().nonnegative(),
    [STATUS_WORK_ENDS_AT_FIELD]: z.number().int().nonnegative(),
    [STATUS_WORK_VERSION_FIELD]: z.number().int().positive(),
    [STATUS_WORK_CANCELED_AT_FIELD]: z.number().int().nonnegative().nullable(),
    [STATUS_WORK_CONSUMED_AT_FIELD]: z.number().int().nonnegative().nullable(),
    [STATUS_WORK_EXPIRED_AT_FIELD]: z.number().int().nonnegative().nullable(),
    [STATUS_WORK_LAST_MAIL_QUEUED_AT_FIELD]: z
      .number()
      .int()
      .nonnegative()
      .nullable(),
  })
  .refine((work) => work.endsAt > work.startsAt)
  .readonly()
export type HilosStatusWorkRow = z.infer<typeof statusWorkSchema>

/** Resolve the normalizer's work reference and preserve its immutable public identity. */
export function resolveHilosStatusWorkRow(
  row: TableRow,
  scopes: ScopeManager,
): HilosStatusWorkRow {
  const reference = z
    .strictObject({
      type: z.literal(HILOS_STATUS_WORK_SLOT),
      id: z.union([
        z.number().int().positive(),
        z.string().regex(/^[1-9]\d*$/),
      ]),
    })
    .parse(row.slots[HILOS_STATUS_WORK_SLOT])
  const work = statusWorkSchema.parse(
    scopes.entitySignal(reference).get()?.fields,
  )
  if (row.rowKey !== String(work.id) || String(reference.id) !== row.rowKey)
    throw new Error('Status work row key does not match its slot.')
  return work
}

export type HilosStatusWorksContext = HilosStatusContext

export const HILOS_STATUS_WORKS_COPY = {
  startsAt: 'Starts',
  endsAt: 'Ends',
  title: 'Title',
  components: 'Components',
  lastMailQueuedAt: 'Last mail queued',
  search: 'Search by title or description…',
  empty: 'No maintenance is scheduled.',
} as const

/** Catalog and summary belong to the page answer, independently of held rows. */
export interface HilosStatusWorksState {
  /** Null is unavailable; a known empty set has upcomingCount zero. */
  readonly data: ReadonlySignal<HilosStatusWorksData>
  start(): void
  dispose(): void
}

/**
 * Observe the complete page data without requesting a companion catalog.
 *
 * @param context The active page scopes and connection.
 */
export function createHilosStatusWorksState(
  context: HilosStatusWorksContext,
): HilosStatusWorksState {
  const data = createSignal<HilosStatusWorksData>(null)
  const source = context.scopes.pageDataSignal(HILOS_STATUS_WORKS_DATA)
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
        const parsed = statusWorksDataSchema.safeParse(source.get())
        data.set(
          scope?.key === HilosPages.STATUS_WORKS && parsed.success
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

/** Standard viewport plus the local focus identity shared by its dialogs. */
export interface HilosStatusWorksTable {
  readonly controller: TableViewportController<HilosStatusWorkRow>
  readonly focusKey: ReadonlySignal<string | null>
  /** Changes on focus ownership changes, including leaving and returning to a key. */
  readonly focusGeneration: ReadonlySignal<number>
  /** Claim a new dialog opening, even when another dialog held the same row key. */
  claimFocus(): void
  start(): void
  dispose(): void
}

/**
 * Bind the public Works columns, component facet and focused-row transport.
 * The server supplies the opening startsAt ASC order and its PK tie breaker.
 *
 * @param context The active page scopes and connection.
 * @param view The page's Schedule opener, shared by the bar and empty state.
 */
export function createHilosStatusWorksTable(
  context: HilosStatusWorksContext,
  view?: { readonly openSchedule: () => void },
): HilosStatusWorksTable {
  const page = HilosPages.STATUS_WORKS
  const focusKey = createSignal<string | null>(null)
  const focusGeneration = createSignal(0)
  const source = context.scopes.pageDataSignal(HILOS_STATUS_WORKS_DATA)
  const columns: readonly HilosTableColumnOf<HilosStatusWorkRow>[] = [
    {
      key: STATUS_WORK_STARTS_AT_FIELD,
      label: HILOS_STATUS_WORKS_COPY.startsAt,
      sortable: true,
      source: HILOS_STATUS_WORK_SLOT,
    },
    {
      key: STATUS_WORK_ENDS_AT_FIELD,
      label: HILOS_STATUS_WORKS_COPY.endsAt,
      sortable: true,
      source: HILOS_STATUS_WORK_SLOT,
    },
    {
      key: STATUS_WORK_TITLE_FIELD,
      label: HILOS_STATUS_WORKS_COPY.title,
      sortable: true,
      card: 'title',
      reads: [STATUS_WORK_DESCRIPTION_FIELD],
      source: HILOS_STATUS_WORK_SLOT,
    },
    {
      key: STATUS_WORK_COMPONENT_KEYS_FIELD,
      label: HILOS_STATUS_WORKS_COPY.components,
      source: HILOS_STATUS_WORK_SLOT,
    },
    {
      key: STATUS_WORK_LAST_MAIL_QUEUED_AT_FIELD,
      label: HILOS_STATUS_WORKS_COPY.lastMailQueuedAt,
      source: HILOS_STATUS_WORK_SLOT,
    },
    {
      key: 'actions',
      label: '',
      reads: [
        STATUS_WORK_ID_FIELD,
        STATUS_WORK_PUBLIC_ID_FIELD,
        STATUS_WORK_VERSION_FIELD,
        STATUS_WORK_CANCELED_AT_FIELD,
        STATUS_WORK_CONSUMED_AT_FIELD,
        STATUS_WORK_EXPIRED_AT_FIELD,
      ],
    },
  ]
  const controller = new TableViewportController<HilosStatusWorkRow>({
    resolve: (row) => resolveHilosStatusWorkRow(row, context.scopes),
    sendViewport: (descriptor) =>
      context.connection.sendTableViewport(
        page,
        HILOS_STATUS_WORKS_TABLE,
        descriptor,
      ),
    sendRendered: (rendered) =>
      context.connection.sendTableRendered(
        page,
        HILOS_STATUS_WORKS_TABLE,
        rendered,
      ),
    sendFocus: (key) => {
      const next = key === '' ? null : key
      if (focusKey.get() !== next) {
        focusKey.set(next)
        focusGeneration.set(focusGeneration.get() + 1)
      }
      context.connection.sendTableRowFocus(page, HILOS_STATUS_WORKS_TABLE, key)
    },
    sendFacets: (facets) =>
      context.connection.sendTableFacets(
        page,
        HILOS_STATUS_WORKS_TABLE,
        facets,
      ),
    frame: {
      columns,
      mainAction: view
        ? { label: 'Schedule maintenance', press: view.openSchedule }
        : undefined,
      search: { placeholder: HILOS_STATUS_WORKS_COPY.search },
      filters: [
        {
          kind: 'select',
          key: STATUS_WORK_FILTER_COMPONENT_KEY,
          label: HILOS_STATUS_WORKS_COPY.components,
          options: () => {
            const parsed = statusWorksDataSchema.safeParse(source.get())
            return parsed.success
              ? (parsed.data?.components.map((component) => ({
                  value: component.componentKey,
                  label: component.name,
                })) ?? [])
              : []
          },
        },
      ],
      empty: { title: HILOS_STATUS_WORKS_COPY.empty },
    },
  })
  let off: (() => void) | undefined
  let offScope: (() => void) | undefined
  let disposed = false
  const dispose = (): void => {
    if (disposed) return
    disposed = true
    off?.()
    offScope?.()
    controller.dispose()
  }
  return {
    controller,
    focusKey,
    focusGeneration,
    claimFocus() {
      focusGeneration.set(focusGeneration.get() + 1)
    },
    start() {
      if (disposed || off !== undefined) return
      const scope = context.scopes.page()
      // Stop observed rows before the binder can replay into a replacement scope.
      offScope = subscribeSignal(
        { get: () => context.scopes.page() },
        (current) => {
          if (current !== scope) dispose()
        },
      )
      off = bindTableViewport(
        context.connection,
        context.scopes,
        { page, tableKey: HILOS_STATUS_WORKS_TABLE },
        controller,
      )
    },
    dispose,
  }
}
