import { describe, expect, it, vi } from 'vitest'

import { createHilosDaemonAgentRestart } from '../../../src/admin/daemon/hilosDaemonAgentRestart.js'
import { createHilosDaemonAgentsTable } from '../../../src/admin/daemon/hilosDaemonAgents.js'
import {
  ActionLifecycle,
  type ActionLifecycleSource,
} from '../../../src/connection/actionLifecycle.js'
import { type HilosConnection } from '../../../src/connection/HilosConnection.js'
import { HilosPages } from '../../../src/routing/hilosPages.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'
import { type TableRow } from '../../../src/state/TableRowsStore.js'

function raw(overrides: Record<string, unknown> = {}): TableRow {
  return {
    rowKey: 'agent:1',
    slots: {
      agent: {
        agentId: 'agent:1',
        restarting: false,
        workerIndex: 1,
        workerKind: 'regular',
        placement: 'node',
        idle: false,
        ownsRt: [],
        ownsDb: [],
        logStream: 'agent-agent_1.log',
        ...overrides,
      },
    },
  }
}

function harness() {
  const scopes = new ScopeManager()
  const page = scopes.openPage(HilosPages.DAEMON_AGENTS)
  page.data.set('node', { clustered: false, state: null, silentSince: null })
  const listeners = new Map<string, (payload: unknown) => void>()
  const sendAction = vi.fn(() => true)
  const connection = {
    sendAction,
    on(event: string, listener: (payload: unknown) => void) {
      listeners.set(event, listener)
      return () => {}
    },
    sendTableRowFocus: vi.fn(),
    sendTableViewport: vi.fn(),
    sendTableRendered: vi.fn(),
  } as unknown as HilosConnection
  const actions = new ActionLifecycle(connection as ActionLifecycleSource)
  const context = { connection, scopes, actions }
  const { controller } = createHilosDaemonAgentsTable(context, 'standalone')
  const restart = createHilosDaemonAgentRestart(
    context,
    controller,
    'standalone',
  )
  const window = (rows = [raw()]) =>
    controller.ingestWindow(rows, rows.length, true, null, null, 25)
  window()
  return {
    restart,
    controller,
    page,
    connection,
    sendAction,
    window,
    answer(error = false) {
      const call = sendAction.mock.calls.at(-1) as unknown as [
        string,
        unknown,
        string,
      ]
      listeners.get(error ? 'actionError' : 'actionSuccess')?.({
        action: call[0],
        requestId: call[2],
        reason: 'Restart refused',
        message: 'Agent restarted.',
      })
    },
  }
}

describe('daemon restart confirmation', () => {
  it('does not adopt a different row that another dialog took into focus', () => {
    const h = harness()
    const other = { ...raw({ agentId: 'other:1' }), rowKey: 'other:1' }
    h.window([raw(), other])
    h.restart.open('agent:1')
    h.controller.focusRow('other:1')
    expect(h.restart.target.get()).toBeUndefined()
    expect(h.restart.canSubmit.get()).toBe(false)
    expect(() => h.restart.sendRestart()).toThrow('unavailable')
    expect(h.sendAction).not.toHaveBeenCalled()
    h.restart.close()
  })

  it('cancels without dispatch and releases the fresh focused row', () => {
    const h = harness()
    h.restart.open('missing')
    expect(h.restart.opened.get()).toBe(false)
    h.restart.open('agent:1')
    expect(h.restart.target.get()?.agentId).toBe('agent:1')
    h.restart.close()
    expect(h.restart.opened.get()).toBe(false)
    expect(h.controller.focusedRow.get()).toBeUndefined()
    expect(h.connection.sendTableRowFocus).toHaveBeenLastCalledWith(
      HilosPages.DAEMON_AGENTS,
      'hilosDaemonAgents',
      '',
    )
    expect(h.sendAction).not.toHaveBeenCalled()
  })

  it('dispatches the current target and route node with requestId and blocks repeats until the reply', async () => {
    const h = harness()
    h.restart.open('agent:1')
    h.window([raw({ agentId: 'updated:1' })])
    const handle = h.restart.sendRestart()
    expect(h.sendAction).toHaveBeenCalledWith(
      'hilos_daemon_agent_restart',
      {
        agentId: 'updated:1',
        nodeId: 'standalone',
      },
      handle.requestId,
    )
    expect(h.restart.canSubmit.get()).toBe(false)
    expect(() => h.restart.sendRestart()).toThrow('unavailable')
    h.restart.close()
    expect(h.restart.opened.get()).toBe(true)
    expect(h.restart.target.get()?.restarting).toBe(false)
    h.answer()
    await handle.done
    expect(h.restart.canSubmit.get()).toBe(true)
    h.restart.close()
  })

  it('keeps an errored confirmation open and permits a deliberate retry', async () => {
    const h = harness()
    h.restart.open('agent:1')
    const handle = h.restart.sendRestart()
    const failed = expect(handle.done).rejects.toThrow('Restart refused')
    h.answer(true)
    await failed
    expect(h.restart.opened.get()).toBe(true)
    expect(h.restart.canSubmit.get()).toBe(true)
    h.restart.close()
  })

  it('rejects a restarting, missing, silent or unanswered target and never uses a replacement focus', async () => {
    const h = harness()
    h.restart.open('agent:1')
    h.window([raw({ restarting: true, workerIndex: null, workerKind: null })])
    expect(h.restart.canSubmit.get()).toBe(false)
    h.window()
    h.page.data.set('node', {
      clustered: true,
      state: 'silent',
      silentSince: 10,
    })
    expect(h.restart.canSubmit.get()).toBe(false)
    h.page.data.set('node', {
      clustered: false,
      state: null,
      silentSince: null,
    })
    h.controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'agent:1',
      reason: 'left_set',
    })
    expect(h.restart.target.get()).toBeUndefined()
    expect(h.restart.canSubmit.get()).toBe(false)
    expect(() => h.restart.sendRestart()).toThrow('unavailable')
    h.window()
    h.restart.open('agent:1')
    vi.useFakeTimers()
    h.controller.setSearch('new')
    await vi.advanceTimersByTimeAsync(400)
    expect(h.restart.canSubmit.get()).toBe(false)
    expect(h.sendAction).not.toHaveBeenCalled()
    h.restart.close()
    vi.useRealTimers()
  })
})
