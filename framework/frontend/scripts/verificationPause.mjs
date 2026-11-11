import { createStandCommandChannel } from './standCommand.mjs'

const END_PAUSE_COMMAND = 'test:verification:end-pause'

const sendCommand = createStandCommandChannel({ timeoutMs: 15_000 })

/**
 * End the resend pause for an address and move a browser session's send line to now.
 *
 * @param {string} address Address whose verification rows are aged.
 * @param {string} token Session token naming the browser whose send line moves.
 * @returns {Promise<void>}
 * @throws {Error} When the command returns another address, no aged-row count, or no aged row.
 */
export async function endVerificationPause(address, token) {
  const reply = await sendCommand(END_PAUSE_COMMAND, {
    address,
    sessionToken: token,
  })
  if (reply.address !== address || typeof reply.aged !== 'number') {
    throw new Error(
      'Resend pause command returned no address or aged-row count',
    )
  }
  // No row aged means the address had no code to pause: a wrong address here
  // would otherwise surface later as a Send again that never appears.
  if (reply.aged < 1) {
    throw new Error(`Resend pause command found no code sent to ${address}`)
  }
}
