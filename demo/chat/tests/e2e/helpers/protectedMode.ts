import net from 'node:net'
import { randomBytes } from 'node:crypto'

// The daemon command channel — the same socket the CLI test:protected-mode:*
// commands speak. The Playwright runner has no PHP, so the e2e drives the freeze
// over the wire directly; this still exercises the real CommandServer parking,
// the agent-side driver, and the one entry the mode has (the initiator agent
// asking its daemon), because nothing here forces any state.
const COMMAND_HOST = process.env.COMMAND_HOST ?? 'chat-test'
const COMMAND_PORT = Number(process.env.COMMAND_PORT ?? 8094)

// Comfortably past the agent's own wait window, so a freeze that never takes
// hold comes back as the agent's stated reason rather than as a socket timeout —
// which is the whole reason that window is the innermost of the three.
const REPLY_TIMEOUT_MS = 10_000

const ENTER_COMMAND = 'test:protected-mode:enter'
const LEAVE_COMMAND = 'test:protected-mode:leave'
const OPEN_COMMAND = 'test:protected-mode:open'
const INSPECT_COMMAND = 'test:protected-mode:inspect'

/** This node's protected-mode state, as the master reports it. */
export interface ProtectedModeSnapshot {
  rtMounted: boolean
  phase: string
  operation: string | null
  initiatorAgentType: string | null
  stoppedAgents: string[]
  agentStartGateClosed: boolean
  passCount: number
}

/**
 * Takes the installation into protected mode through the live initiator agent.
 *
 * Resolves once the freeze has actually taken hold — the agent answers from its
 * ready hook — so a caller may assert on the frozen app immediately, without
 * polling for the state to arrive.
 *
 * @param operation Operation name the freeze protects, carried to the browser.
 * @param acceptKey Connection accept key to keep live through the lockdown;
 *   omitted (the default) means every browser connection is locked out.
 * @returns The phase the agent observed.
 */
export async function enterProtectedMode(
  operation: string,
  acceptKey = '',
): Promise<string> {
  const reply = await sendCommand(ENTER_COMMAND, { operation, acceptKey })

  return String(reply.phase ?? '')
}

/**
 * Ends the driven operation, landing the node in the verification window.
 *
 * Deliberately not an unlock: a finished operation leaves the system closed to
 * everyone, and opening it is the separate step below — the same two moves an
 * operator makes in production, where nothing reopens a restored database
 * without a human having looked at it.
 *
 * Resolves once this node's runtime row reads verifying.
 *
 * @returns The phase the agent observed.
 */
export async function leaveProtectedMode(): Promise<string> {
  const reply = await sendCommand(LEAVE_COMMAND, {})

  return String(reply.phase ?? '')
}

/**
 * Opens the system to everyone, through the agent that entered the freeze.
 *
 * Resolves once this node's runtime row is back to inactive, so a caller may
 * load a page on the next line without racing the agents coming back up.
 *
 * @returns The phase the agent observed.
 */
export async function openProtectedMode(): Promise<string> {
  const reply = await sendCommand(OPEN_COMMAND, {})

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
    await openProtectedMode()
  } catch {
    // Nothing to lift, which is the state the teardown wanted anyway.
  }
}

/**
 * Reads this node's protected-mode state from the master.
 *
 * Answered by the daemon itself rather than by an agent, so it keeps answering
 * mid-freeze — when every agent but the initiator is stopped.
 */
export async function inspectProtectedMode(): Promise<ProtectedModeSnapshot> {
  return (await sendCommand(INSPECT_COMMAND, {})) as unknown as ProtectedModeSnapshot
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
      reject(new Error(`No command-channel reply to ${command} within ${REPLY_TIMEOUT_MS}ms`))
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
          new Error(`${command} failed: ${reply.payload?.message ?? 'unknown error'}`),
        )
      }
    })
    socket.on('error', (error) => {
      clearTimeout(timer)
      reject(error)
    })
  })
}
