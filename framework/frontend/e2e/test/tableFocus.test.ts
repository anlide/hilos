// @vitest-environment happy-dom
import type { Expect, Page } from '@playwright/test'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick, onUnmounted, shallowRef } from 'vue'
import { TableViewportController } from '../../core/src/table/TableViewportController.js'
import { subscribeSignal } from '../../core/src/state/signal.js'
import type { TableRow } from '../../core/src/state/TableRowsStore.js'
import {
  parseSignalValue,
  unpackSignalFrames,
} from '../../core/src/base/index.js'
import { watchTableSearch } from '../tableFocus.js'

const address = { page: 'search_page', tableKey: 'search_table' }
const batch = (...frames: unknown[]) => ({
  type: 'signal_batch',
  data: { frames },
})
const windowData = (keys: string[] = ['same']) => ({
  ...address,
  rows: keys.map((rowKey) => ({ rowKey, slots: {} })),
  totalCount: keys.length,
  totalExact: true,
  limit: 10,
  firstAnchor: null,
  lastAnchor: null,
  rowsBefore: 0,
})
const opening = () => ({
  type: 'page_response',
  data: {
    page: address.page,
    payload: { windows: { [address.tableKey]: windowData() } },
  },
})
const once = {
  poll: (read: () => Promise<unknown>) => ({
    toEqual: async (expected: unknown) =>
      expect(await read()).toEqual(expected),
  }),
} as unknown as Expect

const cleanups: (() => void)[] = []
afterEach(() => {
  for (const cleanup of cleanups.splice(0).reverse()) cleanup()
  document.body.replaceChildren()
  vi.unstubAllGlobals()
})

async function browser(batched = false, full = true) {
  class Socket extends EventTarget {
    readyState = 1
    sent: string[] = []
    send(raw: string) {
      this.sent.push(raw)
    }
    receive(raw: unknown) {
      this.dispatchEvent(new MessageEvent('message', { data: raw }))
    }
  }
  // Each navigation gets a fresh Window in Playwright. Keep nonconfigurable
  // init-script hooks private to this simulated document too.
  const browserWindow = Object.create(window) as Window & typeof globalThis
  browserWindow.WebSocket = Socket as unknown as typeof WebSocket
  vi.stubGlobal('window', browserWindow)
  const page = {
    addInitScript: async (script: (arg: unknown) => unknown, arg: unknown) =>
      script(arg),
    evaluate: async (read: (arg: unknown) => unknown, arg: unknown) =>
      read(arg),
  } as unknown as Page
  const watch = await watchTableSearch(page, address)
  const socket = new window.WebSocket('ws://test') as unknown as Socket
  let apply = true
  let latest: string | undefined
  const controller = new TableViewportController<TableRow>({
    frame: { columns: [] },
    resolve: (row) => row,
    sendViewport: (descriptor) => {
      latest = descriptor.requestId
      socket.send(
        JSON.stringify({ type: 'table_viewport', ...address, ...descriptor }),
      )
    },
  })
  // The actual SDK guards and completion signal, with a synchronous product
  // message listener registered before the passive observer's checkpoint.
  socket.addEventListener('message', (event) => {
    if (!apply) return
    const delivery = unpackSignalFrames((event as MessageEvent).data)
    if (!delivery.ok) return
    for (const wire of delivery.frames) {
      const parsed = parseSignalValue(wire)
      if (!parsed.ok) continue
      const signal = parsed.signal
      if (
        signal.kind === 'tableWindow' ||
        signal.kind === 'tableWindowRefused'
      ) {
        const data = signal.data
        if (data.page !== address.page || data.tableKey !== address.tableKey)
          continue
        if (signal.kind === 'tableWindow') {
          controller.ingestWindow(
            signal.data.rows as TableRow[],
            signal.data.totalCount,
            signal.data.totalExact,
            null,
            null,
            10,
            null,
            signal.data.requestId,
          )
        } else
          controller.ingestRefusal(signal.data.errorCode, signal.data.requestId)
      }
    }
  })
  const host = document.createElement('div')
  document.body.append(host)
  const view = {
    props: ['controller'],
    setup() {
      const rows = shallowRef(controller.rows.get())
      const search = shallowRef(controller.search.get())
      const offRows = subscribeSignal(controller.rows, (value) => {
        rows.value = value
      })
      const offSearch = subscribeSignal(controller.search, (value) => {
        search.value = value
      })
      onUnmounted(() => {
        offRows()
        offSearch()
      })
      return () =>
        h('div', { 'data-id': 'hilos-viewport-table' }, [
          h('input', { 'data-id': 'hilos-table-search', value: search.value }),
          h('table', [
            h(
              'tbody',
              rows.value.map((row) =>
                h('tr', { 'data-id': `hilos-table-row-${row.rowKey}` }),
              ),
            ),
          ]),
        ])
    },
  }
  const app = createApp(view, { controller })
  app.mount(host)
  let mounted = true
  const unmount = () => {
    if (mounted) app.unmount()
    mounted = false
  }
  cleanups.push(() => {
    unmount()
    controller.dispose()
  })
  await Promise.resolve()
  socket.send(JSON.stringify({ type: 'page_subscribe', page: address.page }))
  controller.ingestSubscriptionWindow(
    windowData().rows,
    1,
    true,
    null,
    null,
    10,
    undefined,
    [],
  )
  const initial = full
    ? opening()
    : {
        type: 'page_response',
        data: { page: address.page, payload: { data: { live: true } } },
      }
  socket.receive(
    JSON.stringify(
      batched ? batch(initial, { type: 'neighbor', data: {} }) : initial,
    ),
  )
  await nextTick()
  const mark = await watch.mark()
  const send = (search = 'query') => {
    controller.setSearch(search)
    return latest!
  }
  const receive = async (wire: unknown, raw = JSON.stringify(wire)) => {
    socket.receive(raw)
    await nextTick()
  }
  const reply = (requestId?: string, keys = ['same']) => ({
    type: 'table_window',
    data: {
      ...windowData(keys),
      ...(requestId === undefined ? {} : { requestId }),
    },
  })
  const refusal = (requestId?: string) => ({
    type: 'table_window_refused',
    data: { ...address, errorCode: 'internal_error', requestId },
  })
  const settled = (
    search = 'query',
    keys = ['same'],
    bookmark = mark,
    check = once,
  ) => watch.settled(check, bookmark, search, keys)
  return {
    watch,
    socket,
    controller,
    mark,
    send,
    receive,
    reply,
    refusal,
    settled,
    unmount,
    reset: () => {
      controller.resetFilters()
      return latest!
    },
    ignore: () => {
      apply = false
    },
  }
}

describe('passive table search completion', () => {
  it('settles a reset whose empty search is canonically omitted from the filter', async () => {
    const b = await browser()
    await b.receive(b.reply(b.send()))
    await b.settled()
    const mark = await b.watch.mark()
    await b.receive(b.reply(b.reset()))
    await b.settled('', ['same'], mark)
    expect((await b.watch.read()).application[0]?.filter).toEqual({})
  })

  it.each([false, true])(
    'recognizes a full opening and latest applied window, batched=%s',
    async (batched) => {
      const b = await browser(batched)
      const id = b.send()
      const wire = b.reply(id)
      await b.receive(
        batched ? batch({ type: 'neighbor', data: {} }, wire) : wire,
      )
      await b.settled()
      const state = await b.watch.read()
      expect(JSON.stringify(state)).toContain(id)
      expect(state).toMatchObject({
        condition: {
          processed: {
            appliedRequestId: id,
            search: 'query',
            rows: ['same'],
            domSearch: 'query',
            domRows: ['same'],
          },
          applied: { appliedRequestId: id, rows: ['same'], domRows: ['same'] },
        },
      })
      const received = state.observations.filter(
        (frame) => frame.direction === 'received',
      )
      const last = received.at(-1)!
      expect(last.raw).toBe(
        JSON.stringify(
          batched ? batch({ type: 'neighbor', data: {} }, wire) : wire,
        ),
      )
      if (batched)
        expect(
          received
            .slice(-2)
            .map(({ socket, message, inner }) => ({ socket, message, inner })),
        ).toEqual([
          { socket: 1, message: last.message, inner: 0 },
          { socket: 1, message: last.message, inner: 1 },
        ])
    },
  )

  it('does not replace an opening or epoch with a data-only page update', async () => {
    const b = await browser(true)
    const id = b.send()
    await b.receive({
      type: 'page_response',
      data: { page: address.page, payload: { data: { live: 2 } } },
    })
    await b.receive(b.reply(id))
    await b.settled()
  })

  it.each([false, true])(
    'requires a true initial window even when query, keys and id agree, batched=%s',
    async (batched) => {
      const b = await browser(batched, false)
      await b.receive(b.reply(b.send()))
      await expect(b.settled()).rejects.toThrow()
    },
  )

  it('does not settle by network receipt, matching query and keys when the controller did not apply', async () => {
    const b = await browser()
    b.ignore()
    await b.receive(b.reply(b.send()))
    await expect(b.settled()).rejects.toThrow()
  })

  it.each(['no-id', 'old', 'unknown'])(
    'does not acknowledge latest with %s window or refusal',
    async (kind) => {
      const b = await browser()
      const old = b.send('earlier')
      const latest = b.send()
      const id = kind === 'no-id' ? undefined : kind === 'old' ? old : 'unknown'
      await b.receive(b.reply(id))
      await b.receive(b.refusal(id))
      await expect(b.settled()).rejects.toThrow()
      await b.receive(b.reply(latest))
      await b.settled()
    },
  )

  it('tracks a newer send during the wait even when two queries have identical keys', async () => {
    const b = await browser()
    await b.receive(b.reply(b.send('earlier')))
    let polls = 0
    const moving = {
      poll: (read: () => Promise<unknown>) => ({
        toEqual: async (expected: unknown) => {
          const first = await read()
          expect(first).not.toEqual(expected)
          polls++
          const latest = b.send()
          expect(await read()).not.toEqual(expected)
          await b.receive(b.reply(latest))
          expect(await read()).toEqual(expected)
        },
      }),
    } as unknown as Expect
    await b.settled('query', ['same'], b.mark, moving)
    expect(polls).toBe(1)
  })

  it('does not accept a pre-mark matched query and id', async () => {
    const b = await browser()
    await b.receive(b.reply(b.send()))
    await b.settled()
    const mark = await b.watch.mark()
    await expect(b.settled('query', ['same'], mark)).rejects.toThrow()
  })

  it.each(['page', 'tableKey'])(
    'ignores a matching id at a foreign %s',
    async (key) => {
      const b = await browser()
      const id = b.send()
      const wire = b.reply(id)
      await b.receive({ ...wire, data: { ...wire.data, [key]: 'foreign' } })
      await expect(b.settled()).rejects.toThrow()
      await b.receive(b.reply(id))
      await b.settled()
    },
  )

  it('treats only the latest applied refusal as terminal, retaining its reason', async () => {
    const b = await browser(true)
    const old = b.send('earlier')
    const latest = b.send()
    await b.receive(b.refusal(old))
    await b.receive(b.refusal(latest))
    await expect(b.settled()).rejects.toThrow()
    expect(JSON.stringify(await b.watch.read())).toContain('internal_error')
    expect(b.controller.lastAppliedWindowRequestId.get()).toBe(latest)
    await b.receive(b.reply(b.send('recovered')))
    await b.settled('recovered')
  })

  it('retains malformed, binary and inner failures without turning them into completions', async () => {
    const b = await browser(true)
    const id = b.send()
    b.socket.receive('{')
    b.socket.receive(new ArrayBuffer(2))
    await b.receive(
      batch(null, { type: 'table_window', data: { requestId: id } }),
    )
    await expect(b.settled()).rejects.toThrow()
    await b.receive(batch(null, b.reply(id)))
    await b.settled()
    const state = await b.watch.read()
    expect(JSON.stringify(state)).toContain('non-text-frame')
    expect(state.observations.some((frame) => frame.wire === null)).toBe(true)
  })

  it('cannot reuse an applied id after the table unmounts', async () => {
    const b = await browser(true)
    await b.receive(b.reply(b.send()))
    await b.settled()
    b.unmount()
    await expect(b.settled()).rejects.toThrow()
  })

  it('does not let the old socket acknowledge a mark on a new connection', async () => {
    const b = await browser(true)
    const id = b.send()
    const replacement = new window.WebSocket('ws://replacement')
    const mark = await b.watch.mark()
    replacement.send(
      JSON.stringify({ type: 'page_subscribe', page: address.page }),
    )
    await b.receive(b.reply(id))
    await expect(b.settled('query', ['same'], mark)).rejects.toThrow()
  })

  it('resets decoded history across documents even when socket numbers repeat', async () => {
    const first = await browser(true)
    await first.receive(first.reply(first.send()))
    await first.settled()
    first.unmount()
    const clock = performance
    vi.stubGlobal('performance', {
      timeOrigin: clock.timeOrigin + 1,
      now: () => clock.now(),
    })
    const second = await browser(true)
    await second.receive(second.reply(second.send()))
    await expect(first.settled()).rejects.toThrow()
    const mark = await first.watch.mark()
    const id = second.send('new-document')
    await second.receive(second.reply(id))
    await first.settled('new-document', ['same'], mark)
  })

  it('ends the marked scope on full replacement, teardown, reconnect or another document', async () => {
    const b = await browser()
    const id = b.send()
    await b.receive(b.reply(id))
    await b.settled()
    await expect(
      b.settled('query', ['same'], { ...b.mark, documentStarted: -1 }),
    ).rejects.toThrow()
    await b.receive(opening())
    await expect(b.settled()).rejects.toThrow()
    const recovered = await b.watch.mark()
    await b.receive(b.reply(b.send('recovered')))
    await b.settled('recovered', ['same'], recovered)
    b.socket.send(JSON.stringify({ type: 'page_unsubscribe' }))
    await expect(b.settled('recovered', ['same'], recovered)).rejects.toThrow()
    new window.WebSocket('ws://reconnect')
    await expect(b.settled('recovered', ['same'], recovered)).rejects.toThrow()
  })
})
