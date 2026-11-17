import { createStandCommandChannel } from './standCommand.mjs'

const sendCommand = createStandCommandChannel({ timeoutMs: 10_000 })

/**
 * @typedef {object} RawSettingSnapshot
 * @property {string} key Stable settings identity.
 * @property {boolean} exists Whether a persisted row existed.
 * @property {string} type Original row type, or the catalog type for an absent row.
 * @property {string | null} rawValue Original nullable persisted text.
 * @property {'default' | 'reference' | 'override' | 'orphan'} valueSource Effective value source.
 */

/**
 * Capture a setting before the scenario's first mutation.
 *
 * @param {string} key Setting key.
 * @returns {Promise<RawSettingSnapshot>} Exact original state.
 */
export async function captureSetting(key) {
  if (typeof key !== 'string' || key.length === 0) {
    throw new Error('A settings snapshot requires a nonempty key.')
  }
  const reply = await sendCommand('test:settings:snapshot', { key })
  if (!hasFields(reply, ['snapshot'])) {
    throw new Error('Invalid settings snapshot reply.')
  }
  const snapshot = readSnapshot(reply.snapshot)
  if (snapshot.key !== key) {
    throw new Error('Settings snapshot reply names a different key.')
  }
  return snapshot
}

/**
 * Restore raw state; a lost acknowledgement is reconciled by one read.
 *
 * @param {RawSettingSnapshot} snapshot Original state kept by the test closure.
 * @returns {Promise<void>} Resolves only when the original state is restored.
 */
export async function restoreSetting(snapshot) {
  const original = readSnapshot(snapshot)
  let reply
  try {
    reply = await sendCommand('test:settings:restore', { snapshot: original })
  } catch (failure) {
    let current
    try {
      current = await captureSetting(original.key)
    } catch (reconciliationFailure) {
      throw new AggregateError(
        [failure, reconciliationFailure],
        'Settings cleanup failed and its state could not be read.',
        { cause: reconciliationFailure },
      )
    }
    if (JSON.stringify(current) === JSON.stringify(original)) return
    throw failure
  }
  if (!hasFields(reply, ['restored']) || reply.restored !== true) {
    throw new Error('Invalid settings restoration reply.')
  }
}

/**
 * Validate the complete raw shape without normalizing persisted text.
 *
 * @param {unknown} input Snapshot from the owner or test closure.
 * @returns {RawSettingSnapshot} Validated copy.
 */
function readSnapshot(input) {
  const fields = ['key', 'exists', 'type', 'rawValue', 'valueSource']
  if (!hasFields(input, fields)) {
    throw new Error('Invalid raw settings snapshot shape.')
  }
  const { key, exists, type, rawValue, valueSource } = input
  const inherited = valueSource === 'default' || valueSource === 'reference'
  if (
    typeof key !== 'string' ||
    key.length === 0 ||
    typeof exists !== 'boolean' ||
    typeof type !== 'string' ||
    (rawValue !== null && typeof rawValue !== 'string') ||
    typeof valueSource !== 'string' ||
    !['default', 'reference', 'override', 'orphan'].includes(valueSource) ||
    (!exists && (!inherited || rawValue !== null)) ||
    (inherited && rawValue !== null) ||
    (valueSource === 'override' && (!exists || rawValue === null)) ||
    (valueSource === 'orphan' && !exists)
  ) {
    throw new Error('The raw settings snapshot fields disagree.')
  }
  return {
    key,
    exists,
    type,
    rawValue,
    valueSource: /** @type {RawSettingSnapshot['valueSource']} */ (valueSource),
  }
}

/**
 * @param {unknown} value Candidate object.
 * @param {string[]} fields Complete expected field set.
 * @returns {value is Record<string, unknown>} Whether all and only the named fields exist.
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
