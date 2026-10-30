import net from 'node:net'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'

import {
  observeChatSubmission,
  observeModeration,
} from './moderationObservation.mjs'

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
  payload: unknown,
  status = 'ok',
) {
  const socket = new net.Socket()
  const write = vi.spyOn(socket, 'write').mockReturnValue(true)
  const connect = vi.spyOn(net, 'connect').mockReturnValueOnce(socket)
  const reply = run()
  socket.emit('connect')
  const request = JSON.parse(String(write.mock.calls[0]?.[0]))
  socket.emit('data', Buffer.from(JSON.stringify({ status, payload }) + '\n'))
  expect(connect).toHaveBeenCalledExactlyOnceWith(9000, 'command.test')
  expect(socket.destroyed).toBe(true)
  return { reply, request }
}

const IDLE = {
  checkRequestId: null,
  activeRequestId: null,
  waitingForUploadHold: false,
}
const waiting = {
  ...IDLE,
  checkRequestId: 'check-a',
  waitingForUploadHold: true,
}

it.each([
  IDLE,
  waiting,
  { ...IDLE, checkRequestId: 'check-a', activeRequestId: 'check-a' },
])(
  'observes the exact pair without converting busy or waiting to idle',
  async (payload) => {
    const { reply, request } = exchange(
      () => observeModeration('connection-a', 'chat_message'),
      payload,
    )
    expect(request).toEqual({
      correlationId: expect.any(String),
      command: 'test:moderation:observe',
      payload: { acceptKey: 'connection-a', kind: 'chat_message' },
    })
    await expect(reply).resolves.toEqual(payload)
  },
)

it.each([
  null,
  [],
  {},
  { ...IDLE, checkRequestId: undefined },
  { ...IDLE, checkRequestId: '' },
  { ...IDLE, checkRequestId: 1 },
  { ...IDLE, activeRequestId: undefined },
  { ...IDLE, activeRequestId: '' },
  { ...IDLE, waitingForUploadHold: undefined },
  { ...IDLE, waitingForUploadHold: 0 },
  { ...IDLE, otherConnections: [] },
])('refuses a malformed moderator observation (%j)', async (payload) => {
  const { reply } = exchange(() => observeModeration('a', 'b'), payload)
  await expect(reply).rejects.toThrow('Invalid test:moderation:observe reply')
})

it.each([
  { phase: '', activeCheckRequestIds: [] },
  { phase: 'checking', activeCheckRequestIds: ['text', 'picture'] },
  { phase: 'checking', activeCheckRequestIds: [] },
  { phase: 'unavailable', activeCheckRequestIds: [] },
])(
  'keeps the Chat FIFO checkpoint distinct from moderator idle (%j)',
  async (payload) => {
    const { reply, request } = exchange(
      () => observeChatSubmission('connection-a'),
      payload,
    )
    expect(request).toEqual({
      correlationId: expect.any(String),
      command: 'test:chat:submission-observe',
      payload: { acceptKey: 'connection-a' },
    })
    await expect(reply).resolves.toEqual(payload)
  },
)

it.each([
  null,
  {},
  { phase: false, activeCheckRequestIds: [] },
  { phase: 'checking', activeCheckRequestIds: null },
  { phase: 'checking', activeCheckRequestIds: [''] },
  { phase: 'checking', activeCheckRequestIds: [null] },
  { phase: 'checking', activeCheckRequestIds: 'check-a' },
  { phase: 'checking', activeCheckRequestIds: [], otherConnection: 'b' },
])('refuses a malformed Chat checkpoint (%j)', async (payload) => {
  const { reply } = exchange(() => observeChatSubmission('a'), payload)
  await expect(reply).rejects.toThrow(
    'Invalid test:chat:submission-observe reply',
  )
})

for (const [name, run] of [
  ['moderator', () => observeModeration('a', 'chat_message')],
  ['Chat', () => observeChatSubmission('a')],
] as const) {
  it(`${name} propagates a refused command instead of reporting idle`, async () => {
    const { reply } = exchange(run, { message: 'Cannot observe' }, 'error')
    await expect(reply).rejects.toThrow('failed: Cannot observe')
  })

  it(`${name} fails on a broken command connection`, async () => {
    const socket = new net.Socket()
    vi.spyOn(net, 'connect').mockReturnValueOnce(socket)
    const reply = run()
    const failure = new Error('Connection refused')
    socket.emit('error', failure)
    await expect(reply).rejects.toBe(failure)
  })
}
