import { createCommandChannel } from '../../../../../framework/frontend/scripts/commandChannel.mjs'
import { reAskProtectedMode } from '../../../../../framework/frontend/scripts/protectedModeReAsk.mjs'

// The daemon command channel — the same socket the CLI test:protected-mode:*
// commands speak. The Playwright runner has no PHP, so the e2e drives the freeze
// over the wire directly; this still exercises the real CommandServer parking,
// the agent-side driver, and the one entry the mode has (the initiator agent
// asking its daemon), because nothing here forces any state.
//
// Narrower than the chat and polls peers on purpose: this demo has one
// protected-mode case, and it needs the freeze on and the freeze off. Inspect is
// present only because either drive may need one state re-ask after a lost reply;
// leave and mint wait for the first spec that has something to assert with them.
const COMMAND_HOST = process.env.COMMAND_HOST ?? 'tasks-daemon-test'
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
const OPEN_COMMAND = 'test:protected-mode:open'
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

const sendCommand = createCommandChannel({
  host: COMMAND_HOST,
  port: COMMAND_PORT,
  timeoutMs: REPLY_TIMEOUT_MS,
  refuse: (message) => new ProtectedModeCommandRefused(message),
})

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
  const reply = await sendProtectedModeDrive(
    ENTER_COMMAND,
    { operation, acceptKey: '' },
    operation,
    ['active', 'verifying', 'deactivating'],
  )

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
    await sendProtectedModeDrive(OPEN_COMMAND, {}, null, ['inactive'])
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
  const inspect = createCommandChannel({
    host: COMMAND_HOST,
    port: COMMAND_PORT,
    timeoutMs,
    refuse: (message) => new ProtectedModeCommandRefused(message),
  })

  return (await inspect(INSPECT_COMMAND, {})) as unknown as ProtectedModeSnapshot
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
