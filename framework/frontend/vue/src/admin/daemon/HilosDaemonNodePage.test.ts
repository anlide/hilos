import { enableAutoUnmount } from '@vue/test-utils'
import { HilosPages } from '@hilos/core/base'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'

import {
  nodeHarness,
  nodeHeading,
  nodeOverview,
} from '../../../test/helpers/hilosDaemonNode.js'

const id = (name: string) => `[data-id="hilos-daemon-${name}"]`
enableAutoUnmount(afterEach)

describe('HilosDaemonNodePage', () => {
  it('waits for the complete addressed picture, then renders 4 CPU, 4 memory, Node and Environment tiles', async () => {
    const h = nodeHarness()
    expect(h.view.get(id('node-waiting')).text()).toContain(
      'Waiting for the node picture',
    )
    expect(h.view.find(id('node-overview')).exists()).toBe(false)
    h.answer(null)
    await nextTick()
    expect(h.view.find(id('node-overview')).exists()).toBe(false)
    h.answer()
    await nextTick()
    expect(h.view.get(id('node-overview')).attributes('data-node-id')).toBe(
      'n1',
    )
    expect(h.view.findAll('h2').map((heading) => heading.text())).toEqual([
      'Processor',
      'Memory',
      'Node',
      'Environment',
      'Node maintenance',
    ])
    const rows = h.view.get(id('node-overview')).findAll('.row')
    expect(rows).toHaveLength(4)
    expect(rows.map((row) => row.findAll('dl').length)).toEqual([4, 4, 4, 4])
    expect(rows[0].findAll('dt').map((cell) => cell.text())).toEqual([
      'Node cores',
      'Daemon CPU',
      'Master CPU',
      'Machine CPU',
    ])
    expect(rows[1].findAll('dt').map((cell) => cell.text())).toEqual([
      'Master',
      'Workers',
      'Measured total',
      'Ceiling',
    ])
    expect(h.view.get(id('cores')).text()).toBe('2')
    expect(h.view.get(id('cpu-percent')).text()).toBe('150% of available CPU')
    expect(h.view.get(id('memory-percent')).text()).toBe('200% of ceiling')
    expect(h.view.get(id('total-memory')).text()).toBe('4.0 KB')
    expect(h.view.get(id('workers-measured')).text()).toBe(
      '2 of 3 worker processes measured',
    )
    expect(h.view.get(id('monopolistic-count')).text()).toBe('2')
    expect(h.view.get(id('uptime')).text()).toBe('2 h 5 min')
    expect(h.view.get(id('environment-attention')).text()).toBe('10')
    expect(h.view.get(id('environment-differences')).text()).toBe('3')
    expect(h.view.text()).toContain('Container CPU quota')
    expect(h.view.text()).toContain('Container memory limit')
    expect(h.view.text()).toContain('PHP memory limit per process: 8.0 KB')
    expect(h.view.text()).toContain('Pool grows on demand')
    expect(h.view.get(id('node-connections')).text()).toBe('3 connections')
    expect(
      h.view.findAll('.hilos-progress').map((bar) => bar.attributes('style')),
    ).toEqual(['--hilos-progress: 100;', '--hilos-progress: 100;'])
    expect(h.view.get(id('environment-link')).attributes('href')).toBe(
      '/hilos/daemon/n1/env',
    )
    expect(h.view.get(id('mismatch-link')).attributes('href')).toBe(
      h.router.resolvePath(HilosPages.DAEMON_ENV_MISMATCH, {}),
    )
    expect(h.router.pageLoading.get()).toBe(false)
    expect(h.send).not.toHaveBeenCalled()
    expect(h.sendAction).not.toHaveBeenCalled()
    expect(h.registerTableWindow).not.toHaveBeenCalled()
  })

  it('keeps known no-report, unknown sections, and known zeroes distinct', async () => {
    const h = nodeHarness()
    h.answer(
      nodeOverview({
        workers: null,
        workerRestarts24h: null,
        uptimeMinutes: null,
        measurements: null,
        standing: null,
        environment: null,
      }),
      nodeHeading({ state: null, role: null }),
    )
    await nextTick()
    expect(h.view.get(id('report-notice')).text()).toContain(
      'has not reported yet',
    )
    for (const field of [
      'cores',
      'total-memory',
      'monopolistic-count',
      'uptime',
      'environment-attention',
      'environment-differences',
    ]) {
      expect(h.view.get(id(field)).text()).toBe('—')
    }
    const full = nodeOverview()
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
    )
    await nextTick()
    expect(h.view.get(id('cores')).text()).toBe('0')
    expect(h.view.get(id('daemon-cores')).text()).toBe('0 cores')
    expect(h.view.get(id('cpu-percent')).text()).toBe('— of available CPU')
    expect(h.view.get(id('memory-percent')).text()).toBe('— of ceiling')
    expect(h.view.get(id('total-memory')).text()).toBe('0 B')
    expect(h.view.get(id('monopolistic-count')).text()).toBe('0')
    expect(h.view.get(id('uptime')).text()).toBe('0 min')
    expect(h.view.get(id('environment-attention')).text()).toBe('0')
    expect(h.view.get(id('environment-differences')).text()).toBe('0')
    expect(h.view.get(id('node-connections')).text()).toBe('0 connections')
    expect(h.view.get(id('report-notice')).classes()).toContain('invisible')
    expect(h.view.get(id('report-notice')).attributes('inert')).toBeDefined()
  })

  it('uses heading role for data-node browsers and retains an incomplete measurement as unknown', async () => {
    const h = nodeHarness()
    const full = nodeOverview()
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
    )
    await nextTick()
    expect(h.view.get(id('total-memory')).text()).toBe('—')
    expect(h.view.get(id('cpu-percent')).text()).toBe('— of available CPU')
    expect(h.view.get(id('node-connections')).text()).toBe(
      'Does not accept browsers',
    )
    expect(h.view.text()).toContain('CPUs available to the process (nproc)')
    expect(h.view.text()).toContain('Physical machine memory')
    h.publish({ node: nodeHeading({ role: null, state: null }) })
    await nextTick()
    expect(h.view.get(id('node-connections')).text()).toBe('3 connections')
    expect(h.view.get(id('node-role')).text()).toBe('Role: —')
    expect(h.view.get(id('report-notice')).text()).not.toContain(
      'not reported yet',
    )
  })

  it('freezes report metrics and uptime through silence, distinguishes no slot, and recovers live', async () => {
    const h = nodeHarness()
    const clock = vi.spyOn(Date, 'now').mockReturnValue(1_700_000_060_000)
    try {
      const silent = nodeHeading({
        state: 'silent',
        silentSince: 1_700_000_000,
      })
      h.answer(nodeOverview({ online: false }), silent)
      await nextTick()
      expect(h.view.get(id('report-notice')).text()).toContain(
        'values from the last report. Last arrival:',
      )
      expect(h.view.get(id('report-notice')).text()).toContain(
        h.view
          .get(id('node-silent'))
          .text()
          .replace('Silent since ', '')
          .replace(/\.$/, ''),
      )
      expect(h.view.get(id('environment-differences')).text()).toBe('—')
      expect(h.view.get(id('uptime')).text()).toBe('2 h 5 min')
      clock.mockReturnValue(1_700_003_660_000)
      h.publish({
        overview: nodeOverview({ online: false, workerRestarts24h: 1 }),
      })
      await nextTick()
      expect(h.view.get(id('uptime')).text()).toBe('2 h 5 min')
      expect(h.view.get(id('cpu-percent')).text()).toBe('150% of available CPU')
      await h.view.get(id('report-details')).trigger('click')
      expect(
        document.body.querySelector('[role="dialog"]')?.textContent,
      ).toContain('values from the last report')
      const close = document.body.querySelector<HTMLButtonElement>(
        '[data-id="modal-close"]',
      )!
      close.click()
      await nextTick()
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
      )
      await nextTick()
      expect(h.view.get(id('report-notice')).text()).toContain(
        'Silent · this node has not reported yet',
      )
      expect(h.view.get(id('report-notice')).text()).not.toContain(
        'last report',
      )
      expect(h.view.get(id('uptime')).text()).toBe('—')
      h.answer(
        nodeOverview({ uptimeMinutes: 130 }),
        nodeHeading({ state: 'leader' }),
      )
      await nextTick()
      expect(h.view.get(id('report-notice')).classes()).toContain('invisible')
      expect(h.view.get(id('node-state')).text()).toBe('Leader')
      expect(h.view.get(id('uptime')).text()).toBe('2 h 10 min')
      expect(h.view.get(id('environment-differences')).text()).toBe('3')
      expect(h.send).not.toHaveBeenCalled()
    } finally {
      clock.mockRestore()
    }
  })

  it.each([
    { answeringNodes: 1, divergedKeys: 2, sourcesDifferKeys: 1 },
    { answeringNodes: 2, divergedKeys: null, sourcesDifferKeys: 1 },
    { answeringNodes: 2, divergedKeys: 2, sourcesDifferKeys: null },
  ])(
    'keeps an unavailable environment comparison unknown: %o',
    async (environmentComparison) => {
      const h = nodeHarness()
      h.answer(nodeOverview({ environmentComparison }))
      await nextTick()
      expect(h.view.get(id('environment-differences')).text()).toBe('—')
    },
  )

  it('leaves unrelated scope rows and the page mount intact on data-only live reports', async () => {
    const h = nodeHarness()
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
    await nextTick()
    const body = h.view.get(id('node-overview')).element
    const page = h.scopes.page()!
    const window = page.tables.signal('unrelated').get()
    h.publish({
      overview: nodeOverview({
        uptimeMinutes: 126,
        standing: { sessions: 5, connections: 8 },
      }),
    })
    await nextTick()
    expect(h.view.get(id('node-overview')).element).toBe(body)
    expect(h.scopes.page()).toBe(page)
    expect(page.tables.signal('unrelated').get()).toBe(window)
    expect(h.view.get(id('uptime')).text()).toBe('2 h 6 min')
    expect(h.view.get(id('node-connections')).text()).toBe('8 connections')
    expect(h.send).not.toHaveBeenCalled()
    expect(h.registerTableWindow).not.toHaveBeenCalled()
  })

  it('keeps the same report dialog and focus through live metadata updates', async () => {
    const h = nodeHarness()
    h.answer(
      nodeOverview({ online: false }),
      nodeHeading({ state: 'silent', silentSince: 1_700_000_000 }),
    )
    await nextTick()
    const opener = h.view.get<HTMLButtonElement>(id('report-details'))
    opener.element.focus()
    await opener.trigger('click')
    await nextTick()
    const dialog = document.querySelector('[role="dialog"]')!
    expect(dialog).not.toBeNull()
    expect(document.activeElement).toBe(dialog)
    h.publish({
      overview: nodeOverview({ online: false, workerRestarts24h: 1 }),
      node: nodeHeading({ state: 'silent', silentSince: 1_700_000_030 }),
    })
    await nextTick()
    expect(document.querySelector('[role="dialog"]')).toBe(dialog)
    expect(document.activeElement).toBe(dialog)
    dialog.querySelector<HTMLButtonElement>('.modal-footer button')!.click()
    await nextTick()
    expect(document.querySelector('[role="dialog"]')).toBeNull()
    expect(document.activeElement).toBe(opener.element)
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
      boundary: 'mismatched node',
      change: (h) => h.answer(nodeOverview({ nodeId: 'another-node' })),
    },
    {
      boundary: 'empty node address',
      change: (h) => h.answer(nodeOverview({ nodeId: '' })),
    },
    { boundary: 'dropped scope', change: (h) => h.scopes.dropPage() },
    {
      boundary: 'unrelated scope',
      change: (h) => h.scopes.openPage(HilosPages.SETTINGS),
    },
    {
      boundary: 'replacement scope for the same node before render',
      change: (h) => {
        h.scopes.openPage(HilosPages.DAEMON_NODE)
        h.answer(
          nodeOverview({ online: false }),
          nodeHeading({ state: 'silent' }),
        )
      },
    },
    {
      boundary: 'clear and recovery before render',
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
    async ({ change, nodeId = 'n1' }) => {
      const h = nodeHarness()
      const silent = nodeHeading({ state: 'silent' })
      h.answer(nodeOverview({ online: false }), silent)
      await nextTick()
      await h.view.get(id('report-details')).trigger('click')
      expect(document.querySelector('[role="dialog"]')).not.toBeNull()
      change(h)
      await nextTick()
      expect(document.querySelector('[role="dialog"]')).toBeNull()
      if (h.router.currentRoute.get().page !== HilosPages.DAEMON_NODE) {
        h.router.navigate(`/hilos/daemon/${nodeId}`)
      }
      if (h.scopes.page()?.key !== HilosPages.DAEMON_NODE) {
        h.scopes.openPage(HilosPages.DAEMON_NODE)
      }
      h.answer(nodeOverview({ nodeId, online: false }), silent)
      await nextTick()
      expect(h.view.get(id('node-overview')).attributes('data-node-id')).toBe(
        nodeId,
      )
      expect(document.querySelector('[role="dialog"]')).toBeNull()
      await h.view.get(id('report-details')).trigger('click')
      expect(document.querySelector('[role="dialog"]')).not.toBeNull()
    },
  )

  it('drops an old or invalid node body when the route, current scope or response does not match', async () => {
    const h = nodeHarness()
    h.answer()
    await nextTick()
    h.answer(nodeOverview({ nodeId: 'another-node' }))
    await nextTick()
    expect(h.view.find(id('node-overview')).exists()).toBe(false)
    expect(h.router.pageIdentity.get()?.pageHeadOwner?.label).toBe('Node')
    h.publish({ overview: { ...nodeOverview(), unexpected: true } })
    await nextTick()
    expect(h.view.find(id('node-overview')).exists()).toBe(false)
    h.answer()
    await nextTick()
    h.scopes.openPage(HilosPages.SETTINGS).data.set('overview', nodeOverview())
    await nextTick()
    expect(h.view.find(id('node-overview')).exists()).toBe(false)
    h.scopes.dropPage()
    await nextTick()
    expect(h.view.find('h1').exists()).toBe(false)
    expect(h.view.text()).not.toContain('n1')
  })

  it('keeps all maintenance controls disabled and sends no wire on attempted presses', async () => {
    const h = nodeHarness()
    h.answer()
    await nextTick()
    const buttons = h.view.get(id('maintenance')).findAll('button')
    expect(buttons.map((button) => button.text())).toEqual([
      'Drain node',
      'Reload configuration',
      'Force disconnect node',
    ])
    for (const button of buttons) {
      expect(button.attributes('disabled')).toBeDefined()
      expect(button.attributes('aria-describedby')).toBe(
        'hilos-daemon-maintenance-unavailable',
      )
      await button.trigger('click')
      await button.trigger('keydown', { key: 'Enter' })
    }
    expect(h.view.get('#hilos-daemon-maintenance-unavailable').text()).toBe(
      'Node maintenance is not available yet.',
    )
    expect(h.send).not.toHaveBeenCalled()
    expect(h.sendAction).not.toHaveBeenCalled()
  })
})
