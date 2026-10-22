import { createCommandChannel } from '../../../../../framework/frontend/scripts/commandChannel.mjs'

// The country names fixture over the daemon command channel. The Playwright
// runner has no PHP, so the spec speaks the test commands the i18n library
// already answers on the wire; the fixture's rows are written by their owner.
const COMMAND_HOST = process.env.COMMAND_HOST ?? 'ecommerce-shop-daemon-test'
const COMMAND_PORT = Number(process.env.COMMAND_PORT ?? 8094)
// The i18n library answers after its database transaction, not from the master loop.
const REPLY_TIMEOUT_MS = 15_000
const COUNTRY_NAMES_SEED_COMMAND = 'test:i18n:country-names:seed'
const COUNTRY_NAMES_CLEAR_COMMAND = 'test:i18n:country-names:clear'

const sendCommand = createCommandChannel({
  host: COMMAND_HOST,
  port: COMMAND_PORT,
  timeoutMs: REPLY_TIMEOUT_MS,
})

/** Create the fixed country names fixture through its owner and await commit. */
export async function seedCountryNamesFixture(): Promise<void> {
  await sendCommand(COUNTRY_NAMES_SEED_COMMAND, {})
}

/** Remove only the fixed country names fixture and await the owner's reply. */
export async function clearCountryNamesFixture(): Promise<void> {
  await sendCommand(COUNTRY_NAMES_CLEAR_COMMAND, {})
}
