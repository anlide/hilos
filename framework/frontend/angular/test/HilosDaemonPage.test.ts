import { TestBed } from '@angular/core/testing'
import {
  DAEMON_OVERVIEW_DATA,
  DAEMON_DATABASE_DATA,
  HilosPages,
  ScopeManager,
} from '@hilos/core'
import { describe, expect, it, vi } from 'vitest'

import { HilosDaemonPage } from '../src/admin/daemon/HilosDaemonPage.js'
import {
  daemonHarness,
  daemonNode,
  daemonPicture,
} from './helpers/hilosDaemon.js'
import { bindTestTimeZone } from './timeZoneTestHarness.js'

function harness() {
  return daemonHarness(HilosDaemonPage, HilosPages.DAEMON)
}

function seed(h: ReturnType<typeof harness>): void {
  h.scope.data.set(
    DAEMON_OVERVIEW_DATA,
    daemonPicture([daemonNode('n1'), daemonNode('n2', { state: 'standby' })]),
  )
  h.detect()
}

describe('HilosDaemonPage', () => {
  it('waits for the lean picture and draws addressable node boxes with live metrics', () => {
    const h = harness()
    expect(h.el('hilos-daemon-overview-waiting')).not.toBeNull()
    seed(h)
    h.scope.data.set(DAEMON_DATABASE_DATA, {
      host: 'db',
      port: 3306,
      name: 'project',
      socket: null,
      migrationIndex: 5,
    })
    h.detect()
    expect(h.el('hilos-daemon-database-address')?.textContent).toContain(
      'mysql://db:3306/project',
    )
    const box = h.el('hilos-daemon-node-n2')!
    expect(box.tagName).toBe('A')
    expect(box.getAttribute('href')).toBe('/hilos/daemon/n2')
    expect(box.getAttribute('aria-label')).toBe('Node n2')
    expect(box.getAttribute('aria-pressed')).toBeNull()
    expect(box.textContent).toContain('150%')
    expect(box.textContent).toContain('4.0 KB')
    expect(box.textContent).toContain('2 worker processes measured')
    expect(h.el('hilos-daemon-inspector')).toBeNull()
    expect(
      h.el('hilos-daemon-worker-n1-regular-0')?.getAttribute('title'),
    ).toBe('Ordinary worker · occupied · 2 agents')
    const requests = h.sendViewport.mock.calls.length
    box.click()
    h.detect()
    expect(h.router.navigate).toHaveBeenCalledWith('/hilos/daemon/n2')
    expect(h.sendViewport).toHaveBeenCalledTimes(requests)
    h.fixture.destroy()
    expect(h.listeners()).toBe(0)
    expect(h.unregister).not.toHaveBeenCalled()
  })

  it('replaces silent metrics with time and restores only a current report', () => {
    const h = harness()
    h.scope.data.set(
      DAEMON_OVERVIEW_DATA,
      daemonPicture([
        daemonNode('n1', {
          online: false,
          state: 'silent',
          silentSince: 1_700_000_000_000,
        }),
      ]),
    )
    h.detect()
    const box = () => h.el('hilos-daemon-node-n1')!
    expect(box().textContent).toContain('Silent since')
    expect(h.el('hilos-daemon-silent-n1')?.textContent).not.toContain('—')
    expect(box().getAttribute('href')).toBe('/hilos/daemon/n1')
    expect(box().textContent).not.toContain('Daemon CPU')
    expect(box().textContent).not.toContain('Measured memory')
    expect(h.el('hilos-daemon-workers-n1')).toBeNull()
    h.scope.data.set(
      DAEMON_OVERVIEW_DATA,
      daemonPicture([
        daemonNode('n1', {
          online: false,
          state: 'silent',
          silentSince: null,
          measurements: null,
          workers: null,
          standing: null,
        }),
      ]),
    )
    h.detect()
    expect(box().textContent).toContain('Silent since —')
    seed(h)
    expect(box().textContent).toContain('150%')
    expect(h.el('hilos-daemon-inspector')).toBeNull()
    h.fixture.destroy()
  })

  it('updates a mounted silent node in the reader zone without replacing its card', () => {
    const timeZone = bindTestTimeZone('UTC')
    const h = harness()
    try {
      const silentSince = 1_700_000_000_000
      h.scope.data.set(
        DAEMON_OVERVIEW_DATA,
        daemonPicture([
          daemonNode('n1', {
            online: false,
            state: 'silent',
            silentSince,
          }),
        ]),
      )
      timeZone.receive('UTC')
      h.detect()
      const line = h.el('hilos-daemon-silent-n1')!
      const initialLabel = line.textContent
      const card = h.el('hilos-daemon-node-n1')!

      timeZone.receive('Pacific/Honolulu')
      h.detect()

      expect(line.textContent).not.toBe(initialLabel)
      expect(h.el('hilos-daemon-silent-n1')).toBe(line)
      expect(h.el('hilos-daemon-node-n1')).toBe(card)
    } finally {
      h.fixture.destroy()
      vi.restoreAllMocks()
      timeZone.receive(null)
      timeZone.release()
      expect(timeZone.listenerCount()).toBe(0)
    }
  })

  it('keeps unknown, empty and zero distinct and encodes a standalone node address', () => {
    const h = harness()
    h.scope.data.set(DAEMON_OVERVIEW_DATA, daemonPicture([]))
    h.detect()
    expect(h.el('hilos-daemon-overview-empty')?.textContent).toContain(
      'No nodes in the picture',
    )
    const nodeId = 'node /?#%2F🌍'
    h.scope.data.set(
      DAEMON_OVERVIEW_DATA,
      daemonPicture(
        [
          daemonNode(nodeId, {
            workers: null,
            measurements: null,
            standing: null,
          }),
        ],
        false,
      ),
    )
    h.detect()
    const box = () =>
      [...h.root.querySelectorAll('a')].find(
        (item) =>
          item.getAttribute('data-id') === `hilos-daemon-node-${nodeId}`,
      )!
    expect(box().textContent).toContain('Single installation')
    expect(box().textContent).toContain('Workers: —')
    expect(box().textContent).toContain('Browser connections: —')
    expect(box().getAttribute('href')).toBe(
      h.router.resolvePath(HilosPages.DAEMON_NODE, { nodeId }),
    )
    const zero = daemonNode(nodeId)
    h.scope.data.set(
      DAEMON_OVERVIEW_DATA,
      daemonPicture(
        [
          {
            ...zero,
            workers: [],
            standing: { connections: 0 },
            measurements: {
              ...zero.measurements!,
              cores: 0,
              daemonCores: 0,
              masterMemory: 0,
              workersMemory: 0,
              workersMeasured: 0,
              memoryCeiling: 0,
            },
          },
        ],
        false,
      ),
    )
    h.detect()
    expect(box().textContent).toContain('No workers')
    expect(box().textContent).toContain('Browser connections: 0')
    expect(box().textContent).toContain('0 B / 0 B')
    expect(h.el('hilos-daemon-consensus')).toBeNull()
    expect(h.el('hilos-daemon-inspector')).toBeNull()
    h.fixture.destroy()
  })

  it('disposes the old context and follows only the new scopes', () => {
    const h = harness()
    seed(h)
    const scopes = new ScopeManager()
    const scope = scopes.openPage(HilosPages.DAEMON)
    scope.data.set(
      DAEMON_OVERVIEW_DATA,
      daemonPicture([daemonNode('solo')], false),
    )
    h.fixture.componentRef.setInput('context', { ...h.context, scopes })
    h.detect()
    expect(h.unregister).not.toHaveBeenCalled()
    expect(h.el('hilos-daemon-node-solo')?.textContent).toContain(
      'Single installation',
    )
    expect(h.el('hilos-daemon-consensus')).toBeNull()
    h.scope.data.set(DAEMON_OVERVIEW_DATA, daemonPicture([]))
    h.detect()
    expect(h.el('hilos-daemon-node-solo')).not.toBeNull()
    h.fixture.destroy()
    expect(h.listeners()).toBe(0)
    expect(h.unregister).not.toHaveBeenCalled()
    TestBed.resetTestingModule()
  })
  it('uses only the selected source ports and resolves its metrics page', () => {
    const h = harness()
    const base = daemonPicture([daemonNode('n1'), daemonNode('n2')])
    for (const source of [
      { nodeId: 'n2', commandPort: 9014, httpPort: 8082 },
      { nodeId: 'n1', commandPort: 0, httpPort: null },
      null,
    ]) {
      h.scope.data.set(DAEMON_OVERVIEW_DATA, { ...base, entryPoints: source })
      h.detect()
      expect(h.el('hilos-daemon-entry-command-port')!.textContent?.trim()).toBe(
        source?.commandPort == null ? '—' : `:${source.commandPort}`,
      )
      expect(h.el('hilos-daemon-entry-http-port')!.textContent?.trim()).toBe(
        source?.httpPort == null ? '—' : `:${source.httpPort}`,
      )
      const link = h.el('hilos-daemon-entry-metrics')
      if (source)
        expect(link?.getAttribute('href')).toBe(
          `/hilos/daemon/${source.nodeId}/http`,
        )
      else expect(link).toBeNull()
      expect(h.register).not.toHaveBeenCalled()
    }
    const resolvePath = h.router.resolvePath.bind(h.router)
    vi.spyOn(h.router, 'resolvePath').mockImplementation((page, params) =>
      page === HilosPages.DAEMON_HTTP_SERVER
        ? undefined
        : resolvePath(page, params),
    )
    h.scope.data.set(DAEMON_OVERVIEW_DATA, {
      ...base,
      entryPoints: { nodeId: 'n2', commandPort: null, httpPort: 0 },
    })
    h.detect()
    expect(h.el('hilos-daemon-entry-http-port')!.textContent?.trim()).toBe(':0')
    expect(h.el('hilos-daemon-entry-metrics')).toBeNull()
    h.fixture.destroy()
  })
})
