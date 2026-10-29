import { EventEmitter } from 'node:events'
import type { Page } from '@playwright/test'
import { describe, expect, it } from 'vitest'
import { watchDeliveryFrames } from '../deliveryFrames.js'
import {
  statusHistoryDeliveries,
  statusHistoryFactSchema,
  statusHistoryWindow,
  type StatusHistoryFact,
} from '../statusHistory.js'

const incident: StatusHistoryFact = {
  id: 'incident:7',
  kind: 'incident',
  sourceId: 7,
  startedAt: 1_800_000_000_000,
  endedAt: 1_800_000_030_000,
  completionObservedAt: null,
  publishedAt: null,
  outcome: 'canceled_publication',
  title: 'Settings library',
  componentKeys: ['settings'],
  severity: 'major_outage',
  announcementKnown: null,
  workPublicId: null,
}
const maintenance: StatusHistoryFact = {
  ...incident,
  id: 'maintenance:7',
  kind: 'maintenance',
  endedAt: null,
  completionObservedAt: 1_800_000_040_000,
  outcome: 'maintenance_completed',
  title: 'Saved maintenance',
  severity: null,
  announcementKnown: true,
  workPublicId: 'public-work',
}
const catalog = {
  components: [{ componentKey: 'settings', name: 'Settings library' }],
}
const row = (fact = incident) => ({ rowKey: fact.id, slots: { history: fact } })
const window = (rows = [row(), row(maintenance)]) => ({
  rows,
  totalCount: rows.length,
  totalExact: true,
  limit: 25,
  firstAnchor: null,
  lastAnchor: null,
})
const opening = (data: unknown = catalog, rows = window().rows) => ({
  type: 'page_response',
  data: {
    page: 'hilos_status_history',
    payload: {
      data: { statusHistory: data },
      windows: { hilosStatusHistory: window(rows) },
    },
  },
})
const catalogUpdate = (data: unknown = catalog) => ({
  type: 'page_response',
  data: {
    page: 'hilos_status_history',
    payload: { data: { statusHistory: data } },
  },
})
const viewport = (requestId = 'history-1') => ({
  type: 'table_viewport',
  page: 'hilos_status_history',
  tableKey: 'hilosStatusHistory',
  requestId,
})
const update = (type = 'table_window', extra = {}) => ({
  type,
  data: {
    page: 'hilos_status_history',
    tableKey: 'hilosStatusHistory',
    ...window(),
    ...extra,
  },
})

function peer() {
  const page = new EventEmitter()
  const socket = new EventEmitter()
  const log = watchDeliveryFrames(page as unknown as Page)
  page.emit('websocket', socket)
  const receive = (wire: unknown) =>
    socket.emit('framereceived', { payload: JSON.stringify(wire) })
  return { page, socket, log, receive }
}

describe('independent History wire vocabulary', () => {
  for (const batch of [false, true]) {
    it(`retains the complete ${batch ? 'batch' : 'single'} opening, window, focused delta and transport lifecycle`, () => {
      const { page, socket, log, receive } = peer()
      const delta = update('table_viewport_delta', {
        kind: 'row_updated',
        rowKey: maintenance.id,
        row: row(maintenance),
        own: false,
      })
      const frames = [
        opening(),
        update(),
        delta,
        update('table_viewport_append'),
      ]
      try {
        if (batch) receive({ type: 'signal_batch', data: { frames } })
        else frames.forEach(receive)
        const deliveries = statusHistoryDeliveries(log)
        expect(deliveries.map(({ rows }) => rows)).toEqual([
          [incident, maintenance],
          [incident, maintenance],
          [maintenance],
          [incident, maintenance],
        ])
        expect(deliveries[0].catalog).toEqual(catalog)
        expect(deliveries.map(({ frame }) => frame.wire)).toEqual(frames)
        expect(
          deliveries.map(({ frame }) => [frame.socket, frame.message]),
        ).toEqual(frames.map((_, i) => [1, batch ? 1 : i + 1]))
        expect(log.packets).toHaveLength(batch ? 1 : frames.length)
        expect(log.packets[0].raw).toBe(
          JSON.stringify(
            batch ? { type: 'signal_batch', data: { frames } } : frames[0],
          ),
        )
        expect(log.connections).toMatchObject([
          { socket: 1, opened: expect.any(Number) },
        ])
        socket.emit('close')
        expect(log.connections[0].closed).toEqual(expect.any(Number))
      } finally {
        log.stop()
      }
      expect(page.listenerCount('websocket')).toBe(0)
      for (const event of ['framereceived', 'framesent', 'close'])
        expect(socket.listenerCount(event)).toBe(0)
      const size = log.packets.length
      receive(opening())
      expect(log.packets).toHaveLength(size)
    })

    it(`reads a ${batch ? 'batch' : 'single'} catalog update after an opening before since and the viewport request`, () => {
      const { socket, log, receive } = peer()
      const changed = catalogUpdate({
        components: [{ componentKey: 'retired', name: 'retired' }],
      })
      const reply = update('table_window', {
        requestId: 'history-1',
        rows: [row(maintenance)],
      })
      try {
        receive(opening())
        const since = log.packets.length
        const sent = log.sent.length
        socket.emit('framesent', { payload: JSON.stringify(viewport()) })
        if (batch)
          receive({ type: 'signal_batch', data: { frames: [changed, reply] } })
        else [changed, reply].forEach(receive)
        const deliveries = statusHistoryDeliveries(log)
        expect(deliveries.map(({ rows }) => rows)).toEqual([
          [incident, maintenance],
          [maintenance],
        ])
        expect(deliveries[0].catalog).toEqual(catalog)
        expect(statusHistoryDeliveries(log, since)).toEqual([deliveries[1]])
        const answer = statusHistoryWindow(log, sent)
        expect(answer?.rows).toEqual([maintenance])
        expect(answer?.request).toBe(log.sent[sent])
        expect(answer?.frame).toBe(log.packets.at(-1)?.frames.at(-1))
        expect(answer?.frame).toMatchObject({
          socket: 1,
          message: batch ? 2 : 3,
          wire: reply,
          raw: JSON.stringify(
            batch
              ? { type: 'signal_batch', data: { frames: [changed, reply] } }
              : reply,
          ),
        })
      } finally {
        log.stop()
      }
    })

    it(`accepts a ${batch ? 'batch' : 'single'} unavailable catalog update and its recovery without clearing the table`, () => {
      const { socket, log, receive } = peer()
      const deliver = (frames: unknown[]) => {
        if (batch) receive({ type: 'signal_batch', data: { frames } })
        else frames.forEach(receive)
      }
      try {
        deliver([opening(), catalogUpdate(null)])
        expect(statusHistoryDeliveries(log).map(({ rows }) => rows)).toEqual([
          [incident, maintenance],
        ])
        const since = log.packets.length
        socket.emit('framesent', { payload: JSON.stringify(viewport()) })
        deliver([
          catalogUpdate(),
          update('table_window', { requestId: 'history-1' }),
        ])
        expect(
          statusHistoryDeliveries(log, since).map(({ rows }) => rows),
        ).toEqual([[incident, maintenance]])
        expect(statusHistoryWindow(log, 0)?.rows).toEqual([
          incident,
          maintenance,
        ])
        expect(
          log.packets.flatMap(({ frames }) => frames).map(({ wire }) => wire),
        ).toEqual([
          opening(),
          catalogUpdate(null),
          catalogUpdate(),
          update('table_window', { requestId: 'history-1' }),
        ])
      } finally {
        log.stop()
      }
    })
  }

  it('requires complete first data and only the named page/table/slot', () => {
    const { log, receive } = peer()
    try {
      receive({
        ...opening(),
        data: { ...opening().data, page: 'hilos_status_works' },
      })
      receive(update('table_window', { tableKey: 'anotherTable' }))
      expect(statusHistoryDeliveries(log)).toEqual([])
      receive(opening(null, []))
      expect(statusHistoryDeliveries(log)[0]).toMatchObject({
        catalog: null,
        rows: [],
      })
    } finally {
      log.stop()
    }
  })

  for (const [name, wire] of [
    ['malformed opening catalog', opening({})],
    [
      'missing opening catalog',
      {
        type: 'page_response',
        data: {
          page: 'hilos_status_history',
          payload: {
            data: {},
            windows: { hilosStatusHistory: window() },
          },
        },
      },
    ],
    ['missing opening window', catalogUpdate()],
    [
      'wrong opening table',
      {
        type: 'page_response',
        data: {
          page: 'hilos_status_history',
          payload: {
            data: { statusHistory: catalog },
            windows: { wrongTable: window() },
          },
        },
      },
    ],
    [
      'wrong row slot',
      update('table_window', {
        rows: [{ rowKey: incident.id, slots: { work: incident } }],
      }),
    ],
    [
      'mismatched row identity',
      update('table_window', {
        rows: [{ rowKey: maintenance.id, slots: { history: incident } }],
      }),
    ],
    [
      'mismatched delta identity',
      update('table_viewport_delta', {
        rowKey: maintenance.id,
        row: row(),
      }),
    ],
  ] as const) {
    it(`rejects ${name}`, () => {
      const { log, receive } = peer()
      try {
        receive(wire)
        expect(() => statusHistoryDeliveries(log)).toThrow()
        expect(log.packets.at(-1)?.raw).toBe(JSON.stringify(wire))
      } finally {
        log.stop()
      }
    })
  }

  for (const data of [
    {},
    { components: [{ componentKey: '', name: 'bad' }] },
  ]) {
    it('rejects a malformed partial catalog after a complete opening', () => {
      const { log, receive } = peer()
      try {
        receive(opening())
        const since = log.packets.length
        receive(catalogUpdate(data))
        expect(() => statusHistoryDeliveries(log, since)).toThrow()
      } finally {
        log.stop()
      }
    })
  }

  it('keeps the latest viewport correlation through catalog updates and late older replies', () => {
    const { socket, log, receive } = peer()
    try {
      receive(opening())
      socket.emit('framesent', {
        payload: JSON.stringify(viewport('history-old')),
      })
      const since = log.sent.length
      socket.emit('framesent', { payload: JSON.stringify(viewport()) })
      socket.emit('framesent', {
        payload: JSON.stringify({ ...viewport('foreign'), tableKey: 'other' }),
      })
      receive(catalogUpdate())
      receive(update('table_window', { requestId: 'history-old' }))
      expect(statusHistoryWindow(log, since)).toBeUndefined()
      receive(
        update('table_window', {
          requestId: 'history-1',
          rows: [row(maintenance)],
        }),
      )
      receive(update('table_window', { requestId: 'history-old' }))
      expect(statusHistoryWindow(log, since)?.rows).toEqual([maintenance])
      expect(statusHistoryWindow(log, since)?.request).toBe(log.sent[since])
    } finally {
      log.stop()
    }
  })

  it('requires a complete opening on each socket', () => {
    const { page, log, receive } = peer()
    try {
      receive(opening())
      const since = log.packets.length
      const second = new EventEmitter()
      page.emit('websocket', second)
      second.emit('framereceived', { payload: JSON.stringify(catalogUpdate()) })
      expect(() => statusHistoryDeliveries(log, since)).toThrow(
        'History first answer has no named window.',
      )
    } finally {
      log.stop()
    }
  })

  it('rejects missing, extra, malformed and mixed source fields', () => {
    for (const field of Object.keys(incident)) {
      const incomplete = { ...incident } as Record<string, unknown>
      delete incomplete[field]
      expect(statusHistoryFactSchema.safeParse(incomplete).success).toBe(false)
    }
    for (const fact of [
      { ...incident, extra: true },
      { ...incident, sourceId: 0 },
      { ...incident, id: maintenance.id },
      { ...incident, endedAt: null },
      { ...incident, startedAt: '2027-01-15' },
      { ...incident, endedAt: 1.5 },
      { ...incident, outcome: 'maintenance_completed' },
      { ...maintenance, completionObservedAt: null },
      { ...maintenance, severity: 'major_outage' },
    ])
      expect(statusHistoryFactSchema.safeParse(fact).success).toBe(false)
    expect(statusHistoryFactSchema.parse(maintenance).endedAt).toBeNull()
    expect(
      statusHistoryFactSchema.parse({
        ...maintenance,
        endedAt: maintenance.completionObservedAt,
      }),
    ).toMatchObject({ endedAt: maintenance.completionObservedAt })
  })

  for (const mismatch of ['none', 'request', 'socket', 'old'] as const) {
    it(`correlates the latest viewport answer: ${mismatch}`, () => {
      const { page, socket, log, receive } = peer()
      const reply = update('table_window', {
        requestId: mismatch === 'request' ? 'other' : 'history-1',
      })
      try {
        if (mismatch === 'old') receive(reply)
        socket.emit('framesent', {
          payload: JSON.stringify(viewport()),
        })
        if (mismatch === 'socket') {
          const second = new EventEmitter()
          page.emit('websocket', second)
          second.emit('framereceived', { payload: JSON.stringify(reply) })
        } else if (mismatch !== 'old') receive(reply)
        if (mismatch === 'none')
          expect(statusHistoryWindow(log, 0)?.rows).toEqual([
            incident,
            maintenance,
          ])
        else expect(statusHistoryWindow(log, 0)).toBeUndefined()
        expect(statusHistoryWindow(log, log.sent.length)).toBeUndefined()
      } finally {
        log.stop()
      }
    })
  }
})
