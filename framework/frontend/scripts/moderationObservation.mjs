import { createStandCommandChannel } from './standCommand.mjs'

const command = createStandCommandChannel({ timeoutMs: 5_000 })

/**
 * @typedef {object} ModerationObservation
 * @property {string|null} checkRequestId Current check of this connection/kind.
 * @property {string|null} activeRequestId Model attempt for this connection/kind.
 * @property {boolean} waitingForUploadHold Whether the check lacks its owner's marks.
 */

/**
 * Ask the real moderator after its next natural tick. The command reads only
 * one connection/kind and does not advance checks or invent an idle outcome.
 *
 * @param {string} acceptKey Connection under observation.
 * @param {string} kind Moderation kind under observation.
 * @returns {Promise<ModerationObservation>} Validated command payload.
 * @throws {Error} When the command fails or the payload violates its contract.
 */
export async function observeModeration(acceptKey, kind) {
  const reply = await command('test:moderation:observe', { acceptKey, kind })
  if (
    !reply ||
    typeof reply !== 'object' ||
    Array.isArray(reply) ||
    Object.keys(reply).sort().join() !==
      'activeRequestId,checkRequestId,waitingForUploadHold' ||
    !requestId(reply.checkRequestId) ||
    !requestId(reply.activeRequestId) ||
    typeof reply.waitingForUploadHold !== 'boolean'
  )
    throw new Error('Invalid test:moderation:observe reply')
  return /** @type {ModerationObservation} */ (reply)
}

/**
 * @typedef {object} ChatSubmissionObservation
 * @property {string} phase Chat's current outbound moderation phase.
 * @property {string[]} activeCheckRequestIds Current message then picture checks.
 */

/**
 * Observe Chat through its FIFO after earlier verdict/approve/publish handlers.
 * A checking phase with no checks witnesses handover waiting on the owner.
 * An empty phase is Chat's normal idle state. This reader is Chat-stand-only.
 *
 * @param {string} acceptKey Chat connection under observation.
 * @returns {Promise<ChatSubmissionObservation>} Validated command payload.
 * @throws {Error} When the command fails or the payload violates its contract.
 */
export async function observeChatSubmission(acceptKey) {
  const reply = await command('test:chat:submission-observe', { acceptKey })
  if (
    !reply ||
    typeof reply !== 'object' ||
    Array.isArray(reply) ||
    Object.keys(reply).sort().join() !== 'activeCheckRequestIds,phase' ||
    typeof reply.phase !== 'string' ||
    !Array.isArray(reply.activeCheckRequestIds) ||
    !reply.activeCheckRequestIds.every(
      (id) => typeof id === 'string' && id !== '',
    )
  )
    throw new Error('Invalid test:chat:submission-observe reply')
  return /** @type {ChatSubmissionObservation} */ (reply)
}

/** An absent check is explicit null, never a missing or empty id. */
function requestId(value) {
  return value === null || (typeof value === 'string' && value !== '')
}
