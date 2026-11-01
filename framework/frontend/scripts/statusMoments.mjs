// Test-only Status commands that bring a heartbeat or a maintenance start to now,
// so a spec never waits the production publication clock out.
import { createStandCommandChannel } from './standCommand.mjs'

// The Status owner answers each command from its own turn: a heartbeat step or one
// transaction, both bounded well below the caller's wait.
const REPLY_TIMEOUT_MS = 5_000

const STALE_COMMAND = 'test:status:heartbeat:stale'
const NOW_COMMAND = 'test:status:heartbeat:now'
const WORK_START_COMMAND = 'test:status:work:start'

/**
 * Publishes the last pair's heartbeat aged just past the reader's stale limit.
 *
 * The owner holds every publication until {@link beatStatusHeartbeat} or its own
 * restart, so the aged moment stays on disk; a test that calls this owns that
 * release in its cleanup.
 *
 * @param {object} [options] Where to send the command.
 * @param {string} [options.host] Explicit cluster host instead of COMMAND_HOST.
 * @returns {Promise<{aliveAt: number}>} The published heartbeat moment in epoch milliseconds.
 * @throws {Error} When the command refuses or returns an invalid heartbeat.
 */
export async function staleStatusHeartbeat({ host } = {}) {
  return heartbeat(STALE_COMMAND, host)
}

/**
 * Releases a held publication and publishes a heartbeat at now, freeze or not.
 *
 * @param {object} [options] Where to send the command.
 * @param {string} [options.host] Explicit cluster host instead of COMMAND_HOST.
 * @returns {Promise<{aliveAt: number}>} The published heartbeat moment in epoch milliseconds.
 * @throws {Error} When the command refuses or returns an invalid heartbeat.
 */
export async function beatStatusHeartbeat({ host } = {}) {
  return heartbeat(NOW_COMMAND, host)
}

/**
 * Moves an announced maintenance work's start to now, rounded down to a second.
 *
 * @param {string} publicId Public id of the announcement to start.
 * @param {object} [options] Where to send the command.
 * @param {string} [options.host] Explicit cluster host instead of COMMAND_HOST.
 * @returns {Promise<{publicId: string, startsAt: number, version: number}>} The moved start in
 *   epoch milliseconds and the announcement's new version.
 * @throws {Error} When the command refuses or returns an invalid announcement.
 */
export async function startStatusWork(publicId, { host } = {}) {
  const reply = await createStandCommandChannel({
    timeoutMs: REPLY_TIMEOUT_MS,
    host,
  })(WORK_START_COMMAND, { publicId })
  if (
    reply.publicId !== publicId ||
    !isMoment(reply.startsAt) ||
    typeof reply.version !== 'number' ||
    !Number.isSafeInteger(reply.version) ||
    reply.version <= 0
  ) {
    throw new Error(`Invalid ${WORK_START_COMMAND} reply`)
  }

  return {
    publicId,
    startsAt: /** @type {number} */ (reply.startsAt),
    version: reply.version,
  }
}

/**
 * Sends one heartbeat command and checks the moment it answers.
 *
 * @param {string} command Heartbeat command name.
 * @param {string | undefined} host Explicit cluster host, or the stand endpoint.
 * @returns {Promise<{aliveAt: number}>} The published heartbeat moment.
 * @throws {Error} When the command refuses or returns an invalid heartbeat.
 */
async function heartbeat(command, host) {
  const reply = await createStandCommandChannel({
    timeoutMs: REPLY_TIMEOUT_MS,
    host,
  })(command, {})
  if (!isMoment(reply.aliveAt)) {
    throw new Error(`Invalid ${command} reply`)
  }

  return { aliveAt: /** @type {number} */ (reply.aliveAt) }
}

/**
 * Whether a reply value is a positive epoch-millisecond moment.
 *
 * @param {unknown} value Reply value to check.
 * @returns {boolean} True for a positive safe integer.
 */
function isMoment(value) {
  return typeof value === 'number' && Number.isSafeInteger(value) && value > 0
}
