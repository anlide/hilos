// Test-only Daemon commands that bring a node report or a cron rule's run to now,
// or pause its schedule, so a spec never waits a reporting interval or schedule out.
import { createStandCommandChannel } from './standCommand.mjs'

// The middle of the command channel's nested windows, a hand-kept copy of
// CommandChannelWindows::CALLER_WAIT_SECONDS. These commands are parked for an
// agent whose own wait, AGENT_WAIT_SECONDS (13.0), runs out first and comes back
// as the daemon's stated reason rather than as a socket timeout.
const REPLY_TIMEOUT_MS = 15_000

const NODE_REPORT_COMMAND = 'test:daemon:node:report'
const CRON_FIRE_COMMAND = 'test:cron:fire'
const CRON_PAUSE_COMMAND = 'test:cron:pause'
const CRON_CANCEL_PAUSE_COMMAND = 'test:cron:cancel-pause'

// The owner named for a rule of the daemon itself, sent as no agent on the wire.
const DAEMON_OWNER = 'daemon'
// The key is cut at its first separator, as the CLI cuts its argument, so a rule
// name may carry the separator itself.
const OWNER_SEPARATOR = '/'

/**
 * Has this node's Daemon node agent report now, carrying the master's fresh picture.
 *
 * The answer comes once the report has left the node agent; the collector's
 * fan-out to open pages follows it.
 *
 * @param {object} [options] Where to send the command.
 * @param {string} [options.host] Explicit cluster host instead of COMMAND_HOST.
 * @returns {Promise<{nodeId: string, reportedAt: number}>} The reporting node and the
 *   moment of the report in epoch milliseconds.
 * @throws {Error} When the command refuses or returns an invalid report.
 */
export async function reportDaemonNode({ host } = {}) {
  const reply = await createStandCommandChannel({
    timeoutMs: REPLY_TIMEOUT_MS,
    host,
  })(NODE_REPORT_COMMAND, {})
  if (
    typeof reply.nodeId !== 'string' ||
    reply.nodeId === '' ||
    !isMoment(reply.reportedAt)
  ) {
    throw new Error(
      `Invalid ${NODE_REPORT_COMMAND} reply: ${JSON.stringify(reply)}`,
    )
  }

  return {
    nodeId: reply.nodeId,
    reportedAt: /** @type {number} */ (reply.reportedAt),
  }
}

/**
 * Fires one cron rule once now by its ordinary path; the schedule does not move.
 *
 * A rule of the daemon fires on the leader at once; a rule of an agent fires at
 * that agent's next check, and the answer comes after its cron report has left.
 *
 * @param {string} key The owner and the rule joined by "/": "daemon/<rule>" for a
 *   rule of the daemon itself, "<agentId>/<rule>" for a rule an agent holds.
 * @param {object} [options] Where to send the command.
 * @param {string} [options.host] Explicit cluster host instead of COMMAND_HOST.
 * @returns {Promise<{firedAt: number}>} The moment the rule ran in epoch milliseconds.
 * @throws {Error} When the key names no owner or no rule, the command refuses, or
 *   it returns an invalid moment.
 */
export async function fireCronRule(key, { host } = {}) {
  const rule = parseCronRuleKey(key, CRON_FIRE_COMMAND)
  const reply = await createStandCommandChannel({
    timeoutMs: REPLY_TIMEOUT_MS,
    host,
  })(CRON_FIRE_COMMAND, rule)
  if (!isMoment(reply.firedAt)) {
    throw new Error(
      `Invalid ${CRON_FIRE_COMMAND} reply: ${JSON.stringify(reply)}`,
    )
  }

  return { firedAt: /** @type {number} */ (reply.firedAt) }
}

/**
 * Pauses scheduled runs of one current cron rule while explicit fires still run.
 *
 * @param {string} key The owner and the rule joined by "/": "daemon/<rule>" or
 *   "<agentId>/<rule>".
 * @param {object} [options] Where to send the command.
 * @param {string} [options.host] Explicit cluster host instead of COMMAND_HOST.
 * @returns {Promise<{pauseId: string}>} The key to cancel in awaited cleanup.
 * @throws {Error} When the key names no owner or no rule, the command refuses, or
 *   it returns an invalid pause key.
 */
export async function pauseCronRule(key, { host } = {}) {
  const rule = parseCronRuleKey(key, CRON_PAUSE_COMMAND)
  const reply = await createStandCommandChannel({
    timeoutMs: REPLY_TIMEOUT_MS,
    host,
  })(CRON_PAUSE_COMMAND, rule)
  if (typeof reply.pauseId !== 'string' || reply.pauseId.trim() === '') {
    throw new Error(
      `Invalid ${CRON_PAUSE_COMMAND} reply: ${JSON.stringify(reply)}`,
    )
  }

  return { pauseId: reply.pauseId }
}

/**
 * Cancels only the pause identified by the supplied key.
 *
 * @param {string} pauseId The key returned by pauseCronRule.
 * @param {object} [options] Where to send the command.
 * @param {string} [options.host] Explicit cluster host instead of COMMAND_HOST.
 * @returns {Promise<void>}
 * @throws {Error} When the command refuses, including an unknown or canceled key.
 */
export async function cancelCronPause(pauseId, { host } = {}) {
  await createStandCommandChannel({
    timeoutMs: REPLY_TIMEOUT_MS,
    host,
  })(CRON_CANCEL_PAUSE_COMMAND, { pauseId })
}

/**
 * Splits the CLI's owner/rule address at its first separator.
 *
 * @param {string} key The owner and rule address.
 * @param {string} command The command named in an invalid-address error.
 * @returns {{agentId: string | null, ruleName: string}} The command's rule target.
 * @throws {Error} When the key names no owner or no rule.
 */
function parseCronRuleKey(key, command) {
  const separator = key.indexOf(OWNER_SEPARATOR)
  const owner = separator === -1 ? '' : key.slice(0, separator)
  const ruleName = separator === -1 ? '' : key.slice(separator + 1)
  if (owner === '' || ruleName === '') {
    throw new Error(
      `${command} needs <owner>/<rule>, got ${JSON.stringify(key)}`,
    )
  }

  return { agentId: owner === DAEMON_OWNER ? null : owner, ruleName }
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
