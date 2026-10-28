import { By } from '@angular/platform-browser'
import { HilosPages, ScopeManager } from '@hilos/core/base'
import { describe, expect, it, vi } from 'vitest'
import { HilosDaemonNodePage } from '../src/admin/daemon/HilosDaemonNodePage.js'
import {
  nodeHarness,
  nodeHeading,
  nodeOverview,
  openNodePage,
} from './helpers/hilosDaemonNode.js'
import { bindTestTimeZone } from './timeZoneTestHarness.js'

const el = (name: string) =>
  document.querySelector<HTMLElement>(`[data-id="hilos-daemon-${name}"]`)
const text = (name: string) =>
  el(name)!.textContent?.replace(/\s+/g, ' ').trim()

describe('HilosDaemonNodePage', () => {
  it('waits for a full addressed picture and renders all four groups with their original diagnostics', () => {
    const h = nodeHarness()
    expect(text('node-waiting')).toContain('Waiting for the node picture')
    expect(el('node-overview')).toBeNull()
    h.act(() => h.answer(null))
    expect(el('node-overview')).toBeNull()
    h.act(() => h.answer())
    expect(el('node-overview')!.getAttribute('data-node-id')).toBe('n1')
    expect(
      [...document.querySelectorAll('h2')].map((heading) =>
        heading.textContent?.replace(/\s+/g, ' ').trim(),
      ),
    ).toEqual([
      'Processor',
      'Memory',
      'Node',
      'Environment',
      'Node maintenance',
    ])
    const rows = [...el('node-overview')!.querySelectorAll('.row')]
    expect(rows).toHaveLength(4)
    expect(rows.map((row) => row.querySelectorAll('dl').length)).toEqual([
      4, 4, 4, 4,
    ])
    expect(
      [...rows[0]!.querySelectorAll('dt')].map((cell) =>
        cell.textContent?.replace(/\s+/g, ' ').trim(),
      ),
    ).toEqual(['Node cores', 'Daemon CPU', 'Master CPU', 'Machine CPU'])
    expect(
      [...rows[1]!.querySelectorAll('dt')].map((cell) =>
        cell.textContent?.replace(/\s+/g, ' ').trim(),
      ),
    ).toEqual(['Master', 'Workers', 'Measured total', 'Ceiling'])
    expect(text('cores')).toBe('2')
    expect(text('cpu-percent')).toBe('150% of available CPU')
    expect(text('memory-percent')).toBe('200% of ceiling')
    expect(text('total-memory')).toBe('4.0 KB')
    expect(text('workers-measured')).toBe('2 of 3 worker processes measured')
    expect(text('monopolistic-count')).toBe('2')
    expect(text('uptime')).toBe('2 h 5 min')
    expect(text('environment-attention')).toBe('10')
    expect(text('environment-differences')).toBe('3')
    expect(document.body.textContent?.replace(/\s+/g, ' ').trim()).toContain(
      'Container CPU quota',
    )
    expect(document.body.textContent?.replace(/\s+/g, ' ').trim()).toContain(
      'Container memory limit',
    )
    expect(document.body.textContent?.replace(/\s+/g, ' ').trim()).toContain(
      'PHP memory limit per process: 8.0 KB',
    )
    expect(document.body.textContent?.replace(/\s+/g, ' ').trim()).toContain(
      'Pool grows on demand',
    )
    expect(text('node-connections')).toBe('3 connections')
    expect(
      [...document.querySelectorAll('.hilos-progress')].map((bar) =>
        bar.getAttribute('style'),
      ),
    ).toEqual(['--hilos-progress: 100;', '--hilos-progress: 100;'])
    expect(el('environment-link')!.getAttribute('href')).toBe(
      '/hilos/daemon/n1/env',
    )
    expect(el('mismatch-link')!.getAttribute('href')).toBe(
      h.router.resolvePath(HilosPages.DAEMON_ENV_MISMATCH, {}),
    )
    expect(h.router.pageLoading.get()).toBe(false)
    expect(h.send).not.toHaveBeenCalled()
    expect(h.sendAction).not.toHaveBeenCalled()
    expect(h.registerTableWindow).not.toHaveBeenCalled()
  })

  it('distinguishes no report even with a declared role, null sections and known zeroes', () => {
    const h = nodeHarness()
    h.act(() =>
      h.answer(
        nodeOverview({
          workers: null,
          workerRestarts24h: null,
          uptimeMinutes: null,
          measurements: null,
          standing: null,
          environment: null,
        }),
        nodeHeading({ state: null, role: 'master' }),
      ),
    )
    expect(text('report-notice')).toContain('has not reported yet')
    for (const field of [
      'cores',
      'total-memory',
      'monopolistic-count',
      'uptime',
      'environment-attention',
      'environment-differences',
    ])
      expect(text(field)).toBe('—')
    const full = nodeOverview()
    h.act(() =>
      h.answer(
        nodeOverview({
          workers: [],
          uptimeMinutes: 0,
          workerRestarts24h: 0,
          measurements: {
            ...full.measurements!,
            cores: 0,
            daemonCores: 0,
            masterCores: 0,
            masterMemory: 0,
            workersMemory: 0,
            workersMeasured: 0,
            memoryCeiling: 0,
            machineLoad: 0,
          },
          standing: { sessions: 0, connections: 0 },
          environment: {
            missingRequired: 0,
            fromExample: 0,
            drifted: 0,
            orphans: 0,
          },
          environmentComparison: {
            answeringNodes: 2,
            divergedKeys: 0,
            sourcesDifferKeys: 0,
          },
        }),
      ),
    )
    expect(text('cores')).toBe('0')
    expect(text('daemon-cores')).toBe('0 cores')
    expect(text('cpu-percent')).toBe('— of available CPU')
    expect(text('memory-percent')).toBe('— of ceiling')
    expect(text('total-memory')).toBe('0 B')
    expect(text('monopolistic-count')).toBe('0')
    expect(text('uptime')).toBe('0 min')
    expect(text('environment-attention')).toBe('0')
    expect(text('environment-differences')).toBe('0')
    expect(text('node-connections')).toBe('0 connections')
    expect(el('report-notice')!.classList.contains('invisible')).toBe(true)
    expect(el('report-notice')!.hasAttribute('inert')).toBe(true)
  })

  it('uses heading role for browser standing and preserves an incomplete measurement as unknown', () => {
    const h = nodeHarness()
    const full = nodeOverview()
    h.act(() =>
      h.answer(
        nodeOverview({
          measurements: {
            ...full.measurements!,
            daemonCores: 0,
            masterMemory: null,
            cores: 0,
            coresBound: 'cpus',
            memoryCeilingBound: 'machine',
          },
        }),
        nodeHeading({ role: 'slave', state: 'data' }),
      ),
    )
    expect(text('total-memory')).toBe('—')
    expect(text('cpu-percent')).toBe('— of available CPU')
    expect(text('node-connections')).toBe('Does not accept browsers')
    expect(document.body.textContent?.replace(/\s+/g, ' ').trim()).toContain(
      'CPUs available to the process (nproc)',
    )
    expect(document.body.textContent?.replace(/\s+/g, ' ').trim()).toContain(
      'Physical machine memory',
    )
    h.act(() => h.publish({ node: nodeHeading({ role: null, state: null }) }))
    expect(text('node-connections')).toBe('3 connections')
    expect(text('node-role')).toBe('Role: —')
    expect(text('report-notice')).not.toContain('not reported yet')
  })

  it('keeps last report metrics through silence, shows the timestamp, distinguishes no slot and recovers live', () => {
    const h = nodeHarness()
    const clock = vi.spyOn(Date, 'now').mockReturnValue(1_700_000_060_000)
    try {
      h.act(() =>
        h.answer(
          nodeOverview({ online: false }),
          nodeHeading({ state: 'silent', silentSince: 1_700_000_000_000 }),
        ),
      )
      expect(text('report-notice')).toContain(
        'values from the last report. Last arrival:',
      )
      expect(text('report-notice')).toContain(
        text('node-silent')!.replace('Silent since ', '').replace(/\.$/, ''),
      )
      expect(text('environment-differences')).toBe('—')
      expect(text('uptime')).toBe('2 h 5 min')
      clock.mockReturnValue(1_700_003_660_000)
      h.act(() =>
        h.publish({
          overview: nodeOverview({ online: false, workerRestarts24h: 1 }),
        }),
      )
      expect(text('uptime')).toBe('2 h 5 min')
      expect(text('cpu-percent')).toBe('150% of available CPU')
      el('report-details')!.click()
      h.detect()
      expect(
        document
          .querySelector('[role="dialog"]')!
          .textContent?.replace(/\s+/g, ' ')
          .trim(),
      ).toContain('values from the last report')
      document.querySelector<HTMLElement>('[data-id="modal-close"]')!.click()
      h.detect()
      h.act(() =>
        h.answer(
          nodeOverview({
            online: false,
            workers: null,
            workerRestarts24h: null,
            uptimeMinutes: null,
            measurements: null,
            standing: null,
            environment: null,
          }),
          nodeHeading({ state: 'silent', role: null }),
        ),
      )
      expect(text('report-notice')).toContain(
        'Silent · this node has not reported yet',
      )
      expect(text('report-notice')).not.toContain('last report')
      expect(text('uptime')).toBe('—')
      h.act(() =>
        h.answer(
          nodeOverview({ uptimeMinutes: 130 }),
          nodeHeading({ state: 'leader' }),
        ),
      )
      expect(el('report-notice')!.classList.contains('invisible')).toBe(true)
      expect(text('node-state')).toBe('Leader')
      expect(text('uptime')).toBe('2 h 10 min')
      expect(text('environment-differences')).toBe('3')
      expect(h.send).not.toHaveBeenCalled()
    } finally {
      clock.mockRestore()
    }
  })

  it('reformats the report notice and shared head after a reader-zone change', () => {
    const timeZone = bindTestTimeZone('UTC')
    const h = nodeHarness()
    try {
      timeZone.receive('UTC')
      h.act(() =>
        h.answer(
          nodeOverview({ online: false }),
          nodeHeading({ state: 'silent', silentSince: 1_700_000_000_000 }),
        ),
      )
      const reportNotice = el('report-notice')!
      const initialNotice = reportNotice.textContent
      const silentLine = el('node-silent')!
      const initialSilent = silentLine.textContent

      timeZone.receive('Pacific/Honolulu')
      h.detect()

      expect(reportNotice.textContent).not.toBe(initialNotice)
      expect(silentLine.textContent).not.toBe(initialSilent)
      expect(el('report-notice')).toBe(reportNotice)
      expect(el('node-silent')).toBe(silentLine)
    } finally {
      h.view.unmount()
      vi.restoreAllMocks()
      timeZone.receive(null)
      timeZone.release()
      expect(timeZone.listenerCount()).toBe(0)
    }
  })

  it.each([
    { answeringNodes: 1, divergedKeys: 2, sourcesDifferKeys: 1 },
    { answeringNodes: 2, divergedKeys: null, sourcesDifferKeys: 1 },
    { answeringNodes: 2, divergedKeys: 2, sourcesDifferKeys: null },
  ])('keeps an unavailable comparison unknown: %o', (environmentComparison) => {
    const h = nodeHarness()
    h.act(() => h.answer(nodeOverview({ environmentComparison })))
    expect(text('environment-differences')).toBe('—')
  })

  it('leaves unrelated scope rows and the body mounted on data-only live reports', () => {
    const h = nodeHarness()
    h.act(() => {
      h.answer()
      h.publish(
        {},
        {
          tables: {
            unrelated: {
              rows: [{ rowKey: 'kept', slots: { value: { count: 9 } } }],
            },
          },
        },
      )
    })
    const body = el('node-overview')
    const page = h.scopes.page()!
    const window = page.tables.signal('unrelated').get()
    h.act(() =>
      h.publish({
        overview: nodeOverview({
          uptimeMinutes: 126,
          standing: { sessions: 5, connections: 8 },
        }),
      }),
    )
    expect(el('node-overview')).toBe(body)
    expect(h.scopes.page()).toBe(page)
    expect(page.tables.signal('unrelated').get()).toBe(window)
    expect(text('uptime')).toBe('2 h 6 min')
    expect(text('node-connections')).toBe('8 connections')
    expect(h.send).not.toHaveBeenCalled()
    expect(h.registerTableWindow).not.toHaveBeenCalled()
  })

  it('drops mismatched, invalid and cleared data instead of retaining the old node', () => {
    const h = nodeHarness()
    h.act(() => h.answer())
    h.act(() => h.answer(nodeOverview({ nodeId: 'another-node' })))
    expect(el('node-overview')).toBeNull()
    expect(h.router.pageIdentity.get()?.pageHeadOwner?.label).toBe('Node')
    h.act(() =>
      h.publish({ overview: { ...nodeOverview(), unexpected: true } }),
    )
    expect(el('node-overview')).toBeNull()
    h.act(() => h.answer())
    h.act(() =>
      h.publish({ node: { clustered: true, state: null, silentSince: null } }),
    )
    expect(el('node-overview')).toBeNull()
    expect(el('node-role')).toBeNull()
    expect(h.router.pageIdentity.get()?.pageHeadOwner?.label).toBe('Node')
    h.act(() => h.answer())
    h.act(() =>
      h.scopes
        .openPage(HilosPages.SETTINGS)
        .data.set('overview', nodeOverview()),
    )
    expect(el('node-overview')).toBeNull()
    h.act(() => h.scopes.dropPage())
    expect(document.querySelector('h1')).toBeNull()
    expect(
      document.body.textContent?.replace(/\s+/g, ' ').trim(),
    ).not.toContain('n1')
  })

  it('keeps all maintenance controls disabled and sends no wire on attempted presses', () => {
    const h = nodeHarness()
    h.act(() => h.answer())
    const buttons = [...el('maintenance')!.querySelectorAll('button')]
    expect(
      buttons.map((button) => button.textContent?.replace(/\s+/g, ' ').trim()),
    ).toEqual(['Drain node', 'Reload configuration', 'Force disconnect node'])
    for (const button of buttons) {
      expect(button.disabled).toBe(true)
      expect(button.getAttribute('aria-describedby')).toBe(
        'hilos-daemon-maintenance-unavailable',
      )
      button.click()
      button.dispatchEvent(
        new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }),
      )
    }
    expect(
      document
        .querySelector('#hilos-daemon-maintenance-unavailable')!
        .textContent?.replace(/\s+/g, ' ')
        .trim(),
    ).toBe('Node maintenance is not available yet.')
    expect(h.send).not.toHaveBeenCalled()
    expect(h.sendAction).not.toHaveBeenCalled()
  })

  it('keeps the same report dialog and focus through live metadata updates', () => {
    const h = nodeHarness()
    h.act(() =>
      h.answer(
        nodeOverview({ online: false }),
        nodeHeading({ state: 'silent', silentSince: 1_700_000_000_000 }),
      ),
    )
    const opener = el('report-details')!
    opener.focus()
    opener.click()
    h.detect()
    const dialog = document.querySelector('[role="dialog"]')!
    expect(dialog).not.toBeNull()
    expect(document.activeElement).toBe(dialog)
    h.act(() =>
      h.publish({
        overview: nodeOverview({ online: false, workerRestarts24h: 1 }),
        node: nodeHeading({ state: 'silent', silentSince: 1_700_000_030_000 }),
      }),
    )
    expect(document.querySelector('[role="dialog"]')).toBe(dialog)
    expect(document.activeElement).toBe(dialog)
    dialog.querySelector<HTMLButtonElement>('.modal-footer button')!.click()
    h.detect()
    expect(document.querySelector('[role="dialog"]')).toBeNull()
    expect(document.activeElement).toBe(opener)
  })

  it.each<{
    boundary: string
    change: (h: ReturnType<typeof nodeHarness>) => void
    nodeId?: string
  }>([
    { boundary: 'null overview', change: (h) => h.answer(null) },
    {
      boundary: 'invalid overview',
      change: (h) =>
        h.publish({ overview: { ...nodeOverview(), unexpected: true } }),
    },
    { boundary: 'missing heading', change: (h) => h.publish({ node: null }) },
    {
      boundary: 'invalid heading',
      change: (h) =>
        h.publish({ node: { ...nodeHeading(), unexpected: true } }),
    },
    {
      boundary: 'mismatched node',
      change: (h) => h.answer(nodeOverview({ nodeId: 'another-node' })),
    },
    {
      boundary: 'empty node address',
      change: (h) => h.answer(nodeOverview({ nodeId: '' })),
    },
    { boundary: 'dropped scope', change: (h) => h.scopes.dropPage() },
    {
      boundary: 'foreign scope',
      change: (h) => h.scopes.openPage(HilosPages.SETTINGS),
    },
    {
      boundary: 'replacement scope for the same node before detectChanges',
      change: (h) => {
        h.scopes.openPage(HilosPages.DAEMON_NODE)
        h.answer(
          nodeOverview({ online: false }),
          nodeHeading({ state: 'silent' }),
        )
      },
    },
    {
      boundary: 'clear and recovery before detectChanges',
      change: (h) => {
        h.answer(null)
        h.answer(
          nodeOverview({ online: false }),
          nodeHeading({ state: 'silent' }),
        )
      },
    },
    {
      boundary: 'another node address',
      change: (h) => h.router.navigate('/hilos/daemon/n2'),
      nodeId: 'n2',
    },
    {
      boundary: 'navigation away',
      change: (h) => h.router.navigate('/hilos/daemon'),
    },
    { boundary: 'subscription refusal', change: (h) => h.refuse() },
  ])(
    'clears report opening after $boundary and keeps recovery closed',
    ({ change, nodeId = 'n1' }) => {
      const h = nodeHarness()
      const silent = nodeHeading({ state: 'silent' })
      h.act(() => h.answer(nodeOverview({ online: false }), silent))
      el('report-details')!.click()
      h.detect()
      expect(document.querySelector('[role="dialog"]')).not.toBeNull()
      h.act(() => change(h))
      expect(document.querySelector('[role="dialog"]')).toBeNull()
      h.act(() => {
        if (h.router.currentRoute.get().page !== HilosPages.DAEMON_NODE) {
          h.router.navigate(`/hilos/daemon/${nodeId}`)
        }
        if (h.scopes.page()?.key !== HilosPages.DAEMON_NODE) {
          h.scopes.openPage(HilosPages.DAEMON_NODE)
        }
        h.answer(nodeOverview({ nodeId, online: false }), silent)
      })
      expect(el('node-overview')!.getAttribute('data-node-id')).toBe(nodeId)
      expect(document.querySelector('[role="dialog"]')).toBeNull()
      el('report-details')!.click()
      h.detect()
      expect(document.querySelector('[role="dialog"]')).not.toBeNull()
    },
  )

  it('releases the old report observation on context change and unmount', () => {
    const h = nodeHarness()
    h.act(() =>
      h.answer(
        nodeOverview({ online: false }),
        nodeHeading({ state: 'silent' }),
      ),
    )
    const oldScope = h.scopes.page()!
    const oldRead = vi.spyOn(oldScope.data.signal('overview'), 'get')
    h.act(() => oldScope.data.set('overview', nodeOverview({ online: false })))
    expect(oldRead).toHaveBeenCalled()
    el('report-details')!.click()
    h.detect()
    expect(document.querySelector('[role="dialog"]')).not.toBeNull()
    const page = h.fixture.debugElement.query(By.directive(HilosDaemonNodePage))
      .componentInstance as HilosDaemonNodePage
    const scopes = new ScopeManager()
    const replacement = openNodePage(scopes, HilosPages.DAEMON_NODE)
    replacement.data.set('overview', nodeOverview({ online: false }))
    h.fixture.componentRef.setInput('context', { ...page.context(), scopes })
    h.detect()
    expect(document.querySelector('[role="dialog"]')).toBeNull()
    expect(el('node-overview')!.getAttribute('data-node-id')).toBe('n1')
    el('report-details')!.click()
    h.detect()
    const dialog = document.querySelector('[role="dialog"]')!
    expect(dialog).not.toBeNull()
    oldRead.mockClear()
    h.act(() => oldScope.data.set('overview', null))
    expect(oldRead).not.toHaveBeenCalled()
    expect(document.querySelector('[role="dialog"]')).toBe(dialog)
    const currentRead = vi.spyOn(replacement.data.signal('overview'), 'get')
    h.act(() =>
      replacement.data.set('overview', nodeOverview({ online: false })),
    )
    expect(currentRead).toHaveBeenCalled()
    h.view.unmount()
    expect(document.querySelector('[role="dialog"]')).toBeNull()
    currentRead.mockClear()
    replacement.data.set('overview', null)
    expect(currentRead).not.toHaveBeenCalled()
    oldRead.mockRestore()
    currentRead.mockRestore()
  })
})
