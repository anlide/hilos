import { openNodePage } from './helpers/hilosDaemonNode.js'
import {
  DAEMON_CRON_REPORTED_DATA,
  DAEMON_NODE_DATA,
  HilosPages,
  ScopeManager,
  type HilosConnection,
} from '@hilos/core'
import { describe, expect, it, vi } from 'vitest'

import { HilosDaemonCronPage } from '../src/admin/daemon/HilosDaemonCronPage.js'
import { daemonHarness } from './helpers/hilosDaemon.js'
import { bindTestTimeZone } from './timeZoneTestHarness.js'

function harness(projectTimeZone?: string) {
  return daemonHarness(
    HilosDaemonCronPage,
    HilosPages.DAEMON_CRON,
    undefined,
    projectTimeZone,
  )
}

function handshakeConnection(
  base: HilosConnection,
  initialProjectTimeZone?: string,
) {
  let projectTimeZone = initialProjectTimeZone
  const listeners = new Set<(value: never) => void>()
  const connection = {
    ...base,
    get projectTimeZone() {
      return projectTimeZone
    },
    on(event: string, listener: (value: never) => void) {
      if (event !== 'handshake') {
        return base.on(event as never, listener as never)
      }
      listeners.add(listener)
      return () => listeners.delete(listener)
    },
  }
  return {
    connection: connection as unknown as HilosConnection,
    listenerCount: () => listeners.size,
    welcome(timeZone?: string): void {
      projectTimeZone = timeZone
      for (const listener of listeners) listener(undefined as never)
    },
  }
}
function rule(
  name: string,
  agentId: string | null = null,
  idleReason: string | null = null,
  lastRunAt: number | null = 1700000000000,
  nextRunAt: number | null = 1700001800000,
) {
  return {
    rowKey: `${agentId ?? ''}/${name}`,
    slots: {
      rule: {
        name,
        agentId,
        expression: '*/30 * * * *',
        lastRunAt,
        nextRunAt,
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

  it('shows the connection project zone from handshakes and releases its old listener', () => {
    const h = harness('Europe/Warsaw')
    const zone = () => h.el('hilos-daemon-cron-project-time-zone')?.textContent
    let destroyed = false
    const destroy = (): void => {
      if (!destroyed) {
        h.fixture.destroy()
        destroyed = true
      }
    }
    let replacement: ReturnType<typeof handshakeConnection> | undefined
    try {
      h.detect()
      expect(zone()).toBe('Europe/Warsaw')
      h.window('hilosDaemonCron', [rule('project-zone')])
      const schedule = () => {
        const cells = [...h.root.querySelectorAll('tbody tr td')]
        return {
          expression: cells[2]?.textContent,
          runTimes: cells.slice(-2).map((cell) => cell.textContent),
        }
      }
      const initialSchedule = schedule()
      expect(
        h
          .el('hilos-daemon-cron-time-zone')
          ?.textContent?.replace(/\s+/g, ' ')
          .trim(),
      ).toBe(
        "Expressions are evaluated in the project's time zone — Europe/Warsaw. Run times are shown in your time zone.",
      )
      expect(h.listeners('handshake')).toBe(1)
      h.setProjectTimeZone('America/New_York')
      h.emit('handshake', undefined)
      h.detect()
      expect(zone()).toBe('America/New_York')
      expect(schedule()).toEqual(initialSchedule)
      expect(h.register).toHaveBeenCalledTimes(1)
      expect(h.unregister).not.toHaveBeenCalled()
      h.setProjectTimeZone(undefined)
      h.emit('handshake', undefined)
      h.detect()
      expect(zone()).toBe('—')

      const scopes = new ScopeManager()
      openNodePage(scopes, HilosPages.DAEMON_CRON)
      replacement = handshakeConnection(h.connection, 'Europe/London')
      h.fixture.componentRef.setInput('context', {
        ...h.context,
        connection: replacement.connection,
        scopes,
      })
      h.detect()
      expect(h.listeners('handshake')).toBe(0)
      expect(replacement.listenerCount()).toBe(1)
      expect(zone()).toBe('Europe/London')

      replacement.welcome()
      h.detect()
      expect(zone()).toBe('—')
      destroy()
      expect(replacement.listenerCount()).toBe(0)
      expect(h.listeners()).toBe(0)
    } finally {
      destroy()
    }
  })

  it('reformats run and silence moments in the reader zone without replacing the table', () => {
    const timeZone = bindTestTimeZone('UTC')
    const h = harness()
    try {
      timeZone.receive('UTC')
      h.scope.data.set(DAEMON_NODE_DATA, {
        clustered: true,
        role: null,
        state: 'silent',
        silentSince: 1700000000000,
      })
      h.window('hilosDaemonCron', [
        rule('reader-zone'),
        rule('idle', null, 'not_leader'),
        rule('never-ran', null, null, null, null),
      ])
      h.detect()

      const table = h.root.querySelector('hilos-viewport-table')
      const readerRow = () =>
        [...h.root.querySelectorAll('tbody tr')].find((row) =>
          row.textContent?.includes('reader-zone'),
        )!
      const cells = () =>
        [...readerRow().querySelectorAll('td')].slice(-2) as HTMLElement[]
      const initialRunCells = cells()
      const initialRuns = initialRunCells.map((cell) => cell.textContent)
      const silentLine = h.el('hilos-daemon-cron-silent')!
      const initialSilent = silentLine.textContent
      expect(initialRunCells).toHaveLength(2)
      expect(h.root.querySelector('tbody')?.textContent).toContain(
        'Not on this node',
      )
      expect(h.root.querySelector('tbody')?.textContent).toContain('Not yet')
      expect(h.root.querySelector('tbody')?.textContent).toContain('Never')
      expect(h.register).toHaveBeenCalledTimes(1)

      timeZone.receive('Pacific/Honolulu')
      h.detect()

      const updatedRunCells = cells()
      expect(updatedRunCells[0]).toBe(initialRunCells[0])
      expect(updatedRunCells[1]).toBe(initialRunCells[1])
      expect(updatedRunCells.map((cell) => cell.textContent)).not.toEqual(
        initialRuns,
      )
      expect(h.root.querySelector('tbody')?.textContent).toContain(
        'Not on this node',
      )
      expect(h.root.querySelector('tbody')?.textContent).toContain('Not yet')
      expect(h.root.querySelector('tbody')?.textContent).toContain('Never')
      expect(h.el('hilos-daemon-cron-silent')).toBe(silentLine)
      expect(silentLine.textContent).not.toBe(initialSilent)
      expect(h.root.querySelector('hilos-viewport-table')).toBe(table)
      expect(h.register).toHaveBeenCalledTimes(1)
      expect(h.unregister).not.toHaveBeenCalled()
    } finally {
      h.fixture.destroy()
      vi.restoreAllMocks()
      timeZone.receive(null)
      timeZone.release()
    }
  })
})
