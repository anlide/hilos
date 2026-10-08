// The export of the acceptance records an administrator orders from the
// acceptances page (HIL-1234): the CSV of what the table shows under its current
// filters and search, built by the server in the background. The state of the
// order is the server's alone — the page answer carries it first and the
// `hilos_legal_acceptances_export_state` frame every change after it, to every
// tab of that administrator; this module never invents a state of its own.
import { z } from 'zod'

import {
  createHilosStepUpActions,
  createHilosStepUpStep,
  type HilosStepUpStep,
} from '../../auth/stepUp.js'
import { ActionError } from '../../connection/actionLifecycle.js'
import { formatBytes } from '../../format/bytes.js'
import { type HilosLegalContext } from '../../legal/legalAgreements.js'
import { toLocal } from '../../session/serverClock.js'
import {
  createSignal,
  subscribeSignal,
  type ReadonlySignal,
} from '../../state/signal.js'
import {
  hilosLegalDocumentLabel,
  LEGAL_DOCUMENT_FILTER,
  LEGAL_REVISION_FILTER,
} from './hilosLegal.js'

/** The page action that orders the export of what the table shows. */
export const LEGAL_ACCEPTANCES_EXPORT_ACTION = 'legal_acceptances_export'

/**
 * The frame carrying the administrator's export after every change. Not named
 * `subscription_page_…`: the connection keeps such frames by type, and this one
 * would overwrite the filter vocabulary frame of the same page.
 */
export const LEGAL_ACCEPTANCES_EXPORT_SIGNAL =
  'hilos_legal_acceptances_export_state'

/** The page answer section carrying the export the page opens with. */
export const LEGAL_ACCEPTANCES_EXPORT_SECTION = 'legalAcceptancesExport'

/** The step-up operation that guards an order. */
export const LEGAL_ACCEPTANCES_EXPORT_OPERATION = 'export_legal_acceptances'

/** Where the finished file is downloaded from, by the session cookie. */
export const LEGAL_ACCEPTANCES_EXPORT_DOWNLOAD_PATH =
  '/_hilos/legal-acceptances-export'

/** The filters and the search an export was ordered under; null where none was set. */
const exportFiltersShape = {
  document: z.string().nullable(),
  revisionId: z.string().nullable(),
  search: z.string().nullable(),
}

/** An export on the wire; its moments are server epoch milliseconds. */
export const legalAcceptancesExportNodeSchema = z.discriminatedUnion('state', [
  z.object({
    state: z.literal('preparing'),
    ...exportFiltersShape,
    requestedAt: z.number(),
    finishedAt: z.null(),
    expiresAt: z.null(),
    sizeBytes: z.null(),
    records: z.null(),
  }),
  z.object({
    state: z.literal('ready'),
    ...exportFiltersShape,
    requestedAt: z.number(),
    finishedAt: z.number(),
    expiresAt: z.number(),
    sizeBytes: z.number(),
    records: z.number(),
  }),
  z.object({
    state: z.literal('failed'),
    ...exportFiltersShape,
    requestedAt: z.number(),
    finishedAt: z.number(),
    expiresAt: z.number(),
    sizeBytes: z.null(),
    records: z.null(),
  }),
])

/** One administrator's export, as the wire and the store carry it. */
export type HilosLegalAcceptancesExportNode = z.infer<
  typeof legalAcceptancesExportNodeSchema
>

/** The payload of {@link LEGAL_ACCEPTANCES_EXPORT_SIGNAL}. */
export const legalAcceptancesExportStateSchema = z.object({
  legalAcceptancesExport: legalAcceptancesExportNodeSchema.nullable(),
})

/** Merged into every connection by `createHilosConnection`. */
export const LEGAL_ACCEPTANCES_EXPORT_SIGNAL_SCHEMAS = {
  [LEGAL_ACCEPTANCES_EXPORT_SIGNAL]: legalAcceptancesExportStateSchema,
}

/** The words of the export, one set for the three frontends. */
export const HILOS_LEGAL_ACCEPTANCES_EXPORT_COPY = {
  button: 'Export',
  preparing: 'Preparing the export… Started at {time}.',
  ready: 'Export ready · {records} records · {size} · available until {time}',
  download: 'Download',
  failed: 'We could not prepare the export.',
  all: 'All records',
} as const

/** The filters and the search an export is ordered under; null where none is set. */
export interface HilosLegalAcceptancesExportFilters {
  readonly document: string | null
  readonly revisionId: string | null
  readonly search: string | null
}

/**
 * The status line of an export, in the reader's words and clock; empty when
 * there is none.
 *
 * @param node The export with its moments already on the reader's clock, or null.
 */
export function hilosLegalAcceptancesExportStatus(
  node: HilosLegalAcceptancesExportNode | null,
): string {
  switch (node?.state) {
    case 'preparing':
      return HILOS_LEGAL_ACCEPTANCES_EXPORT_COPY.preparing.replace(
        '{time}',
        new Date(node.requestedAt).toLocaleString(),
      )
    case 'ready':
      return HILOS_LEGAL_ACCEPTANCES_EXPORT_COPY.ready
        .replace('{records}', node.records.toLocaleString())
        .replace('{size}', formatBytes(node.sizeBytes))
        .replace('{time}', new Date(node.expiresAt).toLocaleString())
    case 'failed':
      return HILOS_LEGAL_ACCEPTANCES_EXPORT_COPY.failed
    default:
      return ''
  }
}

/**
 * The longest status line an export can show — a ready one with large figures —
 * for the invisible twin that holds the line's room before there is anything
 * to say.
 */
export function hilosLegalAcceptancesExportStatusRoom(): string {
  const now = Date.now()

  return hilosLegalAcceptancesExportStatus({
    state: 'ready',
    document: null,
    revisionId: null,
    search: null,
    requestedAt: now,
    finishedAt: now,
    expiresAt: now,
    sizeBytes: 1023.9 * 1024 ** 3,
    records: 9_999_999,
  })
}

/**
 * The line saying what an export holds: the document's name, the revision and
 * the search in curly quotes, joined by ' · ' — or 'All records' when nothing
 * narrowed it.
 *
 * @param filters The filters and the search the export was ordered under.
 */
export function hilosLegalAcceptancesExportFilterLine(
  filters: HilosLegalAcceptancesExportFilters,
): string {
  const parts = [
    ...(filters.document === null
      ? []
      : [hilosLegalDocumentLabel(filters.document)]),
    ...(filters.revisionId === null ? [] : [filters.revisionId]),
    ...(filters.search === null ? [] : [`“${filters.search}”`]),
  ]

  return parts.length === 0
    ? HILOS_LEGAL_ACCEPTANCES_EXPORT_COPY.all
    : parts.join(' · ')
}

/** The administrator's export the server says exists, and its mount lifecycle. */
export interface HilosLegalAcceptancesExportStore {
  /**
   * The export with its moments on the reader's clock, or null when there is
   * none — and for a viewer of the admin view mode, who is not sent one.
   */
  readonly state: ReadonlySignal<HilosLegalAcceptancesExportNode | null>
  /** Start taking the export from the page answer and the live frames — call on mount. */
  start(): void
  /** Stop taking it and forget it — call on unmount. */
  dispose(): void
}

/**
 * Read a node as the wire carries it; anything else, an absent key included,
 * is no export.
 *
 * @param raw The section's or the frame's node.
 */
function readNode(raw: unknown): HilosLegalAcceptancesExportNode | null {
  const parsed = legalAcceptancesExportNodeSchema.safeParse(raw)
  if (!parsed.success) {
    return null
  }
  const node = parsed.data

  return node.state === 'preparing'
    ? { ...node, requestedAt: toLocal(node.requestedAt) }
    : {
        ...node,
        requestedAt: toLocal(node.requestedAt),
        finishedAt: toLocal(node.finishedAt),
        expiresAt: toLocal(node.expiresAt),
      }
}

/**
 * The one place the acceptances page reads its export from: the page answer's
 * `legalAcceptancesExport` section, then every
 * `hilos_legal_acceptances_export_state` frame after it — whichever arrived
 * last.
 *
 * @param context The page's scope stores and the connection the frames ride.
 */
export function createHilosLegalAcceptancesExportStore(
  context: Pick<HilosLegalContext, 'scopes' | 'connection'>,
): HilosLegalAcceptancesExportStore {
  const state = createSignal<HilosLegalAcceptancesExportNode | null>(null)
  let stop: (() => void) | null = null

  return {
    state,
    start() {
      stop?.()
      const section = context.scopes.pageDataSignal(
        LEGAL_ACCEPTANCES_EXPORT_SECTION,
      )
      state.set(readNode(section.get()))
      const offSection = subscribeSignal(section, (raw) =>
        state.set(readNode(raw)),
      )
      const offFrames = context.connection.on('projectSignal', (signal) => {
        if (signal.type !== LEGAL_ACCEPTANCES_EXPORT_SIGNAL) {
          return
        }
        const parsed = legalAcceptancesExportStateSchema.safeParse(signal.data)
        if (parsed.success) {
          state.set(readNode(parsed.data.legalAcceptancesExport))
        }
      })
      stop = () => {
        offSection()
        offFrames()
      }
    },
    dispose() {
      stop?.()
      stop = null
      state.set(null)
    },
  }
}

/** Where an order reads the table's filters and search at the press. */
export interface HilosLegalAcceptancesExportSource {
  /** The table's filter map. */
  readonly filter: ReadonlySignal<Readonly<Record<string, unknown>>>
  /** The table's search query, empty when unset. */
  readonly search: ReadonlySignal<string>
}

/**
 * The export of the acceptances page: the server's state of it, and the order
 * behind the application's confirmation step.
 */
export interface HilosLegalAcceptancesExport {
  /** The administrator's export, or null when there is none. */
  readonly state: ReadonlySignal<HilosLegalAcceptancesExportNode | null>
  /** The confirmation step the window asks for. */
  readonly stepUp: HilosStepUpStep
  /** Whether the confirmation window is open. */
  readonly open: ReadonlySignal<boolean>
  /** Whether an order or its confirmation is in flight. */
  readonly busy: ReadonlySignal<boolean>
  /** Why the order was refused, or null. */
  readonly refusal: ReadonlySignal<string | null>
  /** Start following the export — call on mount. */
  start(): void
  /** Order the export of what the table shows now. */
  export(): Promise<void>
  /** Confirm in the window and order. */
  confirm(): Promise<void>
  /** Close the window and forget the round. */
  close(): void
  /** Stop following the export and close the window — call on unmount. */
  dispose(): void
}

/**
 * A value the table holds, or null where it holds nothing.
 *
 * @param value The filter's or the search's value.
 */
function filterText(value: unknown): string | null {
  return typeof value === 'string' && value.trim() !== '' ? value.trim() : null
}

/**
 * Bind the acceptances page's export: the store over its page answer and frames,
 * and the order behind the confirmation step — skipped, asked in a window, or
 * refused before it begins. The order carries the table's filters and search at
 * the press; the state changes only on the server's word.
 *
 * @param context The page's connection, scope stores and action lifecycle.
 * @param table The acceptances table whose filters and search the order carries.
 */
export function createHilosLegalAcceptancesExport(
  context: HilosLegalContext,
  table: HilosLegalAcceptancesExportSource,
): HilosLegalAcceptancesExport {
  const store = createHilosLegalAcceptancesExportStore(context)
  // Passing the step without this window's own Confirm means another tab of
  // the session confirmed it, and that tab orders the export: this window
  // closes and orders nothing (HIL-1330).
  const stepUp = createHilosStepUpStep(
    createHilosStepUpActions(context.actions),
    () => close(),
  )
  const open = createSignal(false)
  const busy = createSignal(false)
  const refusal = createSignal<string | null>(null)
  let round = 0
  let filters: HilosLegalAcceptancesExportFilters | null = null
  let offState: (() => void) | null = null

  function close(): void {
    round += 1
    filters = null
    open.set(false)
    refusal.set(null)
    stepUp.password.set('')
    stepUp.code.set('')
  }

  async function order(
    started: number,
    payload: HilosLegalAcceptancesExportFilters,
  ): Promise<void> {
    try {
      await context.actions.dispatch(LEGAL_ACCEPTANCES_EXPORT_ACTION, payload)
        .done
      if (round === started) {
        close()
      }
    } catch (error) {
      if (round === started) {
        refusal.set(
          error instanceof ActionError ? error.message : 'The action failed.',
        )
      }
    }
  }

  return {
    state: store.state,
    stepUp,
    open,
    busy,
    refusal,
    start() {
      offState?.()
      store.start()
      // An order from another tab closes this tab's window: the export it
      // would order is already being prepared.
      offState = subscribeSignal(store.state, (node) => {
        if (node?.state === 'preparing') {
          close()
        }
      })
    },
    async export() {
      if (busy.get() || store.state.get()?.state === 'preparing') {
        return
      }
      busy.set(true)
      refusal.set(null)
      const started = round
      const payload: HilosLegalAcceptancesExportFilters = {
        document: filterText(table.filter.get()[LEGAL_DOCUMENT_FILTER]),
        revisionId: filterText(table.filter.get()[LEGAL_REVISION_FILTER]),
        search: filterText(table.search.get()),
      }
      try {
        const verdict = await stepUp.open(LEGAL_ACCEPTANCES_EXPORT_OPERATION)
        if (started !== round) {
          return
        }
        if (verdict === 'skip') {
          await order(started, payload)
        } else if (verdict === 'ask') {
          filters = payload
          open.set(true)
        } else {
          refusal.set(stepUp.refusal.get())
        }
      } finally {
        busy.set(false)
      }
    },
    async confirm() {
      const payload = filters
      if (busy.get() || !open.get() || payload === null) {
        return
      }
      busy.set(true)
      refusal.set(null)
      const started = round
      try {
        if ((await stepUp.confirm()) && round === started) {
          await order(started, payload)
        }
      } finally {
        busy.set(false)
      }
    },
    close,
    dispose() {
      offState?.()
      offState = null
      close()
      store.dispose()
    },
  }
}
