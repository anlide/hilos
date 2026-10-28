import { EventEmitter } from 'node:events'
import type { Expect, Page } from '@playwright/test'
import { describe, expect, it } from 'vitest'
import { submitStatusIncident, watchStatusFrames } from '../status.js'
import {
  statusSubscriberFrames,
  statusSubscriberWindow,
} from '../statusSubscriptions.js'
import { watchDeliveryFrames } from '../deliveryFrames.js'

const status = {
  healthKnown: true,
  components: { operationalCount: 1, totalCount: 1 },
  publishDelaySeconds: 300,
  maintenance: null,
  incidents: [],
  subscribers: { confirmedCount: 0, pendingCount: 0 },
}
const overview = {
  type: 'page_response',
  data: { page: 'hilos_status', payload: { data: { status } } },
}
const components = {
  type: 'page_response',
  data: {
    page: 'hilos_status_components',
    payload: {
      windows: { hilosStatusComponents: { rows: [], windowSize: 25 } },
    },
  },
}

function peer() {
  const page = new EventEmitter()
  const socket = new EventEmitter()
  const log = watchStatusFrames(page as unknown as Page)
  page.emit('websocket', socket)
  return {
    page,
    socket,
    log,
    receive: (payload: string) => socket.emit('framereceived', { payload }),
  }
}

describe('Status real wire observations', () => {
  for (const batch of [false, true]) {
    it(`keeps the complete ${batch ? 'batch' : 'single'} first answer and raw transport, then releases listeners`, () => {
      const { page, socket, log, receive } = peer()
      const frames = [overview, components]
      const raw = batch
        ? [JSON.stringify({ type: 'signal_batch', data: { frames } })]
        : frames.map((frame) => JSON.stringify(frame))
      socket.emit('framesent', {
        payload: JSON.stringify({ type: 'action', requestId: 'status-1' }),
      })
      for (const payload of raw) receive(payload)
      expect(log.sent).toMatchObject([
        { socket: 1, wire: { type: 'action', requestId: 'status-1' } },
      ])
      expect(log.overviews).toEqual([status])
      expect(log.latest()).toEqual(status)
      expect(log.replies).toEqual([overview.data.payload])
      expect(log.components).toEqual([{ rows: [], windowSize: 25 }])
      expect(log.frames.map((frame) => frame.wire)).toEqual(frames)
      expect(log.frames.map((frame) => frame.raw)).toEqual(
        batch ? [raw[0], raw[0]] : raw,
      )
      log.stop()
      expect(page.listenerCount('websocket')).toBe(0)
      expect(socket.listenerCount('framereceived')).toBe(0)
      expect(socket.listenerCount('framesent')).toBe(0)
      expect(socket.listenerCount('close')).toBe(0)
      receive(JSON.stringify(overview))
      expect(log.overviews).toHaveLength(1)
    })
  }
  it('rejects an incomplete Status first frame and retains the failed evidence', () => {
    const { log, receive } = peer()
    const { maintenance: omitted, ...incomplete } = status
    void omitted
    const raw = JSON.stringify({
      ...overview,
      data: { ...overview.data, payload: { data: { status: incomplete } } },
    })
    try {
      expect(() => receive(raw)).toThrow()
      expect(log.frames[0].raw).toBe(raw)
      expect(log.overviews).toEqual([])
    } finally {
      log.stop()
    }
  })
})

describe('Status incident acknowledgement correlation', () => {
  for (const mismatch of [
    'none',
    'target',
    'request',
    'socket',
    'old',
  ] as const) {
    it(`accepts only the submitted incident and request on its socket: ${mismatch}`, async () => {
      const { log } = peer()
      const action = 'hilos_status_publish_now'
      const requestId = 'status-1'
      const reply = {
        socket: mismatch === 'socket' ? 2 : 1,
        message: 1,
        raw: '',
        failure: undefined,
        wire: {
          type: 'action_success',
          requestId: mismatch === 'request' ? 'another-request' : requestId,
          data: { action },
        },
      }
      if (mismatch === 'old') log.frames.push(reply)
      const button = {
        scrollIntoViewIfNeeded: async () => {},
        focus: async () => {},
        click: async () => {
          log.sent.push({
            socket: 1,
            at: 1,
            raw: '',
            wire: {
              type: 'action',
              action,
              requestId,
              data: { incidentId: mismatch === 'target' ? 42 : 7 },
            },
          })
          if (mismatch !== 'old') log.frames.push(reply)
        },
      }
      const form = {}
      const page = {
        getByTestId: (id: string) =>
          id === 'hilos-status-incident-confirm' ? button : form,
      } as unknown as Page
      // Judge the polling predicate immediately: a wrong correlation must be
      // false, without spending a real Playwright timeout in this unit test.
      const check = Object.assign(
        (value: unknown) =>
          typeof value === 'function'
            ? { toPass: async () => value() }
            : value === button
              ? { toBeVisible() {}, toBeEnabled() {} }
              : value === form
                ? { toBeHidden() {} }
                : expect(value),
        { any: expect.any, poll: (read: () => unknown) => expect(read()) },
      ) as unknown as Expect
      try {
        const result = submitStatusIncident(check, page, log, action, 7)
        if (mismatch === 'none') await result
        else await expect(result).rejects.toThrow()
      } finally {
        log.stop()
      }
    })
  }
})

describe('Status subscriber deliveries', () => {
  for (const batch of [false, true]) {
    it(`selects only the subscriber table and page in ${batch ? 'batched' : 'single'} packets`, () => {
      const page = new EventEmitter()
      const socket = new EventEmitter()
      const log = watchDeliveryFrames(page as unknown as Page)
      page.emit('websocket', socket)
      const selected = {
        page: 'hilos_status_subscribers',
        tableKey: 'hilosStatusSubscribers',
        rowKey: 7,
        kind: 'row_removed',
        reason: 'deleted',
        own: false,
      }
      const frames = [
        { type: 'table_viewport_delta', data: selected },
        {
          type: 'table_viewport_delta',
          data: { ...selected, page: 'another_page' },
        },
        {
          type: 'table_viewport_delta',
          data: { ...selected, tableKey: 'anotherTable' },
        },
        { type: 'table_window', data: selected },
        { type: 'table_viewport_delta', data: null },
      ]
      try {
        const packets = batch
          ? [{ type: 'signal_batch', data: { frames } }]
          : frames
        for (const frame of packets)
          socket.emit('framereceived', { payload: JSON.stringify(frame) })
        expect(statusSubscriberFrames(log, 'table_viewport_delta')).toEqual([
          selected,
        ])
        expect(statusSubscriberFrames(log, 'table_window')).toEqual([selected])
        expect(statusSubscriberFrames(log, 'table_viewport_announce')).toEqual(
          [],
        )
      } finally {
        log.stop()
      }
      expect(page.listenerCount('websocket')).toBe(0)
      expect(socket.listenerCount('framereceived')).toBe(0)
    })
  }
})

describe('Status subscriber window correlation', () => {
  for (const mismatch of [
    'none',
    'socket',
    'request',
    'page',
    'table',
    'older',
    'superseded',
  ] as const) {
    it(`uses the newest request on its physical socket: ${mismatch}`, () => {
      const page = new EventEmitter()
      const socket = new EventEmitter()
      const log = watchDeliveryFrames(page as unknown as Page)
      page.emit('websocket', socket)
      const request = {
        type: 'table_viewport',
        page: 'hilos_status_subscribers',
        tableKey: 'hilosStatusSubscribers',
        requestId: 'viewport-2',
      }
      socket.emit('framesent', { payload: JSON.stringify(request) })
      const data = {
        page: mismatch === 'page' ? 'other' : request.page,
        tableKey: mismatch === 'table' ? 'other' : request.tableKey,
        requestId: mismatch === 'request' ? 'viewport-1' : request.requestId,
        rows: [{ rowKey: 7 }],
      }
      socket.emit('framereceived', {
        payload: JSON.stringify({
          type: 'signal_batch',
          data: { frames: [{ type: 'table_window', data }] },
        }),
      })
      if (mismatch === 'socket')
        log.packets[0] = { ...log.packets[0], socket: 2 }
      if (mismatch === 'older')
        log.packets[0] = { ...log.packets[0], at: log.sent[0].at - 1 }
      if (mismatch === 'superseded')
        socket.emit('framesent', {
          payload: JSON.stringify({ ...request, requestId: 'viewport-3' }),
        })
      try {
        expect(statusSubscriberWindow(log)).toEqual(
          mismatch === 'none' ? data : undefined,
        )
        expect(statusSubscriberWindow(log, log.sent.length)).toBeUndefined()
      } finally {
        log.stop()
      }
    })
  }
})
