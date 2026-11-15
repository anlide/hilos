import net from 'node:net'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'

import { forceAccountPurge } from './accountDeletion.mjs'
import * as commandChannel from './commandChannel.mjs'

const sockets: net.Socket[] = []
const requests: { command: string; payload: Record<string, unknown> }[] = []

beforeEach(() => {
  vi.stubEnv('COMMAND_HOST', 'command.test')
  vi.stubEnv('COMMAND_PORT', '9000')
  vi.spyOn(net, 'connect').mockImplementation(() => {
    throw new Error('Unexpected command connection')
  })
  vi.spyOn(commandChannel, 'createCommandChannel')
})

afterEach(() => {
  for (const socket of sockets) socket.destroy()
  sockets.length = 0
  requests.length = 0
  vi.unstubAllEnvs()
  vi.restoreAllMocks()
})

it('waits for the reply as long as the CLI does', async () => {
  answer({ userId: 42, outcome: 'completed', rowsErased: { personal: 3 } })

  await forceAccountPurge(42)

  expect(commandChannel.createCommandChannel).toHaveBeenCalledWith(
    expect.objectContaining({ timeoutMs: 15_000 }),
  )
})

it('resolves once the erasure is completed', async () => {
  answer({ userId: 42, outcome: 'completed', rowsErased: { personal: 3 } })

  await expect(forceAccountPurge(42)).resolves.toBeUndefined()
  expect(requests).toEqual([
    { command: 'test:account:force-purge', payload: { userId: 42 } },
  ])
})

it('rejects with the account and the stage when the erasure is not finished', async () => {
  answer({
    userId: 42,
    outcome: 'erasing',
    erasure: { startedAt: 1, parts: [], waits: [], refusals: [] },
  })

  await expect(forceAccountPurge(42)).rejects.toThrow(
    'test:account:force-purge did not finish the erasure of user 42: erasing',
  )
})

/** Answer the next command with the unchanged command-channel framing. */
function answer(payload: object): void {
  vi.mocked(net.connect).mockImplementationOnce(() => {
    const socket = new net.Socket()
    sockets.push(socket)
    vi.spyOn(socket, 'write').mockImplementation((chunk) => {
      const { command, payload: sent } = JSON.parse(String(chunk))
      requests.push({ command, payload: sent })
      return true
    })
    queueMicrotask(() => {
      socket.emit('connect')
      socket.emit(
        'data',
        Buffer.from(JSON.stringify({ status: 'ok', payload }) + '\n'),
      )
    })
    return socket
  })
}
