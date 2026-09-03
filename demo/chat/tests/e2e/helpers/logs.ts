import net from 'node:net'
import { randomBytes } from 'node:crypto'

import {
  deriveTimeoutScale,
  readHostPressure,
} from '../../../../../framework/frontend/scripts/timeout-scale.mjs'

// The daemon command channel — the same socket the CLI test:log:append command
// speaks. The Playwright runner has no PHP, so the e2e asks over the wire
// directly; the lines are still written by the log-store agent the ordinary way,
// so the master files them under the agent's own log and a follower sees exactly
// what it would see in production. Appending to the file from here instead would
// prove only that a file grew.
//
// The round-trip is a fourth local copy of the one in adminGrant.ts,
// notifications.ts and protectedMode.ts on purpose: a shared helper would have
// meant editing three files this leaf knows nothing about. The extraction is
// filed as a proposal instead.
const COMMAND_HOST = process.env.COMMAND_HOST ?? 'chat-test'
const COMMAND_PORT = Number(process.env.COMMAND_PORT ?? 8094)

// Past the append's own work — the agent writes the lines synchronously, and the
// master files each of them — so a slow box comes back as the agent's answer
// rather than as a socket timeout.
const REPLY_TIMEOUT_MS = 10_000

const APPEND_COMMAND = 'test:log:append'

/**
 * The cap a wait on an appended line reaching the screen gets, in milliseconds.
 *
 * Its own cap rather than the config's `expect`, for the reason a wait on an
 * intercepted letter has one: the chain is the longest this suite waits on, and
 * none of it is DOM work. The agent prints the line, the master lifts it out of
 * the worker's output and files it, the owner of the directory notices the file
 * grew on its once-a-second round, and only then does a frame cross the socket.
 * `expect` is the shortest cap there is and describes something else entirely.
 *
 * Scaled by the same host-pressure factor every Playwright cap is, so a starved
 * box makes this slower rather than red.
 */
export const TAIL_ARRIVAL_TIMEOUT_MS =
  15_000 *
  deriveTimeoutScale({
    override: process.env.HILOS_E2E_TIMEOUT_SCALE,
    ...readHostPressure(),
  }).factor

/**
 * The stream the appended lines land in.
 *
 * Written out rather than read from the reply because the rule behind it — an
 * agent's log file is `agent-` plus its agent id — is private to WorkerServer,
 * and putting it on the wire for a test's sake would declare an internal rule a
 * contract.
 */
export const FOLLOWED_STREAM = 'agent-hilos_log_store.log'

/**
 * Asks the live daemon's log-store agent to write lines into its own log.
 *
 * The agent numbers them, so lines of one call can be told apart from each other
 * and from the lines an earlier call with the same message left behind — the log
 * file outlives a test run.
 *
 * @param message Text of every appended line, which the agent numbers.
 * @param count How many lines to write.
 * @returns How many lines the agent reports writing.
 */
export async function appendLogLines(
  message: string,
  count: number,
): Promise<number> {
  const reply = await sendCommand(APPEND_COMMAND, { message, count })

  return Number(reply.count)
}

/**
 * Mints a marker no other run and no other line of this one can carry.
 *
 * @param label Human-readable prefix, so a stray line names the test that wrote it.
 * @returns The marker.
 */
export function logMarker(label: string): string {
  return `${label}-${randomBytes(6).toString('hex')}`
}

/**
 * Sends one command over the daemon command channel and resolves its payload.
 *
 * @param command Command-channel wire name.
 * @param payload Request payload.
 * @returns The reply payload on success.
 */
function sendCommand(
  command: string,
  payload: Record<string, unknown>,
): Promise<Record<string, unknown>> {
  return new Promise((resolve, reject) => {
    const request =
      JSON.stringify({
        correlationId: randomBytes(8).toString('hex'),
        command,
        payload,
      }) + '\n'

    const socket = net.connect(COMMAND_PORT, COMMAND_HOST)
    let buffer = ''

    const timer = setTimeout(() => {
      socket.destroy()
      reject(
        new Error(
          `No command-channel reply to ${command} within ${REPLY_TIMEOUT_MS}ms`,
        ),
      )
    }, REPLY_TIMEOUT_MS)

    socket.on('connect', () => {
      socket.write(request)
    })
    socket.on('data', (chunk: Buffer) => {
      buffer += chunk.toString()
      const newline = buffer.indexOf('\n')
      if (newline === -1) {
        return
      }
      clearTimeout(timer)
      socket.destroy()

      const reply = JSON.parse(buffer.slice(0, newline)) as {
        status?: string
        payload?: Record<string, unknown> & { message?: string }
      }
      if (reply.status === 'ok') {
        resolve(reply.payload ?? {})
      } else {
        reject(
          new Error(
            `${command} failed: ${reply.payload?.message ?? 'unknown error'}`,
          ),
        )
      }
    })
    socket.on('error', (error) => {
      clearTimeout(timer)
      reject(error)
    })
  })
}
