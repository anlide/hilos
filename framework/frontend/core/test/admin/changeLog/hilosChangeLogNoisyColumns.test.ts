import { describe, expect, it, vi } from 'vitest'
import {
  createHilosChangeLogNoisyColumnsTable,
  resolveHilosChangeLogNoisyColumnsRow,
} from '../../../src/admin/changeLog/hilosChangeLogNoisyColumns.js'
import { type HilosChangeLogContext } from '../../../src/admin/changeLog/hilosChangeLog.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'

/** A real page scope and an observable connection boundary. */
function harness() {
  const scopes = new ScopeManager()
  scopes.openPage('hilos_change_log_noisy')
  const listeners = new Map<string, Set<(signal: unknown) => void>>()
  const connection = {
    registerTableWindow: vi.fn(),
    unregisterTableWindow: vi.fn(),
    tableWindowDescriptors: () => ({}),
    sendTableViewport: vi.fn(() => true),
    sendTableRendered: vi.fn(() => true),
    on(event: string, listener: (signal: unknown) => void) {
      const set = listeners.get(event) ?? new Set()
      listeners.set(event, set)
      set.add(listener)
      return () => set.delete(listener)
    },
  }
  const table = createHilosChangeLogNoisyColumnsTable({
    connection: connection as unknown as HilosChangeLogContext['connection'],
    scopes,
  })
  return { table, connection, listeners }
}

describe('noisy-column viewport', () => {
  it('resolves only the inline noisyColumn slot and the envelope key', () => {
    expect(
      resolveHilosChangeLogNoisyColumnsRow({
        rowKey: 'bot.heartbeat',
        slots: {
          noisyColumn: {
            columnKey: 'wrong',
            table: 'bot',
            column: 'heartbeat',
            reason: 'Automatic',
          },
        },
      }),
    ).toEqual({
      rowKey: 'bot.heartbeat',
      table: 'bot',
      column: 'heartbeat',
      reason: 'Automatic',
    })
    expect(
      resolveHilosChangeLogNoisyColumnsRow({ rowKey: 'other', slots: {} }),
    ).toEqual({
      rowKey: 'other',
      table: '',
      column: '',
      reason: '',
    })
  })

  it('declares the two read-only columns without controls or a second title', () => {
    const { table } = harness()
    expect(table.controller.frame.declaration).toEqual({
      columns: [
        { key: 'column', label: 'Column', card: 'title', reads: ['table'] },
        { key: 'reason', label: 'Why noisy' },
      ],
      empty: { title: 'No noisy columns are declared on tracked tables' },
    })
  })

  it('binds the first window on Noisy, sends rendered and viewport addresses there, then releases', () => {
    const { table, connection, listeners } = harness()
    table.start()
    expect(connection.registerTableWindow).toHaveBeenCalledWith(
      'hilosChangeLogNoisyColumns',
      expect.anything(),
    )
    for (const listener of listeners.get('projectSignal') ?? [])
      listener({
        type: 'page_response',
        data: {
          page: 'hilos_change_log_noisy',
          payload: {
            windows: {
              hilosChangeLogNoisyColumns: {
                rows: [
                  {
                    rowKey: 'bot.heartbeat',
                    slots: {
                      noisyColumn: {
                        table: 'bot',
                        column: 'heartbeat',
                        reason: 'Automatic',
                      },
                    },
                  },
                ],
                totalCount: 1,
                totalExact: true,
                firstAnchor: null,
                lastAnchor: null,
                offset: 0,
                limit: 25,
              },
            },
          },
        },
      })
    expect(table.controller.rows.get()).toHaveLength(1)
    expect(connection.sendTableViewport).not.toHaveBeenCalled()
    expect(connection.sendTableRendered).toHaveBeenCalledWith(
      'hilos_change_log_noisy',
      'hilosChangeLogNoisyColumns',
      expect.anything(),
    )
    table.controller.setFilter('unknown', 'value')
    expect(connection.sendTableViewport).toHaveBeenCalledWith(
      'hilos_change_log_noisy',
      'hilosChangeLogNoisyColumns',
      expect.anything(),
    )
    table.dispose()
    expect(connection.unregisterTableWindow).toHaveBeenCalledWith(
      'hilosChangeLogNoisyColumns',
      expect.anything(),
    )
    expect([...listeners.values()].every((set) => set.size === 0)).toBe(true)
  })
})
