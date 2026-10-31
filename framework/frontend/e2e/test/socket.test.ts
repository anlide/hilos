import { EventEmitter } from 'node:events'
import type { Page, WebSocketRoute } from '@playwright/test'
import { describe, expect, it, vi } from 'vitest'
import {
  controlSocketReconnect,
  createSocketFrameReader,
  forwardSessionTimeZone,
  isApplicationSocket,
  watchSocketFrames,
} from '../socket.js'
import { watchThemeFrames } from '../themeFrames.js'
import { watchHandshakeFrames } from '../handshakeFrames.js'
import { watchPageErrorFrames } from '../pageErrorFrames.js'
import {
  readAnalyticsOverviewSnapshot,
  watchAnalyticsFrames,
} from '../analytics.js'
import { watchDaemonCronFrames } from '../daemon.js'
import { readMonopolisticWorker } from '../daemonWorker.js'
import { holdPageReply, watchPageReplies } from '../lazyBundles.js'
import { watchUploadFrames } from '../uploadFrames.js'

const batch = (...frames: unknown[]): string =>
  JSON.stringify({ type: 'signal_batch', data: { frames } })
const welcome = { type: 'handshake', data: { build: 'test' } }
const pageResponse = (page: string) => ({
  type: 'page_response',
  data: { page, payload: {} },
})

function browser() {
  const events = new EventEmitter()
  const page = events as unknown as Page
  const connect = () => {
    const socket = new EventEmitter()
    events.emit('websocket', socket)
    return (payload: string | Buffer) =>
      socket.emit('framereceived', { payload })
  }
  return { page, connect }
}

function proxy() {
  const { page, connect } = browser()
  const sent: (string | Buffer)[] = []
  const outgoing: (string | Buffer)[] = []
  let incoming!: (message: string | Buffer) => void
  let clientMessage!: (message: string | Buffer) => void
  let close!: (code: number, reason: string) => void
  const server = {
    onMessage: (listener: typeof incoming) => {
      incoming = listener
    },
    send: (message: string | Buffer) => outgoing.push(message),
    close: async () => {},
  }
  const client = {
    connectToServer: () => server,
    send: (message: string | Buffer) => sent.push(message),
    onMessage: (listener: typeof incoming) => {
      clientMessage = listener
    },
    onClose: (listener: typeof close) => {
      close = listener
    },
  }
  const routed: Parameters<Page['routeWebSocket']>[0][] = []
  page.routeWebSocket = async (url, handler) => {
    if (typeof handler !== 'function')
      throw new Error('Expected a route handler')
    routed.push(url)
    await handler(client as unknown as WebSocketRoute)
  }
  return {
    page,
    connect,
    routed,
    sent,
    outgoing,
    incoming: (message: string | Buffer) => incoming(message),
    outgoingMessage: (message: string | Buffer) => clientMessage(message),
    close: () => close(1000, 'test'),
  }
}

/** Model the public routing lifecycle, including the automatic mock-open. */
function reconnectBrowser() {
  const events = new EventEmitter()
  const mainFrame = {}
  const page = events as unknown as Page
  let closed = false
  let handler!: (route: WebSocketRoute) => void | Promise<void>
  const routed: Parameters<Page['routeWebSocket']>[0][] = []
  page.routeWebSocket = async (url, callback) => {
    if (typeof callback !== 'function') throw new Error('Expected a handler')
    routed.push(url)
    handler = callback
  }
  page.isClosed = () => closed
  page.mainFrame = () => mainFrame as ReturnType<Page['mainFrame']>
  // The routed controller must not rely on constructor-init-script ordering.
  page.addInitScript = vi.fn(() => {
    throw new Error('Unexpected constructor wrapper')
  })

  function open() {
    let live = true
    let serverLive = true
    let onClientClose = () => {}
    let onServerClose = () => {}
    let settled = false
    let mockOpened = false
    const server = {
      onClose: vi.fn((listener: () => void) => {
        onServerClose = listener
      }),
      close: vi.fn(async () => {
        if (!serverLive) return
        serverLive = false
        onServerClose()
      }),
      onMessage: vi.fn(),
      send: vi.fn(),
    }
    const client = {
      connectToServer: vi.fn(() => {
        if (!live) throw new Error('Connected a dead route')
        return server
      }),
      onClose: vi.fn((listener: () => void) => {
        onClientClose = listener
      }),
      close: vi.fn(async () => {
        if (!live) return
        live = false
        onClientClose()
      }),
      onMessage: vi.fn(),
      send: vi.fn(),
    }
    const handled = Promise.resolve(
      handler(client as unknown as WebSocketRoute),
    ).then(() => {
      // Playwright's after-handler mock-open only opens a CONNECTING socket.
      mockOpened = live && client.connectToServer.mock.calls.length === 0
      settled = true
    })
    return {
      client,
      server,
      handled,
      settled: () => settled,
      mockOpened: () => mockOpened,
    }
  }

  return {
    page,
    routed,
    open,
    events,
    close() {
      closed = true
      events.emit('close')
    },
    navigate(frame = mainFrame, navigation = true) {
      events.emit('request', {
        isNavigationRequest: () => navigation,
        frame: () => frame,
      })
    },
  }
}

describe('controlled socket reconnect', () => {
  it('passes initial real connections through without taking over any frames', async () => {
    const browser = reconnectBrowser()
    const control = await controlSocketReconnect(browser.page)
    expect(browser.routed).toEqual([isApplicationSocket])
    const route = browser.open()
    await route.handled
    expect(route.client.connectToServer).toHaveBeenCalledExactlyOnceWith()
    expect(route.client.onMessage).not.toHaveBeenCalled()
    expect(route.server.onMessage).not.toHaveBeenCalled()
    expect(route.client.send).not.toHaveBeenCalled()
    expect(route.server.send).not.toHaveBeenCalled()
    expect(route.mockOpened()).toBe(false)
    await control.dispose()
  })

  it('holds a session rotation without closing the still-live current socket', async () => {
    const browser = reconnectBrowser()
    const control = await controlSocketReconnect(browser.page)
    const initial = browser.open()
    await initial.handled
    await control.hold()
    expect(initial.client.close).not.toHaveBeenCalled()
    expect(initial.server.close).not.toHaveBeenCalled()
    const next = browser.open()
    expect(next.settled()).toBe(false)
    expect(next.client.connectToServer).not.toHaveBeenCalled()
    await control.release()
    await next.handled
    expect(next.client.connectToServer).toHaveBeenCalledExactlyOnceWith()
    expect(next.mockOpened()).toBe(false)
    await control.dispose()
    await expect(control.hold()).rejects.toThrow('disposed')
  })

  it('can drop the live socket after arming a hold without retiring waiting routes', async () => {
    const browser = reconnectBrowser()
    const control = await controlSocketReconnect(browser.page)
    const initial = browser.open()
    await initial.handled
    await control.hold()
    const waiting = browser.open()
    await control.drop()
    expect(initial.client.close).toHaveBeenCalledOnce()
    expect(waiting.client.close).not.toHaveBeenCalled()
    expect(waiting.client.connectToServer).not.toHaveBeenCalled()
    await control.release()
    await waiting.handled
    expect(waiting.client.connectToServer).toHaveBeenCalledOnce()
    await control.dispose()
  })

  it('holds the handler itself until release connects to the real server', async () => {
    const browser = reconnectBrowser()
    const control = await controlSocketReconnect(browser.page)
    const initial = browser.open()
    await initial.handled
    // A reconnect starting during close already has to see the hold.
    let reconnect!: ReturnType<typeof browser.open>
    initial.server.close.mockImplementationOnce(async () => {
      reconnect = browser.open()
    })
    await control.drop()
    expect(initial.client.close).toHaveBeenCalledOnce()
    expect(initial.server.close).toHaveBeenCalledOnce()
    expect(reconnect.client.connectToServer).not.toHaveBeenCalled()
    expect(reconnect.settled()).toBe(false)
    expect(reconnect.mockOpened()).toBe(false)
    await control.release()
    expect(reconnect.settled()).toBe(true)
    expect(reconnect.client.connectToServer).toHaveBeenCalledExactlyOnceWith()
    expect(reconnect.mockOpened()).toBe(false)
    await control.dispose()
  })

  it('supports repeated cycles and idempotent release, drop and disposal', async () => {
    const browser = reconnectBrowser()
    const control = await controlSocketReconnect(browser.page)
    for (let cycle = 0; cycle < 2; cycle++) {
      await control.drop()
      const reconnect = browser.open()
      await control.drop()
      expect(reconnect.client.close).not.toHaveBeenCalled()
      expect(reconnect.settled()).toBe(false)
      await control.release()
      await control.release()
      expect(reconnect.client.connectToServer).toHaveBeenCalledOnce()
    }
    await control.dispose()
    await control.dispose()
    await control.release()
    expect(browser.events.listenerCount('request')).toBe(0)
    expect(browser.events.listenerCount('close')).toBe(0)
    const later = browser.open()
    await later.handled
    expect(later.client.connectToServer).toHaveBeenCalledOnce()
    expect(later.client.onClose).not.toHaveBeenCalled()
    await expect(control.drop()).rejects.toThrow('disposed')
  })

  it('disposes a live hold into transparent routing without closing the page', async () => {
    const browser = reconnectBrowser()
    const control = await controlSocketReconnect(browser.page)
    await control.drop()
    const reconnect = browser.open()
    await control.dispose()
    expect(reconnect.settled()).toBe(true)
    expect(reconnect.client.connectToServer).toHaveBeenCalledOnce()
    expect(reconnect.client.close).not.toHaveBeenCalled()
    // After detachment, closing either peer still closes its real counterpart.
    await reconnect.server.close()
    expect(reconnect.client.close).toHaveBeenCalledOnce()
    expect(browser.events.eventNames()).toEqual([])
  })

  it('settles a closed pending socket and never connects it on release', async () => {
    const browser = reconnectBrowser()
    const control = await controlSocketReconnect(browser.page)
    await control.drop()
    const stale = browser.open()
    await stale.client.close()
    await stale.handled
    const live = browser.open()
    await control.release()
    expect(stale.client.connectToServer).not.toHaveBeenCalled()
    expect(stale.mockOpened()).toBe(false)
    expect(live.client.connectToServer).toHaveBeenCalledOnce()
    await control.dispose()
  })

  it('cleans connected routes when the server closes', async () => {
    const browser = reconnectBrowser()
    const control = await controlSocketReconnect(browser.page)
    const initial = browser.open()
    await initial.handled
    await initial.server.close()
    await control.drop()
    expect(initial.client.close).toHaveBeenCalledOnce()
    await control.dispose()
  })

  it('settles pending handlers on page close, including late route deliveries', async () => {
    const browser = reconnectBrowser()
    const control = await controlSocketReconnect(browser.page)
    const initial = browser.open()
    await initial.handled
    await control.drop()
    const pending = browser.open()
    browser.close()
    await pending.handled
    await control.dispose()
    await control.release()
    expect(pending.client.connectToServer).not.toHaveBeenCalled()
    expect(pending.mockOpened()).toBe(false)
    const late = browser.open()
    await late.handled
    expect(late.client.connectToServer).not.toHaveBeenCalled()
    expect(late.client.close).toHaveBeenCalledOnce()
    expect(browser.events.eventNames()).toEqual([])
  })

  it('retires old document waits on navigation, but ignores assets and subframes', async () => {
    const browser = reconnectBrowser()
    const control = await controlSocketReconnect(browser.page)
    await control.drop()
    const old = browser.open()
    browser.navigate({}, true)
    browser.navigate(undefined, false)
    expect(old.client.close).not.toHaveBeenCalled()
    expect(old.settled()).toBe(false)
    browser.navigate()
    await old.handled
    const current = browser.open()
    expect(current.settled()).toBe(false)
    await control.release()
    expect(old.client.connectToServer).not.toHaveBeenCalled()
    expect(old.mockOpened()).toBe(false)
    expect(current.client.connectToServer).toHaveBeenCalledOnce()
    await control.dispose()
  })
})

describe('shared incoming socket observations', () => {
  it('keeps inner order, metadata, exact raw and physical identities', () => {
    const read = createSocketFrameReader(7)
    const first = JSON.stringify(welcome)
    const inner = {
      type: 'custom',
      data: { value: 2 },
      requestId: 'r',
      outcome: 'fail',
      time: 123,
    }
    const raw = `  ${batch(welcome, inner)}\n`
    expect(read(first)[0]).toMatchObject({
      socket: 7,
      message: 1,
      raw: first,
      wire: welcome,
    })
    const frames = read(raw)
    expect(frames.map(({ wire }) => wire)).toEqual([welcome, inner])
    expect(
      frames.map(({ socket, message, raw: text }) => [socket, message, text]),
    ).toEqual([
      [7, 2, raw],
      [7, 2, raw],
    ])
    expect(read(first)[0].message).toBe(3)
  })

  it('retains bad inners beside later good ones and rejects nested wrappers', () => {
    const read = createSocketFrameReader(1)
    const bad = { type: 'handshake', data: { build: 42 } }
    const nested = { type: 'signal_batch', data: { frames: [welcome] } }
    const frames = read(batch(null, bad, nested, welcome))
    expect(frames.map(({ wire }) => wire)).toEqual([null, bad, nested, welcome])
    expect(frames.map(({ failure }) => failure?.kind)).toEqual([
      'invalid-envelope',
      'invalid-signal-data',
      'invalid-signal-data',
      undefined,
    ])
  })

  it.each(['{', '{}', batch(), '{"type":"signal_batch","data":{"frames":{}}}'])(
    'makes an invalid outer observable once: %s',
    (raw) => {
      const frames = createSocketFrameReader(1)(raw)
      expect(frames).toHaveLength(1)
      expect(frames[0]).toMatchObject({
        raw,
        wire: undefined,
        socket: 1,
        message: 1,
      })
      expect(frames[0].failure).toBeDefined()
    },
  )

  it('does not interpret binary text as a signal', () => {
    expect(
      createSocketFrameReader(1)(Buffer.from(JSON.stringify(welcome))),
    ).toEqual([
      {
        socket: 1,
        message: 1,
        raw: undefined,
        wire: undefined,
        failure: { kind: 'non-text-frame' },
      },
    ])
  })

  it('distinguishes reconnects and removes listeners on stop', () => {
    const { page, connect } = browser()
    const frames: unknown[] = []
    const watch = watchSocketFrames(page, (frame) => frames.push(frame))
    const first = connect()
    first(batch(welcome, welcome))
    connect()(JSON.stringify(welcome))
    expect(frames).toMatchObject([
      { socket: 1, message: 1 },
      { socket: 1, message: 1 },
      { socket: 2, message: 1 },
    ])
    expect(watch.sockets()).toBe(2)
    watch.stop()
    first(JSON.stringify(welcome))
    connect()(JSON.stringify(welcome))
    expect(frames).toHaveLength(3)
  })

  it('keeps malformed relevant payloads and raw privacy evidence in specialized logs', () => {
    const { page, connect } = browser()
    const themes = watchThemeFrames(page)
    const handshakes = watchHandshakeFrames(page)
    const errors = watchPageErrorFrames(page)
    const send = connect()
    const raw = batch(
      null,
      { type: 'handshake_response', data: null },
      { type: 'hilos_theme_settings' },
      { type: 'handshake' },
      { type: 'subscription_page_error', data: { private: 'sentinel' } },
    )
    send(raw)
    expect(themes).toEqual([
      { type: 'handshake_response', data: null, raw },
      { type: 'hilos_theme_settings', data: undefined, raw },
    ])
    expect(handshakes).toEqual([{ type: 'handshake', data: undefined, raw }])
    expect(errors).toEqual([
      { type: 'subscription_page_error', data: { private: 'sentinel' }, raw },
    ])
    for (const type of [
      'handshake_response',
      'hilos_theme_settings',
      'handshake',
      'subscription_page_error',
    ]) {
      send(`{"type":"${type}",`)
    }
    expect(themes.slice(2).map(({ data }) => data)).toEqual([
      undefined,
      undefined,
    ])
    expect(handshakes).toHaveLength(2)
    expect(errors).toHaveLength(2)
    send(Buffer.from(raw))
    expect(themes).toHaveLength(4)
    expect(errors).toHaveLength(2)
  })

  it('keeps analytics inner envelopes beside the physical privacy text', () => {
    const { page, connect } = browser()
    const frames = watchAnalyticsFrames(page)
    const wire = pageResponse('hilos_analytics_browser_session')
    const raw = batch(welcome, wire)
    connect()(raw)
    expect(frames).toEqual([{ wire, raw }])
  })

  it('reads the opening overview reply inside a batch, not another page or a later snapshot', () => {
    const read = createSocketFrameReader(1)
    const snapshot = {
      timeZone: 'Asia/Kathmandu',
      weekStartTs: Date.parse('2026-10-18T18:15:00Z'),
      dayStartTs: Date.parse('2026-10-22T18:15:00Z'),
      hourStartTs: Date.parse('2026-10-23T05:15:00Z'),
      asOfTs: Date.parse('2026-10-23T05:50:00Z'),
      visitorsThisWeek: 5,
      visitorsToday: 2,
      visitorsThisHour: 0,
    }
    const reply = (page: string, analyticsOverview: unknown) => ({
      type: 'page_response',
      data: { page, payload: { data: { analyticsOverview } } },
    })
    const later = { ...snapshot, visitorsThisHour: 1 }
    const frames = read(JSON.stringify(reply('hilos_analytics', later)))
    const opening = frames.length
    frames.push(
      ...read(
        batch(
          reply('another_page', later),
          reply('hilos_analytics', snapshot),
          reply('hilos_analytics', later),
        ),
      ),
    )
    expect(readAnalyticsOverviewSnapshot(frames.slice(opening))).toEqual(
      snapshot,
    )
  })

  it('never completes an overview from partial replies or substitutes an unrelated reply', () => {
    const read = createSocketFrameReader(1)
    const context = {
      timeZone: 'UTC',
      weekStartTs: 100,
      dayStartTs: 200,
      hourStartTs: 300,
      asOfTs: 400,
    }
    const counts = {
      visitorsThisWeek: 0,
      visitorsToday: 0,
      visitorsThisHour: 0,
    }
    const reply = (page: string, analyticsOverview: unknown) => ({
      type: 'page_response',
      data: { page, payload: { data: { analyticsOverview } } },
    })
    expect(() =>
      readAnalyticsOverviewSnapshot(
        read(
          batch(
            reply('hilos_analytics', context),
            reply('hilos_analytics', counts),
            reply('hilos_analytics', { ...context, ...counts }),
          ),
        ),
      ),
    ).toThrow('Invalid or incomplete Analytics overview snapshot')
    expect(() =>
      readAnalyticsOverviewSnapshot(
        read(JSON.stringify(reply('another_page', { ...context, ...counts }))),
      ),
    ).toThrow('No Analytics overview page_response was observed')
  })

  it('retains cron inner envelopes for late snapshots', () => {
    const { page, connect } = browser()
    const { frames, stop } = watchDaemonCronFrames(page)
    const rule = {
      agentId: 'agent',
      name: 'rule',
      nextRunAt: 123,
      idleReason: null,
    }
    const wire = {
      type: 'page_response',
      data: {
        page: 'hilos_daemon_cron',
        payload: {
          data: { cronReported: true },
          windows: {
            hilosDaemonCron: { rows: [{ rowKey: 'rule', slots: { rule } }] },
          },
        },
      },
    }
    const raw = batch(welcome, wire)
    connect()(raw)
    expect(frames).toEqual([{ type: 'page_response', wire, raw }])
    stop()
  })

  it('finds the monopolistic worker in a batch and stops observing afterward', async () => {
    const { page, connect } = browser()
    const worker = {
      index: 2,
      pid: 123,
      kind: 'monopolistic',
      agentIds: ['agent'],
    }
    const result = await readMonopolisticWorker(
      page,
      'agent',
      async () => {
        connect()(
          batch(welcome, {
            type: 'table_window',
            data: {
              page: 'hilos_daemon_workers',
              tableKey: 'hilosDaemonWorkers',
              rows: [{ slots: { worker } }],
              totalExact: true,
              rowsBefore: 0,
              totalCount: 1,
            },
          }),
        )
      },
      async (predicate) => {
        expect(predicate()).toBe(true)
      },
    )
    expect(result).toEqual(worker)
  })

  it('observes each page reply in delivery order across sockets', () => {
    const { page, connect } = browser()
    const watch = watchPageReplies(page)
    connect()(
      batch(pageResponse('a'), {
        type: 'subscription_page_error',
        data: { page: 'b', httpCode: 403 },
      }),
    )
    connect()(JSON.stringify(pageResponse('c')))
    expect(watch.replies).toEqual([
      { socket: 1, type: 'page_response', page: 'a' },
      { socket: 1, type: 'subscription_page_error', page: 'b', httpCode: 403 },
      { socket: 2, type: 'page_response', page: 'c' },
    ])
    expect(watch.sockets()).toBe(2)
  })
})

describe('real-message proxy holds', () => {
  it('routes the application socket by its path, whatever its query names', async () => {
    const pageRoute = proxy()
    await holdPageReply(pageRoute.page, 'target')
    const uploadRoute = proxy()
    await watchUploadFrames(uploadRoute.page, 'test', () => {}).holdCredits()
    for (const { routed } of [pageRoute, uploadRoute]) {
      expect(routed).toEqual([isApplicationSocket])
    }
    expect(
      [
        'wss://stand.test/ws',
        'wss://stand.test/ws?browserTimeZone=Europe%2FWarsaw',
        'wss://stand.test/ws?browserTimeZone=UTC&pass=p',
        'wss://stand.test/wsx?browserTimeZone=UTC',
        'wss://stand.test/ws/next',
        'https://stand.test/assets/ws',
      ].map((address) => isApplicationSocket(new URL(address))),
    ).toEqual([true, true, true, false, false, false])
  })

  it('holds a complete page-reply message and successors without changing bytes or order', async () => {
    const route = proxy()
    const hold = await holdPageReply(route.page, 'target')
    const before = Buffer.from('binary before')
    route.incoming(before)
    const raw = batch(welcome, pageResponse('target'), pageResponse('neighbor'))
    route.incoming(raw)
    await hold.received
    const after = JSON.stringify(pageResponse('after'))
    route.incoming(after)
    const binary = Buffer.from('binary after')
    route.incoming(binary)
    expect(route.sent).toEqual([before])
    expect(hold.replies.map(({ page }) => page)).toEqual([
      'target',
      'neighbor',
      'after',
    ])
    hold.release()
    hold.release()
    expect(route.sent).toEqual([before, raw, after, binary])
    route.incoming('unparsed text after release')
    expect(route.sent.at(-1)).toBe('unparsed text after release')
  })

  it('rearms access holds and separates real sessions from a batch without losing metadata or ACKs', async () => {
    const route = proxy()
    const hold = await holdPageReply(route.page, 'target', {
      initiallyArmed: false,
      passSession: true,
    })
    const opening = JSON.stringify(pageResponse('target'))
    route.incoming(opening)
    expect(route.sent).toEqual([opening])
    for (const admin of [true, false]) {
      const received = hold.arm()
      const session = {
        type: 'handshake_response',
        data: { entities: { currentUser: { id: 7, admin } } },
      }
      const reply = pageResponse('target')
      const ack = {
        type: 'action_result',
        data: { value: admin },
        requestId: 'kept-ack',
        outcome: 'success',
      }
      // Session is deliberately after the page in one of the physical batches.
      const frames = admin ? [session, reply, ack] : [reply, ack, session]
      const outer = {
        type: 'signal_batch',
        time: 123,
        data: { frames, trace: 'kept' },
      }
      const since = route.sent.length
      const forwarded = hold.forwarded.length
      route.incoming(JSON.stringify(outer))
      await received
      expect(hold.forwarded.slice(forwarded).map(({ wire }) => wire)).toEqual([
        session,
      ])
      expect(JSON.parse(String(route.sent[since]))).toEqual({
        ...outer,
        data: { ...outer.data, frames: [session] },
      })
      route.incoming('pong')
      const successor = batch(pageResponse('neighbor'))
      route.incoming(successor)
      expect(route.sent.slice(since + 1)).toEqual(['pong'])
      hold.release()
      expect(JSON.parse(String(route.sent[since + 2]))).toEqual({
        ...outer,
        data: { ...outer.data, frames: [reply, ack] },
      })
      expect(route.sent.at(-1)).toBe(successor)
      expect(hold.forwarded.slice(forwarded).map(({ wire }) => wire)).toEqual([
        session,
        undefined,
        reply,
        ack,
        pageResponse('neighbor'),
      ])
      expect(hold.frames.slice(-5).map(({ wire }) => wire)).toEqual([
        ...frames,
        undefined,
        pageResponse('neighbor'),
      ])
    }
    hold.dispose()
    expect(() => hold.arm()).toThrow('Release the live page-reply hold')
  })

  it('holds real upload credits with neighbors, keeps requests/binary intact and releases once', async () => {
    const route = proxy()
    const evidence: Record<string, unknown>[] = []
    const uploads = watchUploadFrames(route.page, 'test', (event) =>
      evidence.push(event),
    )
    const hold = await uploads.holdCredits()
    const init = JSON.stringify({
      type: 'action',
      action: 'hilos_upload_init',
      requestId: 'init',
      data: {
        clientUploadId: 'u',
        filename: 'test.bin',
        size: 3,
      },
    })
    route.outgoingMessage(init)
    const demand =
      '{"type":"hilos_upload_credit_request","clientUploadId":"u","bytes":3}'
    route.outgoingMessage(demand)
    const credit = {
      type: 'hilos_upload_credit',
      data: { clientUploadId: 'u', sendUpToBytes: 3 },
    }
    const state = {
      type: 'hilos_upload_state',
      data: { clientUploadId: 'u', state: 'receiving' },
    }
    const raw = batch(welcome, credit, state)
    route.incoming(raw)
    const later = JSON.stringify(state)
    route.incoming(later)
    expect(route.sent).toEqual([])
    expect(hold.credits).toEqual([{ clientUploadId: 'u', sendUpToBytes: 3 }])
    expect(uploads.summary()[0]).toMatchObject({
      demands: [3],
      grants: [3],
      states: [state.data, state.data],
    })
    expect(
      evidence.filter(({ kind }) => kind === 'withheld-real-credit'),
    ).toMatchObject([
      { originalFrame: raw, raw, socket: 1, message: 1, wire: credit },
    ])
    hold.release()
    hold.dispose()
    expect(route.sent).toEqual([raw, later])
    const bytes = Buffer.from([1, 'u'.charCodeAt(0), 1, 2, 3])
    route.outgoingMessage(bytes)
    expect(route.outgoing).toEqual([init, demand, bytes])
    expect(uploads.sentBytes()).toBe(3)
    expect(uploads.errors).toEqual([])
    route.close()
    expect(uploads.connections()).toEqual({ sockets: 1, closes: 1 })
  })
})

describe('real-message session time zone forwarding', () => {
  const session = (id: number, timeZone: string | null) => ({
    type: 'handshake_response',
    requestId: 'session-ask',
    time: 123,
    data: {
      entities: {
        currentUser: { id, timeZone, name: 'Original', isAdmin: true },
        impersonatedBy: { id: 99, timeZone: 'America/New_York' },
      },
      data: { authority: 'server', pending: null },
      lists: {},
    },
  })

  it('changes only the own zone in single and batch replies and replays only the latest identity', async () => {
    const route = proxy()
    const forward = await forwardSessionTimeZone(route.page, 'Europe/Kyiv')
    expect(route.routed).toEqual([isApplicationSocket])
    expect(() => forward.replay(null)).toThrow('No live signed-in session')
    const first = session(7, 'UTC')
    route.incoming(JSON.stringify(first))
    expect(JSON.parse(route.sent[0] as string)).toEqual(
      session(7, 'Europe/Kyiv'),
    )

    const receipt = {
      type: 'action_success',
      data: { action: 'read', reply: 3 },
    }
    const latest = session(8, null)
    const outer = {
      type: 'signal_batch',
      requestId: 'batch-ask',
      extra: 'outer metadata',
      data: {
        frames: [welcome, latest, null, receipt],
        extra: 'batch metadata',
      },
    }
    const raw = JSON.stringify(outer)
    route.incoming(raw)
    expect(JSON.parse(route.sent[1] as string)).toEqual({
      ...outer,
      data: {
        ...outer.data,
        frames: [welcome, session(8, 'Europe/Kyiv'), null, receipt],
      },
    })
    expect(forward.frames.map(({ wire }) => wire)).toEqual([
      first,
      welcome,
      latest,
      null,
      receipt,
    ])
    expect(forward.frames.slice(1).map(({ raw }) => raw)).toEqual([
      raw,
      raw,
      raw,
      raw,
    ])

    forward.replay(null)
    expect(JSON.parse(route.sent[2] as string)).toEqual(latest)
    expect(forward.sessions).toEqual([
      session(7, 'Europe/Kyiv'),
      session(8, 'Europe/Kyiv'),
      latest,
    ])
    expect(forward.frames).toHaveLength(5)
    expect(forward.sockets()).toBe(1)
    route.incoming(JSON.stringify(latest))
    expect(JSON.parse(route.sent[3] as string)).toEqual(latest)
    route.close()
    expect(() => forward.replay('UTC')).toThrow('No live signed-in session')
  })

  it('forwards requests, bytes, malformed and guest messages unchanged and disposes into passthrough', async () => {
    const route = proxy()
    const forward = await forwardSessionTimeZone(route.page, 'Europe/Kyiv')
    const request = '{"type":"action","action":"existing"}'
    const bytes = Buffer.from([1, 2, 3])
    route.outgoingMessage(request)
    route.outgoingMessage(bytes)
    expect(route.outgoing).toEqual([request, bytes])
    const guest = JSON.stringify({
      type: 'handshake_response',
      data: { entities: { currentUser: null } },
    })
    const legacy = JSON.stringify({
      type: 'handshake_response',
      data: { entities: { currentUser: { id: 7 } } },
    })
    for (const message of [
      bytes,
      '{',
      JSON.stringify(welcome),
      guest,
      legacy,
    ]) {
      route.incoming(message)
      expect(route.sent.at(-1)).toBe(message)
    }
    expect(forward.sessions).toEqual([])
    forward.dispose()
    const raw = JSON.stringify(session(7, 'UTC'))
    route.incoming(raw)
    expect(route.sent.at(-1)).toBe(raw)
    expect(() => forward.replay(null)).toThrow('No live signed-in session')
  })
})
