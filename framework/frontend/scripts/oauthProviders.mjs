import { createStandCommandChannel } from './standCommand.mjs'

const sendCommand = createStandCommandChannel({ timeoutMs: 10_000 })
const rowFields = [
  'id',
  'clientId',
  'clientSecret',
  'scope',
  'createdAt',
  'updatedAt',
]

/**
 * @typedef {object} RawOAuthProviderRow
 * @property {number} id Captured primary identity.
 * @property {string | null} clientId Raw stored client id.
 * @property {string | null} clientSecret Raw DB-only secret.
 * @property {string | null} scope Raw stored scope.
 * @property {number} createdAt Original epoch milliseconds.
 * @property {number} updatedAt Original epoch milliseconds.
 */

/**
 * @typedef {object} RawOAuthProviderSnapshot
 * @property {string} providerKey External directory key.
 * @property {RawOAuthProviderRow | null} row Original row, or absence.
 */

/**
 * Capture only through the closed stand command channel, before any mutation.
 *
 * @param {string} providerKey External directory key.
 * @returns {Promise<RawOAuthProviderSnapshot>} State held only by Node cleanup.
 */
export async function captureOAuthProvider(providerKey) {
  if (typeof providerKey !== 'string' || providerKey.length === 0) {
    throw new Error('Invalid OAuth provider snapshot key.')
  }
  let reply
  try {
    reply = await sendCommand('test:oauth-provider:snapshot', { providerKey })
  } catch {
    throw new Error('OAuth provider snapshot could not be read.')
  }
  if (!hasFields(reply, ['snapshot'])) {
    throw new Error('Invalid OAuth provider snapshot reply.')
  }
  const snapshot = readSnapshot(reply.snapshot)
  if (snapshot.providerKey !== providerKey) {
    throw new Error('OAuth provider snapshot names a different key.')
  }
  return snapshot
}

/**
 * Restore once and check all original fields; a lost reply gets one read, no retry.
 *
 * @param {RawOAuthProviderSnapshot} snapshot Original state in the cleanup closure.
 * @param {number | null} currentId Fresh primary id captured after UI work.
 * @returns {Promise<void>} Completion of exact cleanup.
 */
export async function restoreOAuthProvider(snapshot, currentId) {
  const original = readSnapshot(snapshot)
  if (currentId !== null && !positiveId(currentId)) {
    throw new Error('Invalid OAuth provider cleanup identity.')
  }
  let reply
  let replyLost = false
  try {
    reply = await sendCommand('test:oauth-provider:restore', {
      snapshot: original,
      currentId,
    })
  } catch {
    replyLost = true
  }
  if (
    !replyLost &&
    (!hasFields(reply, ['restored']) || reply.restored !== true)
  ) {
    throw new Error('Invalid OAuth provider cleanup reply.')
  }
  const current = await captureOAuthProvider(original.providerKey)
  // Compare secrets only as part of a boolean verdict: never format raw values.
  if (!sameSnapshot(original, current)) {
    throw new Error(
      'OAuth provider cleanup did not restore the original state.',
    )
  }
}

/**
 * @param {RawOAuthProviderSnapshot} left Original state.
 * @param {RawOAuthProviderSnapshot} right Current state.
 * @returns {boolean} Exact presence, identity, raw fields and moments match.
 */
function sameSnapshot(left, right) {
  if (left.providerKey !== right.providerKey) return false
  if (left.row === null || right.row === null) return left.row === right.row
  return rowFields.every((field) => left.row[field] === right.row[field])
}

/**
 * @param {unknown} input Closed-channel data or the cleanup's captured state.
 * @returns {RawOAuthProviderSnapshot} Strict copy without normalizing raw text.
 */
function readSnapshot(input) {
  if (!hasFields(input, ['providerKey', 'row'])) {
    throw new Error('Invalid raw OAuth provider snapshot.')
  }
  const { providerKey, row } = input
  if (typeof providerKey !== 'string' || providerKey.length === 0) {
    throw new Error('Invalid raw OAuth provider snapshot.')
  }
  if (row === null) return { providerKey, row: null }
  if (
    !hasFields(row, rowFields) ||
    !positiveId(row.id) ||
    !Number.isSafeInteger(row.createdAt) ||
    !Number.isSafeInteger(row.updatedAt) ||
    [row.clientId, row.clientSecret, row.scope].some(
      (value) => value !== null && typeof value !== 'string',
    )
  ) {
    throw new Error('Invalid raw OAuth provider row.')
  }
  return {
    providerKey,
    row: /** @type {RawOAuthProviderRow} */ ({ ...row }),
  }
}

/**
 * @param {unknown} value Candidate identity.
 * @returns {value is number} Strict positive JSON integer.
 */
function positiveId(value) {
  return typeof value === 'number' && Number.isSafeInteger(value) && value > 0
}

/**
 * @param {unknown} value Candidate object.
 * @param {string[]} fields Exact expected keys.
 * @returns {value is Record<string, unknown>} Complete object shape.
 */
function hasFields(value, fields) {
  return (
    value !== null &&
    typeof value === 'object' &&
    !Array.isArray(value) &&
    Object.keys(value).length === fields.length &&
    fields.every((field) => Object.hasOwn(value, field))
  )
}
