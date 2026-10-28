import { describe, expect, expectTypeOf, it } from 'vitest'
import {
  DAEMON_WEBSOCKETS_DATA,
  daemonWebsocketsSchema,
  readHilosDaemonWebsockets,
  type HilosDaemonWebsocketsData,
} from '../../../src/admin/daemon/hilosDaemonWebsockets.js'
import { HilosPages } from '../../../src/routing/hilosPages.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'
import {
  subscribeSignal,
  type ReadonlySignal,
} from '../../../src/state/signal.js'

const invalidSlots: readonly [string, unknown][] = [
  ['null slot', null],
  ['absent slot', undefined],
  ['scalar slot', 0],
  ['array slot', []],
  ['empty object', {}],
  ['missing connections', { dataNode: false }],
  ['missing dataNode', { connections: 0 }],
  ['undefined connections', { connections: undefined, dataNode: false }],
  ['undefined dataNode', { connections: 0, dataNode: undefined }],
  ['negative connections', { connections: -1, dataNode: false }],
  ['fractional connections', { connections: 0.5, dataNode: false }],
  ['string connections', { connections: '0', dataNode: false }],
  ['boolean connections', { connections: false, dataNode: false }],
  ['NaN connections', { connections: NaN, dataNode: false }],
  ['infinite connections', { connections: Infinity, dataNode: false }],
  [
    'negative infinite connections',
    { connections: -Infinity, dataNode: false },
  ],
  ['null dataNode', { connections: 0, dataNode: null }],
  ['numeric dataNode', { connections: 0, dataNode: 0 }],
  ['string dataNode', { connections: 0, dataNode: 'false' }],
  ['extra field', { connections: 0, dataNode: false, extra: true }],
]

describe('daemon WebSockets boundary', () => {
  it.each([null, 0, 42])(
    'preserves connections=%s and both dataNode values',
    (connections) => {
      for (const dataNode of [false, true]) {
        const data = { connections, dataNode }
        const parsed = daemonWebsocketsSchema.parse(data)
        expect(parsed).toEqual(data)
        expect(Object.isFrozen(parsed)).toBe(true)
      }
      expectTypeOf<HilosDaemonWebsocketsData>().toEqualTypeOf<{
        readonly connections: number | null
        readonly dataNode: boolean
      }>()
    },
  )

  it.each(invalidSlots)(
    'rejects %s without accepting it as zero',
    (_name, slot) => {
      expect(daemonWebsocketsSchema.safeParse(slot).success).toBe(false)
      const scopes = new ScopeManager()
      const reader = readHilosDaemonWebsockets(scopes)
      const page = scopes.openPage(HilosPages.DAEMON_WEBSOCKETS)
      page.data.set('websockets', { connections: 7, dataNode: false })
      expect(reader.get()?.connections).toBe(7)
      page.data.set('websockets', slot)
      expect(reader.get()).toBeNull()
    },
  )

  it('reads its own page slot, publishes live changes and drops the previous node', () => {
    const scopes = new ScopeManager()
    const reader = readHilosDaemonWebsockets(scopes)
    expectTypeOf(reader).toEqualTypeOf<
      ReadonlySignal<HilosDaemonWebsocketsData | null>
    >()
    expect('set' in reader).toBe(false)
    expect(reader.get()).toBeNull()
    const changes: (HilosDaemonWebsocketsData | null)[] = []
    const off = subscribeSignal(reader, (value) => changes.push(value))
    const page = scopes.openPage(HilosPages.DAEMON_WEBSOCKETS)
    const zero = { connections: 0, dataNode: false }
    const live = { connections: 12, dataNode: false }
    const unknown = { connections: null, dataNode: false }
    const dataNode = { connections: null, dataNode: true }
    page.data.set('node', zero)
    scopes.session.data.set('websockets', zero)
    scopes.user.data.set('websockets', zero)
    scopes.openGroup('daemon').data.set('websockets', zero)
    expect(reader.get()).toBeNull()
    expect(DAEMON_WEBSOCKETS_DATA).toBe('websockets')
    page.data.set('websockets', zero)
    expect(reader.get()).toEqual(zero)
    page.data.set('websockets', live)
    expect(reader.get()).toEqual(live)
    page.data.set('websockets', unknown)
    expect(reader.get()).toEqual(unknown)
    page.data.set('websockets', dataNode)
    expect(reader.get()).toEqual(dataNode)
    page.data.set('websockets', null)
    expect(reader.get()).toBeNull()
    page.data.set('websockets', live)
    const next = scopes.openPage(HilosPages.DAEMON_WEBSOCKETS)
    expect(reader.get()).toBeNull()
    page.data.set('websockets', zero)
    expect(reader.get()).toBeNull()
    next.data.set('websockets', zero)
    expect(reader.get()).toEqual(zero)
    scopes.dropPage()
    expect(reader.get()).toBeNull()
    expect(changes).toEqual([
      zero,
      live,
      unknown,
      dataNode,
      null,
      live,
      null,
      zero,
      null,
    ])
    off()
  })
})
