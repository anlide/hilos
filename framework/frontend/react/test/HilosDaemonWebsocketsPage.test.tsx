import { act, cleanup } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import { bindAdminAccess, HilosPages } from '@hilos/core/base'
import { nodeHarness, nodeHeading } from './helpers/hilosDaemonNode.js'

afterEach(cleanup)

function harness(options: Parameters<typeof nodeHarness>[0] = {}) {
  const h = nodeHarness({
    child: true,
    page: HilosPages.DAEMON_WEBSOCKETS,
    ...options,
  })
  const root = h.view.container
  const el = (name: string) =>
    root.querySelector(`[data-id="hilos-daemon-websockets-${name}"]`)
  return {
    ...h,
    root,
    el,
    text: (name: string) => el(name)?.textContent?.trim(),
    run: (fn: () => unknown) =>
      act(() => {
        fn()
      }),
    runAsync: async (fn: () => unknown) => {
      await act(async () => {
        await fn()
      })
    },
  }
}

const WAITING = 'Waiting for the node report'
const LIVE = 'Open on this node right now'
const SILENT = 'At the time of the last report'
const DATA_NODE = 'Data nodes do not accept browsers'

describe('HilosDaemonWebsocketsPage', () => {
  it('draws one count under the shared owner, breadcrumb and current tab without a table or requests', () => {
    const nodeId = 'node /?#%2F🌍'
    const h = harness({ nodeId })
    h.run(() =>
      h.answer(null, nodeHeading(), {
        websockets: { connections: 1284, dataNode: false },
      }),
    )
    expect(h.text('count')).toBe((1284).toLocaleString())
    expect(h.text('note')).toBe(LIVE)
    expect(h.el('tile')?.textContent).toContain('WebSocket connections now')
    expect(h.el('tile')?.textContent).not.toMatch(/sessions/i)
    expect(
      h.el('tile')?.querySelector('i.bi-plug')?.getAttribute('aria-hidden'),
    ).toBe('true')
    expect(h.root.querySelectorAll('h1')).toHaveLength(1)
    expect(h.root.querySelector('h1')?.textContent?.trim()).toBe(nodeId)
    expect(h.router.pageIdentity.get()?.pageHeadOwner).toMatchObject({
      page: HilosPages.DAEMON_NODE,
      label: nodeId,
    })
    const crumbs = h.root.querySelector('nav[aria-label="Breadcrumb"]')!
    expect(
      [...crumbs.querySelectorAll('li')].map((el) => {
        const words = el.cloneNode(true) as Element
        words
          .querySelectorAll('[aria-hidden="true"]')
          .forEach((hidden) => hidden.remove())
        return words.textContent?.trim()
      }),
    ).toEqual(['Hilos', 'Daemon', nodeId])
    expect(crumbs.querySelector('[aria-current="page"]')?.tagName).toBe('LI')
    const nav = h.root.querySelector('nav[aria-label="Node pages"]')!
    expect(
      [...nav.querySelectorAll('a')].map((el) => el.textContent?.trim()),
    ).toEqual([
      'Overview',
      'Workers',
      'Agents',
      'Cron',
      'WebSockets',
      'HTTP',
      'Environment',
      'Env orphans',
    ])
    expect(nav.querySelectorAll('[aria-current="page"]')).toHaveLength(1)
    expect(nav.querySelector('[aria-current="page"]')?.textContent).toBe(
      'WebSockets',
    )
    expect(
      nav.querySelector('[aria-current="page"]')?.getAttribute('href'),
    ).toBe(h.router.resolvePath(HilosPages.DAEMON_WEBSOCKETS, { nodeId }))
    expect(nav.hasAttribute('role')).toBe(false)
    expect(h.root.querySelector('table, [role="table"]')).toBeNull()
    expect(h.registerTableWindow).not.toHaveBeenCalled()
    expect(h.sendViewport).not.toHaveBeenCalled()
    expect(h.sendRendered).not.toHaveBeenCalled()
    expect(h.sendAction).not.toHaveBeenCalled()
    expect(h.send).not.toHaveBeenCalled()
  })

  it.each([
    ['absent', undefined],
    ['null', null],
    ['unknown count', { connections: null, dataNode: false }],
    ['missing count', { dataNode: false }],
    ['negative', { connections: -1, dataNode: false }],
    ['fractional', { connections: 1.5, dataNode: false }],
    ['wrong type', { connections: '2', dataNode: false }],
    ['missing flag', { connections: 2 }],
    ['wrong flag', { connections: 2, dataNode: 'false' }],
    ['extra field', { connections: 2, dataNode: false, sessions: 3 }],
  ])('shows waiting for %s even when silent', (_, websockets) => {
    const h = harness()
    h.run(() =>
      h.answer(
        null,
        nodeHeading({ state: 'silent', silentSince: 1_700_000_000_000 }),
        websockets === undefined ? {} : { websockets },
      ),
    )
    expect(h.text('count')).toBe('—')
    expect(h.text('note')).toBe(WAITING)
    expect(h.router.pageError.get()).toBeNull()
  })

  it.each([null, 0, 17])(
    'gives data-node wording priority over silence and count %s',
    (connections) => {
      const h = harness()
      h.run(() =>
        h.answer(
          null,
          nodeHeading({
            state: 'silent',
            role: 'slave',
            silentSince: 1_700_000_000_000,
          }),
          { websockets: { connections, dataNode: true } },
        ),
      )
      expect(h.text('count')).toBe('—')
      expect(h.text('note')).toBe(DATA_NODE)
      expect(
        h.root.querySelector('[data-id="hilos-daemon-node-state"]')
          ?.textContent,
      ).toBe('Silent')
    },
  )

  it('keeps zero, live updates, silence and recovery in the same nodes', () => {
    const h = harness()
    h.run(() =>
      h.answer(null, nodeHeading(), {
        websockets: { connections: 0, dataNode: false },
      }),
    )
    const tile = h.el('tile'),
      count = h.el('count'),
      note = h.el('note'),
      heading = h.root.querySelector('h1')
    expect(h.text('count')).toBe('0')
    expect(h.text('note')).toBe(LIVE)
    h.run(() =>
      h.publish({
        websockets: { connections: 42, dataNode: false },
        node: nodeHeading({ state: 'silent', silentSince: 1_700_000_000_000 }),
      }),
    )
    expect(h.text('count')).toBe('42')
    expect(h.text('note')).toBe(SILENT)
    h.run(() => h.publish({ websockets: null }))
    expect(h.text('count')).toBe('—')
    expect(h.text('note')).toBe(WAITING)
    h.run(() =>
      h.publish({
        node: nodeHeading({ state: 'leader' }),
        websockets: { connections: 7, dataNode: false },
      }),
    )
    expect(h.text('count')).toBe('7')
    expect(h.text('note')).toBe(LIVE)
    expect(h.el('tile')).toBe(tile)
    expect(h.el('count')).toBe(count)
    expect(h.el('note')).toBe(note)
    expect(h.root.querySelector('h1')).toBe(heading)
    expect(h.send).not.toHaveBeenCalled()
    expect(h.sendAction).not.toHaveBeenCalled()
    expect(h.registerTableWindow).not.toHaveBeenCalled()
  })

  it('reserves every caption with inert twins and one ordinary text note', () => {
    const h = harness()
    h.run(() => h.answer(null))
    const twins = [
      ...h.el('tile')!.querySelectorAll('.hilos-stack > .invisible'),
    ]
    expect(twins.map((el) => el.textContent?.trim())).toEqual([
      DATA_NODE,
      WAITING,
      SILENT,
      LIVE,
    ])
    for (const twin of twins) {
      expect(twin.getAttribute('aria-hidden')).toBe('true')
      expect(twin.hasAttribute('inert')).toBe(true)
      expect(twin.hasAttribute('data-id')).toBe(false)
      expect(
        twin.querySelector(
          '[data-id], [id], a, button, input, select, textarea',
        ),
      ).toBeNull()
    }
    for (const name of ['tile', 'count', 'note'])
      expect(
        h.root.querySelectorAll(`[data-id="hilos-daemon-websockets-${name}"]`),
      ).toHaveLength(1)
    expect(
      h.el('tile')?.querySelector('[aria-live], [role="status"]'),
    ).toBeNull()
    expect(h.el('note')?.hasAttribute('aria-hidden')).toBe(false)
  })

  it('drops the count when heading, permission or addressed scope is lost', () => {
    const h = harness()
    h.run(() =>
      h.answer(null, nodeHeading(), {
        websockets: { connections: 12, dataNode: false },
      }),
    )
    h.run(() => h.publish({ node: null }))
    expect(h.text('count')).toBe('—')
    expect(h.text('note')).toBe(WAITING)
    expect(h.root.querySelector('h1')?.textContent?.trim()).toBe('Node')
    h.run(() => h.publish({ node: nodeHeading() }))
    expect(h.text('count')).toBe('12')
    h.run(() =>
      h.router.navigate(
        h.router.resolvePath(HilosPages.DAEMON_WEBSOCKETS, { nodeId: 'n2' })!,
      ),
    )
    expect(h.text('count')).toBe('—')
    expect(h.root.querySelector('h1')).toBeNull()
    h.run(() =>
      h.answer(null, nodeHeading(), {
        websockets: { connections: 2, dataNode: false },
      }),
    )
    expect(h.text('count')).toBe('2')
    expect(h.root.querySelector('h1')?.textContent?.trim()).toBe('n2')
    h.run(() => h.scopes.openPage(HilosPages.DAEMON_WEBSOCKETS))
    expect(h.text('count')).toBe('—')
    expect(h.root.querySelector('h1')).toBeNull()
    h.run(() => {
      h.scopes.openPage(HilosPages.DAEMON_NODE)
      h.publish({
        node: nodeHeading(),
        websockets: { connections: 999, dataNode: false },
      })
    })
    expect(h.text('count')).toBe('—')
  })

  it('recovers through the real outlet and drops late frames after departure', async () => {
    const h = harness({ outlet: true })
    await h.runAsync(() =>
      h.answer(null, nodeHeading(), {
        websockets: { connections: 31, dataNode: false },
      }),
    )
    expect(h.text('count')).toBe('31')
    await h.runAsync(() => h.refuse())
    expect(h.el('tile')).toBeNull()
    expect(h.root.textContent).toContain('This page is closed.')
    expect(h.root.querySelector('[data-id="hilos-page-head"]')).toBeNull()
    await h.runAsync(() =>
      h.answer(null, nodeHeading(), {
        websockets: { connections: 0, dataNode: false },
      }),
    )
    expect(h.text('count')).toBe('0')
    await h.runAsync(() => {
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
    })
    expect(h.el('tile')).toBeNull()
    expect(h.scopes.pageDataSignal('websockets').get()).toBeUndefined()
  })

  it('shows a view-mode reader the standalone count without an unavailable log link', () => {
    const h = harness({ unavailable: [HilosPages.LOGS_VIEW] })
    const release = bindAdminAccess(h.scopes)
    try {
      h.run(() => {
        h.scopes.session.data.set('adminViewMode', true)
        h.answer(
          null,
          nodeHeading({ clustered: false, state: null, role: null }),
          { websockets: { connections: 4, dataNode: false } },
        )
      })
      expect(h.text('count')).toBe('4')
      expect(h.text('note')).toBe(LIVE)
      expect(
        h.root
          .querySelector('[data-id="hilos-daemon-node-solo"]')
          ?.textContent?.trim(),
      ).toBe('Single installation')
      expect(
        h.root.querySelector('[data-id="hilos-daemon-node-log"]'),
      ).toBeNull()
      expect(h.root.querySelector('button, input, select, textarea')).toBeNull()
      expect(h.sendAction).not.toHaveBeenCalled()
    } finally {
      release()
    }
  })
})

it('lets Ecommerce suppress a served log link', () => {
  const h = harness({ showLogLinks: false })
  h.run(() =>
    h.answer(null, nodeHeading(), {
      websockets: { connections: 5, dataNode: false },
    }),
  )
  expect(h.text('count')).toBe('5')
  expect(h.root.querySelector('[data-id="hilos-daemon-node-log"]')).toBeNull()
})
