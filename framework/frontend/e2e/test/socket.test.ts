import { EventEmitter } from 'node:events'
import type { Page, WebSocketRoute } from '@playwright/test'
import { describe, expect, it } from 'vitest'
import { createSocketFrameReader, watchSocketFrames } from '../socket.js'
import { watchThemeFrames } from '../themeFrames.js'
import { watchHandshakeFrames } from '../handshakeFrames.js'
import { watchPageErrorFrames } from '../pageErrorFrames.js'
import { watchAnalyticsFrames } from '../analytics.js'
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
  page.routeWebSocket = async (_url, handler) => {
    if (typeof handler !== 'function')
      throw new Error('Expected a route handler')
    await handler(client as unknown as WebSocketRoute)
  }
  return {
    page,
    connect,
    sent,
    outgoing,
    incoming: (message: string | Buffer) => incoming(message),
    outgoingMessage: (message: string | Buffer) => clientMessage(message),
    close: () => close(1000, 'test'),
  }
}

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
