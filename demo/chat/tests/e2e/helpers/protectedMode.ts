import net from 'node:net'
import { randomBytes } from 'node:crypto'
import type { BrowserContext } from '@playwright/test'

import { reAskProtectedMode } from '../../../../../framework/frontend/scripts/protectedModeReAsk.mjs'
import { isSessionCookie } from './session'

// The daemon command channel — the same socket the CLI test:protected-mode:*
// commands speak. The Playwright runner has no PHP, so the e2e drives the freeze
// over the wire directly; this still exercises the real CommandServer parking,
// the agent-side driver, and the one entry the mode has (the initiator agent
// asking its daemon), because nothing here forces any state.
const COMMAND_HOST = process.env.COMMAND_HOST ?? 'chat-test'
const COMMAND_PORT = Number(process.env.COMMAND_PORT ?? 8094)

// The MIDDLE of the command channel's three nested windows: how long the side
// that asked is willing to wait. It is a hand-kept copy of
// CommandChannelWindows::CALLER_WAIT_SECONDS, and a copy because it has to be —
// Playwright has no PHP to read the original with, it reaches the command port
// over TCP. Inside it sits AGENT_WAIT_SECONDS (13.0), which is what makes a
// freeze that never takes hold come back as the agent's stated reason rather
// than as a socket timeout; outside it sits CHANNEL_HELD_SECONDS (30.0). Change
// this and the PHP constant together, or the order stops holding.
const REPLY_TIMEOUT_MS = 15_000

// How long the re-ask waits, hand-kept copy of CommandChannelWindows::RE_ASK_WAIT_SECONDS.
// Not a fourth nested window: the master answers this one on its accept path, so it waits
// for a live process rather than for an agent. See that class for why it is 5 and not 15.
const RE_ASK_TIMEOUT_MS = 5_000

const ENTER_COMMAND = 'test:protected-mode:enter'
const LEAVE_COMMAND = 'test:protected-mode:leave'
const OPEN_COMMAND = 'test:protected-mode:open'
const CLOSE_COMMAND = 'test:protected-mode:close'
const PASS_COMMAND = 'test:protected-mode:pass'
const INSPECT_COMMAND = 'protected-mode:inspect'

/**
 * A refusal the daemon answered with, as opposed to a command it never answered.
 *
 * The distinction is the whole point of {@link openProtectedModeIfAny}: "there is no freeze
 * here" is an answer and means the teardown has nothing left to do, while a timeout or a dead
 * socket means the node never said it was open. Those two must not share a catch.
 */
export class ProtectedModeCommandRefused extends Error {}

/** This node's protected-mode state, as the master reports it. */
export interface ProtectedModeSnapshot {
  rtMounted: boolean
  phase: string
  operation: string | null
  initiatorAgentType: string | null
  stoppedAgents: string[]
  agentStartGateClosed: boolean
  passCount: number
  circleSize: number
  circleAdmitted: number
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
 * @param sessionToken Session cookie of the browser the freeze is entered on
 *   behalf of; every tab carrying it stays inside for as long as the freeze
 *   holds, reloads and tabs opened afterwards included. Omitted means the same
 *   as an omitted accept key: nothing with a browser asked.
 * @returns The phase the agent observed.
 */
export async function enterProtectedMode(
  operation: string,
  acceptKey = '',
  sessionToken = '',
): Promise<string> {
  const reply = await sendProtectedModeDrive(
    ENTER_COMMAND,
    { operation, acceptKey, sessionToken },
    operation,
    ['active', 'verifying', 'deactivating'],
  )

  return String(reply.phase ?? '')
}

/**
 * Reads the session cookie a browser context carries — the value the freeze
 * names its initiator by.
 *
 * A browser never learns its own accept key (the welcome frame carries none),
 * so the cookie is the only handle a spec has on "this browser". It is minted
 * on the first 101, so the context must have been connected once already.
 *
 * @param context The Playwright browser context to read the jar of.
 * @returns The session token, or an empty string when the stand issued none.
 */
export async function sessionTokenOf(context: BrowserContext): Promise<string> {
  const cookies = await context.cookies()

  return cookies.find((cookie) => isSessionCookie(cookie.name))?.value ?? ''
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
  const reply = await sendProtectedModeDrive(LEAVE_COMMAND, {}, null, [
    'verifying',
  ])

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
  const reply = await sendProtectedModeDrive(OPEN_COMMAND, {}, null, [
    'inactive',
  ])

  return String(reply.phase ?? '')
}

/**
 * Closes the system back from the verification window, through the agent that entered the freeze.
 *
 * The window's other exit, and the one an operator takes when the verifiers found
 * something wrong: every pass is voided, this node's agents are stopped again, the
 * maintenance screen comes back for the verifiers too, and another destructive
 * operation may run.
 *
 * Not for teardown — it is refused outside the window, which is why
 * {@link openProtectedModeIfAny} is the unconditional one. Resolves once this
 * node's runtime row reads active, so a caller may assert on the re-frozen app
 * without polling for the transition to arrive.
 *
 * @returns The phase the agent observed.
 */
export async function closeProtectedMode(): Promise<string> {
  const reply = await sendProtectedModeDrive(CLOSE_COMMAND, {}, null, [
    'active',
  ])

  return String(reply.phase ?? '')
}

/**
 * Mints one pass into the driven verification window and returns the clear key.
 *
 * The test path's own name for a mint the operator's command owns in production:
 * a command routes to exactly one agent type, that one belongs to the agent that
 * runs real operations, and a freeze may only be driven by the agent the row
 * records as its initiator — which here is the test driver's carrier. Behind both
 * names is the same handler, so what comes back is a real pass on a real row.
 *
 * Resolves once the hash is on the row, so a caller may type the key on the next
 * line without polling — and an assertion about the code field appearing is about
 * a bit that is already true.
 *
 * @returns The clear pass, which exists nowhere else.
 */
export async function mintProtectedModePass(): Promise<string> {
  let reply: Record<string, unknown>
  try {
    reply = await sendCommand(PASS_COMMAND, {})
  } catch (error) {
    if (error instanceof ProtectedModeCommandRefused) throw error

    const outcome = await reAskProtectedMode(
      () => inspectProtectedMode(RE_ASK_TIMEOUT_MS),
      { operation: null, takenPhases: [] },
    )
    if (outcome.verdict === 'unknown') {
      throw new Error(
        `${PASS_COMMAND} was not answered, and ${INSPECT_COMMAND} was not answered either; ` +
          'whether a pass was minted is unknown',
      )
    }

    const snapshot = outcome.snapshot as ProtectedModeSnapshot
    throw new Error(
      `${PASS_COMMAND} was not answered; the node now holds ${snapshot.passCount} passes, ` +
        'but a pass exists only in the reply that was lost - mint another',
    )
  }

  return String(reply.pass ?? '')
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
  } catch (error) {
    if (error instanceof ProtectedModeCommandRefused) {
      // Answered, and the answer was that there is nothing to lift - the state the teardown
      // wanted anyway.
      return
    }
    // Anything else is the opposite of "nothing to lift". A freeze left standing takes the
    // whole node with it, so the spec that left it has to be the one that goes red: swallowed
    // here, the failure surfaces in whatever spec runs next and looks like that one's defect.
    throw error
  }
}

/**
 * Reads this node's protected-mode state from the master.
 *
 * Answered by the daemon itself rather than by an agent, so it keeps answering
 * mid-freeze — when every agent but the initiator is stopped.
 *
 * @param timeoutMs How long to wait for the master reply.
 * @returns This node's protected-mode snapshot.
 */
export async function inspectProtectedMode(
  timeoutMs = REPLY_TIMEOUT_MS,
): Promise<ProtectedModeSnapshot> {
  return (await sendCommand(
    INSPECT_COMMAND,
    {},
    timeoutMs,
  )) as unknown as ProtectedModeSnapshot
}

/**
 * Sends one protected-mode drive, then asks this node once when its reply was not an answer.
 *
 * @param command Drive command name.
 * @param payload Drive payload.
 * @param operation Operation the drive named, or null.
 * @param takenPhases Phases that prove the drive happened.
 * @returns The drive reply or the recovered state snapshot.
 */
async function sendProtectedModeDrive(
  command: string,
  payload: Record<string, unknown>,
  operation: string | null,
  takenPhases: string[],
): Promise<Record<string, unknown>> {
  try {
    return await sendCommand(command, payload)
  } catch (error) {
    if (error instanceof ProtectedModeCommandRefused) throw error

    const outcome = await reAskProtectedMode(
      () => inspectProtectedMode(RE_ASK_TIMEOUT_MS),
      { operation, takenPhases },
    )
    if (outcome.verdict === 'taken') {
      return outcome.snapshot as unknown as Record<string, unknown>
    }

    throw protectedModeReAskError(
      command,
      outcome.verdict,
      outcome.snapshot,
      operation,
    )
  }
}

/**
 * Names what the single re-ask learned instead of collapsing it into a failed drive.
 *
 * @param command Unanswered drive command.
 * @param verdict Re-ask verdict.
 * @param rawSnapshot Snapshot behind the verdict.
 * @param operation Operation the drive named, or null.
 * @returns Error carrying the state verdict.
 */
function protectedModeReAskError(
  command: string,
  verdict: 'underWay' | 'notTaken' | 'unknown',
  rawSnapshot: object,
  operation: string | null,
): Error {
  if (verdict === 'unknown') {
    return new Error(
      `${command} was not answered, and ${INSPECT_COMMAND} was not answered either; ` +
        'whether the command was taken is unknown',
    )
  }

  const snapshot = rawSnapshot as ProtectedModeSnapshot
  if (verdict === 'underWay') {
    return new Error(
      `${command} was not answered; the node reads '${snapshot.phase}' - ` +
        'the freeze is under way but has not taken hold',
    )
  }
  if (!snapshot.rtMounted) {
    return new Error(
      `${command} was not answered; this node has no protected mode`,
    )
  }
  if (operation !== null && snapshot.operation !== operation) {
    return new Error(
      `${command} was not answered; the node is frozen for '${snapshot.operation}', ` +
        `not '${operation}'`,
    )
  }

  return new Error(
    `${command} was not answered; the node reads '${snapshot.phase}', so the command was not taken`,
  )
}

/**
 * Sends one command over the daemon command channel and resolves its payload.
 *
 * @param command Command-channel wire name.
 * @param payload Request payload.
 * @param timeoutMs How long to wait for the reply.
 * @returns The reply payload on success.
 */
function sendCommand(
  command: string,
  payload: Record<string, unknown>,
  timeoutMs = REPLY_TIMEOUT_MS,
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
          `No command-channel reply to ${command} within ${timeoutMs}ms`,
        ),
      )
    }, timeoutMs)

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
          new ProtectedModeCommandRefused(
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
