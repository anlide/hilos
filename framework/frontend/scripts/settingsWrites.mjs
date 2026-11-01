// Test-only settings writes: a batch of example settings keys written by the
// settings library one write per pair, so a spec drives a real settings source
// at the pace it needs instead of through the edit modal.
import { createStandCommandChannel } from './standCommand.mjs'

// The settings library writes every pair of a batch in one turn, one short
// transaction each; at most 32 pairs stay well below the caller's wait.
const REPLY_TIMEOUT_MS = 5_000

const WRITE_COMMAND = 'test:settings:write'

/**
 * Writes example settings keys through the settings library, one write per pair
 * and in the given order, the way the settings screen writes them.
 *
 * The library answers once, after the last write; the first refused pair ends
 * the batch with a refusal, and the pairs before it stay written.
 *
 * @param {Array<{key: string, value: string}>} writes Pairs to write, 1 to 32 of them.
 * @param {object} [options] Where to send the command.
 * @param {string} [options.host] Explicit cluster host instead of COMMAND_HOST.
 * @returns {Promise<Array<{key: string, value: string, acceptedAt: number}>>} Every
 *   written pair in order, with the moment the library accepted it in epoch milliseconds.
 * @throws {Error} When the command refuses or its reply does not hand back the pairs sent.
 */
export async function writeSettings(writes, { host } = {}) {
  const reply = await createStandCommandChannel({
    timeoutMs: REPLY_TIMEOUT_MS,
    host,
  })(WRITE_COMMAND, {
    writes: writes.map(({ key, value }) => ({ key, value })),
  })
  const accepted = reply.writes
  if (!Array.isArray(accepted) || accepted.length !== writes.length) {
    throw new Error(`Invalid ${WRITE_COMMAND} reply`)
  }

  return writes.map(({ key, value }, index) => {
    const write = accepted[index]
    if (
      write === null ||
      typeof write !== 'object' ||
      write.key !== key ||
      write.value !== value ||
      !isMoment(write.acceptedAt)
    ) {
      throw new Error(`Invalid ${WRITE_COMMAND} reply`)
    }

    return { key, value, acceptedAt: /** @type {number} */ (write.acceptedAt) }
  })
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
