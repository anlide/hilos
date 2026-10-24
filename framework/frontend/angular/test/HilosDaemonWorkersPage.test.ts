import { openNodePage } from './helpers/hilosDaemonNode.js'
import {
  DAEMON_NODE_DATA,
  DAEMON_PROCESSES_REPORTED_DATA,
  HilosPages,
  ScopeManager,
} from '@hilos/core'
import { describe, expect, it } from 'vitest'

import { HilosDaemonWorkersPage } from '../src/admin/daemon/HilosDaemonWorkersPage.js'
import { daemonHarness } from './helpers/hilosDaemon.js'

function harness() {
  return daemonHarness(HilosDaemonWorkersPage, HilosPages.DAEMON_WORKERS)
}
const worker = {
  rowKey: 'regular:1',
  slots: {
    worker: {
      index: 1,
      kind: 'regular',
      pid: 1234,
      memoryBytes: 2048,
      agentCount: 2,
      agentIds: ['room:12', 'user:7'],
      logStream: 'worker-regular-1.log',
    },
  },
}

describe('HilosDaemonWorkersPage', () => {
  it('distinguishes waiting, silent without a roster, and an explicitly empty roster', () => {
    const h = harness()
    h.window('hilosDaemonWorkers', [])
    expect(h.el('hilos-daemon-workers-empty-waiting')?.textContent).toContain(
      'not zero workers',
    )
    h.scope.data.set(DAEMON_NODE_DATA, {
      clustered: true,
      role: null,
      state: 'silent',
      silentSince: 1700000000,
    })
    h.detect()
    expect(h.el('hilos-daemon-node-state')?.textContent).toContain('Silent')
    expect(h.el('hilos-page-head-action')?.getAttribute('href')).toBe(
      '/hilos/daemon',
    )
    expect(h.el('hilos-daemon-workers-empty-silent')).not.toBeNull()
    h.scope.data.set(DAEMON_PROCESSES_REPORTED_DATA, true)
    h.detect()
    expect(h.el('hilos-daemon-workers-empty-none')).not.toBeNull()
    h.fixture.destroy()
    expect(h.listeners()).toBe(0)
  })

  it('draws the worker log, detail ids and a last-report notice on a silent node', () => {
    const h = harness()
    h.window('hilosDaemonWorkers', [worker])
    h.scope.data.set(DAEMON_NODE_DATA, {
      clustered: true,
      role: null,
      state: 'silent',
      silentSince: 1700000000,
    })
    h.detect()
    expect(h.el('hilos-daemon-workers-silent')?.textContent).toContain(
      'last report',
    )
    expect(
      h.el('hilos-daemon-worker-log-regular:1')?.getAttribute('href'),
    ).toContain('worker-regular-1.log')
    h.el('hilos-table-expand-regular:1')!.click()
    h.detect()
    expect(h.el('hilos-daemon-worker-agents-regular:1')?.textContent).toContain(
      'room:12',
    )
    expect(h.el('hilos-daemon-worker-agents-regular:1')?.textContent).toContain(
      'user:7',
    )
    h.fixture.destroy()
    expect(h.listeners()).toBe(0)
    expect(h.unregister).toHaveBeenCalledTimes(1)
  })

  it('rebinds the table to a changed node address and tears it down on context swap', () => {
    const h = harness()
    h.window('hilosDaemonWorkers', [worker])
    h.route.set({
      page: HilosPages.DAEMON_WORKERS,
      params: { nodeId: 'n2' },
      admin: true,
    })
    h.detect()
    expect(h.unregister).toHaveBeenCalledTimes(1)
    expect(h.el('hilos-page-head-title')?.textContent).toContain('n2')
    h.window('hilosDaemonWorkers', [worker])
    expect(h.register.mock.calls.at(-1)?.[1].descriptor().filter).toEqual({
      node: 'n2',
    })
    const scopes = new ScopeManager()
    openNodePage(scopes, HilosPages.DAEMON_WORKERS)
    h.fixture.componentRef.setInput('context', { ...h.context, scopes })
    h.detect()
    expect(h.unregister).toHaveBeenCalledTimes(2)
    h.fixture.destroy()
    expect(h.unregister).toHaveBeenCalledTimes(3)
    expect(h.listeners()).toBe(0)
  })
})
