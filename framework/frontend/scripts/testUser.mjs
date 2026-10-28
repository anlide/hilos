import { createStandCommandChannel } from './standCommand.mjs'

/** Precomputed password_hash for the shared PASSWORD, 'correct horse battery'. */
export const FIXTURE_PASSWORD_HASH =
  '$2y$10$abcdefghijklmnopqrstuuCj7fyLDJytXL.TfOiOeiv0fQvSOK1/2'

const COMMAND_TIMEOUT_MS = 5_000

/**
 * Create a verified fixture account through its existing non-production command.
 *
 * @param {object} options The account's fixture values.
 * @param {string} options.email Address to normalize and verify on the server.
 * @param {string|null} [options.passwordHash] Null selects a passwordless account.
 * @param {'light'|'dark'|'system'|null} [options.themePick] Account theme choice.
 * @param {string|null} [options.phone] Optional verified E.164 SMS identity.
 * @param {string} [host] Explicit cluster host instead of COMMAND_HOST.
 * @returns {Promise<{userId: number, email: string, name: string}>}
 * @throws {Error} When the command fails or returns an invalid account.
 */
export async function seedTestUser(
  {
    email,
    passwordHash = FIXTURE_PASSWORD_HASH,
    themePick = null,
    phone = null,
  },
  host,
) {
  const sendCommand = createStandCommandChannel({
    timeoutMs: COMMAND_TIMEOUT_MS,
    host,
  })
  const reply = await sendCommand('test:user:create', {
    email,
    passwordHash,
    themePick,
    phone,
  })
  if (
    reply === null ||
    typeof reply !== 'object' ||
    Array.isArray(reply) ||
    typeof reply.userId !== 'number' ||
    !Number.isSafeInteger(reply.userId) ||
    reply.userId <= 0 ||
    typeof reply.email !== 'string' ||
    reply.email.trim() === '' ||
    typeof reply.name !== 'string' ||
    reply.name.trim() === ''
  ) {
    throw new Error('Invalid test:user:create reply')
  }

  return { userId: reply.userId, email: reply.email, name: reply.name }
}

/**
 * Bind an existing browser session to a fixture account without an auth flow.
 *
 * @param {object} options Session and account to bind.
 * @param {string} options.sessionToken Cookie of the already connected browser.
 * @param {number} options.userId Positive account id returned by seedTestUser.
 * @param {string} [host] Explicit cluster host instead of COMMAND_HOST.
 * @returns {Promise<{sessionId: number, userId: number}>}
 * @throws {Error} When the command fails or returns an invalid or different bind.
 */
export async function bindTestSession({ sessionToken, userId }, host) {
  const sendCommand = createStandCommandChannel({
    timeoutMs: COMMAND_TIMEOUT_MS,
    host,
  })
  const reply = await sendCommand('test:session:bind', { sessionToken, userId })
  if (
    reply === null ||
    typeof reply !== 'object' ||
    Array.isArray(reply) ||
    typeof reply.sessionId !== 'number' ||
    !Number.isSafeInteger(reply.sessionId) ||
    reply.sessionId <= 0 ||
    typeof reply.userId !== 'number' ||
    !Number.isSafeInteger(reply.userId) ||
    reply.userId <= 0 ||
    reply.userId !== userId
  ) {
    throw new Error('Invalid test:session:bind reply')
  }

  return { sessionId: reply.sessionId, userId: reply.userId }
}
