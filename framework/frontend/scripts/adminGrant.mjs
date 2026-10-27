import { createStandCommandChannel } from './standCommand.mjs'

const ADMIN_CREATE_COMMAND = 'admin:create'
const ADMIN_GRANT_COMMAND = 'admin:grant'
const ADMIN_REVOKE_COMMAND = 'admin:revoke'

const sendCommand = createStandCommandChannel({ timeoutMs: 5_000 })

/**
 * Create or find the account named by a browser session and grant it admin.
 *
 * @param {string} token Session token naming the browser session.
 * @returns {Promise<number>} The account's positive user id.
 * @throws {Error} When the command does not return a positive user id.
 */
export async function mintAdmin(token) {
  const reply = await sendCommand(ADMIN_CREATE_COMMAND, {
    sessionToken: token,
  })
  const userId = Number(reply.userId)
  if (!(userId > 0)) {
    throw new Error('admin:create returned no positive user id')
  }

  return userId
}

/**
 * Set a user's admin flag through the stand command channel.
 *
 * @param {number} userId Target user id.
 * @param {boolean} admin Whether to grant or revoke admin.
 * @returns {Promise<void>}
 */
export async function setAdmin(userId, admin) {
  await sendCommand(admin ? ADMIN_GRANT_COMMAND : ADMIN_REVOKE_COMMAND, {
    userId,
    admin,
  })
}
