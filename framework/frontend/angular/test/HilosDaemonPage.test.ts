import { TestBed } from '@angular/core/testing'
import {
  DAEMON_OVERVIEW_DATA,
  DAEMON_DATABASE_DATA,
} from '@hilos/core/admin/daemon'
import { HilosPages, ScopeManager } from '@hilos/core/base'
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
  it('names node boxes, consensus and membership links with literal names while keeping id addresses', () => {
    const h = harness()
    const name = 'Варшава 🌍 <b>main</b>'
    const picture = daemonPicture([
      daemonNode('n1', { nodeName: name }),
      daemonNode('n2', { nodeName: 'Запасной' }),
    ])
    h.scope.data.set(DAEMON_OVERVIEW_DATA, {
      ...picture,
      consensus: { ...picture.consensus!, nodeName: name },
    })
    h.detect()
    const box = h.el('hilos-daemon-node-n1')!
    expect(box.textContent).toContain(name)
    expect(box.textContent).toContain('n1')
    expect(box.querySelector('b')).toBeNull()
    expect(box.getAttribute('aria-label')).toBe(`Node ${name} (n1)`)
    expect(box.getAttribute('href')).toBe('/hilos/daemon/node/n1')
    expect(
      h.el('hilos-daemon-consensus')?.textContent?.replace(/\s+/g, ' '),
    ).toContain(`Report: ${name} (n1) · Majority: 3`)
    expect(
      h.el('hilos-daemon-link-n2')?.textContent?.replace(/\s+/g, ' ').trim(),
    ).toBe(`${name} (n1) ↔ Запасной (n2) · Membership link`)
    box.click()
    expect(h.router.navigate).toHaveBeenCalledWith('/hilos/daemon/node/n1')
    expect(h.connection.sendAction).not.toHaveBeenCalled()
    h.fixture.destroy()
  })

  it('renames an existing box and falls back to its id when the name is unknown', () => {
    const h = harness()
    const update = (nodeName: string | null) => {
      h.scope.data.set(
        DAEMON_OVERVIEW_DATA,
        daemonPicture([daemonNode('n1', { nodeName }), daemonNode('n2')]),
      )
      h.detect()
    }
    update('Первый')
    const box = h.el('hilos-daemon-node-n1')!
    update('Второй')
    expect(h.el('hilos-daemon-node-n1')).toBe(box)
    expect(box.getAttribute('aria-label')).toBe('Node Второй (n1)')
    update(null)
    expect(h.el('hilos-daemon-node-n1')).toBe(box)
    expect(box.getAttribute('aria-label')).toBe('Node n1')
    expect(box.getAttribute('href')).toBe('/hilos/daemon/node/n1')
    expect(box.textContent).not.toContain('Второй')
    expect(
      h.el('hilos-daemon-link-n2')?.textContent?.replace(/\s+/g, ' ').trim(),
    ).toBe('n1 ↔ n2 · Membership link')
    expect(h.register).not.toHaveBeenCalled()
    expect(h.sendViewport).not.toHaveBeenCalled()
    h.fixture.destroy()
  })

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
    expect(box.getAttribute('href')).toBe('/hilos/daemon/node/n2')
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
    expect(h.router.navigate).toHaveBeenCalledWith('/hilos/daemon/node/n2')
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
          clock: {
            reference: false,
            offsetMs: 812,
            measuredAt: 1_700_000_000_000,
            thresholdMs: 300,
            beyond: true,
          },
        }),
      ]),
    )
    h.detect()
    const box = () => h.el('hilos-daemon-node-n1')!
    expect(box().textContent).toContain('Silent since')
    expect(h.el('hilos-daemon-silent-n1')?.textContent).not.toContain('—')
    expect(box().getAttribute('href')).toBe('/hilos/daemon/node/n1')
    expect(box().textContent).not.toContain('Daemon CPU')
    expect(box().textContent).not.toContain('Measured memory')
    expect(h.el('hilos-daemon-workers-n1')).toBeNull()
    expect(h.el('hilos-daemon-clock-n1')).toBeNull()
    expect(h.el('hilos-daemon-clock-warning-n1')).toBeNull()
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

  it('shows clock readings and removes an accessible warning after recovery', () => {
    vi.spyOn(Date, 'now').mockReturnValue(Date.parse('2026-11-02T12:00:00Z'))
    const timeZone = bindTestTimeZone('UTC')
    const h = harness()
    const measuredAt = Date.parse('2026-10-09T10:11:12Z')
    const clock = {
      reference: false,
      offsetMs: -120,
      measuredAt,
      thresholdMs: 300,
      beyond: false,
    }
    const warningNodeId = 'node /?#%2F🌍'
    const findDataId = (dataId: string): HTMLElement | null =>
      [...h.root.querySelectorAll<HTMLElement>('[data-id]')].find(
        (element) => element.getAttribute('data-id') === dataId,
      ) ?? null
    try {
      timeZone.receive('UTC')
      h.scope.data.set(
        DAEMON_OVERVIEW_DATA,
        daemonPicture([
          daemonNode('n1', {
            clock: {
              ...clock,
              reference: true,
              offsetMs: null,
              measuredAt: null,
            },
          }),
          daemonNode('n2', { clock }),
          daemonNode('n3', {
            clock: { ...clock, offsetMs: null, measuredAt: null },
          }),
          daemonNode(warningNodeId, {
            clock: { ...clock, offsetMs: 812, beyond: true },
          }),
          daemonNode('silent', {
            online: false,
            state: 'silent',
            clock: { ...clock, offsetMs: 812, beyond: true },
          }),
        ]),
      )
      h.detect()
      const date = new Intl.DateTimeFormat(undefined, {
        timeZone: 'UTC',
        dateStyle: 'medium',
      }).format(measuredAt)
      const time = new Intl.DateTimeFormat(undefined, {
        timeZone: 'UTC',
        hour: '2-digit',
        minute: '2-digit',
      }).format(measuredAt)
      expect(h.el('hilos-daemon-clock-n1')?.textContent).toBe(
        'Clock: reference',
      )
      expect(h.el('hilos-daemon-clock-n2')?.textContent).toBe(
        `Clock: -120 ms to the leader, measured ${date}, ${time}`,
      )
      expect(h.el('hilos-daemon-clock-n3')?.textContent).toBe(
        'Clock: not measured yet',
      )
      expect(
        findDataId(`hilos-daemon-clock-${warningNodeId}`)?.textContent,
      ).toBe(`Clock: +812 ms to the leader, measured ${date}, ${time}`)
      expect(findDataId('hilos-daemon-clock-silent')).toBeNull()
      expect(findDataId('hilos-daemon-clock-warning-silent')).toBeNull()

      const warning = findDataId(`hilos-daemon-clock-warning-${warningNodeId}`)!
      const warningId = `hilos-daemon-clock-warning-${encodeURIComponent(warningNodeId)}`
      const link = findDataId(`hilos-daemon-node-${warningNodeId}`)!
      expect(warning.textContent?.replace(/\s+/g, ' ').trim()).toBe(
        'Clock 812 ms off the leader (threshold 300 ms)',
      )
      expect(warning.querySelector('i')?.getAttribute('aria-hidden')).toBe(
        'true',
      )
      expect(warning.closest('[aria-hidden="true"]')).toBeNull()
      expect(warning.id).toBe(warningId)
      expect(link.getAttribute('aria-label')).toBe(`Node ${warningNodeId}`)
      expect(link.getAttribute('aria-describedby')).toBe(warningId)
      expect(document.getElementById(warningId)).toBe(warning)

      h.scope.data.set(
        DAEMON_OVERVIEW_DATA,
        daemonPicture([
          daemonNode(warningNodeId, {
            clock: { ...clock, offsetMs: 0 },
          }),
        ]),
      )
      h.detect()
      expect(
        findDataId(`hilos-daemon-clock-${warningNodeId}`)?.textContent,
      ).toBe(`Clock: +0 ms to the leader, measured ${date}, ${time}`)
      expect(
        findDataId(`hilos-daemon-clock-warning-${warningNodeId}`),
      ).toBeNull()
      expect(
        findDataId(`hilos-daemon-node-${warningNodeId}`)?.getAttribute(
          'aria-describedby',
        ),
      ).toBeNull()

      h.scope.data.set(
        DAEMON_OVERVIEW_DATA,
        daemonPicture([
          daemonNode(warningNodeId, {
            clock: { ...clock, offsetMs: 812 },
          }),
        ]),
      )
      h.detect()
      expect(
        findDataId(`hilos-daemon-clock-warning-${warningNodeId}`),
      ).toBeNull()
      h.scope.data.set(
        DAEMON_OVERVIEW_DATA,
        daemonPicture([daemonNode(warningNodeId)]),
      )
      h.detect()
      expect(findDataId(`hilos-daemon-clock-${warningNodeId}`)).toBeNull()
      expect(
        findDataId(`hilos-daemon-clock-warning-${warningNodeId}`),
      ).toBeNull()
      expect(h.register).not.toHaveBeenCalled()
      expect(h.sendViewport).not.toHaveBeenCalled()
      expect(h.connection.sendAction).not.toHaveBeenCalled()
    } finally {
      h.fixture.destroy()
      vi.restoreAllMocks()
      timeZone.receive(null)
      timeZone.release()
      expect(timeZone.listenerCount()).toBe(0)
    }
  })

  it('scrolls only the focused node’s Clock with plain Left and Right and preserves native activation', () => {
    const clock = {
      reference: false,
      offsetMs: 812,
      measuredAt: 1_700_000_000_000,
      thresholdMs: 300,
      beyond: true,
    }
    const nodeId = 'node /?#%2F🌍'
    const h = harness()
    h.scope.data.set(
      DAEMON_OVERVIEW_DATA,
      daemonPicture([
        daemonNode(nodeId, { clock }),
        daemonNode('n2', { clock }),
      ]),
    )
    h.detect()
    const link = h.el(`hilos-daemon-node-${nodeId}`)!
    const lines = h.el(`hilos-daemon-clock-${nodeId}`)!.parentElement!
    const otherLines = h.el('hilos-daemon-clock-n2')!.parentElement!
    // jsdom has no layout or scrolling: record the native requests only.
    const scroll = vi.fn()
    const otherScroll = vi.fn()
    Object.defineProperty(lines, 'scrollBy', { value: scroll })
    Object.defineProperty(otherLines, 'scrollBy', { value: otherScroll })
    link.focus()
    expect(document.activeElement).toBe(link)
    expect(link.querySelector('[tabindex], button, input, a')).toBeNull()
    for (const key of ['ArrowRight', 'ArrowRight', 'ArrowLeft']) {
      const event = new KeyboardEvent('keydown', {
        key,
        bubbles: true,
        cancelable: true,
      })
      link.dispatchEvent(event)
      expect(event.defaultPrevented).toBe(true)
    }
    expect(scroll.mock.calls).toEqual([
      [{ left: 80 }],
      [{ left: 80 }],
      [{ left: -80 }],
    ])
    expect(otherScroll).not.toHaveBeenCalled()
    expect(document.activeElement).toBe(link)

    scroll.mockClear()
    for (const options of [
      { key: 'Enter' },
      { key: 'Tab' },
      { key: 'ArrowUp' },
      { key: 'ArrowDown' },
      { key: 'Home' },
      { key: 'End' },
      { key: ' ' },
      { key: 'ArrowRight', altKey: true },
      { key: 'ArrowLeft', ctrlKey: true },
      { key: 'ArrowRight', metaKey: true },
      { key: 'ArrowLeft', shiftKey: true },
    ]) {
      const event = new KeyboardEvent('keydown', {
        ...options,
        bubbles: true,
        cancelable: true,
      })
      link.dispatchEvent(event)
      expect(event.defaultPrevented).toBe(false)
    }
    const claimed = new KeyboardEvent('keydown', {
      key: 'ArrowRight',
      bubbles: true,
      cancelable: true,
    })
    claimed.preventDefault()
    link.dispatchEvent(claimed)
    const childKey = new KeyboardEvent('keydown', {
      key: 'ArrowRight',
      bubbles: true,
      cancelable: true,
    })
    lines.dispatchEvent(childKey)
    expect(childKey.defaultPrevented).toBe(false)
    expect(scroll).not.toHaveBeenCalled()
    expect(otherScroll).not.toHaveBeenCalled()
    expect(h.router.navigate).not.toHaveBeenCalled()

    // Observe the link's verdict before stopping jsdom's hard navigation.
    const nativeClicks: boolean[] = []
    const stopNavigation = (event: Event) => {
      nativeClicks.push(event.defaultPrevented)
      event.preventDefault()
    }
    h.root.addEventListener('click', stopNavigation)
    try {
      for (const modifier of ['altKey', 'ctrlKey', 'metaKey', 'shiftKey']) {
        link.dispatchEvent(
          new MouseEvent('click', {
            [modifier]: true,
            bubbles: true,
            cancelable: true,
          }),
        )
      }
      expect(nativeClicks).toEqual([false, false, false, false])
      expect(h.router.navigate).not.toHaveBeenCalled()
    } finally {
      h.root.removeEventListener('click', stopNavigation)
    }
    // jsdom does not synthesize the anchor's native click for Enter.
    link.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }),
    )
    link.dispatchEvent(
      new MouseEvent('click', { detail: 0, bubbles: true, cancelable: true }),
    )
    expect(h.router.navigate).toHaveBeenCalledWith(
      h.router.resolvePath(HilosPages.DAEMON_NODE, { nodeId }),
    )
    expect(h.connection.sendAction).not.toHaveBeenCalled()
    expect(h.sendViewport).not.toHaveBeenCalled()
    h.fixture.destroy()
    link.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }),
    )
    expect(scroll).not.toHaveBeenCalled()
    expect(h.listeners()).toBe(0)
  })

  it('leaves arrow keys native when the node has no Clock to read', () => {
    const h = harness()
    for (const value of [
      daemonPicture([daemonNode('n1')]),
      daemonPicture([daemonNode('n1', { online: false, state: 'silent' })]),
      daemonPicture([daemonNode('n1')], false),
    ]) {
      h.scope.data.set(DAEMON_OVERVIEW_DATA, value)
      h.detect()
      const link = h.el('hilos-daemon-node-n1')!
      link.focus()
      for (const key of ['ArrowLeft', 'ArrowRight']) {
        const event = new KeyboardEvent('keydown', {
          key,
          bubbles: true,
          cancelable: true,
        })
        link.dispatchEvent(event)
        expect(event.defaultPrevented).toBe(false)
      }
    }
    expect(h.router.navigate).not.toHaveBeenCalled()
    expect(h.connection.sendAction).not.toHaveBeenCalled()
    expect(h.sendViewport).not.toHaveBeenCalled()
    h.fixture.destroy()
  })

  it('reformats a mounted clock in the reader zone without another page answer', () => {
    vi.spyOn(Date, 'now').mockReturnValue(Date.parse('2026-11-02T12:00:00Z'))
    const timeZone = bindTestTimeZone('UTC')
    const h = harness()
    const measuredAt = Date.parse('2026-10-09T01:11:12Z')
    try {
      timeZone.receive('UTC')
      h.scope.data.set(
        DAEMON_OVERVIEW_DATA,
        daemonPicture([
          daemonNode('n2', {
            clock: {
              reference: false,
              offsetMs: 120,
              measuredAt,
              thresholdMs: 300,
              beyond: false,
            },
          }),
        ]),
      )
      h.detect()
      const clockLine = h.el('hilos-daemon-clock-n2')!
      const card = h.el('hilos-daemon-node-n2')!
      const picture = h.scope.data.signal(DAEMON_OVERVIEW_DATA).get()
      const formatExpected = (zone: string) => {
        const date = new Intl.DateTimeFormat(undefined, {
          timeZone: zone,
          dateStyle: 'medium',
        }).format(measuredAt)
        const time = new Intl.DateTimeFormat(undefined, {
          timeZone: zone,
          hour: '2-digit',
          minute: '2-digit',
        }).format(measuredAt)
        return `Clock: +120 ms to the leader, measured ${date}, ${time}`
      }
      expect(clockLine.textContent).toBe(formatExpected('UTC'))

      timeZone.receive('Pacific/Honolulu')
      h.detect()

      expect(clockLine.textContent).toBe(formatExpected('Pacific/Honolulu'))
      expect(clockLine.textContent).not.toBe(formatExpected('UTC'))
      expect(h.el('hilos-daemon-clock-n2')).toBe(clockLine)
      expect(h.el('hilos-daemon-node-n2')).toBe(card)
      expect(h.scope.data.signal(DAEMON_OVERVIEW_DATA).get()).toBe(picture)
      expect(h.register).not.toHaveBeenCalled()
      expect(h.sendViewport).not.toHaveBeenCalled()
      expect(h.connection.sendAction).not.toHaveBeenCalled()
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
    expect(h.root.textContent).not.toContain('Clock:')
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
          `/hilos/daemon/node/${source.nodeId}/http`,
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
