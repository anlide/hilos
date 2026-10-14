import { TestBed } from '@angular/core/testing'
import {
  DAEMON_OVERVIEW_DATA,
  DAEMON_DATABASE_DATA,
  HilosPages,
  ScopeManager,
  type TableRow,
} from '@hilos/core'
import { describe, expect, it } from 'vitest'

import { HilosDaemonPage } from '../src/admin/daemon/HilosDaemonPage.js'
import {
  daemonHarness,
  daemonNode,
  daemonPicture,
} from './helpers/hilosDaemon.js'

function placementRow(rowKey = 'room', nodeIds = ['n1', 'n2']): TableRow {
  return {
    rowKey,
    slots: {
      type: {
        agentType: rowKey,
        placement: 'policy',
        idle: false,
        nodes: nodeIds.map((nodeId) => ({
          nodeId,
          agentIds: [`${rowKey}:${nodeId}`],
        })),
        unplacedAgentIds: [],
      },
    },
  }
}

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
  it('waits for the picture, selects locally and updates child and Logs links', () => {
    const h = harness()
    expect(h.el('hilos-daemon-overview-waiting')).not.toBeNull()
    expect(h.el('hilos-daemon-inspector')).toBeNull()
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
    const requests = h.sendViewport.mock.calls.length
    expect(
      h.el('hilos-daemon-worker-n1-regular-0')?.getAttribute('title'),
    ).toBe('Ordinary worker · occupied · 2 agents')
    expect(
      h.el('hilos-daemon-worker-n1-monopolistic-4')?.getAttribute('title'),
    ).toBe('Monopolistic worker · occupied · 1 agents')
    h.el('hilos-daemon-node-n2')!.click()
    h.detect()
    expect(h.el('hilos-daemon-inspector')?.getAttribute('data-node-id')).toBe(
      'n2',
    )
    expect(
      h.el('hilos-admin-child-hilos_daemon_workers')?.getAttribute('href'),
    ).toBe('/hilos/daemon/n2/workers')
    expect(h.el('hilos-daemon-node-log')?.getAttribute('href')).toContain(
      '/hilos/logs/view/n2/',
    )
    expect(h.router.navigate).not.toHaveBeenCalled()
    expect(h.router.replacePath).not.toHaveBeenCalled()
    expect(h.sendViewport).toHaveBeenCalledTimes(requests)
    expect(h.el('hilos-daemon-monopolistic-count')?.textContent).toContain('1')
    expect(h.el('hilos-daemon-cpu-percent')?.textContent).toContain('150%')
    h.fixture.destroy()
    expect(h.listeners()).toBe(0)
    expect(h.unregister).toHaveBeenCalledTimes(1)
  })

  it('draws inline details under the selected type and inside its card, keeping tabs mounted', () => {
    const h = harness()
    seed(h)
    h.window('hilosDaemonPlacement', [placementRow(), placementRow('other')])
    h.el('hilos-daemon-placement-tab')!.click()
    h.detect()
    const wide =
      h.root.querySelector('[data-id="hilos-table-scroll"]') ??
      h.root.querySelector('table')!
    const cell = wide.querySelector<HTMLButtonElement>(
      '[data-id="hilos-daemon-placement-cell-room-n1"]',
    )!
    cell.click()
    h.detect()
    expect(cell.getAttribute('aria-expanded')).toBe('true')
    const row = h.root.querySelector('[data-id="hilos-table-row-room"]')!
    expect(row.nextElementSibling?.getAttribute('data-id')).toBe(
      'hilos-table-row-detail-room',
    )
    expect(
      row.nextElementSibling?.querySelector(
        '[data-id="hilos-daemon-placement-selection"]',
      )?.textContent,
    ).toContain('room:n1')
    const cards = h.el('hilos-table-cards')!
    expect(
      cards.querySelector(
        '[data-id="hilos-table-card-room"] [data-id="hilos-daemon-placement-selection"]',
      ),
    ).not.toBeNull()
    h.el('hilos-daemon-nodes-tab')!.click()
    h.detect()
    h.el('hilos-daemon-placement-tab')!.click()
    h.detect()
    expect(cell.getAttribute('aria-expanded')).toBe('true')
    wide
      .querySelector<HTMLButtonElement>(
        '[data-id="hilos-daemon-placement-cell-room-n2"]',
      )!
      .click()
    h.detect()
    expect(row.nextElementSibling?.textContent).toContain('room:n2')
    wide
      .querySelector<HTMLButtonElement>(
        '[data-id="hilos-daemon-placement-cell-other-n1"]',
      )!
      .click()
    h.detect()
    expect(
      wide.querySelectorAll('[data-id="hilos-daemon-placement-selection"]'),
    ).toHaveLength(1)
    expect(row.nextElementSibling?.getAttribute('data-id')).not.toBe(
      'hilos-table-row-detail-room',
    )
    wide
      .querySelector<HTMLButtonElement>(
        '[data-id="hilos-daemon-placement-collapse"]',
      )!
      .click()
    h.detect()
    expect(
      wide.querySelector('[data-id="hilos-daemon-placement-selection"]'),
    ).toBeNull()
    h.fixture.destroy()
    expect(h.listeners()).toBe(0)
  })

  it('refreshes dynamic node cells and closes details when a node falls silent or disappears', () => {
    const h = harness()
    seed(h)
    h.window('hilosDaemonPlacement', [placementRow()])
    h.el('hilos-daemon-placement-tab')!.click()
    h.detect()
    h.el('hilos-daemon-placement-cell-room-n1')!.click()
    h.detect()
    h.scope.data.set(
      DAEMON_OVERVIEW_DATA,
      daemonPicture([
        daemonNode('n1', { state: 'silent', online: false }),
        daemonNode('n3'),
      ]),
    )
    h.detect()
    expect(h.el('hilos-daemon-placement-selection')).toBeNull()
    expect(h.el('hilos-daemon-placement-cell-room-n2')).toBeNull()
    expect(
      [...h.root.querySelectorAll('th')].some((header) =>
        header.textContent?.includes('n3'),
      ),
    ).toBe(true)
    expect(h.el('hilos-daemon-placement-instances')?.textContent).toContain('7')
    const current = daemonPicture([daemonNode('n1'), daemonNode('n3')])
    h.scope.data.set(DAEMON_OVERVIEW_DATA, {
      ...current,
      placement: { ...current.placement, instances: 11 },
    })
    h.detect()
    expect(h.el('hilos-daemon-placement-instances')?.textContent).toContain(
      '11',
    )
    h.fixture.destroy()
    expect(h.listeners()).toBe(0)
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
    expect(h.unregister).toHaveBeenCalledTimes(1)
    expect(h.el('hilos-daemon-node-solo')?.textContent).toContain(
      'Single installation',
    )
    expect(h.el('hilos-daemon-consensus')).toBeNull()
    h.scope.data.set(DAEMON_OVERVIEW_DATA, daemonPicture([]))
    h.detect()
    expect(h.el('hilos-daemon-node-solo')).not.toBeNull()
    h.fixture.destroy()
    expect(h.listeners()).toBe(0)
    expect(h.unregister).toHaveBeenCalledTimes(2)
    TestBed.resetTestingModule()
  })
})
