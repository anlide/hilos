import net, { type Server, type Socket } from 'node:net'
import { afterEach, expect, it } from 'vitest'

import { createCommandChannel } from './commandChannel.mjs'

const HOST = '127.0.0.1'
const TEST_TIMEOUT_MS = 1_000

let server: Server | undefined
const sockets = new Set<Socket>()

afterEach(async () => {
  await closeServer()
})

it('resolves the payload of an ok reply', async () => {
  const port = await listen((socket) => {
    socket.end('{"status":"ok","payload":{"answer":42}}\n')
  })

  await expect(sendTo(port)('test:answer', {})).resolves.toEqual({ answer: 42 })
})

it('accumulates a reply split before its newline', async () => {
  const port = await listen((socket) => {
    socket.write('{"status":"ok","payload":{"answer":')
    socket.end('42}}\n')
  })

  await expect(sendTo(port)('test:split', {})).resolves.toEqual({ answer: 42 })
})

it('parses only the first reply line', async () => {
  const port = await listen((socket) => {
    socket.end(
      '{"status":"ok","payload":{"answer":"first"}}\n' +
        '{"status":"ok","payload":{"answer":"second"}}\n',
    )
  })

  await expect(sendTo(port)('test:first', {})).resolves.toEqual({
    answer: 'first',
  })
})

it('rejects an error reply with its command and message', async () => {
  const port = await listen((socket) => {
    socket.end('{"status":"error","payload":{"message":"refused"}}\n')
  })

  await expect(sendTo(port)('test:refusal', {})).rejects.toThrow(
    'test:refusal failed: refused',
  )
})

it("uses the caller's refusal class", async () => {
  class CommandRefused extends Error {}

  const port = await listen((socket) => {
    socket.end('{"status":"error","payload":{"message":"refused"}}\n')
  })
  const sendCommand = createCommandChannel({
    host: HOST,
    port,
    timeoutMs: TEST_TIMEOUT_MS,
    refuse: (message) => new CommandRefused(message),
  })

  await expect(sendCommand('test:typed-refusal', {})).rejects.toBeInstanceOf(
    CommandRefused,
  )
})

it('rejects a silent channel when its reply window ends', async () => {
  const port = await listen(() => undefined)
  const sendCommand = createCommandChannel({
    host: HOST,
    port,
    timeoutMs: 50,
  })

  await expect(sendCommand('test:silence', {})).rejects.toThrow(
    'No command-channel reply to test:silence within 50ms',
  )
})

it("passes through the socket's connection error", async () => {
  const port = await listen(() => undefined)
  await closeServer()

  await expect(sendTo(port)('test:closed', {})).rejects.toMatchObject({
    code: 'ECONNREFUSED',
  })
})

it('writes one line and gives each call a different correlation id', async () => {
  const requests: string[] = []
  const port = await listen((socket) => {
    let request = ''
    socket.on('data', (chunk: Buffer) => {
      request += chunk.toString()
      if (!request.includes('\n')) {
        return
      }
      requests.push(request)
      socket.end('{"status":"ok","payload":{}}\n')
    })
  })
  const sendCommand = sendTo(port)

  await sendCommand('test:first', { value: 1 })
  await sendCommand('test:second', { value: 2 })

  expect(requests).toHaveLength(2)
  expect(requests.every((request) => request.endsWith('\n'))).toBe(true)
  expect(
    requests.every((request) => request.indexOf('\n') === request.length - 1),
  ).toBe(true)
  const [first, second] = requests.map((request) => JSON.parse(request))
  expect(first.correlationId).not.toBe(second.correlationId)
})

/** Opens the local server used by one test and returns its assigned port. */
async function listen(onConnection: (socket: Socket) => void): Promise<number> {
  server = net.createServer((socket) => {
    sockets.add(socket)
    socket.on('close', () => sockets.delete(socket))
    onConnection(socket)
  })
  await new Promise<void>((resolve) => server?.listen(0, HOST, resolve))
  const address = server.address()
  if (address === null || typeof address === 'string') {
    throw new Error('test command channel did not receive a TCP address')
  }

  return address.port
}

/** Closes the current test server, if one is listening. */
async function closeServer(): Promise<void> {
  if (server === undefined || !server.listening) {
    server = undefined
    return
  }

  const closing = new Promise<void>((resolve, reject) => {
    server?.close((error) => (error === undefined ? resolve() : reject(error)))
  })
  for (const socket of sockets) {
    socket.destroy()
  }
  await closing
  server = undefined
}

/** Binds the usual test address and reply window. */
function sendTo(port: number) {
  return createCommandChannel({ host: HOST, port, timeoutMs: TEST_TIMEOUT_MS })
}
