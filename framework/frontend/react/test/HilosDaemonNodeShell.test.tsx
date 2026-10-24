import { act, cleanup } from '@testing-library/react'
import { bindAdminAccess, HilosPages, hilosAdminAccess } from '@hilos/core/base'
import { afterEach, describe, expect, it } from 'vitest'
import {
  nodeHarness,
  nodeHeading,
  nodeOverview,
  nodeTabs,
} from './helpers/hilosDaemonNode.js'

const el = (name: string) =>
  document.querySelector<HTMLElement>(`[data-id="${name}"]`)
const node = (name: string) => el(`hilos-daemon-node-${name}`)
const text = (selector: string) =>
  [...document.querySelectorAll(selector)].map((item) => item.textContent)
afterEach(cleanup)

describe('HilosDaemonNodeShell', () => {
  it('waits in the real entity skeleton and shows the bound name in its first committed paint', () => {
    const h = nodeHarness()
    expect(el('hilos-page-head-skeleton')).not.toBeNull()
    expect(document.querySelector('h1')).toBeNull()
    expect(document.querySelector('nav')).toBeNull()
    expect(node('visual')).toBeNull()
    act(() => h.answer())
    expect(text('h1')).toEqual(['n1'])
    expect(el('hilos-page-head-title')!.textContent).toBe('n1')
    expect(el('hilos-admin-children')).toBeNull()
    expect(node('line')).toBeNull()
    expect(
      node('visual')!.querySelector('i')!.classList.contains('bi-hdd-network'),
    ).toBe(true)
    expect(node('visual')!.getAttribute('aria-hidden')).toBe('true')
    expect(text('nav[aria-label="Breadcrumb"] li')).toEqual([
      'Hilos',
      '/Daemon',
      '/n1',
    ])
    const crumbs = document.querySelector('nav[aria-label="Breadcrumb"]')!
    expect(
      [...crumbs.querySelectorAll('a')].map((link) =>
        link.getAttribute('href'),
      ),
    ).toEqual(['/hilos', '/hilos/daemon'])
    expect(crumbs.querySelector('[aria-current="page"]')!.tagName).toBe('LI')
    expect(h.router.pageIdentity.get()?.pageHeadOwner?.label).toBe('n1')
    h.view.unmount()
    expect(h.router.pageIdentity.get()?.pageHeadOwner?.label).toBe('Node')
    const ready = nodeHarness({ initial: true, nodeId: 'ready-node' })
    expect(text('h1')).toEqual(['ready-node'])
    expect(ready.router.pageIdentity.get()?.pageHeadOwner?.label).toBe(
      'ready-node',
    )
  })

  it.each(nodeTabs.filter((tab) => tab.page !== HilosPages.DAEMON_WEBSOCKETS))(
    'marks $label by page key in the one ordered link row',
    ({ page }) => {
      const nodeId = 'node /?#%2F🌍'
      const h = nodeHarness({ shell: true, page, nodeId })
      act(() => h.answer(nodeOverview({ nodeId })))
      expect(
        document.querySelectorAll('nav[aria-label="Node pages"]'),
      ).toHaveLength(1)
      const nav = document.querySelector('nav[aria-label="Node pages"]')!
      const links = [...nav.querySelectorAll('a')]
      expect(links.map((link) => link.textContent)).toEqual([
        'Overview',
        'Workers',
        'Agents',
        'Cron',
        'HTTP',
        'Environment',
      ])
      const active = nodeTabs.find((tab) => tab.page === page)!
      expect(
        [...nav.querySelectorAll('[aria-current="page"]')].map(
          (link) => link.textContent,
        ),
      ).toEqual([active.label])
      for (const tab of nodeTabs.filter(
        (tab) => tab.page !== HilosPages.DAEMON_WEBSOCKETS,
      )) {
        const link = links.find((link) => link.textContent === tab.label)!
        expect(link.getAttribute('href')).toBe(
          h.router.resolvePath(tab.page, { nodeId }),
        )
        expect(link.hasAttribute('role')).toBe(false)
      }
      expect(text('h1')).toEqual([nodeId])
      expect(text('nav[aria-label="Breadcrumb"] li')).toEqual([
        'Hilos',
        '/Daemon',
        `/${nodeId}`,
      ])
      expect(document.body.textContent).not.toContain('hilos_daemon')
      expect(document.querySelector('a[href=""]')).toBeNull()
      expect(h.send).not.toHaveBeenCalled()
      expect(h.sendAction).not.toHaveBeenCalled()
    },
  )

  it('omits unavailable and unbuilt tabs and all unavailable body and log links', () => {
    const h = nodeHarness({
      unavailable: [
        HilosPages.DAEMON_ENV,
        HilosPages.DAEMON_ENV_MISMATCH,
        HilosPages.LOGS_VIEW,
      ],
    })
    act(() => h.answer())
    expect(text('nav[aria-label="Node pages"] a')).toEqual([
      'Overview',
      'Workers',
      'Agents',
      'Cron',
      'HTTP',
    ])
    expect(node('log')).toBeNull()
    expect(el('hilos-daemon-environment-link')).toBeNull()
    expect(el('hilos-daemon-environment-unbuilt')!.textContent).toBe(
      'Environment page is not available yet',
    )
    expect(el('hilos-daemon-mismatch-link')).toBeNull()
    expect(document.querySelector('a[href=""]')).toBeNull()
  })

  it.each([
    ['leader', 'master', 'Leader', 'warning'],
    ['standby', 'master', 'Standby', 'secondary'],
    ['data', 'slave', 'Data', 'info'],
    ['silent', null, 'Silent', 'danger'],
  ] as const)(
    'separates %s from declared role %s',
    (state, role, label, tone) => {
      const h = nodeHarness({ shell: true, page: HilosPages.DAEMON_AGENTS })
      act(() =>
        h.answer(
          null,
          nodeHeading({
            state,
            role,
            silentSince: state === 'silent' ? 1_700_000_000_000 : null,
          }),
        ),
      )
      expect(node('state')!.textContent).toBe(label)
      expect(node('state')!.classList.contains(`bg-${tone}-subtle`)).toBe(true)
      expect(node('role')!.textContent).toBe(`Role: ${role ?? '—'}`)
      expect(el('hilos-page-head-action')!.textContent).toBe(
        'Change on the diagram',
      )
      expect(el('hilos-page-head-action')!.getAttribute('href')).toBe(
        '/hilos/daemon',
      )
      if (state === 'silent') {
        expect(
          node('visual')!.querySelector('i')!.classList.contains('bi-plug'),
        ).toBe(true)
        expect(node('silent')!.textContent).toMatch(/^Silent since /)
      }
    },
  )

  it('uses Environment’s own nullable state and declared role through silence and recovery', () => {
    const h = nodeHarness({ shell: true, page: HilosPages.DAEMON_ENV })
    act(() => h.answer(null, nodeHeading({ state: null, role: 'master' })))
    expect(text('h1')).toEqual(['n1'])
    expect(node('role')!.textContent).toBe('Role: master')
    expect(node('state')).toBeNull()
    expect(document.body.textContent).not.toContain('Leader')
    act(() =>
      h.publish({ node: nodeHeading({ state: 'silent', role: 'slave' }) }),
    )
    expect(node('state')!.textContent).toBe('Silent')
    expect(node('role')!.textContent).toBe('Role: slave')
    expect(node('silent')).toBeNull()
    act(() => h.publish({ node: nodeHeading({ state: null, role: null }) }))
    expect(node('state')).toBeNull()
    expect(node('role')!.textContent).toBe('Role: —')
  })

  it('keeps standalone free of state, role, mismatch and diagram transition', () => {
    const h = nodeHarness()
    act(() =>
      h.answer(
        nodeOverview(),
        nodeHeading({
          clustered: false,
          state: 'silent',
          role: 'master',
          silentSince: 1_700_000_000_000,
        }),
      ),
    )
    expect(node('solo')!.textContent).toBe('Single installation')
    for (const name of ['state', 'role', 'silent'])
      expect(node(name)).toBeNull()
    expect(
      node('visual')!.querySelector('i')!.classList.contains('bi-hdd-network'),
    ).toBe(true)
    expect(el('hilos-page-head-action')).toBeNull()
    expect(el('hilos-daemon-mismatch-link')).toBeNull()
    expect(el('hilos-daemon-environment-differences')!.textContent).toBe('—')
  })

  it('updates addressed links and releases the name on switch, clear and unmount', () => {
    const h = nodeHarness()
    act(() => h.answer())
    expect(node('log')!.getAttribute('href')).toBe('/hilos/logs/view/n1/live')
    act(() =>
      h.router.navigate(
        h.router.resolvePath(HilosPages.DAEMON_NODE, { nodeId: 'n2' })!,
      ),
    )
    expect(h.router.pageIdentity.get()).toBeUndefined()
    expect(document.querySelector('h1')).toBeNull()
    expect(node('overview')).toBeNull()
    act(() => h.answer(nodeOverview({ nodeId: 'n2' })))
    expect(text('h1')).toEqual(['n2'])
    expect(node('log')!.getAttribute('href')).toBe('/hilos/logs/view/n2/live')
    expect(el('hilos-daemon-environment-link')!.getAttribute('href')).toBe(
      '/hilos/daemon/n2/env',
    )
    act(() => h.scopes.openPage(HilosPages.DAEMON_NODE))
    expect(document.querySelector('h1')).toBeNull()
    expect(node('overview')).toBeNull()
    act(() => h.answer(nodeOverview({ nodeId: 'n2' })))
    h.view.unmount()
    expect(h.router.pageIdentity.get()?.pageHeadOwner?.label).toBe('Node')
    expect(h.router.pageIdentity.get()?.breadcrumb.at(-1)?.label).toBe('Node')
  })

  it('inherits the real outlet refusal and recovery without retaining the previous head', async () => {
    const h = nodeHarness({ outlet: true })
    await act(async () => h.answer())
    expect(text('h1')).toEqual(['n1'])
    await act(async () => h.refuse())
    expect(document.body.textContent).toContain('This page is closed.')
    expect(el('hilos-page-head')).toBeNull()
    expect(node('overview')).toBeNull()
    expect(h.router.pageIdentity.get()).toBeUndefined()
    await act(async () => h.answer(nodeOverview({ uptimeMinutes: 126 })))
    expect(text('h1')).toEqual(['n1'])
    expect(el('hilos-daemon-uptime')!.textContent).toBe('2 h 6 min')
    expect(h.send).not.toHaveBeenCalled()
  })

  it.each([false, true])(
    'suppresses every node log link on %s Shell mode with an available route through live switch',
    (shell) => {
      const h = nodeHarness({ shell, showLogLinks: false })
      expect(
        h.router.resolvePath(HilosPages.LOGS_VIEW, {
          nodeId: 'n1',
          source: 'live',
        }),
      ).toBe('/hilos/logs/view/n1/live')
      act(() => h.answer())
      expect(node('log')).toBeNull()
      act(() =>
        h.publish({
          node: nodeHeading({ state: 'leader' }),
          overview: nodeOverview({ uptimeMinutes: 126 }),
        }),
      )
      expect(node('log')).toBeNull()
      act(() =>
        h.router.navigate(
          h.router.resolvePath(HilosPages.DAEMON_NODE, { nodeId: 'n2' })!,
        ),
      )
      act(() => h.answer(nodeOverview({ nodeId: 'n2' })))
      expect(text('h1')).toEqual(['n2'])
      expect(node('log')).toBeNull()
      expect(document.querySelector('a[href*="/logs/"]')).toBeNull()
    },
  )

  it('offers the same value-free diagnostics and links to an admin-view-mode viewer', () => {
    const h = nodeHarness()
    act(() => h.scopes.session.data.set('adminViewMode', true))
    let release: () => void
    act(() => {
      release = bindAdminAccess(h.scopes)
    })
    try {
      act(() => h.answer())
      expect(hilosAdminAccess.get()).toBe('view')
      expect(text('h1')).toEqual(['n1'])
      expect(
        document.querySelectorAll('nav[aria-label="Node pages"] a'),
      ).toHaveLength(6)
      expect(el('hilos-daemon-environment-link')!.textContent).toBe(
        'On the environment page',
      )
      expect(document.body.textContent).not.toMatch(/APP_ENV\s+prod/)
      expect(document.querySelector('input')).toBeNull()
      expect(h.sendAction).not.toHaveBeenCalled()
    } finally {
      act(() => release!())
    }
  })

  it('keeps a child table’s window, expansion and mount through live heading updates', () => {
    const h = nodeHarness({
      shell: true,
      workers: true,
      page: HilosPages.DAEMON_WORKERS,
    })
    act(() => h.answer(null))
    const rows = [
      {
        rowKey: 'regular:0',
        slots: {
          worker: {
            index: 0,
            kind: 'regular',
            pid: 123,
            memoryBytes: 1024,
            agentCount: 1,
            agentIds: ['room:1'],
            logStream: 'worker-regular-0.log',
          },
        },
      },
    ]
    act(() => {
      h.publish(
        {},
        {
          windows: {
            hilosDaemonWorkers: {
              rows,
              totalCount: 1,
              totalExact: true,
              firstAnchor: null,
              lastAnchor: null,
              limit: 25,
              sort: [],
              rowsBefore: 0,
            },
          },
        },
      )
      h.emit('tableWindow', {
        data: {
          page: HilosPages.DAEMON_WORKERS,
          tableKey: 'hilosDaemonWorkers',
          requestId: h.sendViewport.mock.calls.at(-1)?.[2].requestId,
          rows,
          totalCount: 1,
          totalExact: true,
          firstAnchor: null,
          lastAnchor: null,
          limit: 25,
          rowsBefore: 0,
        },
      })
      h.workers!.controller.expandRow('regular:0', true)
    })
    const table = document.querySelector('table')
    const window = h.workers!.controller.rows.get()
    const descriptor = h.workers!.controller.descriptor()
    const requests = h.sendViewport.mock.calls.length
    expect(el('hilos-table-row-detail-regular:0')).not.toBeNull()
    act(() => h.publish({ node: nodeHeading({ state: 'leader' }) }))
    expect(document.querySelector('table')).toBe(table)
    expect(h.workers!.controller.rows.get()).toBe(window)
    expect(h.workers!.controller.descriptor()).toEqual(descriptor)
    expect(el('hilos-table-row-detail-regular:0')).not.toBeNull()
    expect(h.sendViewport).toHaveBeenCalledTimes(requests)
    expect(h.registerTableWindow).toHaveBeenCalledTimes(1)
    expect(text('h1')).toEqual(['n1'])
  })
})

describe('actual node child pages', () => {
  it.each(
    nodeTabs.filter(
      (tab) =>
        tab.page !== HilosPages.DAEMON_NODE &&
        tab.page !== HilosPages.DAEMON_WEBSOCKETS,
    ),
  )(
    'keeps $label tables and no-LOGS policy under the live head',
    ({ page, label }) => {
      const h = nodeHarness({ child: true, page, showLogLinks: false })
      act(() => h.answer())
      expect(text('h1')).toEqual(['n1'])
      expect(
        text('nav[aria-label="Node pages"] [aria-current="page"]'),
      ).toEqual([label])
      expect(el('hilos-admin-children')).toBeNull()
      expect(node('line')).toBeNull()
      expect(node('log')).toBeNull()
      const tables = [...h.view.container.querySelectorAll('table.table-hover')]
      expect(tables).toHaveLength(page === HilosPages.DAEMON_ENV ? 2 : 1)
      const windows = h.registerTableWindow.mock.calls.length
      act(() =>
        h.publish({
          node: nodeHeading({
            role: 'slave',
            state: page === HilosPages.DAEMON_ENV ? null : 'data',
          }),
        }),
      )
      expect(node('role')!.textContent).toBe('Role: slave')
      expect(node('log')).toBeNull()
      expect([
        ...h.view.container.querySelectorAll('table.table-hover'),
      ]).toEqual(tables)
      expect(h.registerTableWindow).toHaveBeenCalledTimes(windows)
      if (page === HilosPages.DAEMON_ENV) {
        expect(
          tables.map((table) =>
            document
              .getElementById(table.getAttribute('aria-labelledby')!)
              ?.textContent?.trim(),
          ),
        ).toEqual(['Catalog keys', 'Orphans in .env'])
      } else {
        expect(tables[0]!.getAttribute('aria-labelledby')).toBe(
          el('hilos-page-head-title')!.id,
        )
      }
    },
  )
})
