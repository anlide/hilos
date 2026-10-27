import net from 'node:net'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'

import * as commandChannel from './commandChannel.mjs'
import {
  ProtectedModeCommandRefused,
  closeProtectedMode,
  disableManualMaintenance,
  enableManualMaintenance,
  enterProtectedMode,
  inspectProtectedMode,
  leaveProtectedMode,
  mintManualMaintenancePass,
  mintProtectedModePass,
  openProtectedMode,
  openProtectedModeIfAny,
  type ProtectedModeSnapshot,
} from './protectedMode.mjs'
import { StandCommandEndpointError } from './standCommand.mjs'

const OPERATION = 'test-freeze'
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

it('imports without requiring an unused endpoint', async () => {
  vi.stubEnv('COMMAND_HOST', undefined)
  vi.stubEnv('COMMAND_PORT', undefined)
  vi.resetModules()

  await expect(import('./protectedMode.mjs')).resolves.toHaveProperty(
    'enterProtectedMode',
  )
  expect(net.connect).not.toHaveBeenCalled()
})

it('keeps the enter payload and reads the endpoint when called', async () => {
  vi.stubEnv('COMMAND_HOST', 'current.test')
  vi.stubEnv('COMMAND_PORT', '9001')
  answer({ phase: 'active' })

  await expect(
    enterProtectedMode(OPERATION, 'accept-key', 'session-token'),
  ).resolves.toBe('active')

  expect(requests).toEqual([
    {
      command: 'test:protected-mode:enter',
      payload: {
        operation: OPERATION,
        acceptKey: 'accept-key',
        sessionToken: 'session-token',
      },
    },
  ])
  expect(net.connect).toHaveBeenCalledWith(9001, 'current.test')
})

it('defaults both browser identifiers to empty strings', async () => {
  answer({ phase: 'active' })

  await enterProtectedMode(OPERATION)

  expect(requests[0]?.payload).toEqual({
    operation: OPERATION,
    acceptKey: '',
    sessionToken: '',
  })
})

it.each([
  [
    'test:protected-mode:leave',
    leaveProtectedMode,
    { phase: 'verifying' },
    'verifying',
  ],
  [
    'test:protected-mode:open',
    openProtectedMode,
    { phase: 'inactive' },
    'inactive',
  ],
  [
    'test:protected-mode:close',
    closeProtectedMode,
    { phase: 'active' },
    'active',
  ],
  [
    'test:protected-mode:pass',
    mintProtectedModePass,
    { pass: 'clear-pass' },
    'clear-pass',
  ],
  [
    'maintenance:enable',
    enableManualMaintenance,
    { phase: 'verifying' },
    'verifying',
  ],
  [
    'maintenance:pass',
    mintManualMaintenancePass,
    { pass: 'manual-pass' },
    'manual-pass',
  ],
  [
    'maintenance:disable',
    disableManualMaintenance,
    { phase: 'inactive' },
    'inactive',
  ],
] as const)(
  'preserves %s and its returned value',
  async (command, send, reply, result) => {
    answer(reply)

    await expect(send()).resolves.toBe(result)

    expect(requests).toEqual([{ command, payload: {} }])
  },
)

it.each([
  ['enter', () => enterProtectedMode(OPERATION)],
  ['leave', leaveProtectedMode],
  ['open', openProtectedMode],
  ['close', closeProtectedMode],
  ['pass', mintProtectedModePass],
  ['maintenance enable', enableManualMaintenance],
  ['maintenance pass', mintManualMaintenancePass],
  ['maintenance disable', disableManualMaintenance],
] as const)(
  'keeps an answered %s refusal typed without a retry or inspect',
  async (_name, send) => {
    answer({ message: 'already frozen' }, 'error')

    const reply = send()

    await expect(reply).rejects.toBeInstanceOf(ProtectedModeCommandRefused)
    await expect(reply).rejects.toThrow('failed: already frozen')
    expect(net.connect).toHaveBeenCalledTimes(1)
    expect(requests).toHaveLength(1)
  },
)

it('recovers a lost drive reply with exactly one 5s inspect after a 15s drive', async () => {
  loseReply()
  answer(snapshot({ phase: 'active' }))

  await expect(enterProtectedMode(OPERATION)).resolves.toBe('active')

  expect(requests.map(({ command }) => command)).toEqual([
    'test:protected-mode:enter',
    'protected-mode:inspect',
  ])
  expect(commandChannel.createCommandChannel).toHaveBeenNthCalledWith(
    1,
    expect.objectContaining({ timeoutMs: 15_000 }),
  )
  expect(commandChannel.createCommandChannel).toHaveBeenNthCalledWith(
    2,
    expect.objectContaining({ timeoutMs: 5_000 }),
  )
  expect(net.connect).toHaveBeenCalledTimes(2)
})

it.each([
  ['activating', 'the freeze is under way but has not taken hold'],
  ['inactive', 'so the command was not taken'],
] as const)(
  'reports %s after a lost reply without repeating the drive',
  async (phase, reason) => {
    loseReply()
    answer(snapshot({ phase }))

    await expect(enterProtectedMode(OPERATION)).rejects.toThrow(reason)

    expect(requests.map(({ command }) => command)).toEqual([
      'test:protected-mode:enter',
      'protected-mode:inspect',
    ])
    expect(net.connect).toHaveBeenCalledTimes(2)
  },
)

it('does not swallow an unknown teardown outcome or keep inspecting', async () => {
  loseReply()
  loseReply(new Error('inspect connection lost'))

  await expect(openProtectedModeIfAny()).rejects.toThrow(
    'whether the command was taken is unknown',
  )

  expect(requests.map(({ command }) => command)).toEqual([
    'test:protected-mode:open',
    'protected-mode:inspect',
  ])
  expect(net.connect).toHaveBeenCalledTimes(2)
})

it('cannot recover a lost clear pass from the snapshot pass count', async () => {
  const lost = new Error('pass reply lost')
  loseReply(lost)
  answer(snapshot({ phase: 'verifying', passCount: 3 }))

  const reply = mintProtectedModePass()

  await expect(reply).rejects.toThrow(
    'the node now holds 3 passes, but a pass exists only in the reply that was lost - mint another',
  )
  await expect(reply).rejects.toHaveProperty('cause', lost)
  expect(requests.map(({ command }) => command)).toEqual([
    'test:protected-mode:pass',
    'protected-mode:inspect',
  ])
  expect(net.connect).toHaveBeenCalledTimes(2)
})

it('reports an unknown pass outcome when its single inspect also fails', async () => {
  loseReply()
  loseReply()

  await expect(mintProtectedModePass()).rejects.toThrow(
    'whether a pass was minted is unknown',
  )

  expect(net.connect).toHaveBeenCalledTimes(2)
})

it('does not invent a manual pass when its reply is lost', async () => {
  const lost = new Error('manual pass reply lost')
  loseReply(lost)

  await expect(mintManualMaintenancePass()).rejects.toBe(lost)

  expect(requests).toEqual([{ command: 'maintenance:pass', payload: {} }])
  expect(net.connect).toHaveBeenCalledTimes(1)
})

it('requires a clear code in an answered manual pass reply', async () => {
  answer({ passCount: 3 })

  await expect(mintManualMaintenancePass()).rejects.toThrow(
    'maintenance:pass returned no code',
  )
})

it('turns off a refused manual window with its production disable command', async () => {
  answer({ message: 'manual window' }, 'error')
  answer(snapshot({ phase: 'verifying', operation: 'manual_maintenance' }))
  answer({ phase: 'inactive' })

  await expect(openProtectedModeIfAny()).resolves.toBeUndefined()

  expect(requests).toEqual([
    { command: 'test:protected-mode:open', payload: {} },
    { command: 'protected-mode:inspect', payload: {} },
    { command: 'maintenance:disable', payload: {} },
  ])
})

it('has nothing to disable after a refusal when inspect says inactive', async () => {
  answer({ message: 'no freeze' }, 'error')
  answer(snapshot({ phase: 'inactive', operation: null }))

  await expect(openProtectedModeIfAny()).resolves.toBeUndefined()

  expect(net.connect).toHaveBeenCalledTimes(2)
})

it('lets a failed teardown inspect fail the caller', async () => {
  const lost = new Error('inspect failed')
  answer({ message: 'manual window' }, 'error')
  loseReply(lost)

  await expect(openProtectedModeIfAny()).rejects.toBe(lost)

  expect(net.connect).toHaveBeenCalledTimes(2)
})

it('lets an answered disable refusal fail the teardown', async () => {
  answer({ message: 'manual window' }, 'error')
  answer(snapshot({ phase: 'verifying', operation: 'manual_maintenance' }))
  answer({ message: 'disable refused' }, 'error')

  const reply = openProtectedModeIfAny()

  await expect(reply).rejects.toBeInstanceOf(ProtectedModeCommandRefused)
  await expect(reply).rejects.toThrow(
    'maintenance:disable failed: disable refused',
  )
  expect(net.connect).toHaveBeenCalledTimes(3)
})

it('lets an unknown disable outcome fail the teardown after one re-ask', async () => {
  answer({ message: 'manual window' }, 'error')
  answer(snapshot({ phase: 'verifying', operation: 'manual_maintenance' }))
  loseReply()
  loseReply()

  await expect(openProtectedModeIfAny()).rejects.toThrow(
    'maintenance:disable was not answered, and protected-mode:inspect was not answered either',
  )

  expect(requests.map(({ command }) => command)).toEqual([
    'test:protected-mode:open',
    'protected-mode:inspect',
    'maintenance:disable',
    'protected-mode:inspect',
  ])
  expect(net.connect).toHaveBeenCalledTimes(4)
})

it('keeps an explicit cluster host and the full snapshot through the transport seam', async () => {
  vi.stubEnv('COMMAND_HOST', undefined)
  const state = snapshot({
    phase: 'verifying',
    circleSize: 4,
    circleAdmitted: 2,
  })
  answer(state)

  await expect(inspectProtectedMode(undefined, 'node-b.test')).resolves.toEqual(
    state,
  )

  expect(net.connect).toHaveBeenCalledWith(9000, 'node-b.test')
  expect(commandChannel.createCommandChannel).toHaveBeenCalledWith(
    expect.objectContaining({
      host: 'node-b.test',
      port: 9000,
      timeoutMs: 15_000,
    }),
  )
  expect(requests).toEqual([{ command: 'protected-mode:inspect', payload: {} }])
})

it('preserves a custom inspect budget and the portable endpoint diagnostic', async () => {
  answer(snapshot())
  await inspectProtectedMode(321)
  expect(commandChannel.createCommandChannel).toHaveBeenCalledWith(
    expect.objectContaining({ host: 'command.test', timeoutMs: 321 }),
  )
  vi.stubEnv('COMMAND_HOST', '')

  await expect(inspectProtectedMode()).rejects.toThrow('COMMAND_HOST')
  expect(net.connect).toHaveBeenCalledTimes(1)
})

it('validates the shared port even with an explicit cluster host', async () => {
  vi.stubEnv('COMMAND_PORT', '9000junk')

  await expect(inspectProtectedMode(undefined, 'node-b.test')).rejects.toThrow(
    'COMMAND_PORT must be an integer TCP port from 1 to 65535',
  )
  expect(net.connect).not.toHaveBeenCalled()
})

it.each([
  ['COMMAND_HOST', ''],
  ['COMMAND_PORT', '9000junk'],
] as const)(
  'keeps %s configuration failures distinct from a lost reply',
  async (name, value) => {
    vi.stubEnv(name, value)

    for (const send of [
      () => enterProtectedMode(OPERATION),
      mintProtectedModePass,
    ]) {
      const reply = send()
      await expect(reply).rejects.toBeInstanceOf(StandCommandEndpointError)
      await expect(reply).rejects.toThrow(name)
    }
    expect(net.connect).not.toHaveBeenCalled()
    expect(commandChannel.createCommandChannel).not.toHaveBeenCalled()
  },
)

/** A full master snapshot; individual tests change only facts their orchestration needs. */
function snapshot(
  fields: Partial<ProtectedModeSnapshot> = {},
): ProtectedModeSnapshot {
  return {
    rtMounted: true,
    phase: 'active',
    operation: OPERATION,
    initiatorAgentType: 'test-driver',
    stoppedAgents: ['library'],
    agentStartGateClosed: true,
    passCount: 0,
    circleSize: 0,
    circleAdmitted: 0,
    ...fields,
  }
}

/** Queue one socket outcome at the existing transport seam, without a real connection. */
function nextConnection(outcome: (socket: net.Socket) => void): void {
  vi.mocked(net.connect).mockImplementationOnce(() => {
    const socket = new net.Socket()
    sockets.push(socket)
    vi.spyOn(socket, 'write').mockImplementation((chunk) => {
      const { command, payload } = JSON.parse(String(chunk))
      requests.push({ command, payload })
      return true
    })
    queueMicrotask(() => {
      socket.emit('connect')
      outcome(socket)
    })
    return socket
  })
}

/** Answer the next command with the unchanged command-channel framing. */
function answer(payload: object, status = 'ok'): void {
  nextConnection((socket) => {
    socket.emit('data', Buffer.from(JSON.stringify({ status, payload }) + '\n'))
  })
}

/** Lose the next command's reply at the transport boundary. */
function loseReply(error = new Error('command reply lost')): void {
  nextConnection((socket) => socket.emit('error', error))
}
