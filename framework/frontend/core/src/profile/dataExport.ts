import { z } from 'zod'

import {
  createHilosStepUpActions,
  createHilosStepUpStep,
  type HilosStepUpStep,
} from '../auth/stepUp.js'
import {
  ActionError,
  type ActionLifecycle,
} from '../connection/actionLifecycle.js'
import { type HilosConnection } from '../connection/HilosConnection.js'
import { formatBytes } from '../format/bytes.js'
import { formatCalendarDate } from '../format/date.js'
import { toLocal } from '../session/serverClock.js'
import { type ScopeManager } from '../state/ScopeManager.js'
import {
  createSignal,
  computedSignal,
  subscribeSignal,
  type ReadonlySignal,
} from '../state/signal.js'

export const HILOS_DATA_EXPORT_ORDER_ACTION = 'hilos_data_export_order'
export const SIGNAL_DATA_EXPORT_STATE = 'hilos_data_export_state'
export const DATA_EXPORT_OPERATION = 'export_data'
export const DATA_EXPORT_DOWNLOAD_PATH = '/_hilos/data-export'
export const PROFILE_DATA_EXPORT_SECTION = 'dataExport'

/** Archive state on the wire; required dates and sizes follow the persisted state. */
export const dataExportNodeSchema = z.discriminatedUnion('state', [
  z.object({
    state: z.literal('preparing'),
    requestedAt: z.number(),
    finishedAt: z.null(),
    expiresAt: z.null(),
    sizeBytes: z.null(),
  }),
  z.object({
    state: z.literal('ready'),
    requestedAt: z.number(),
    finishedAt: z.number(),
    expiresAt: z.number(),
    sizeBytes: z.number(),
  }),
  z.object({
    state: z.literal('failed'),
    requestedAt: z.number(),
    finishedAt: z.number(),
    expiresAt: z.number(),
    sizeBytes: z.null(),
  }),
])
export type DataExportNode = z.infer<typeof dataExportNodeSchema>
export const dataExportStateSchema = z.object({
  dataExport: dataExportNodeSchema.nullable(),
})
export const DATA_EXPORT_SIGNAL_SCHEMAS = {
  [SIGNAL_DATA_EXPORT_STATE]: dataExportStateSchema,
}

export const HILOS_DATA_EXPORT_COPY = {
  title: 'Your data',
  lead: 'Download a copy of what your account holds.',
  blockedLead: 'Blocking takes away signing in, not your data.',
  sectionLead:
    'The copy holds your account, your ways to sign in and device keys, sessions, two-step verification, notifications, a pending deletion, and what you keep in this app. Passwords, keys and codes are never in it.',
  summaryNone: 'No copy yet',
  summaryPreparing: 'Preparing a copy…',
  summaryReady: 'Copy ready until {date}',
  summaryFailed: 'The last copy could not be prepared',
  deleteHint: 'Want a copy first?',
  deleteLink: 'Your data',
  prepare: 'Prepare a copy',
  preparing:
    'Preparing your copy… Started at {time}. You can close this page: the copy will wait here.',
  ready: 'Your copy is ready · {size} · available until {date}',
  download: 'Download',
  prepareNew: 'Prepare a new copy',
  failed: 'We could not prepare your copy.',
  retry: 'Try again',
} as const

/** The same words and formatting in all three view packages. */
export function dataExportStatusText(node: DataExportNode | null): string {
  switch (node?.state) {
    case 'preparing':
      return HILOS_DATA_EXPORT_COPY.preparing.replace(
        '{time}',
        new Date(node.requestedAt).toLocaleString(),
      )
    case 'ready':
      return HILOS_DATA_EXPORT_COPY.ready
        .replace('{size}', formatBytes(node.sizeBytes))
        .replace('{date}', formatCalendarDate(node.expiresAt))
    case 'failed':
      return HILOS_DATA_EXPORT_COPY.failed
    default:
      return ''
  }
}

/** The archive the server says exists, with dates translated to the reader's clock. */
export interface HilosDataExportStore {
  readonly state: ReadonlySignal<DataExportNode | null>
  start(): void
  dispose(): void
}

/**
 * Follow one initial-state source and subsequent group frames. The owner starts and disposes it.
 * @param connection Connection carrying the person's group frames.
 * @param initial Node carried by the current card or profile subscription, in server time.
 */
export function createHilosDataExportStore(
  connection: Pick<HilosConnection, 'on'>,
  initial: ReadonlySignal<DataExportNode | null>,
): HilosDataExportStore {
  const state = createSignal<DataExportNode | null>(null)
  let stop: (() => void) | null = null
  function apply(node: DataExportNode | null): void {
    state.set(
      node === null
        ? null
        : ({
            ...node,
            requestedAt: toLocal(node.requestedAt),
            ...(node.state === 'preparing'
              ? {}
              : {
                  finishedAt: toLocal(node.finishedAt),
                  expiresAt: toLocal(node.expiresAt),
                }),
          } as DataExportNode),
    )
  }
  return {
    state,
    start() {
      stop?.()
      apply(initial.get())
      const offInitial = subscribeSignal(initial, apply)
      const offFrame = connection.on('projectSignal', (signal) => {
        if (signal.type === SIGNAL_DATA_EXPORT_STATE) {
          const parsed = dataExportStateSchema.safeParse(signal.data)
          if (parsed.success) apply(parsed.data.dataExport)
        }
      })
      stop = () => {
        offInitial()
        offFrame()
      }
    },
    dispose() {
      stop?.()
      stop = null
      state.set(null)
    },
  }
}

/** Confirmation and order state; archive state changes only on server frames. */
export interface HilosDataExportFlow {
  readonly stepUp: HilosStepUpStep
  readonly open: ReadonlySignal<boolean>
  readonly busy: ReadonlySignal<boolean>
  readonly refusal: ReadonlySignal<string | null>
  prepare(): Promise<void>
  confirm(): Promise<void>
  close(): void
  dispose(): void
}

/**
 * Order a copy after the application's confirmation gate, including its skip/refusal outcomes.
 * @param actions The application's one action lifecycle.
 * @param store This person's current copy.
 */
export function createHilosDataExportFlow(
  actions: ActionLifecycle,
  store: HilosDataExportStore,
): HilosDataExportFlow {
  const stepUp = createHilosStepUpStep(createHilosStepUpActions(actions))
  const open = createSignal(false)
  const busy = createSignal(false)
  const refusal = createSignal<string | null>(null)
  let round = 0
  function close(): void {
    round += 1
    open.set(false)
    refusal.set(null)
    stepUp.password.set('')
    stepUp.code.set('')
  }
  const offState = subscribeSignal(store.state, (node) => {
    if (node?.state === 'preparing') close()
  })
  async function order(started: number): Promise<void> {
    try {
      await actions.dispatch(HILOS_DATA_EXPORT_ORDER_ACTION, {}).done
      if (round === started) close()
    } catch (error) {
      if (round === started)
        refusal.set(
          error instanceof ActionError ? error.message : 'The action failed.',
        )
    }
  }
  return {
    stepUp,
    open,
    busy,
    refusal,
    async prepare() {
      if (busy.get() || store.state.get()?.state === 'preparing') return
      busy.set(true)
      refusal.set(null)
      const started = round
      try {
        const verdict = await stepUp.open(DATA_EXPORT_OPERATION)
        if (started !== round) return
        if (verdict === 'skip') await order(started)
        else if (verdict === 'ask') open.set(true)
        else refusal.set(stepUp.refusal.get())
      } finally {
        busy.set(false)
      }
    },
    async confirm() {
      if (busy.get() || !open.get()) return
      busy.set(true)
      refusal.set(null)
      const started = round
      try {
        if ((await stepUp.confirm()) && round === started) await order(started)
      } finally {
        busy.set(false)
      }
    },
    close,
    dispose() {
      offState()
      close()
    },
  }
}

/**
 * Summarize the copy for its profile row, using the store's local dates.
 * @param node The person's copy or its absence.
 */
export function describeHilosDataExport(node: DataExportNode | null): string {
  switch (node?.state) {
    case 'preparing':
      return HILOS_DATA_EXPORT_COPY.summaryPreparing
    case 'ready':
      return HILOS_DATA_EXPORT_COPY.summaryReady.replace(
        '{date}',
        formatCalendarDate(node.expiresAt),
      )
    case 'failed':
      return HILOS_DATA_EXPORT_COPY.summaryFailed
    default:
      return HILOS_DATA_EXPORT_COPY.summaryNone
  }
}

/**
 * Read the copy carried by the page's subscription, in server time.
 * @param scopes The page scope owning the section.
 */
export function profileDataExportNode(
  scopes: ScopeManager,
): ReadonlySignal<DataExportNode | null> {
  const section = scopes.pageDataSignal(PROFILE_DATA_EXPORT_SECTION)
  return computedSignal(() => {
    const parsed = dataExportNodeSchema.nullable().safeParse(section.get())
    return parsed.success ? parsed.data : null
  })
}

/** The project's connection, page scope and action lifecycle. */
export interface HilosDataExportContext {
  readonly connection: HilosConnection
  readonly scopes: ScopeManager
  readonly actions: ActionLifecycle
}

/**
 * Bind a profile copy to the existing store and confirmation flow.
 * The owner starts the store and disposes both objects on unmount.
 * @param context The project's profile context.
 */
export function createHilosProfileDataExport(context: HilosDataExportContext): {
  store: HilosDataExportStore
  flow: HilosDataExportFlow
} {
  const store = createHilosDataExportStore(
    context.connection,
    profileDataExportNode(context.scopes),
  )
  return { store, flow: createHilosDataExportFlow(context.actions, store) }
}
