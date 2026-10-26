import type { Expect, Page } from '@playwright/test'
import { describe, expect, it } from 'vitest'
import { watchDaemonSource } from '../daemonSource.js'
import type { watchTableSearch } from '../tableFocus.js'

const mark = { documentStarted: 1, socket: 1, cursor: 1 }
const pageReply = (connections: number, windows?: unknown) => ({
  type: 'page_response',
  data: {
    page: 'hilos_daemon_placement',
    payload: {
      data: {
        daemon: {
          nodes: [{ nodeId: 'standalone', standing: { connections } }],
        },
      },
      ...(windows === undefined ? {} : { windows }),
    },
  },
})
const workerReply = () => ({
  type: 'table_viewport_delta',
  data: {
    page: 'hilos_daemon_workers',
    tableKey: 'hilosDaemonWorkers',
    kind: 'row_updated',
    own: false,
    rowKey: 'regular:1',
    row: {
      rowKey: 'regular:1',
      slots: { worker: { agentIds: ['hilos_user:42'], agentCount: 1 } },
    },
  },
})

async function journal(connectionCount = 1) {
  const frame = (wire: unknown, index: number, socket = 1) => ({
    wire,
    index,
    socket,
    direction: 'received',
    raw: JSON.stringify(wire),
  })
  let frames = [frame(pageReply(connectionCount), 0)]
  let dom = 'Browser connections: 1'
  const windows = {
    async read() {
      return {
        documentStarted: 1,
        socket: 1,
        readyState: 1,
        frames: [...frames],
        application: [{ rows: ['regular:1'] }],
      }
    },
  } as unknown as Awaited<ReturnType<typeof watchTableSearch>>
  const page = {
    async evaluate() {
      const value = /^Browser connections: (\d+)$/.exec(dom)
      return value ? Number(value[1]) : null
    },
    getByTestId() {
      return {
        node: true,
        locator() {
          return {
            nth() {
              return { node: true }
            },
          }
        },
      }
    },
  } as unknown as Page
  const checks = Object.assign(
    (value: unknown, message?: string) => {
      if (value && typeof value === 'object' && 'node' in value)
        return {
          toHaveText: async (wanted: string) => expect(dom).toBe(wanted),
        }
      return expect(value, message)
    },
    {
      poll: (read: () => Promise<unknown>) => ({
        toEqual: async (wanted: unknown) =>
          expect(await read()).toEqual(wanted),
      }),
    },
  ) as unknown as Expect
  const source = await watchDaemonSource(windows, page)
  return {
    source,
    checks,
    receive(wire: unknown, text = 'Browser connections: 2', socket = 1) {
      frames = [...frames, frame(wire, frames.length, socket)]
      dom = text
    },
  }
}

describe('Daemon source proof classification', () => {
  it('establishes the actual one-client baseline before adding another client', async () => {
    const b = await journal(2)
    b.receive(pageReply(1), 'Browser connections: 1')
    await b.source.singleConnection(b.checks)
    b.receive(pageReply(2))
    await b.source.connectionOpened(b.checks, { ...mark, cursor: 2 })
  })

  it('requires a changed real value and its committed Placement projection', async () => {
    const b = await journal()
    b.receive(pageReply(2))
    await b.source.connectionOpened(b.checks, mark)
    expect(b.source.read()).toHaveLength(1)
  })

  it.each([
    'unchanged',
    'foreign socket',
    'whole replacement',
    'ack',
    'uncommitted projection',
  ])('rejects %s as an ordinary causal connection update', async (failure) => {
    const b = await journal()
    const wire = pageReply(
      failure === 'unchanged' ? 1 : 2,
      failure === 'whole replacement' ? {} : undefined,
    )
    if (failure === 'ack') Object.assign(wire.data, { requestId: 'unrelated' })
    b.receive(
      wire,
      failure === 'uncommitted projection'
        ? 'Browser connections: 1'
        : 'Browser connections: 2',
      failure === 'foreign socket' ? 2 : 1,
    )
    await expect(b.source.connectionOpened(b.checks, mark)).rejects.toThrow()
    expect(b.source.read()).toHaveLength(1)
  })

  it('requires the exact newly created owner in a native foreign row update', async () => {
    const b = await journal()
    b.receive(workerReply(), '1')
    const proof = await b.source.workerAdded(b.checks, mark, 42)
    expect(proof.agentId).toBe('hilos_user:42')
    expect(proof.rowKey).toBe('regular:1')
  })

  it('recognizes the canonical omitted false own flag on a foreign update', async () => {
    const b = await journal()
    const wire = workerReply()
    Reflect.deleteProperty(wire.data, 'own')
    b.receive(wire, '1')
    await b.source.workerAdded(b.checks, mark, 42)
  })

  it.each(['wrong owner', 'own', 'moved', 'unheld row'])(
    'rejects %s as the retained worker proof',
    async (failure) => {
      const b = await journal()
      const wire = workerReply()
      if (failure === 'own') wire.data.own = true
      if (failure === 'moved') wire.data.kind = 'row_moved'
      if (failure === 'unheld row') wire.data.rowKey = 'regular:9'
      b.receive(wire, '1')
      await expect(
        b.source.workerAdded(
          b.checks,
          mark,
          failure === 'wrong owner' ? 43 : 42,
        ),
      ).rejects.toThrow()
    },
  )
})
