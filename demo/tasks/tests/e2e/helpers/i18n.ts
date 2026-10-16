import { createCommandChannel } from '../../../../../framework/frontend/scripts/commandChannel.mjs'

const COMMAND_HOST = process.env.COMMAND_HOST ?? 'tasks-daemon-test'
const COMMAND_PORT = Number(process.env.COMMAND_PORT ?? 8094)
// The i18n library answers after its database transaction, not from the master loop.
const REPLY_TIMEOUT_MS = 15_000
const LANGUAGE_ON_COMMAND = 'test:i18n:language:on'
const LANGUAGE_OFF_COMMAND = 'test:i18n:language:off'
const LANGUAGE_DELETE_COMMAND = 'test:i18n:language:delete'
const LOCALE_ON_COMMAND = 'test:i18n:locale:on'
const LOCALE_DELETE_COMMAND = 'test:i18n:locale:delete'
const COUNTRY_ON_COMMAND = 'test:i18n:country:on'
const COUNTRY_OFF_COMMAND = 'test:i18n:country:off'
const LANGUAGE_NAMES_SEED_COMMAND = 'test:i18n:language-names:seed'
const LANGUAGE_NAMES_CLEAR_COMMAND = 'test:i18n:language-names:clear'
const COUNTRY_NAMES_SEED_COMMAND = 'test:i18n:country-names:seed'
const COUNTRY_NAMES_CLEAR_COMMAND = 'test:i18n:country-names:clear'

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
 * Remove a non-default built-in countryless locale fixture through its owner.
 *
 * @param code Two-letter code of the countryless locale to remove.
 */
export async function deleteLocale(code: string): Promise<void> {
  await sendCommand(LOCALE_DELETE_COMMAND, { code })
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

/** Create the fixed language names fixture through its owner and await commit. */
export async function seedLanguageNamesFixture(): Promise<void> {
  await sendCommand(LANGUAGE_NAMES_SEED_COMMAND, {})
}

/** Remove only the fixed language names fixture and await the owner's reply. */
export async function clearLanguageNamesFixture(): Promise<void> {
  await sendCommand(LANGUAGE_NAMES_CLEAR_COMMAND, {})
}

/** Create the fixed country names fixture through its owner and await commit. */
export async function seedCountryNamesFixture(): Promise<void> {
  await sendCommand(COUNTRY_NAMES_SEED_COMMAND, {})
}

/** Remove only the fixed country names fixture and await the owner's reply. */
export async function clearCountryNamesFixture(): Promise<void> {
  await sendCommand(COUNTRY_NAMES_CLEAR_COMMAND, {})
}
