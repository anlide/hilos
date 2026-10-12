import { createCommandChannel } from '../../../../../framework/frontend/scripts/commandChannel.mjs'

const COMMAND_HOST = process.env.COMMAND_HOST ?? 'chat-test'
const COMMAND_PORT = Number(process.env.COMMAND_PORT ?? 8094)
// The i18n library answers after its database transaction, not from the master loop.
const REPLY_TIMEOUT_MS = 15_000
const LANGUAGE_ON_COMMAND = 'test:i18n:language:on'
const LANGUAGE_OFF_COMMAND = 'test:i18n:language:off'
const LANGUAGE_DELETE_COMMAND = 'test:i18n:language:delete'
const LOCALE_ON_COMMAND = 'test:i18n:locale:on'
const COUNTRY_ON_COMMAND = 'test:i18n:country:on'
const COUNTRY_OFF_COMMAND = 'test:i18n:country:off'

const sendCommand = createCommandChannel({
  host: COMMAND_HOST,
  port: COMMAND_PORT,
  timeoutMs: REPLY_TIMEOUT_MS,
})

/**
 * Make one built-in language available for a browser test through its owner.
 *
 * @param code Two-letter language code from the built-in catalog.
 */
export async function switchLanguageOn(code: string): Promise<void> {
  await sendCommand(LANGUAGE_ON_COMMAND, { code })
}

/**
 * Switch off one built-in language through its owner before a browser test.
 *
 * @param code Two-letter language code from the built-in catalog.
 */
export async function switchLanguageOff(code: string): Promise<void> {
  await sendCommand(LANGUAGE_OFF_COMMAND, { code })
}

/**
 * Remove a browser test's built-in language fixture through its owner.
 *
 * @param code Two-letter language code of the fixture to remove.
 */
export async function deleteLanguage(code: string): Promise<void> {
  await sendCommand(LANGUAGE_DELETE_COMMAND, { code })
}

/**
 * Switch on a language's countryless locale through its owner.
 *
 * @param code Two-letter language code of the countryless locale.
 */
export async function switchLocaleOn(code: string): Promise<void> {
  await sendCommand(LOCALE_ON_COMMAND, { code })
}

/**
 * Make one built-in country available for a browser test through its owner.
 *
 * @param code Two-letter country code from the built-in catalog.
 */
export async function switchCountryOn(code: string): Promise<void> {
  await sendCommand(COUNTRY_ON_COMMAND, { code })
}

/**
 * Switch off one built-in country through its owner before a browser test.
 *
 * @param code Two-letter country code from the built-in catalog.
 */
export async function switchCountryOff(code: string): Promise<void> {
  await sendCommand(COUNTRY_OFF_COMMAND, { code })
}
