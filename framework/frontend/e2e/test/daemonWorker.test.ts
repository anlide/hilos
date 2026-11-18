import { EventEmitter } from 'node:events'
import type { BrowserContext, Page } from '@playwright/test'
import { afterEach, expect, it, vi } from 'vitest'
import { HilosPages } from '../../core/src/routing/hilosPages.js'
import { reportDaemonNode } from '../../scripts/daemonMoments.mjs'
import { readMonopolisticWorker } from '../daemonWorker.js'

vi.mock('../../scripts/daemonMoments.mjs', () => ({
  reportDaemonNode: vi.fn(),
}))

const REPORT = { nodeId: 'standalone', reportedAt: 1 }
const AGENT = 'hilos_settings_library'
const WORKER = {
  index: 8,
  pid: 12345,
  kind: 'monopolistic',
  agentIds: [AGENT],
}

function browser() {
  const events = new EventEmitter()
  const page = events as unknown as Page
  const socket = new EventEmitter()
  page.close = vi.fn().mockResolvedValue(undefined)
  return {
    page,
    open() {
      events.emit('websocket', socket)
    },
    send(...frames: unknown[]) {
      socket.emit('framereceived', {
        payload: JSON.stringify({ type: 'signal_batch', data: { frames } }),
      })
    },
  }
}

function reader() {
  const source = browser()
  const target = browser()
  target.page.context = () =>
    ({ newPage: async () => source.page }) as BrowserContext
  return { source, target }
}

function reported(value: boolean, page: string = HilosPages.DAEMON_WORKERS) {
  return {
    type: 'page_response',
    data: { page, payload: { data: { processesReported: value } } },
  }
}

function window(
  rows: readonly unknown[],
  rowsBefore = 0,
  totalCount = rows.length,
) {
  return {
    type: 'table_window',
    data: {
      page: HilosPages.DAEMON_WORKERS,
      tableKey: 'hilosDaemonWorkers',
      rows,
      totalCount,
      totalExact: true,
      limit: 25,
      firstAnchor: null,
      lastAnchor: null,
      rowsBefore,
      requestId: 'workers:0:1',
    },
  }
}

// The cold capture answered the initial request with no rows, then delivered
// processesReported + a nonzero count without another window or request.
const COLD_OPENING = {
  type: 'page_response',
  data: {
    page: HilosPages.DAEMON_WORKERS,
    payload: {
      data: { processesReported: false },
      windows: {
        hilosDaemonWorkers: {
          rows: [],
          sort: [],
          filter: {},
          limit: 25,
          totalCount: 0,
          totalExact: true,
          firstAnchor: null,
          lastAnchor: null,
          rowsBefore: 0,
        },
      },
    },
  },
}
const LATE_COUNT = {
  type: 'table_viewport_count',
  data: {
    page: HilosPages.DAEMON_WORKERS,
    tableKey: 'hilosDaemonWorkers',
    totalCount: 22,
    totalExact: true,
    pageCount: 1,
  },
}

/** A finite poll budget in turns, without a real clock or a reporting timer. */
async function waitFor(predicate: () => boolean) {
  for (let turn = 0; turn < 5; turn++) {
    if (predicate()) return
    await Promise.resolve()
  }
  throw new Error('The reader wait expired')
}

afterEach(() => vi.resetAllMocks())

it('prepares the reported mirror before opening the selection window after a cold empty reply', async () => {
  const { source, target } = reader()
  const order: string[] = []
  let ready = false
  vi.mocked(reportDaemonNode).mockImplementation(async () => {
    order.push('source report')
    ready = true
    source.send(reported(true), LATE_COUNT)
    return REPORT
  })
  const navigate = async (page: Page) => {
    if (page === source.page) {
      order.push('source open')
      source.open()
      source.send(COLD_OPENING, window([]))
    } else {
      order.push('selection open')
      expect(source.page.close).not.toHaveBeenCalled()
      target.open()
      target.send(reported(ready))
      // The initial viewport reads the mirror that is ready when it opens;
      // a cold viewport has only the empty answer and later count above.
      target.send(window(ready ? [{ slots: { worker: WORKER } }] : []))
    }
  }

  await expect(
    readMonopolisticWorker(target.page, AGENT, navigate, waitFor, {
      host: 'node-a',
    }),
  ).resolves.toEqual(WORKER)
  expect(order).toEqual(['source open', 'source report', 'selection open'])
  expect(reportDaemonNode).toHaveBeenCalledExactlyOnceWith({ host: 'node-a' })
  expect(source.page.close).toHaveBeenCalledOnce()
  // Both observers have gone; a later invalid text frame cannot throw.
  expect(() => source.send('late')).not.toThrow()
  expect(() => target.send('late')).not.toThrow()
})

it('does not mistake a count or another page report for a ready source', async () => {
  const { source, target } = reader()
  vi.mocked(reportDaemonNode).mockResolvedValue(REPORT)
  const navigate = vi.fn(async () => {
    source.open()
    source.send(
      COLD_OPENING,
      window([]),
      LATE_COUNT,
      reported(true, 'another_page'),
    )
  })

  await expect(
    readMonopolisticWorker(target.page, AGENT, navigate, waitFor),
  ).rejects.toThrow('The reader wait expired')
  expect(navigate).toHaveBeenCalledExactlyOnceWith(source.page)
  expect(source.page.close).toHaveBeenCalledOnce()
})

it('propagates a refused preparation report and closes its tab before opening the reader', async () => {
  const { source, target } = reader()
  const refusal = new Error(
    'The Daemon node agent is not running on this node.',
  )
  vi.mocked(reportDaemonNode).mockRejectedValue(refusal)
  const navigate = vi.fn(async () => {
    source.open()
    source.send(COLD_OPENING)
  })

  await expect(
    readMonopolisticWorker(target.page, AGENT, navigate, waitFor),
  ).rejects.toBe(refusal)
  expect(navigate).toHaveBeenCalledExactlyOnceWith(source.page)
  expect(source.page.close).toHaveBeenCalledOnce()
})

it('selects only its own typed roster and continues past a first page without the requested agent', async () => {
  const { source, target } = reader()
  const next = vi.fn(async () => {
    // Next first echoes the old page; it must then advance to the new window.
    target.send(
      window([{ slots: { worker: { ...WORKER, agentIds: ['other'] } } }], 0, 2),
    )
    target.send(window([{ slots: { worker: WORKER } }], 1, 2))
  })
  target.page.getByTestId = vi.fn().mockReturnValue({ click: next })
  const navigate = async (page: Page) => {
    if (page === source.page) {
      source.open()
      source.send(
        reported(true),
        window([{ slots: { worker: { ...WORKER, pid: 999 } } }]),
      )
    } else {
      target.open()
      target.send(
        window(
          [{ slots: { worker: { ...WORKER, agentIds: ['other'] } } }],
          0,
          2,
        ),
      )
    }
  }

  await expect(
    readMonopolisticWorker(target.page, AGENT, navigate, waitFor),
  ).resolves.toEqual(WORKER)
  expect(target.page.getByTestId).toHaveBeenCalledWith('hilos-table-next')
  expect(next).toHaveBeenCalledOnce()
  expect(reportDaemonNode).not.toHaveBeenCalled()
})

it.each([{ index: 0 }, { pid: 1 }])(
  'refuses the selected worker with an invalid identity %j',
  async (identity) => {
    const { source, target } = reader()
    const navigate = async (page: Page) => {
      if (page === source.page) {
        source.open()
        source.send(reported(true))
      } else {
        target.open()
        target.send(window([{ slots: { worker: { ...WORKER, ...identity } } }]))
      }
    }

    await expect(
      readMonopolisticWorker(target.page, AGENT, navigate, waitFor),
    ).rejects.toThrow('invalid monopolistic worker identity')
    expect(source.page.close).toHaveBeenCalledOnce()
  },
)
