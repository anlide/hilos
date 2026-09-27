import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  ActionError,
  type ActionLifecycle,
} from '../../src/connection/actionLifecycle.js'
import { type HilosConnection } from '../../src/connection/HilosConnection.js'
import { type ProjectSignal } from '../../src/protocol/parseSignal.js'
import { createSignal } from '../../src/state/signal.js'
import { applyServerTime } from '../../src/session/serverClock.js'
import {
  createHilosDataExportFlow,
  createHilosDataExportStore,
  dataExportNodeSchema,
  type DataExportNode,
} from '../../src/profile/dataExport.js'

function world(answers: Record<string, unknown> = {}) {
  const listeners = new Set<(frame: ProjectSignal) => void>()
  const connection = {
    on: (_: string, listener: (frame: ProjectSignal) => void) => {
      listeners.add(listener)
      return () => listeners.delete(listener)
    },
  } as unknown as HilosConnection
  const initial = createSignal<DataExportNode | null>(null)
  const sent: string[] = []
  const actions = {
    dispatch(name: string) {
      sent.push(name)
      const reply = answers[name] ?? {}
      return {
        done:
          reply instanceof Error
            ? Promise.reject(reply)
            : Promise.resolve({ reply }),
      }
    },
  } as unknown as ActionLifecycle
  const store = createHilosDataExportStore(connection, initial)
  store.start()
  const flow = createHilosDataExportFlow(actions, store)
  return {
    initial,
    store,
    flow,
    sent,
    emit(dataExport: unknown) {
      for (const listener of listeners)
        listener({
          type: 'hilos_data_export_state',
          data: { dataExport },
        } as unknown as ProjectSignal)
    },
    dispose() {
      flow.dispose()
      store.dispose()
    },
  }
}
const preparing = {
  state: 'preparing',
  requestedAt: 1000,
  finishedAt: null,
  expiresAt: null,
  sizeBytes: null,
} as const
const ready = {
  state: 'ready',
  requestedAt: 1000,
  finishedAt: 2000,
  expiresAt: 3000,
  sizeBytes: 456,
} as const

afterEach(() => {
  vi.useRealTimers()
  applyServerTime(Date.now())
})

describe('data export', () => {
  it('rejects a ready node without the metadata needed to download it', () => {
    expect(
      dataExportNodeSchema.safeParse({ ...preparing, state: 'ready' }).success,
    ).toBe(false)
  })
  it('follows initial state, group updates, expiry and disposal with dates converted once', () => {
    vi.useFakeTimers()
    vi.setSystemTime(0)
    applyServerTime(100)
    const w = world()
    w.initial.set(preparing)
    expect(w.store.state.get()?.requestedAt).toBe(900)
    w.emit(ready)
    expect(w.store.state.get()).toEqual({
      ...ready,
      requestedAt: 900,
      finishedAt: 1900,
      expiresAt: 2900,
    })
    w.emit(null)
    expect(w.store.state.get()).toBeNull()
    w.dispose()
    w.emit(ready)
    expect(w.store.state.get()).toBeNull()
  })
  it('orders after a skipped proof without inventing a preparing state', async () => {
    const w = world({
      hilos_step_up_start: { required: false, purpose: 'export your data' },
    })
    await Promise.all([w.flow.prepare(), w.flow.prepare()])
    expect(w.sent).toEqual(['hilos_step_up_start', 'hilos_data_export_order'])
    expect(w.store.state.get()).toBeNull()
    expect(w.flow.open.get()).toBe(false)
    w.dispose()
  })
  it('opens confirmation and orders only after proof succeeds', async () => {
    const w = world({
      hilos_step_up_start: {
        required: true,
        purpose: 'export your data',
        method: 'password',
      },
    })
    await w.flow.prepare()
    expect(w.flow.open.get()).toBe(true)
    expect(w.sent).toEqual(['hilos_step_up_start'])
    w.flow.stepUp.password.set('a proof')
    await w.flow.confirm()
    expect(w.sent).toEqual([
      'hilos_step_up_start',
      'hilos_step_up_confirm',
      'hilos_data_export_order',
    ])
    expect(w.flow.open.get()).toBe(false)
    expect(w.flow.stepUp.password.get()).toBe('')
    w.dispose()
  })
  it('keeps an opening refusal on the block and sends no order', async () => {
    const w = world({
      hilos_step_up_start: new ActionError(
        'hilos_step_up_start',
        'fail',
        'Confirmation refused',
      ),
    })
    await w.flow.prepare()
    expect(w.flow.refusal.get()).toBe('Confirmation refused')
    expect(w.flow.open.get()).toBe(false)
    expect(w.sent).toEqual(['hilos_step_up_start'])
    w.dispose()
  })
  it('does not order while preparing and closes proof when another tab orders', async () => {
    const w = world({
      hilos_step_up_start: {
        required: true,
        purpose: 'export your data',
        method: 'password',
      },
    })
    await w.flow.prepare()
    w.emit(preparing)
    expect(w.flow.open.get()).toBe(false)
    await w.flow.prepare()
    expect(w.sent).toEqual(['hilos_step_up_start'])
    w.dispose()
  })
  it('does not order if its surface closes while the opening is in flight', async () => {
    const w = world({
      hilos_step_up_start: { required: false, purpose: 'export your data' },
    })
    const pending = w.flow.prepare()
    w.flow.close()
    await pending
    expect(w.sent).toEqual(['hilos_step_up_start'])
    w.dispose()
  })
})
