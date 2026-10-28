import net from 'node:net'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'

import * as standCommand from './standCommand.mjs'
import {
  bindTestSession,
  FIXTURE_PASSWORD_HASH,
  seedTestUser,
} from './testUser.mjs'

const USER = { userId: 73, email: 'fixture@example.test', name: 'fixture' }
const BIND = { sessionId: 91, userId: USER.userId }
const SESSION = { sessionToken: 'fixture-session-token', userId: USER.userId }

beforeEach(() => {
  vi.stubEnv('COMMAND_HOST', 'command.test')
  vi.stubEnv('COMMAND_PORT', '9000')
})

afterEach(() => {
  vi.unstubAllEnvs()
  vi.restoreAllMocks()
})

/** Run the real wrapper and transport against a mock command-channel socket. */
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
  expect(socket.destroyed).toBe(true)
  return { reply, request, connect }
}

it('uses the same precomputed PASSWORD hash as the PHP fixture proof', () => {
  expect(FIXTURE_PASSWORD_HASH).toBe(
    '$2y$10$abcdefghijklmnopqrstuuCj7fyLDJytXL.TfOiOeiv0fQvSOK1/2',
  )
})

it('creates a password fixture with explicit null phone and theme defaults', async () => {
  const { reply, request, connect } = exchange(
    () => seedTestUser({ email: ' Fixture@Example.test ' }),
    USER,
  )

  expect(request).toEqual({
    correlationId: expect.any(String),
    command: 'test:user:create',
    payload: {
      email: ' Fixture@Example.test ',
      passwordHash: FIXTURE_PASSWORD_HASH,
      themePick: null,
      phone: null,
    },
  })
  expect(connect).toHaveBeenCalledExactlyOnceWith(9000, 'command.test')
  await expect(reply).resolves.toEqual(USER)
})

it.each([null, 'light', 'dark', 'system'] as const)(
  'forwards passwordless identity, phone and theme %s without extra fields',
  async (themePick) => {
    const payload = {
      email: USER.email,
      passwordHash: null,
      themePick,
      phone: '+15555550123',
    }
    const { reply, request } = exchange(() => seedTestUser(payload), USER)

    expect(request).toEqual({
      correlationId: expect.any(String),
      command: 'test:user:create',
      payload,
    })
    await expect(reply).resolves.toEqual(USER)
  },
)

it('forwards an explicitly supplied password hash', async () => {
  const passwordHash = 'caller-supplied-precomputed-hash'
  const { reply, request } = exchange(
    () => seedTestUser({ email: USER.email, passwordHash }),
    USER,
  )

  expect(request.payload).toEqual({
    email: USER.email,
    passwordHash,
    themePick: null,
    phone: null,
  })
  await expect(reply).resolves.toEqual(USER)
})

it('binds only the session token and the requested account id', async () => {
  const { reply, request, connect } = exchange(
    () => bindTestSession(SESSION),
    BIND,
  )

  expect(request).toEqual({
    correlationId: expect.any(String),
    command: 'test:session:bind',
    payload: SESSION,
  })
  expect(connect).toHaveBeenCalledExactlyOnceWith(9000, 'command.test')
  await expect(reply).resolves.toEqual(BIND)
})

const commands = [
  {
    command: 'test:user:create',
    run: (host?: string) => seedTestUser({ email: USER.email }, host),
    payload: USER,
  },
  {
    command: 'test:session:bind',
    run: (host?: string) => bindTestSession(SESSION, host),
    payload: BIND,
  },
] as const

it.each(commands)('$command reads the endpoint at each call', async (c) => {
  for (const [host, port] of [
    ['first.test', 9001],
    ['second.test', 9002],
  ] as const) {
    vi.stubEnv('COMMAND_HOST', host)
    vi.stubEnv('COMMAND_PORT', String(port))
    const { reply, connect } = exchange(() => c.run(), c.payload)

    expect(connect).toHaveBeenLastCalledWith(port, host)
    await expect(reply).resolves.toEqual(c.payload)
  }
})

it.each(commands)(
  '$command overrides only the host for a cluster command',
  async (c) => {
    vi.stubEnv('COMMAND_HOST', undefined)
    vi.stubEnv('COMMAND_PORT', '9003')
    const { reply, connect } = exchange(() => c.run('cluster.test'), c.payload)

    expect(connect).toHaveBeenCalledExactlyOnceWith(9003, 'cluster.test')
    await expect(reply).resolves.toEqual(c.payload)
  },
)

it.each(commands)('$command refuses a missing stand endpoint', async (c) => {
  vi.stubEnv('COMMAND_HOST', undefined)
  const connect = vi.spyOn(net, 'connect')

  await expect(c.run()).rejects.toThrow('COMMAND_HOST is not set')
  expect(connect).not.toHaveBeenCalled()
})

it.each(commands)('$command keeps the shared port validation', async (c) => {
  vi.stubEnv('COMMAND_PORT', '9000junk')
  const connect = vi.spyOn(net, 'connect')

  await expect(c.run('cluster.test')).rejects.toThrow('COMMAND_PORT must be')
  expect(connect).not.toHaveBeenCalled()
})

it.each(commands)('$command preserves command refusals', async (c) => {
  const { reply, connect } = exchange(c.run, { message: 'refused' }, 'error')

  await expect(reply).rejects.toThrow(`${c.command} failed: refused`)
  expect(connect).toHaveBeenCalledTimes(1)
})

it.each(commands)(
  '$command gives the existing transport a five-second reply window',
  async (c) => {
    const channel = vi
      .spyOn(standCommand, 'createStandCommandChannel')
      .mockReturnValueOnce(vi.fn().mockResolvedValue(c.payload))

    await expect(c.run()).resolves.toEqual(c.payload)
    expect(channel).toHaveBeenCalledExactlyOnceWith({
      timeoutMs: 5_000,
      host: undefined,
    })
  },
)

const invalidIds = [
  undefined,
  null,
  '73',
  true,
  false,
  0,
  -1,
  1.5,
  Number.MAX_SAFE_INTEGER + 1,
]

it.each(invalidIds)('refuses invalid create userId %s', async (userId) => {
  const { reply } = exchange(() => seedTestUser({ email: USER.email }), {
    ...USER,
    userId,
  })

  await expect(reply).rejects.toThrow('Invalid test:user:create reply')
})

it.each(['email', 'name'] as const)(
  'requires nonempty create %s',
  async (key) => {
    for (const value of [undefined, null, '', '   ', 73, true]) {
      const { reply } = exchange(() => seedTestUser({ email: USER.email }), {
        ...USER,
        [key]: value,
      })

      await expect(reply).rejects.toThrow('Invalid test:user:create reply')
    }
  },
)

it.each(['sessionId', 'userId'] as const)(
  'refuses invalid bind %s without coercing it',
  async (key) => {
    for (const value of invalidIds) {
      const { reply } = exchange(() => bindTestSession(SESSION), {
        ...BIND,
        [key]: value,
      })

      await expect(reply).rejects.toThrow('Invalid test:session:bind reply')
    }
  },
)

it('refuses a bind reply for another account', async () => {
  const { reply } = exchange(() => bindTestSession(SESSION), {
    ...BIND,
    userId: USER.userId + 1,
  })

  await expect(reply).rejects.toThrow('Invalid test:session:bind reply')
})

it.each(commands)(
  '$command refuses malformed successful payloads',
  async (c) => {
    for (const payload of [undefined, null, [], {}, true, 73, 'account']) {
      const { reply } = exchange(c.run, payload)

      await expect(reply).rejects.toThrow(`Invalid ${c.command} reply`)
    }
  },
)

it('accepts positive safe integer ids up to the JSON boundary', async () => {
  const userId = Number.MAX_SAFE_INTEGER
  const user = { ...USER, userId }
  const bind = { userId, sessionId: Number.MAX_SAFE_INTEGER }
  const created = exchange(() => seedTestUser({ email: USER.email }), user)
  await expect(created.reply).resolves.toEqual(user)
  const bound = exchange(() => bindTestSession({ ...SESSION, userId }), bind)
  await expect(bound.reply).resolves.toEqual(bind)
})
