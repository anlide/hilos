import net from 'node:net'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'

import { appendLogLines, rotateLogs, walkLogStore } from './standLogs.mjs'

beforeEach(() => {
  vi.stubEnv('COMMAND_HOST', 'command.test')
  vi.stubEnv('COMMAND_PORT', '9000')
})

afterEach(() => {
  vi.unstubAllEnvs()
  vi.restoreAllMocks()
})

/** Exercise the real shared command transport with an in-memory socket. */
function exchange(
  run: () => Promise<unknown>,
  host: string,
  reply: { status: string; payload: unknown },
) {
  const socket = new net.Socket()
  const write = vi.spyOn(socket, 'write').mockReturnValue(true)
  const connect = vi.spyOn(net, 'connect').mockReturnValueOnce(socket)
  const result = run()
  socket.emit('connect')
  const request = JSON.parse(String(write.mock.calls[0]?.[0]))
  socket.emit('data', Buffer.from(JSON.stringify(reply) + '\n'))
  expect(connect).toHaveBeenCalledExactlyOnceWith(9000, host)
  return { result, request }
}

it('asks the stand’s log-store agent to walk, with an empty payload', async () => {
  const { result, request } = exchange(() => walkLogStore(), 'command.test', {
    status: 'ok',
    payload: {},
  })

  expect(request).toEqual({
    correlationId: expect.any(String),
    command: 'test:logs:walk',
    payload: {},
  })
  await expect(result).resolves.toBeUndefined()
})

it('walks the store of the cluster node it is given instead of the stand host', async () => {
  const { result, request } = exchange(
    () => walkLogStore('node-b.test'),
    'node-b.test',
    { status: 'ok', payload: {} },
  )

  expect(request.command).toBe('test:logs:walk')
  await expect(result).resolves.toBeUndefined()
})

it('asks the stand’s log-store agent to append numbered lines, with their text and count', async () => {
  const { result, request } = exchange(
    () => appendLogLines('e2e-follow-arrives', 3),
    'command.test',
    { status: 'ok', payload: { count: 3 } },
  )

  expect(request).toEqual({
    correlationId: expect.any(String),
    command: 'test:log:append',
    payload: { message: 'e2e-follow-arrives', count: 3 },
  })
  await expect(result).resolves.toBe(3)
})

it('appends to the log of the cluster node it is given instead of the stand host', async () => {
  const { result, request } = exchange(
    () => appendLogLines('cluster-follow', 1, 'node-b.test'),
    'node-b.test',
    { status: 'ok', payload: { count: 1 } },
  )

  expect(request.command).toBe('test:log:append')
  expect(request.payload).toEqual({ message: 'cluster-follow', count: 1 })
  await expect(result).resolves.toBe(1)
})

it('asks the stand’s log-store agent to rotate, with an empty payload', async () => {
  const { result, request } = exchange(() => rotateLogs(), 'command.test', {
    status: 'ok',
    payload: { moved: 2 },
  })

  expect(request).toEqual({
    correlationId: expect.any(String),
    command: 'test:logs:rotate',
    payload: {},
  })
  await expect(result).resolves.toBe(2)
})

it('rotates the store of the cluster node it is given instead of the stand host', async () => {
  const { result, request } = exchange(
    () => rotateLogs('node-b.test'),
    'node-b.test',
    { status: 'ok', payload: { moved: 0 } },
  )

  expect(request.command).toBe('test:logs:rotate')
  await expect(result).resolves.toBe(0)
})

it('fails with the agent’s words when the walk is refused', async () => {
  const { result } = exchange(() => walkLogStore(), 'command.test', {
    status: 'error',
    payload: { message: 'No readable log store' },
  })

  await expect(result).rejects.toThrow(
    'test:logs:walk failed: No readable log store',
  )
})

it('fails on a broken command connection', async () => {
  const socket = new net.Socket()
  vi.spyOn(net, 'connect').mockReturnValueOnce(socket)
  const result = walkLogStore()
  const failure = new Error('Connection refused')
  socket.emit('error', failure)

  await expect(result).rejects.toBe(failure)
})
