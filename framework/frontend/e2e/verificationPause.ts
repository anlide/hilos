import type { Page } from '@playwright/test'
import { sessionToken } from './sessionCookie.js'
import { endVerificationPause } from '../scripts/verificationPause.mjs'

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
  await endVerificationPause(address, await sessionToken(page))
}
