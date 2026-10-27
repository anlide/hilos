import {
  createStandCommandChannel,
  StandCommandEndpointError,
} from './standCommand.mjs'
import { reAskProtectedMode } from './protectedModeReAsk.mjs'

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
const MAINTENANCE_DISABLE_COMMAND = 'maintenance:disable'
const MANUAL_MAINTENANCE_OPERATION = 'manual_maintenance'

/**
 * A refusal the daemon answered with, as opposed to a command it never answered.
 *
 * The distinction is the whole point of {@link openProtectedModeIfAny}: "there is no freeze
 * here" is an answer and means the teardown has nothing left to do, while a timeout or a dead
 * socket means the node never said it was open. Those two must not share a catch.
 */
export class ProtectedModeCommandRefused extends Error {}

/**
 * This node's protected-mode state, as the master reports it.
 *
 * @typedef {object} ProtectedModeSnapshot
 * @property {boolean} rtMounted Whether protected-mode runtime state is mounted.
 * @property {string} phase This node's protected-mode phase.
 * @property {string | null} operation Operation protected by the freeze.
 * @property {string | null} initiatorAgentType Agent driving the freeze.
 * @property {string[]} stoppedAgents Agents stopped by this node.
 * @property {boolean} agentStartGateClosed Whether new agents are held back.
 * @property {number} passCount Number of passes held by the node, never their clear keys.
 * @property {number} circleSize Number of verifiers photographed at the freeze.
 * @property {number} circleAdmitted Number of admitted verifiers.
 */

const sendCommand = createStandCommandChannel({
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
 * @param {string} operation Operation name the freeze protects, carried to the browser.
 * @param {string} [acceptKey] Connection accept key to keep live through the lockdown;
 *   omitted (the default) means every browser connection is locked out.
 * @param {string} [sessionToken] Session cookie of the browser the freeze is entered on
 *   behalf of; every tab carrying it stays inside for as long as the freeze
 *   holds, reloads and tabs opened afterwards included. Omitted means the same
 *   as an omitted accept key: nothing with a browser asked.
 * @returns {Promise<string>} The phase the agent observed.
 */
export async function enterProtectedMode(
  operation,
  acceptKey = '',
  sessionToken = '',
) {
  const reply = await sendProtectedModeDrive(
    ENTER_COMMAND,
    { operation, acceptKey, sessionToken },
    operation,
    ['active', 'verifying', 'deactivating'],
  )

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
 * @returns {Promise<string>} The phase the agent observed.
 */
export async function leaveProtectedMode() {
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
 * @returns {Promise<string>} The phase the agent observed.
 */
export async function openProtectedMode() {
  const reply = await sendProtectedModeDrive(OPEN_COMMAND, {}, null, [
    'inactive',
  ])

  return String(reply.phase ?? '')
}

/** Enter the manual window through the production command channel, with no browser initiator. */
export async function enableManualMaintenance() {
  const reply = await sendCommand('maintenance:enable', {})

  return String(reply.phase)
}

/** Mint a code through the production manual-window command, never the test freeze family. */
export async function mintManualMaintenancePass() {
  const reply = await sendCommand('maintenance:pass', {})
  if (typeof reply.pass !== 'string' || reply.pass === '') {
    throw new Error('maintenance:pass returned no code')
  }

  return reply.pass
}

/** Disable manual maintenance through its existing CLI command, for cleanup. */
export async function disableManualMaintenance() {
  const reply = await sendProtectedModeDrive(
    MAINTENANCE_DISABLE_COMMAND,
    {},
    null,
    ['inactive'],
  )

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
 * @returns {Promise<string>} The phase the agent observed.
 */
export async function closeProtectedMode() {
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
 * @returns {Promise<string>} The clear pass, which exists nowhere else.
 */
export async function mintProtectedModePass() {
  /** @type {Record<string, unknown>} */
  let reply
  try {
    reply = await sendCommand(PASS_COMMAND, {})
  } catch (error) {
    if (
      error instanceof ProtectedModeCommandRefused ||
      error instanceof StandCommandEndpointError
    ) {
      throw error
    }

    const outcome = await reAskProtectedMode(
      () => inspectProtectedMode(RE_ASK_TIMEOUT_MS),
      { operation: null, takenPhases: [] },
    )
    if (outcome.verdict === 'unknown') {
      throw new Error(
        `${PASS_COMMAND} was not answered, and ${INSPECT_COMMAND} was not answered either; ` +
          'whether a pass was minted is unknown',
        { cause: error },
      )
    }

    const snapshot = /** @type {ProtectedModeSnapshot} */ (outcome.snapshot)
    throw new Error(
      `${PASS_COMMAND} was not answered; the node now holds ${snapshot.passCount} passes, ` +
        'but a pass exists only in the reply that was lost - mint another',
      { cause: error },
    )
  }

  return String(reply.pass ?? '')
}

/**
 * Opens the system, swallowing a refusal.
 *
 * For unconditional teardown: an enter may have been refused, or may have landed
 * after the test gave up on it, and either way the node must not be left frozen
 * for every spec that follows. The open lifts from any phase of a driven freeze for
 * exactly this reason — a teardown cannot know which one a failed assertion left
 * behind. A manual window it refuses by design
 * (framework/backend/Core/Agent/ProtectedModeTestDriverTrait.php:342-346), so after a
 * refusal the teardown asks the master and turns a manual window off with its
 * production command; any other refusal means there was nothing to lift.
 */
export async function openProtectedModeIfAny() {
  try {
    await openProtectedMode()
  } catch (error) {
    if (error instanceof ProtectedModeCommandRefused) {
      // Answered. The refusal may stand for a manual window, which the test open never
      // drives, so the master is asked and a window it reports is turned off; any other
      // refusal means there is nothing to lift - the state the teardown wanted anyway.
      // A failed inspect or disable goes red here, in the spec that left the window.
      const snapshot = await inspectProtectedMode()
      if (
        snapshot.phase !== 'inactive' &&
        snapshot.operation === MANUAL_MAINTENANCE_OPERATION
      ) {
        await disableManualMaintenance()
      }
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
 * @param {number} [timeoutMs] How long to wait for the master reply.
 * @param {string} [host] Explicit cluster node; omitted uses the stand endpoint.
 * @returns {Promise<ProtectedModeSnapshot>} This node's protected-mode snapshot.
 */
export async function inspectProtectedMode(timeoutMs = REPLY_TIMEOUT_MS, host) {
  const inspect = createStandCommandChannel({
    host,
    timeoutMs,
    refuse: (message) => new ProtectedModeCommandRefused(message),
  })

  return /** @type {ProtectedModeSnapshot} */ (
    await inspect(INSPECT_COMMAND, {})
  )
}

/**
 * Sends one protected-mode drive, then asks this node once when its reply was not an answer.
 *
 * @param {string} command Drive command name.
 * @param {Record<string, unknown>} payload Drive payload.
 * @param {string | null} operation Operation the drive named, or null.
 * @param {string[]} takenPhases Phases that prove the drive happened.
 * @returns {Promise<Record<string, unknown>>} The drive reply or the recovered state snapshot.
 */
async function sendProtectedModeDrive(
  command,
  payload,
  operation,
  takenPhases,
) {
  try {
    return await sendCommand(command, payload)
  } catch (error) {
    if (
      error instanceof ProtectedModeCommandRefused ||
      error instanceof StandCommandEndpointError
    ) {
      throw error
    }

    const outcome = await reAskProtectedMode(
      () => inspectProtectedMode(RE_ASK_TIMEOUT_MS),
      { operation, takenPhases },
    )
    if (outcome.verdict === 'taken') {
      return /** @type {Record<string, unknown>} */ (outcome.snapshot)
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
 * @param {string} command Unanswered drive command.
 * @param {'underWay' | 'notTaken' | 'unknown'} verdict Re-ask verdict.
 * @param {object} rawSnapshot Snapshot behind the verdict.
 * @param {string | null} operation Operation the drive named, or null.
 * @returns {Error} Error carrying the state verdict.
 */
function protectedModeReAskError(command, verdict, rawSnapshot, operation) {
  if (verdict === 'unknown') {
    return new Error(
      `${command} was not answered, and ${INSPECT_COMMAND} was not answered either; ` +
        'whether the command was taken is unknown',
    )
  }

  const snapshot = /** @type {ProtectedModeSnapshot} */ (rawSnapshot)
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
