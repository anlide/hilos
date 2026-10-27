import { openNodePage } from './helpers/hilosDaemonNode.js'
import {
  DAEMON_CRON_REPORTED_DATA,
  DAEMON_NODE_DATA,
  HilosPages,
  ScopeManager,
} from '@hilos/core'
import { describe, expect, it } from 'vitest'

import { HilosDaemonCronPage } from '../src/admin/daemon/HilosDaemonCronPage.js'
import { daemonHarness } from './helpers/hilosDaemon.js'

function harness() {
  return daemonHarness(HilosDaemonCronPage, HilosPages.DAEMON_CRON)
}
function rule(
  name: string,
  agentId: string | null = null,
  idleReason: string | null = null,
) {
  return {
    rowKey: `${agentId ?? ''}/${name}`,
    slots: {
      rule: {
        name,
        agentId,
        expression: '*/30 * * * *',
        lastRunAt: 1700000000000,
        nextRunAt: 1700001800000,
        idleReason,
      },
    },
  }
}

describe('HilosDaemonCronPage', () => {
  it('shows the initial placeholder, then waiting, silent and reported-empty explanations', () => {
    const h = harness()
    expect(h.el('hilos-table-loading')).not.toBeNull()
    h.window('hilosDaemonCron', [])
    expect(h.el('hilos-daemon-cron-empty-waiting')?.textContent).toContain(
      'not zero rules',
    )
    h.scope.data.set(DAEMON_NODE_DATA, {
      clustered: true,
      role: null,
      state: 'silent',
      silentSince: 1700000000000,
    })
    h.detect()
    expect(h.el('hilos-daemon-cron-empty-silent')).not.toBeNull()
    h.scope.data.set(DAEMON_CRON_REPORTED_DATA, true)
    h.detect()
    expect(h.el('hilos-daemon-cron-empty-none')).not.toBeNull()
    h.fixture.destroy()
    expect(h.listeners()).toBe(0)
  })

  it('keeps picture order and the standby idle notice before a sort', () => {
    const h = harness()
    h.scope.data.set(DAEMON_NODE_DATA, {
      clustered: true,
      role: null,
      state: 'standby',
      silentSince: null,
    })
    h.window('hilosDaemonCron', [
      rule('z-last', null, 'not_leader'),
      rule('a-first', 'room:7'),
    ])
    expect(
      h.el('hilos-daemon-cron-not-leader')?.getAttribute('data-notice'),
    ).toBe('idle_until_leader')
    expect(h.el('hilos-daemon-cron-not-leader')?.textContent).toContain(
      'leadership moves',
    )
    expect(h.el('hilos-daemon-cron-executor-/z-last')?.textContent).toContain(
      'Daemon',
    )
    expect(
      h.el('hilos-daemon-cron-executor-room:7/a-first')?.textContent,
    ).toContain('room:7')
    expect(
      h.root.querySelector('tbody')?.textContent?.indexOf('z-last'),
    ).toBeLessThan(
      h.root.querySelector('tbody')!.textContent!.indexOf('a-first'),
    )
    expect(h.el('hilos-table-search')?.getAttribute('placeholder')).toBe(
      'Find by rule or agent',
    )
    expect(h.root.querySelectorAll('[aria-sort="none"]')).toHaveLength(2)
    h.scope.data.set(DAEMON_NODE_DATA, {
      clustered: true,
      role: null,
      state: 'silent',
      silentSince: 1700000000000,
    })
    h.detect()
    expect(h.el('hilos-daemon-cron-silent')?.textContent).toContain(
      'last report',
    )
    expect(
      h.el('hilos-daemon-cron-not-leader')?.getAttribute('data-notice'),
    ).toBe('idle')
    h.fixture.destroy()
    expect(h.listeners()).toBe(0)
  })

  it('rebinds on node and context changes and releases the old heading watchers', () => {
    const h = harness()
    h.route.set({
      page: HilosPages.DAEMON_CRON,
      params: { nodeId: 'n2' },
      admin: true,
    })
    h.detect()
    expect(h.unregister).toHaveBeenCalledTimes(1)
    h.window('hilosDaemonCron', [])
    expect(h.register.mock.calls.at(-1)?.[1].descriptor().filter).toEqual({
      node: 'n2',
    })
    const scopes = new ScopeManager()
    const replacement = openNodePage(scopes, HilosPages.DAEMON_CRON)
    h.fixture.componentRef.setInput('context', { ...h.context, scopes })
    h.detect()
    replacement.data.set(DAEMON_NODE_DATA, {
      clustered: true,
      role: null,
      state: 'leader',
      silentSince: null,
    })
    h.scope.data.set(DAEMON_NODE_DATA, {
      clustered: true,
      role: null,
      state: 'silent',
      silentSince: 1700000000000,
    })
    h.detect()
    expect(h.el('hilos-daemon-node-state')?.textContent).toContain('Leader')
    h.fixture.destroy()
    expect(h.unregister).toHaveBeenCalledTimes(3)
    expect(h.listeners()).toBe(0)
  })
})
