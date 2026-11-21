import net from 'node:net'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'

import { mergeAccounts } from './accountMerge.mjs'
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
  answer({ merge: { outcome: 'completed' } })

  await mergeAccounts({ survivorUserId: 7, loserUserId: 8 })

  expect(commandChannel.createCommandChannel).toHaveBeenCalledWith(
    expect.objectContaining({ timeoutMs: 15_000 }),
  )
})

it('sends one start request and resolves when the merge completes', async () => {
  answer({ merge: { outcome: 'completed' } })

  await expect(
    mergeAccounts({ survivorUserId: 7, loserUserId: 8 }),
  ).resolves.toBeUndefined()
  expect(requests).toEqual([
    {
      command: 'account:merge',
      payload: {
        mode: 'start',
        survivorUserId: 7,
        loserUserId: 8,
        passwordFate: 'survivor',
      },
    },
  ])
})

it('rejects with both user IDs and the outcome when the merge does not complete', async () => {
  answer({ merge: { outcome: 'not_started' } })

  await expect(
    mergeAccounts({ survivorUserId: 7, loserUserId: 8 }),
  ).rejects.toThrow(
    'account:merge did not complete the merge of user 8 into 7: not_started',
  )
})

it('rejects with both user IDs when the reply has no merge', async () => {
  answer({})

  await expect(
    mergeAccounts({ survivorUserId: 7, loserUserId: 8 }),
  ).rejects.toThrow(
    'account:merge did not complete the merge of user 8 into 7: no merge in the reply',
  )
})

/** Answer the next command with the command-channel framing. */
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
