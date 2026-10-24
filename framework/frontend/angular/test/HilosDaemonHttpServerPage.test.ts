import { openNodePage } from './helpers/hilosDaemonNode.js'
import {
  DAEMON_HTTP_DATA,
  DAEMON_NODE_DATA,
  HilosPages,
  ScopeManager,
} from '@hilos/core'
import { describe, expect, it } from 'vitest'

import { HilosDaemonHttpServerPage } from '../src/admin/daemon/HilosDaemonHttpServerPage.js'
import { daemonHarness } from './helpers/hilosDaemon.js'

const summary = {
  host: '::',
  port: 8080,
  countingHours: 3,
  requests: 42,
  serverErrors: 2,
  slow: 1,
}
const route = {
  rowKey: 'GET /health',
  slots: {
    route: {
      method: 'GET',
      path: '/health',
      agentType: 'health_agent',
      requests: 3,
      serverErrors: 0,
      slowestMs: 12,
    },
  },
}
function harness() {
  return daemonHarness(HilosDaemonHttpServerPage, HilosPages.DAEMON_HTTP_SERVER)
}

describe('HilosDaemonHttpServerPage', () => {
  it('distinguishes missing metrics from known zeros and a retained silent report', () => {
    const h = harness()
    expect(h.el('hilos-daemon-http-address')?.textContent?.trim()).toBe('—')
    expect(h.el('hilos-daemon-http-status')?.getAttribute('data-status')).toBe(
      'waiting',
    )
    h.scope.data.set(DAEMON_NODE_DATA, {
      clustered: true,
      role: null,
      state: 'silent',
      silentSince: 1700000000000,
    })
    h.detect()
    expect(h.el('hilos-daemon-http-status')?.getAttribute('data-status')).toBe(
      'silent',
    )
    h.scope.data.set(DAEMON_HTTP_DATA, {
      ...summary,
      requests: 0,
      serverErrors: 0,
      slow: 0,
    })
    h.detect()
    expect(h.el('hilos-daemon-http-requests')?.textContent?.trim()).toBe('0')
    expect(h.el('hilos-daemon-http-status')?.getAttribute('data-status')).toBe(
      'last-report',
    )
    expect(h.el('hilos-daemon-http-status')?.textContent).toContain(
      'last report',
    )
    h.fixture.destroy()
    expect(h.listeners()).toBe(0)
  })

  it('draws the whole-page summary and the routes window with its declared table name', () => {
    const h = harness()
    h.scope.data.set(DAEMON_HTTP_DATA, summary)
    h.window('hilosDaemonHttpRoutes', [route])
    expect(h.el('hilos-daemon-http-address')?.textContent).toContain(
      '[::]:8080',
    )
    expect(h.el('hilos-daemon-http-period')?.textContent).toContain(
      'Since start, 3 h',
    )
    expect(h.el('hilos-daemon-http-requests')?.textContent).toContain('42')
    expect(h.el('hilos-daemon-http-errors')?.textContent).toContain('2')
    expect(h.el('hilos-daemon-http-slow')?.textContent).toContain('1')
    expect(h.root.querySelector('tbody')?.textContent).toContain('/health')
    expect(h.root.querySelector('tbody')?.textContent).toContain('health_agent')
    expect(h.root.querySelector('tbody')?.textContent).toContain('12 ms')
    h.fixture.destroy()
    expect(h.unregister).toHaveBeenCalledTimes(1)
    expect(h.listeners()).toBe(0)
  })

  it('drops the old routes and summary watchers on node and context replacement', () => {
    const h = harness()
    h.window('hilosDaemonHttpRoutes', [route])
    h.route.set({
      page: HilosPages.DAEMON_HTTP_SERVER,
      params: { nodeId: 'n2' },
      admin: true,
    })
    h.detect()
    expect(h.root.querySelector('tbody')?.textContent).not.toContain('/health')
    h.window('hilosDaemonHttpRoutes', [])
    expect(h.register.mock.calls.at(-1)?.[1].descriptor().filter).toEqual({
      node: 'n2',
    })
    const scopes = new ScopeManager()
    const replacement = openNodePage(scopes, HilosPages.DAEMON_HTTP_SERVER)
    replacement.data.set(DAEMON_HTTP_DATA, { ...summary, port: 9090 })
    h.fixture.componentRef.setInput('context', { ...h.context, scopes })
    h.detect()
    h.scope.data.set(DAEMON_HTTP_DATA, summary)
    h.detect()
    expect(h.el('hilos-daemon-http-address')?.textContent).toContain('9090')
    h.fixture.destroy()
    expect(h.unregister).toHaveBeenCalledTimes(3)
    expect(h.listeners()).toBe(0)
  })
})
