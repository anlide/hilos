import { z } from 'zod'
import {
  ActionError,
  type ActionHandle,
} from '../../connection/actionLifecycle.js'
import { HilosPages } from '../../routing/hilosPages.js'
import { hilosAdminAccess } from '../../session/adminAccess.js'
import { hideable } from '../../state/hideableSchema.js'
import { isHiddenValue } from '../../state/hiddenValue.js'
import { type ScopeManager } from '../../state/ScopeManager.js'
import { type TableRow } from '../../state/TableRowsStore.js'
import {
  computedSignal,
  createSignal,
  subscribeSignal,
  type ReadonlySignal,
} from '../../state/signal.js'
import { bindTableViewport } from '../../subscription/bindTableViewport.js'
import { type HilosTableColumnOf } from '../../table/hilosTableColumn.js'
import { TableViewportController } from '../../table/TableViewportController.js'
import { HILOS_VIEW_MODE_COPY } from '../viewMode.js'
import { type HilosStatusContext } from './hilosStatus.js'

/** Page-data key in the existing page_response payload. */
export const HILOS_STATUS_SUBSCRIBERS_DATA = 'statusSubscribers'
export const HILOS_STATUS_SUBSCRIBERS_TABLE = 'hilosStatusSubscribers'
export const HILOS_STATUS_SUBSCRIBER_SLOT = 'subscriber'
/** Viewport filter key, independent of the row's confirmed field. */
export const STATUS_SUBSCRIBER_FILTER_CONFIRMED = 'confirmed'
/** Row payload key of the persisted subscriber identity. */
const STATUS_SUBSCRIBER_ID_FIELD = 'id'
/** Row payload key of the personal subscription address. */
export const STATUS_SUBSCRIBER_EMAIL_FIELD = 'email'
/** Row payload key of the subscription instant in epoch milliseconds UTC. */
export const STATUS_SUBSCRIBER_SUBSCRIBED_AT_FIELD = 'subscribedAt'
/** Row payload key of the authoritative confirmation verdict. */
export const STATUS_SUBSCRIBER_CONFIRMED_FIELD = 'confirmed'

/** Public aggregate; internal audience bounds never cross this boundary. */
export const statusSubscriberCountsSchema = z
  .strictObject({
    confirmedCount: z.number().int().nonnegative(),
    pendingCount: z.number().int().nonnegative(),
  })
  .readonly()
export type HilosStatusSubscriberCountsData = z.infer<
  typeof statusSubscriberCountsSchema
>

/** Null means unavailable; a known empty set carries two zero counts. */
export const statusSubscribersDataSchema =
  statusSubscriberCountsSchema.nullable()
export type HilosStatusSubscribersData = z.infer<
  typeof statusSubscribersDataSchema
>

export const statusSubscriberSchema = z
  .strictObject({
    [STATUS_SUBSCRIBER_ID_FIELD]: z.number().int().positive(),
    [STATUS_SUBSCRIBER_EMAIL_FIELD]: hideable(z.string()),
    [STATUS_SUBSCRIBER_SUBSCRIBED_AT_FIELD]: z.number().int(),
    [STATUS_SUBSCRIBER_CONFIRMED_FIELD]: z.boolean(),
  })
  .readonly()
export type HilosStatusSubscriberRow = z.infer<typeof statusSubscriberSchema>

export const HILOS_STATUS_SUBSCRIBER_REMOVE_ACTION =
  'hilos_status_subscriber_remove'
export type HilosStatusSubscribersContext = HilosStatusContext

export const HILOS_STATUS_SUBSCRIBERS_COPY = {
  email: 'Email',
  subscribedAt: 'Subscribed',
  confirmed: 'Confirmed',
  yes: 'Yes',
  no: 'No',
  search: 'Search by email…',
  empty: 'No one has subscribed yet.',
  subscribers: 'Subscribers',
  awaitingConfirmation: 'Awaiting confirmation',
  remove: 'Remove a subscriber',
  removeConfirm: 'Remove',
  removeLead: 'This address will stop receiving outage updates.',
  removeHint:
    'People can unsubscribe using the link in any letter. Use this action if they asked another way. They can subscribe again themselves.',
  cancel: 'Cancel',
  deletedLabel: 'Deleted',
  removed: 'Subscriber removed.',
  unavailable: 'Subscriber data is unavailable.',
  deleted: 'This subscriber was deleted.',
  // TODO(i18n): Local transport outcomes have no backend-authored sentence.
  disconnected: 'A live connection is required to continue.',
  unconfirmed:
    'The result is unconfirmed. The operation may have completed. Check before submitting again.',
  localFailure: 'The action could not be completed. Please try again.',
} as const

/** The slot owns all four fields; no address coercion or inferred confirmation. */
export function resolveHilosStatusSubscriberRow(
  row: TableRow,
  scopes: ScopeManager,
): HilosStatusSubscriberRow {
  // The normalizer turns every id-bearing slot into a reference.
  const reference = z
    .strictObject({
      type: z.literal(HILOS_STATUS_SUBSCRIBER_SLOT),
      id: z.union([
        z.number().int().positive(),
        z.string().regex(/^[1-9]\d*$/),
      ]),
    })
    .parse(row.slots[HILOS_STATUS_SUBSCRIBER_SLOT])
  const subscriber = statusSubscriberSchema.parse(
    scopes.entitySignal(reference).get()?.fields,
  )
  if (
    row.rowKey !== String(subscriber.id) ||
    String(reference.id) !== row.rowKey
  )
    throw new Error('Status subscriber row key does not match its slot.')
  return subscriber
}

export interface HilosStatusSubscribersState {
  readonly counts: ReadonlySignal<HilosStatusSubscribersData>
  start(): void
  dispose(): void
}

/** Counts come from the complete page answer, independently of its held window. */
export function createHilosStatusSubscribersState(
  context: HilosStatusSubscribersContext,
): HilosStatusSubscribersState {
  const counts = createSignal<HilosStatusSubscribersData>(null)
  const source = context.scopes.pageDataSignal(HILOS_STATUS_SUBSCRIBERS_DATA)
  let off: (() => void) | undefined
  let disposed = false
  const dispose = (): void => {
    if (disposed) return
    disposed = true
    off?.()
    off = undefined
    counts.set(null)
  }
  return {
    counts,
    start() {
      if (disposed || off !== undefined) return
      const scope = context.scopes.page()
      const update = (): void => {
        if (context.scopes.page() !== scope) {
          dispose()
          return
        }
        const parsed = statusSubscribersDataSchema.safeParse(source.get())
        counts.set(
          scope?.key === HilosPages.STATUS_SUBSCRIBERS && parsed.success
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

export interface HilosStatusSubscribersTable {
  readonly controller: TableViewportController<HilosStatusSubscriberRow>
  /** UI controls share the shell's view-mode explanation. */
  readonly personalControlsReason: ReadonlySignal<string | null>
  start(): void
  dispose(): void
}

/** All search, ordering, pending changes and selection belong to the standard viewport. */
export function createHilosStatusSubscribersTable(
  context: HilosStatusSubscribersContext,
): HilosStatusSubscribersTable {
  const page = HilosPages.STATUS_SUBSCRIBERS
  const personalControlsReason = computedSignal(() =>
    hilosAdminAccess.get() === 'full' ? null : HILOS_VIEW_MODE_COPY.explanation,
  )
  const columns =
    (): readonly HilosTableColumnOf<HilosStatusSubscriberRow>[] => [
      {
        key: STATUS_SUBSCRIBER_EMAIL_FIELD,
        label: HILOS_STATUS_SUBSCRIBERS_COPY.email,
        sortable: hilosAdminAccess.get() === 'full',
        card: 'title',
        source: HILOS_STATUS_SUBSCRIBER_SLOT,
      },
      {
        key: STATUS_SUBSCRIBER_SUBSCRIBED_AT_FIELD,
        label: HILOS_STATUS_SUBSCRIBERS_COPY.subscribedAt,
        sortable: true,
        source: HILOS_STATUS_SUBSCRIBER_SLOT,
      },
      {
        key: STATUS_SUBSCRIBER_CONFIRMED_FIELD,
        label: HILOS_STATUS_SUBSCRIBERS_COPY.confirmed,
        sortable: true,
        card: 'badge',
        source: HILOS_STATUS_SUBSCRIBER_SLOT,
      },
      {
        key: 'actions',
        label: '',
        reads: [STATUS_SUBSCRIBER_ID_FIELD, STATUS_SUBSCRIBER_EMAIL_FIELD],
      },
    ]
  const controller = new TableViewportController<HilosStatusSubscriberRow>({
    resolve: (row) => resolveHilosStatusSubscriberRow(row, context.scopes),
    sendViewport: (descriptor) => {
      if (
        hilosAdminAccess.get() !== 'full' &&
        (descriptor.filter?.['search'] !== undefined ||
          descriptor.sort?.some(
            (part) => part.field === STATUS_SUBSCRIBER_EMAIL_FIELD,
          ))
      )
        return
      context.connection.sendTableViewport(
        page,
        HILOS_STATUS_SUBSCRIBERS_TABLE,
        descriptor,
      )
    },
    sendRendered: (rendered) =>
      context.connection.sendTableRendered(
        page,
        HILOS_STATUS_SUBSCRIBERS_TABLE,
        rendered,
      ),
    sendFocus: (key) =>
      context.connection.sendTableRowFocus(
        page,
        HILOS_STATUS_SUBSCRIBERS_TABLE,
        key,
      ),
    sendFacets: (facets) =>
      context.connection.sendTableFacets(
        page,
        HILOS_STATUS_SUBSCRIBERS_TABLE,
        facets,
      ),
    frame: {
      columns,
      search:
        hilosAdminAccess.get() === 'full'
          ? { placeholder: HILOS_STATUS_SUBSCRIBERS_COPY.search }
          : undefined,
      filters: [
        {
          kind: 'select',
          key: STATUS_SUBSCRIBER_FILTER_CONFIRMED,
          label: HILOS_STATUS_SUBSCRIBERS_COPY.confirmed,
          options: () => [
            { value: true, label: HILOS_STATUS_SUBSCRIBERS_COPY.yes },
            { value: false, label: HILOS_STATUS_SUBSCRIBERS_COPY.no },
          ],
        },
      ],
      empty: { title: HILOS_STATUS_SUBSCRIBERS_COPY.empty },
    },
  })
  let off: (() => void) | undefined
  let offScope: (() => void) | undefined
  let disposed = false
  const dispose = (): void => {
    if (disposed) return
    disposed = true
    off?.()
    off = undefined
    offScope?.()
    offScope = undefined
    controller.dispose()
  }
  return {
    controller,
    personalControlsReason,
    start() {
      if (disposed || off !== undefined) return
      const scope = context.scopes.page()
      // The binder replays the opening answer synchronously. Dispose on scope
      // change before that replay lets observed rows depend on this scope.
      offScope = subscribeSignal(
        { get: () => context.scopes.page() },
        (current) => {
          if (current !== scope) dispose()
        },
      )
      off = bindTableViewport(
        context.connection,
        context.scopes,
        { page, tableKey: HILOS_STATUS_SUBSCRIBERS_TABLE },
        controller,
      )
    },
    dispose,
  }
}

export interface HilosStatusSubscribersActions {
  remove(subscriberId: number): ActionHandle
}
export function createHilosStatusSubscribersActions(
  context: HilosStatusSubscribersContext,
): HilosStatusSubscribersActions {
  return {
    remove: (subscriberId) =>
      context.actions.dispatch(HILOS_STATUS_SUBSCRIBER_REMOVE_ACTION, {
        subscriberId,
      }),
  }
}

export interface HilosStatusSubscriberDialog {
  readonly subscriberId: number
  readonly email: HilosStatusSubscriberRow['email']
}
export interface HilosStatusSubscriberController {
  readonly dialog: ReadonlySignal<HilosStatusSubscriberDialog | null>
  readonly target: ReadonlySignal<HilosStatusSubscriberRow | null>
  readonly busy: ReadonlySignal<boolean>
  readonly loading: ReadonlySignal<boolean>
  readonly error: ReadonlySignal<string | null>
  readonly failure: ReadonlySignal<ActionError | null>
  readonly unconfirmed: ReadonlySignal<boolean>
  readonly success: ReadonlySignal<string | null>
  readonly requestId: ReadonlySignal<string | null>
  readonly blockedReason: ReadonlySignal<string | null>
  readonly canSubmit: ReadonlySignal<boolean>
  start(): void
  open(rowKey: string): void
  close(): void
  submit(): Promise<boolean>
  dispose(): void
}

/** The captured id and caption cannot follow a reordered or newly created subscription. */
export function createHilosStatusSubscriberController(
  context: HilosStatusSubscribersContext,
  table: HilosStatusSubscribersTable,
): HilosStatusSubscriberController {
  const actions = createHilosStatusSubscribersActions(context)
  const dialog = createSignal<HilosStatusSubscriberDialog | null>(null)
  const busy = createSignal(false)
  const loading = createSignal(false)
  const error = createSignal<string | null>(null)
  const failure = createSignal<ActionError | null>(null)
  const unconfirmed = createSignal(false)
  const success = createSignal<string | null>(null)
  const requestId = createSignal<string | null>(null)
  const active = createSignal(false)
  const usable = createSignal(false)
  let disposed = false
  let round = 0
  let openedScope = context.scopes.page()
  let offLoading: (() => void) | undefined
  const teardown: Array<() => void> = []
  const target = computedSignal(() => {
    const current = table.controller.focusedRow.get()
    return current?.id === dialog.get()?.subscriberId ? (current ?? null) : null
  })
  const blockedReason = computedSignal(() => {
    if (dialog.get() === null) return null
    if (hilosAdminAccess.get() !== 'full')
      return HILOS_VIEW_MODE_COPY.explanation
    if (!usable.get()) return HILOS_STATUS_SUBSCRIBERS_COPY.disconnected
    if (
      table.controller.frozenSince.get() !== null ||
      table.controller.rows
        .get()
        .some(
          (row) =>
            row.rowKey === String(dialog.get()?.subscriberId) &&
            row.staleSources.includes(HILOS_STATUS_SUBSCRIBER_SLOT),
        )
    )
      return HILOS_STATUS_SUBSCRIBERS_COPY.unavailable
    if (target.get() === null) return HILOS_STATUS_SUBSCRIBERS_COPY.deleted
    if (isHiddenValue(target.get()?.email))
      return HILOS_VIEW_MODE_COPY.explanation
    return null
  })
  const canSubmit = computedSignal(
    () =>
      active.get() &&
      dialog.get() !== null &&
      openedScope === context.scopes.page() &&
      !busy.get() &&
      blockedReason.get() === null,
  )
  const reset = (): void => {
    round++
    offLoading?.()
    offLoading = undefined
    table.controller.releaseFocus()
    dialog.set(null)
    busy.set(false)
    loading.set(false)
    error.set(null)
    failure.set(null)
    unconfirmed.set(false)
    requestId.set(null)
  }
  const dispose = (): void => {
    if (disposed) return
    disposed = true
    active.set(false)
    for (const off of teardown.splice(0)) off()
    reset()
    success.set(null)
  }
  const updateUsable = (): void => {
    usable.set(
      context.connection.state === 'connected' &&
        !context.connection.protectedMode.active,
    )
  }
  return {
    dialog,
    target,
    busy,
    loading,
    error,
    failure,
    unconfirmed,
    success,
    requestId,
    blockedReason,
    canSubmit,
    start() {
      if (disposed || active.get()) return
      active.set(true)
      const scope = context.scopes.page()
      teardown.push(
        subscribeSignal({ get: () => context.scopes.page() }, (current) => {
          if (current !== scope) dispose()
        }),
        context.connection.on('state', updateUsable),
        context.connection.on('protectedMode', updateUsable),
      )
      updateUsable()
    },
    open(rowKey) {
      if (
        disposed ||
        !active.get() ||
        busy.get() ||
        context.scopes.page()?.key !== HilosPages.STATUS_SUBSCRIBERS
      )
        return
      reset()
      success.set(null)
      const row = table.controller.focusRow(rowKey)
      if (row === null) return
      openedScope = context.scopes.page()
      dialog.set(Object.freeze({ subscriberId: row.id, email: row.email }))
    },
    close() {
      if (!disposed && !busy.get()) reset()
    },
    async submit() {
      if (
        !canSubmit.get() ||
        context.connection.state !== 'connected' ||
        context.connection.protectedMode.active
      )
        return false
      const current = dialog.get()!
      const started = ++round
      busy.set(true)
      error.set(null)
      failure.set(null)
      unconfirmed.set(false)
      const handle = actions.remove(current.subscriberId)
      requestId.set(handle.requestId)
      loading.set(handle.loading.get())
      offLoading = subscribeSignal(handle.loading, (value) =>
        loading.set(value),
      )
      try {
        const result = await handle.done
        if (
          disposed ||
          started !== round ||
          openedScope !== context.scopes.page()
        )
          return false
        reset()
        success.set(result.message ?? HILOS_STATUS_SUBSCRIBERS_COPY.removed)
        return true
      } catch (caught) {
        if (
          disposed ||
          started !== round ||
          openedScope !== context.scopes.page()
        )
          return false
        const actionFailure = caught instanceof ActionError ? caught : null
        failure.set(actionFailure)
        const uncertain =
          actionFailure !== null && actionFailure.outcome !== 'fail'
        unconfirmed.set(uncertain)
        error.set(
          actionFailure?.outcome === 'fail'
            ? actionFailure.message
            : uncertain
              ? HILOS_STATUS_SUBSCRIBERS_COPY.unconfirmed
              : HILOS_STATUS_SUBSCRIBERS_COPY.localFailure,
        )
        return false
      } finally {
        if (!disposed && started === round) {
          offLoading?.()
          offLoading = undefined
          loading.set(false)
          busy.set(false)
        }
      }
    },
    dispose,
  }
}
