import { enableAutoUnmount, flushPromises } from '@vue/test-utils'
import { bindAdminAccess, HilosPages } from '@hilos/core/base'
import { afterEach, describe, expect, it } from 'vitest'
import { nextTick } from 'vue'

import {
  nodeHarness,
  nodeHeading,
} from '../../../test/helpers/hilosDaemonNode.js'
import HilosDaemonWebsocketsPage from './HilosDaemonWebsocketsPage.vue'

const id = (name: string) => `[data-id="hilos-daemon-websockets-${name}"]`
const WAITING = 'Waiting for the node report'
const LIVE = 'Open on this node right now'
const SILENT = 'At the time of the last report'
const DATA_NODE = 'Data nodes do not accept browsers'

enableAutoUnmount(afterEach)

function harness(options: Parameters<typeof nodeHarness>[0] = {}) {
  return nodeHarness({
    child: true,
    page: HilosPages.DAEMON_WEBSOCKETS,
    ...options,
  })
}

describe('HilosDaemonWebsocketsPage', () => {
  it('draws one count under the shared node owner, breadcrumb and current tab', async () => {
    const nodeId = 'node /?#%2F🌍'
    const h = harness({ nodeId })
    h.answer(null, nodeHeading(), {
      websockets: { connections: 1284, dataNode: false },
    })
    await nextTick()
    expect(h.view.findAll(id('tile'))).toHaveLength(1)
    expect(h.view.get(id('tile')).text()).toContain('WebSocket connections now')
    expect(h.view.get(id('count')).text()).toBe((1284).toLocaleString())
    expect(h.view.get(id('note')).text()).toBe(LIVE)
    expect(h.view.get(id('tile')).text()).not.toMatch(/sessions/i)
    expect(
      h.view.get(id('tile')).get('i.bi-plug').attributes('aria-hidden'),
    ).toBe('true')
    expect(h.view.findAll('h1')).toHaveLength(1)
    expect(h.view.get('h1').text()).toBe(nodeId)
    expect(h.router.pageIdentity.get()?.pageHeadOwner).toMatchObject({
      page: HilosPages.DAEMON_NODE,
      label: nodeId,
    })
    const crumbs = h.view.get('nav[aria-label="Breadcrumb"]')
    expect(crumbs.findAll('li').map((crumb) => crumb.text())).toEqual([
      'Hilos',
      'Daemon',
      nodeId,
    ])
    expect(crumbs.get('[aria-current="page"]').element.tagName).toBe('LI')
    const nav = h.view.get('nav[aria-label="Node pages"]')
    expect(nav.findAll('a').map((link) => link.text())).toEqual([
      'Overview',
      'Workers',
      'Agents',
      'Cron',
      'WebSockets',
      'HTTP',
      'Environment',
      'Env orphans',
    ])
    expect(nav.findAll('[aria-current="page"]')).toHaveLength(1)
    expect(nav.get('[aria-current="page"]').text()).toBe('WebSockets')
    expect(nav.get('[aria-current="page"]').attributes('href')).toBe(
      h.router.resolvePath(HilosPages.DAEMON_WEBSOCKETS, { nodeId }),
    )
    expect(nav.attributes('role')).toBeUndefined()
    expect(h.view.findAll('nav[aria-label="Node pages"]')).toHaveLength(1)
    expect(h.view.find('table, [role="table"]').exists()).toBe(false)
    expect(h.registerTableWindow).not.toHaveBeenCalled()
    expect(h.sendViewport).not.toHaveBeenCalled()
    expect(h.sendRendered).not.toHaveBeenCalled()
    expect(h.sendAction).not.toHaveBeenCalled()
    expect(h.send).not.toHaveBeenCalled()
  })

  it.each([
    ['absent', undefined],
    ['null slot', null],
    ['null count', { connections: null, dataNode: false }],
    ['missing count', { dataNode: false }],
    ['negative count', { connections: -1, dataNode: false }],
    ['fractional count', { connections: 1.5, dataNode: false }],
    ['wrong count type', { connections: '2', dataNode: false }],
    ['missing data-node flag', { connections: 2 }],
    ['wrong data-node type', { connections: 2, dataNode: 'false' }],
    ['extra field', { connections: 2, dataNode: false, sessions: 3 }],
  ])(
    'shows waiting for %s, even when the node is silent',
    async (_, websockets) => {
      const h = harness()
      h.answer(
        null,
        nodeHeading({ state: 'silent', silentSince: 1_700_000_000_000 }),
      )
      if (websockets !== undefined) h.publish({ websockets })
      await nextTick()
      expect(h.view.get(id('count')).text()).toBe('—')
      expect(h.view.get(id('note')).text()).toBe(WAITING)
      expect(h.router.pageError.get()).toBeNull()
      expect(h.view.get('h1').text()).toBe('n1')
    },
  )

  it.each([null, 0, 17])(
    'gives data-node wording priority over silence and count %s',
    async (connections) => {
      const h = harness()
      h.answer(
        null,
        nodeHeading({
          state: 'silent',
          role: 'slave',
          silentSince: 1_700_000_000_000,
        }),
      )
      h.publish({ websockets: { connections, dataNode: true } })
      await nextTick()
      expect(h.view.get(id('count')).text()).toBe('—')
      expect(h.view.get(id('note')).text()).toBe(DATA_NODE)
      expect(h.view.get('[data-id="hilos-daemon-node-state"]').text()).toBe(
        'Silent',
      )
    },
  )

  it('keeps zero and the last reported count on silence, then recovers live without remounting', async () => {
    const h = harness()
    h.answer(null)
    h.publish({ websockets: { connections: 0, dataNode: false } })
    await nextTick()
    const component = h.view.getComponent(HilosDaemonWebsocketsPage).vm.$
    const tile = h.view.get(id('tile')).element
    const count = h.view.get(id('count')).element
    const note = h.view.get(id('note')).element
    const heading = h.view.get('h1').element
    expect(h.view.get(id('count')).text()).toBe('0')
    expect(h.view.get(id('note')).text()).toBe(LIVE)
    h.publish({ websockets: { connections: 42, dataNode: false } })
    await nextTick()
    h.publish({
      node: nodeHeading({ state: 'silent', silentSince: 1_700_000_000_000 }),
    })
    await nextTick()
    expect(h.view.get(id('count')).text()).toBe('42')
    expect(h.view.get(id('note')).text()).toBe(SILENT)
    h.publish({ websockets: null })
    await nextTick()
    expect(h.view.get(id('count')).text()).toBe('—')
    expect(h.view.get(id('note')).text()).toBe(WAITING)
    h.publish({
      node: nodeHeading({ state: 'leader' }),
      websockets: { connections: 7, dataNode: false },
    })
    await nextTick()
    expect(h.view.get(id('count')).text()).toBe('7')
    expect(h.view.get(id('note')).text()).toBe(LIVE)
    expect(h.view.getComponent(HilosDaemonWebsocketsPage).vm.$).toBe(component)
    expect(h.view.get(id('tile')).element).toBe(tile)
    expect(h.view.get(id('count')).element).toBe(count)
    expect(h.view.get(id('note')).element).toBe(note)
    expect(h.view.get('h1').element).toBe(heading)
    expect(h.send).not.toHaveBeenCalled()
    expect(h.sendAction).not.toHaveBeenCalled()
    expect(h.registerTableWindow).not.toHaveBeenCalled()
  })

  it('drops a previously shown count when its slot or permitted heading is lost', async () => {
    const h = harness()
    h.answer(null, nodeHeading(), {
      websockets: { connections: 12, dataNode: false },
    })
    await nextTick()
    expect(h.view.get(id('count')).text()).toBe('12')
    h.publish({ websockets: { connections: 'broken', dataNode: false } })
    await nextTick()
    expect(h.view.get(id('count')).text()).toBe('—')
    expect(h.view.get(id('note')).text()).toBe(WAITING)
    h.publish({ websockets: { connections: 3, dataNode: false } })
    await nextTick()
    expect(h.view.get(id('count')).text()).toBe('3')
    h.publish({ node: null })
    await nextTick()
    expect(h.view.get(id('count')).text()).toBe('—')
    expect(h.view.get(id('note')).text()).toBe(WAITING)
    expect(h.view.get('h1').text()).toBe('Node')
    expect(h.view.find('[data-id="hilos-daemon-node-state"]').exists()).toBe(
      false,
    )
    h.publish({ node: nodeHeading() })
    await nextTick()
    expect(h.view.get(id('count')).text()).toBe('3')
    expect(h.view.get(id('note')).text()).toBe(LIVE)
  })

  it('holds all four captions in inert twins and exposes one ordinary text note', async () => {
    const h = harness()
    h.answer(null)
    await nextTick()
    const tile = h.view.get(id('tile'))
    const twins = tile.findAll('.hilos-stack > .invisible')
    expect(twins.map((twin) => twin.text())).toEqual([
      DATA_NODE,
      WAITING,
      SILENT,
      LIVE,
    ])
    for (const twin of twins) {
      expect(twin.attributes('aria-hidden')).toBe('true')
      expect(twin.attributes('inert')).toBeDefined()
      expect(
        twin
          .find('[data-id], [id], a, button, input, select, textarea')
          .exists(),
      ).toBe(false)
      expect(twin.attributes('data-id')).toBeUndefined()
      expect(twin.attributes('id')).toBeUndefined()
    }
    for (const name of ['tile', 'count', 'note']) {
      expect(h.view.findAll(id(name))).toHaveLength(1)
    }
    expect(tile.find('[aria-live], [role="status"]').exists()).toBe(false)
    expect(h.view.get(id('note')).attributes('aria-hidden')).toBeUndefined()
    h.publish({ websockets: { connections: 9, dataNode: true } })
    await nextTick()
    expect(h.view.get(id('note')).text()).toBe(DATA_NODE)
    expect(
      tile.findAll('.hilos-stack > .invisible').map((twin) => twin.element),
    ).toEqual(twins.map((twin) => twin.element))
  })

  it('clears the addressed node data on navigation and on a scope reset', async () => {
    const h = harness()
    h.answer(null)
    h.publish({ websockets: { connections: 83, dataNode: false } })
    await nextTick()
    const component = h.view.getComponent(HilosDaemonWebsocketsPage).vm.$
    h.router.navigate(
      h.router.resolvePath(HilosPages.DAEMON_WEBSOCKETS, { nodeId: 'n2' })!,
    )
    await nextTick()
    expect(h.view.get(id('count')).text()).toBe('—')
    expect(h.view.get(id('note')).text()).toBe(WAITING)
    expect(h.view.find('h1').exists()).toBe(false)
    h.answer(null)
    h.publish({ websockets: { connections: 2, dataNode: false } })
    await nextTick()
    expect(h.view.get('h1').text()).toBe('n2')
    expect(h.view.get(id('count')).text()).toBe('2')
    expect(h.view.getComponent(HilosDaemonWebsocketsPage).vm.$).toBe(component)
    expect(
      h.view.get('[data-id="hilos-daemon-node-log"]').attributes('href'),
    ).toBe('/hilos/logs/view/n2/live')
    h.scopes.openPage(HilosPages.DAEMON_WEBSOCKETS)
    await nextTick()
    expect(h.view.get(id('count')).text()).toBe('—')
    expect(h.view.get(id('note')).text()).toBe(WAITING)
    expect(h.view.find('h1').exists()).toBe(false)
    h.scopes.openPage(HilosPages.DAEMON_NODE)
    h.publish({
      node: nodeHeading(),
      websockets: { connections: 999, dataNode: false },
    })
    await nextTick()
    expect(h.view.get(id('count')).text()).toBe('—')
    expect(h.view.get(id('note')).text()).toBe(WAITING)
  })

  it('inherits refusal and recovery from the real outlet and drops late frames after departure', async () => {
    const h = harness({ outlet: true })
    h.answer(null)
    h.publish({ websockets: { connections: 31, dataNode: false } })
    await flushPromises()
    expect(h.view.get(id('count')).text()).toBe('31')
    h.refuse()
    await flushPromises()
    expect(h.view.text()).toContain('This page is closed.')
    expect(h.view.find(id('tile')).exists()).toBe(false)
    expect(h.view.find('[data-id="hilos-page-head"]').exists()).toBe(false)
    h.answer(null)
    h.publish({ websockets: { connections: 0, dataNode: false } })
    await flushPromises()
    expect(h.view.get(id('count')).text()).toBe('0')
    expect(h.view.get('h1').text()).toBe('n1')
    h.router.navigate('/hilos/daemon/n2')
    h.answer(null)
    h.emit('projectSignal', {
      type: 'page_response',
      data: {
        page: HilosPages.DAEMON_WEBSOCKETS,
        payload: {
          data: { websockets: { connections: 999, dataNode: false } },
        },
      },
    })
    await flushPromises()
    expect(h.view.find(id('tile')).exists()).toBe(false)
    expect(h.scopes.pageDataSignal('websockets').get()).toBeUndefined()
  })

  it('shows the count to a view-mode viewer on standalone without an unavailable log link', async () => {
    const h = harness({
      nodeId: 'standalone',
      unavailable: [HilosPages.LOGS_VIEW],
    })
    h.scopes.session.data.set('adminViewMode', true)
    const release = bindAdminAccess(h.scopes)
    try {
      h.answer(null, nodeHeading({ clustered: false, state: null, role: null }))
      h.publish({ websockets: { connections: 4, dataNode: false } })
      await nextTick()
      expect(h.view.get('h1').text()).toBe('standalone')
      expect(h.view.get('[data-id="hilos-daemon-node-solo"]').text()).toBe(
        'Single installation',
      )
      expect(h.view.get(id('count')).text()).toBe('4')
      expect(h.view.get(id('note')).text()).toBe(LIVE)
      expect(h.view.find('[data-id="hilos-daemon-node-log"]').exists()).toBe(
        false,
      )
      expect(h.view.find('button, input, select, textarea').exists()).toBe(
        false,
      )
      expect(h.sendAction).not.toHaveBeenCalled()
    } finally {
      release()
    }
  })
})
