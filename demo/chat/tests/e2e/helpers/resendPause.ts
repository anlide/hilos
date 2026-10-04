import type { Page } from '@playwright/test'

import { createCommandChannel } from '../../../../../framework/frontend/scripts/commandChannel.mjs'
import { isSessionCookie } from './session'

const COMMAND_HOST = process.env.COMMAND_HOST ?? 'chat-test'
const COMMAND_PORT = Number(process.env.COMMAND_PORT ?? 8094)
const REPLY_TIMEOUT_MS = 15_000
const END_PAUSE_COMMAND = 'test:verification:end-pause'

const sendCommand = createCommandChannel({
  host: COMMAND_HOST,
  port: COMMAND_PORT,
  timeoutMs: REPLY_TIMEOUT_MS,
})

/**
 * End the resend pause for an address and move this browser's send line to now.
 *
 * @param page Browser whose session owns the send line.
 * @param address Address whose verification rows are aged.
 */
export async function endResendPause(
  page: Page,
  address: string,
): Promise<void> {
  const cookies = await page.context().cookies()
  const sessionToken = cookies.find((cookie) =>
    isSessionCookie(cookie.name),
  )?.value
  if (sessionToken === undefined) {
    throw new Error('No session cookie for the resend pause command')
  }

  const reply = (await sendCommand(END_PAUSE_COMMAND, {
    address,
    sessionToken,
  })) as { address?: unknown; aged?: unknown }
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
