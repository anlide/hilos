import { createStandCommandChannel } from './standCommand.mjs'

// The middle of the command channel's nested windows, a hand-kept copy of
// CommandChannelWindows::CALLER_WAIT_SECONDS. The caller waits as long as the CLI does.
const REPLY_TIMEOUT_MS = 15_000

const sendCommand = createStandCommandChannel({ timeoutMs: REPLY_TIMEOUT_MS })

/**
 * Merge two fixture accounts through the live daemon. The fixture users share the
 * password represented by FIXTURE_PASSWORD_HASH and have no second factor. The
 * command requires a password fate, so keep the survivor's password.
 *
 * @param {{ survivorUserId: number, loserUserId: number }} users Accounts to merge.
 * @returns {Promise<void>}
 * @throws {Error} When the merge does not complete.
 */
export async function mergeAccounts({ survivorUserId, loserUserId }) {
  const reply = await sendCommand('account:merge', {
    mode: 'start',
    survivorUserId,
    loserUserId,
    passwordFate: 'survivor',
  })
  const outcome = reply.merge?.outcome
  if (outcome !== 'completed') {
    throw new Error(
      `account:merge did not complete the merge of user ${loserUserId} into ${survivorUserId}: ${outcome ?? 'no merge in the reply'}`,
    )
  }
}
