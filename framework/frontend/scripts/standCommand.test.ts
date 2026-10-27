import net from 'node:net'
import { afterEach, expect, it, vi } from 'vitest'

import { createStandCommandChannel } from './standCommand.mjs'

const TIMEOUT_MS = 50

afterEach(() => {
  vi.unstubAllEnvs()
  vi.restoreAllMocks()
})

it('imports and creates a channel without requiring an unused stand endpoint', async () => {
  vi.stubEnv('COMMAND_HOST', undefined)
  vi.stubEnv('COMMAND_PORT', undefined)
  vi.resetModules()
  const { createStandCommandChannel: create } =
    await import('./standCommand.mjs')

  expect(() => create({ timeoutMs: TIMEOUT_MS })).not.toThrow()
})

it.each([
  ['COMMAND_HOST', undefined],
  ['COMMAND_HOST', ''],
  ['COMMAND_HOST', '   '],
  ['COMMAND_PORT', undefined],
  ['COMMAND_PORT', ''],
] as const)('requires %s at the call (%s)', async (name, value) => {
  vi.stubEnv('COMMAND_HOST', 'command.test')
  vi.stubEnv('COMMAND_PORT', '9000')
  const send = createStandCommandChannel({ timeoutMs: TIMEOUT_MS })
  vi.stubEnv(name, value)
  const connect = vi.spyOn(net, 'connect')

  await expect(send('test:address', {})).rejects.toThrow(name)
  expect(connect).not.toHaveBeenCalled()
})

it.each(['0', '-1', '65536', '1.5', 'NaN', 'Infinity', '9000junk', '1e3'])(
  'refuses invalid TCP port %s',
  async (port) => {
    vi.stubEnv('COMMAND_HOST', 'command.test')
    vi.stubEnv('COMMAND_PORT', port)
    const connect = vi.spyOn(net, 'connect')
    const send = createStandCommandChannel({ timeoutMs: TIMEOUT_MS })

    await expect(send('test:port', {})).rejects.toThrow(
      'COMMAND_PORT must be an integer TCP port from 1 to 65535',
    )
    expect(connect).not.toHaveBeenCalled()
  },
)

it('reads the endpoint afresh on every call and delegates the unchanged request', async () => {
  const send = createStandCommandChannel({ timeoutMs: TIMEOUT_MS })
  const connect = vi.spyOn(net, 'connect')

  for (const [host, port] of [
    ['first.test', 1],
    ['second.test', 65535],
  ] as const) {
    vi.stubEnv('COMMAND_HOST', host)
    vi.stubEnv('COMMAND_PORT', String(port))
    const socket = new net.Socket()
    const write = vi.spyOn(socket, 'write').mockReturnValue(true)
    connect.mockReturnValueOnce(socket)

    const reply = send('test:answer', { value: 42 })
    socket.emit('connect')
    socket.emit(
      'data',
      Buffer.from('{"status":"ok","payload":{"answer":42}}\n'),
    )

    await expect(reply).resolves.toEqual({ answer: 42 })
    expect(connect).toHaveBeenLastCalledWith(port, host)
    const request = String(write.mock.calls[0]?.[0])
    expect(request.endsWith('\n')).toBe(true)
    expect(JSON.parse(request)).toMatchObject({
      command: 'test:answer',
      payload: { value: 42 },
    })
  }
})

it('preserves the refusal type through the existing transport', async () => {
  class CommandRefused extends Error {}

  vi.stubEnv('COMMAND_HOST', 'command.test')
  vi.stubEnv('COMMAND_PORT', '9000')
  const socket = new net.Socket()
  vi.spyOn(net, 'connect').mockReturnValueOnce(socket)
  const send = createStandCommandChannel({
    timeoutMs: TIMEOUT_MS,
    refuse: (message) => new CommandRefused(message),
  })

  const reply = send('test:refusal', {})
  socket.emit(
    'data',
    Buffer.from('{"status":"error","payload":{"message":"refused"}}\n'),
  )

  await expect(reply).rejects.toBeInstanceOf(CommandRefused)
  await expect(reply).rejects.toThrow('test:refusal failed: refused')
})

it('keeps the operation’s reply window in the existing transport', async () => {
  vi.stubEnv('COMMAND_HOST', 'command.test')
  vi.stubEnv('COMMAND_PORT', '9000')
  const socket = new net.Socket()
  vi.spyOn(net, 'connect').mockReturnValueOnce(socket)
  const send = createStandCommandChannel({ timeoutMs: TIMEOUT_MS })

  await expect(send('test:silent', {})).rejects.toThrow(
    'No command-channel reply to test:silent within 50ms',
  )
  expect(socket.destroyed).toBe(true)
})
