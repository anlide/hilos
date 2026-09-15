// Read the one protected-mode snapshot fetched after a drive command went unanswered.
// The rule is shared by the three demo e2e helpers so a lost reply means the same thing
// in every frontend stack. It deliberately knows no socket, host, command name or retry:
// the caller supplies one inspect function, and this module invokes it at most once.

/**
 * @typedef {'taken' | 'underWay' | 'notTaken'} ProtectedModeVerdict
 */

/**
 * @typedef {object} ProtectedModeSnapshot
 * @property {boolean} rtMounted Whether this project mounted protected-mode runtime state
 * @property {string} phase This node's protected-mode phase
 * @property {string | null} operation Operation currently protected by the freeze
 */

/**
 * Reads what one protected-mode snapshot means to the caller whose drive reply was lost.
 *
 * @param {ProtectedModeSnapshot} snapshot This node's protected-mode snapshot.
 * @param {object} expectation Facts the unanswered drive command was waiting for.
 * @param {string | null} expectation.operation Operation it drove, or null when none.
 * @param {string[]} expectation.takenPhases Phases that mean the drive happened.
 * @returns {ProtectedModeVerdict} The fact read from this node's state.
 */
export function protectedModeVerdict(snapshot, { operation, takenPhases }) {
  if (snapshot.rtMounted !== true) return 'notTaken'
  if (operation !== null && snapshot.operation !== operation) return 'notTaken'
  if (takenPhases.includes(snapshot.phase)) return 'taken'
  if (snapshot.phase === 'activating') return 'underWay'
  return 'notTaken'
}

/**
 * Asks for protected-mode state once and reads the drive verdict from it.
 *
 * @param {() => Promise<ProtectedModeSnapshot>} inspect One state question supplied by the caller.
 * @param {object} expectation Facts the unanswered drive command was waiting for.
 * @param {string | null} expectation.operation Operation it drove, or null when none.
 * @param {string[]} expectation.takenPhases Phases that mean the drive happened.
 * @returns {Promise<{
 *   verdict: ProtectedModeVerdict | 'unknown',
 *   snapshot: ProtectedModeSnapshot | Record<string, never>,
 * }>} The state verdict, or unknown when the state question itself failed.
 */
export async function reAskProtectedMode(inspect, expectation) {
  try {
    const snapshot = await inspect()
    return { verdict: protectedModeVerdict(snapshot, expectation), snapshot }
  } catch {
    return { verdict: 'unknown', snapshot: {} }
  }
}
