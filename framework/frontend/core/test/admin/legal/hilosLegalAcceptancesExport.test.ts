// The export of the acceptance records (HIL-1234): the store follows the page
// answer and then the latest frame, the order carries the table's filters and
// search behind the confirmation step, and the lines say what the export holds.
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  createHilosLegalAcceptancesExport,
  createHilosLegalAcceptancesExportStore,
  hilosLegalAcceptancesExportFilterLine,
  hilosLegalAcceptancesExportStatus,
  hilosLegalAcceptancesExportStatusRoom,
  legalAcceptancesExportNodeSchema,
  LEGAL_ACCEPTANCES_EXPORT_SIGNAL,
  type HilosLegalAcceptancesExportNode,
} from '../../../src/admin/legal/hilosLegalAcceptancesExport.js'
import { ActionError } from '../../../src/connection/actionLifecycle.js'
import { formatBytes } from '../../../src/format/bytes.js'
import { type HilosLegalContext } from '../../../src/legal/legalAgreements.js'
import { type ProjectSignal } from '../../../src/protocol/parseSignal.js'
import { applyServerTime } from '../../../src/session/serverClock.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'
import { createSignal } from '../../../src/state/signal.js'

const preparing = {
  state: 'preparing',
  document: 'terms',
  revisionId: 'current',
  search: 'anna',
  requestedAt: 1000,
  finishedAt: null,
  expiresAt: null,
  sizeBytes: null,
  records: null,
} as const satisfies HilosLegalAcceptancesExportNode
const ready = {
  state: 'ready',
  document: null,
  revisionId: null,
  search: null,
  requestedAt: 1000,
  finishedAt: 2000,
  expiresAt: 3000,
  sizeBytes: 4567,
  records: 1234,
} as const satisfies HilosLegalAcceptancesExportNode
const failed = {
  ...ready,
  state: 'failed',
  sizeBytes: null,
  records: null,
} as const satisfies HilosLegalAcceptancesExportNode

/**
 * A page, its frames and its actions; the step-up answers by `stepUp`.
 *
 * @param stepUp How the server answers the opening of the step.
 * @param exportReply What the order itself answers: success, or a refusal.
 */
function world(
  stepUp: 'skip' | 'ask' | 'refused' = 'skip',
  exportReply: Error | null = null,
) {
  const listeners = new Set<(frame: ProjectSignal) => void>()
  const scopes = new ScopeManager()
  const page = scopes.openPage('hilos_legal_acceptances')
  const sent: Array<{ name: string; data: unknown }> = []
  const answers: Record<string, unknown> = {
    hilos_step_up_start:
      stepUp === 'refused'
        ? new ActionError(
            'hilos_step_up_start',
            'fail',
            'Only an active administrator can do this',
          )
        : {
            required: stepUp === 'ask',
            purpose: 'export acceptance records',
            method: 'password',
          },
    legal_acceptances_export: exportReply ?? {},
  }
  const context = {
    scopes,
    connection: {
      on(_: string, listener: (frame: ProjectSignal) => void) {
        listeners.add(listener)
        return () => listeners.delete(listener)
      },
    },
    actions: {
      dispatch(name: string, data: unknown) {
        sent.push({ name, data })
        const reply = answers[name] ?? {}
        return {
          done:
            reply instanceof Error
              ? Promise.reject(reply)
              : Promise.resolve({ reply }),
        }
      },
    },
  } as unknown as HilosLegalContext
  const table = {
    filter: createSignal<Record<string, unknown>>({
      document: 'terms',
      revision: 'current',
    }),
    search: createSignal('anna'),
  }

  return {
    page,
    context,
    table,
    sent,
    names: () => sent.map((entry) => entry.name),
    emit(legalAcceptancesExport: unknown) {
      for (const listener of listeners)
        listener({
          type: LEGAL_ACCEPTANCES_EXPORT_SIGNAL,
          data: { legalAcceptancesExport },
        } as unknown as ProjectSignal)
    },
  }
}

afterEach(() => {
  vi.useRealTimers()
  applyServerTime(Date.now())
})

describe('the export node', () => {
  it('rejects a ready node without the figures its line names', () => {
    expect(
      legalAcceptancesExportNodeSchema.safeParse({ ...ready, records: null })
        .success,
    ).toBe(false)
    expect(legalAcceptancesExportNodeSchema.safeParse(failed).success).toBe(
      true,
    )
  })
})

describe('createHilosLegalAcceptancesExportStore', () => {
  it('starts from the page answer, with the moments on the reader clock', () => {
    vi.useFakeTimers()
    vi.setSystemTime(0)
    applyServerTime(100)
    const w = world()
    w.page.data.set('legalAcceptancesExport', preparing)
    const store = createHilosLegalAcceptancesExportStore(w.context)
    store.start()

    expect(store.state.get()).toEqual({ ...preparing, requestedAt: 900 })
    store.dispose()
  })

  it('takes a frame after the answer, and whichever arrived last wins', () => {
    const w = world()
    w.page.data.set('legalAcceptancesExport', preparing)
    const store = createHilosLegalAcceptancesExportStore(w.context)
    store.start()

    w.emit(ready)
    expect(store.state.get()?.state).toBe('ready')
    w.page.data.set('legalAcceptancesExport', failed)
    expect(store.state.get()?.state).toBe('failed')
    w.emit(preparing)
    expect(store.state.get()?.state).toBe('preparing')
    w.emit(null)
    expect(store.state.get()).toBeNull()
    store.dispose()
  })

  it('reads an absent section — a viewer of the admin view mode — as no export', () => {
    const w = world()
    const store = createHilosLegalAcceptancesExportStore(w.context)
    store.start()

    expect(store.state.get()).toBeNull()
    w.emit({ ...ready, state: 'unknown' })
    expect(store.state.get()).toBeNull()
    store.dispose()
  })

  it('forgets the export and takes no frame once disposed', () => {
    const w = world()
    w.page.data.set('legalAcceptancesExport', ready)
    const store = createHilosLegalAcceptancesExportStore(w.context)
    store.start()
    store.dispose()

    expect(store.state.get()).toBeNull()
    w.emit(ready)
    expect(store.state.get()).toBeNull()
  })
})

describe('createHilosLegalAcceptancesExport', () => {
  it('orders the table’s filters and search when the step is skipped, and a repeat does not go', async () => {
    const w = world('skip')
    const exporter = createHilosLegalAcceptancesExport(w.context, w.table)
    exporter.start()

    await Promise.all([exporter.export(), exporter.export()])

    expect(w.sent).toEqual([
      {
        name: 'hilos_step_up_start',
        data: { operation: 'export_legal_acceptances' },
      },
      {
        name: 'legal_acceptances_export',
        data: { document: 'terms', revisionId: 'current', search: 'anna' },
      },
    ])
    expect(exporter.open.get()).toBe(false)
    expect(exporter.state.get()).toBeNull()
    exporter.dispose()
  })

  it('sends null for a filter the table does not hold and for a blank search', async () => {
    const w = world('skip')
    w.table.filter.set({})
    w.table.search.set('   ')
    const exporter = createHilosLegalAcceptancesExport(w.context, w.table)
    exporter.start()

    await exporter.export()

    expect(w.sent.at(-1)).toEqual({
      name: 'legal_acceptances_export',
      data: { document: null, revisionId: null, search: null },
    })
    exporter.dispose()
  })

  it('asks in the window and orders only after the confirmation', async () => {
    const w = world('ask')
    const exporter = createHilosLegalAcceptancesExport(w.context, w.table)
    exporter.start()

    await exporter.export()
    expect(exporter.open.get()).toBe(true)
    expect(w.names()).toEqual(['hilos_step_up_start'])

    exporter.stepUp.password.set('a proof')
    await exporter.confirm()

    expect(w.names()).toEqual([
      'hilos_step_up_start',
      'hilos_step_up_confirm',
      'legal_acceptances_export',
    ])
    expect(w.sent.at(-1)?.data).toEqual({
      document: 'terms',
      revisionId: 'current',
      search: 'anna',
    })
    expect(exporter.open.get()).toBe(false)
    expect(exporter.stepUp.password.get()).toBe('')
    exporter.dispose()
  })

  it('keeps a refusal of the step on the page and sends no order', async () => {
    const w = world('refused')
    const exporter = createHilosLegalAcceptancesExport(w.context, w.table)
    exporter.start()

    await exporter.export()

    expect(exporter.refusal.get()).toBe(
      'Only an active administrator can do this',
    )
    expect(exporter.open.get()).toBe(false)
    expect(w.names()).toEqual(['hilos_step_up_start'])
    exporter.dispose()
  })

  it('keeps a refusal of the order itself', async () => {
    const w = world(
      'skip',
      new ActionError(
        'legal_acceptances_export',
        'fail',
        'Invalid export filter',
      ),
    )
    const exporter = createHilosLegalAcceptancesExport(w.context, w.table)
    exporter.start()

    await exporter.export()

    expect(exporter.refusal.get()).toBe('Invalid export filter')
    exporter.dispose()
  })

  it('does not order while an export is preparing, and closes the window when another tab ordered', async () => {
    const w = world('ask')
    const exporter = createHilosLegalAcceptancesExport(w.context, w.table)
    exporter.start()

    await exporter.export()
    expect(exporter.open.get()).toBe(true)
    w.emit(preparing)
    expect(exporter.open.get()).toBe(false)

    await exporter.export()
    await exporter.confirm()
    expect(w.names()).toEqual(['hilos_step_up_start'])
    exporter.dispose()
  })

  it('does not order if the window closes while the step is opening', async () => {
    const w = world('skip')
    const exporter = createHilosLegalAcceptancesExport(w.context, w.table)
    exporter.start()

    const pending = exporter.export()
    exporter.close()
    await pending

    expect(w.names()).toEqual(['hilos_step_up_start'])
    exporter.dispose()
  })

  it('follows the export again after a restart', () => {
    const w = world()
    const exporter = createHilosLegalAcceptancesExport(w.context, w.table)
    exporter.start()
    exporter.dispose()
    exporter.start()

    w.emit(ready)
    expect(exporter.state.get()?.state).toBe('ready')
    exporter.dispose()
  })
})

describe('the lines of the export', () => {
  it('says what the export holds, or all records when nothing narrowed it', () => {
    expect(
      hilosLegalAcceptancesExportFilterLine({
        document: null,
        revisionId: null,
        search: null,
      }),
    ).toBe('All records')
    expect(hilosLegalAcceptancesExportFilterLine(preparing)).toBe(
      'Terms · current · “anna”',
    )
    expect(
      hilosLegalAcceptancesExportFilterLine({
        document: 'privacy',
        revisionId: null,
        search: null,
      }),
    ).toBe('Privacy policy')
    expect(
      hilosLegalAcceptancesExportFilterLine({
        document: null,
        revisionId: null,
        search: 'olena',
      }),
    ).toBe('“olena”')
  })

  it('says the state of the export in the reader’s words', () => {
    expect(hilosLegalAcceptancesExportStatus(null)).toBe('')
    expect(hilosLegalAcceptancesExportStatus(preparing)).toBe(
      `Preparing the export… Started at ${new Date(1000).toLocaleString()}.`,
    )
    expect(hilosLegalAcceptancesExportStatus(ready)).toBe(
      `Export ready · ${(1234).toLocaleString()} records · ${formatBytes(4567)} · available until ${new Date(3000).toLocaleString()}`,
    )
    expect(hilosLegalAcceptancesExportStatus(failed)).toBe(
      'We could not prepare the export.',
    )
    expect(hilosLegalAcceptancesExportStatusRoom()).toMatch(/^Export ready · /)
  })
})
