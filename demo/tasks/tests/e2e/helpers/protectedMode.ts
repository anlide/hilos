import net from 'node:net'
import { randomBytes } from 'node:crypto'

// The daemon command channel — the same socket the CLI test:protected-mode:*
// commands speak. The Playwright runner has no PHP, so the e2e drives the freeze
// over the wire directly; this still exercises the real CommandServer parking,
// the agent-side driver, and the one entry the mode has (the initiator agent
// asking its daemon), because nothing here forces any state.
//
// Narrower than the chat and simple-poll peers on purpose: this demo has one
// protected-mode case, and it needs the freeze on and the freeze off. The rest
// of the command set (leave, mint, inspect) is added by the spec that first has
// something to assert with it.
const COMMAND_HOST = process.env.COMMAND_HOST ?? 'tasks-daemon-test'
const COMMAND_PORT = Number(process.env.COMMAND_PORT ?? 8094)

// Comfortably past the agent's own wait window, so a freeze that never takes
// hold comes back as the agent's stated reason rather than as a socket timeout —
// which is the whole reason that window is the innermost of the three.
const REPLY_TIMEOUT_MS = 10_000

const ENTER_COMMAND = 'test:protected-mode:enter'
const OPEN_COMMAND = 'test:protected-mode:open'

/**
 * Takes the installation into protected mode through the live initiator agent.
 *
 * Resolves once the freeze has actually taken hold — the agent answers from its
 * ready hook — so a caller may assert on the frozen app immediately, without
 * polling for the state to arrive.
 *
 * @param operation Operation name the freeze protects, carried to the browser.
 * @returns The phase the agent observed.
 */
export async function enterProtectedMode(operation: string): Promise<string> {
  const reply = await sendCommand(ENTER_COMMAND, { operation, acceptKey: '' })

  return String(reply.phase ?? '')
}

/**
 * Opens the system, swallowing a refusal.
 *
 * For unconditional teardown: an enter may have been refused, or may have landed
 * after the test gave up on it, and either way the node must not be left frozen
 * for every spec that follows. The open lifts from any frozen phase for exactly
 * this reason — a teardown cannot know which one a failed assertion left behind.
 * A refusal here means there was nothing to lift.
 */
export async function openProtectedModeIfAny(): Promise<void> {
  try {
    await sendCommand(OPEN_COMMAND, {})
  } catch {
    // Nothing to lift, which is the state the teardown wanted anyway.
  }
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
