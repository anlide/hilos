import { createStandCommandChannel } from './standCommand.mjs'

const REPLY_TIMEOUT_MS = 15_000
const sendCommand = createStandCommandChannel({ timeoutMs: REPLY_TIMEOUT_MS })

/**
 * Make one built-in language available for a browser test through its owner.
 *
 * @param {string} code Two-letter language code from the built-in catalog.
 * @returns {Promise<void>}
 */
export async function switchLanguageOn(code) {
  await sendCommand('test:i18n:language:on', { code })
}

/**
 * Switch off one built-in language through its owner before a browser test.
 *
 * @param {string} code Two-letter language code from the built-in catalog.
 * @returns {Promise<void>}
 */
export async function switchLanguageOff(code) {
  await sendCommand('test:i18n:language:off', { code })
}

/**
 * Remove a browser test's built-in language fixture through its owner.
 *
 * @param {string} code Two-letter language code of the fixture to remove.
 * @returns {Promise<void>}
 */
export async function deleteLanguage(code) {
  await sendCommand('test:i18n:language:delete', { code })
}

/**
 * Switch on a language's countryless locale through its owner.
 *
 * @param {string} code Two-letter language code of the countryless locale.
 * @returns {Promise<void>}
 */
export async function switchLocaleOn(code) {
  await sendCommand('test:i18n:locale:on', { code })
}

/**
 * Remove a non-default built-in countryless locale fixture through its owner.
 *
 * @param {string} code Two-letter code of the countryless locale to remove.
 * @returns {Promise<void>}
 */
export async function deleteLocale(code) {
  await sendCommand('test:i18n:locale:delete', { code })
}

/**
 * Make one built-in country available for a browser test through its owner.
 *
 * @param {string} code Two-letter country code from the built-in catalog.
 * @returns {Promise<void>}
 */
export async function switchCountryOn(code) {
  await sendCommand('test:i18n:country:on', { code })
}

/**
 * Switch off one built-in country through its owner before a browser test.
 *
 * @param {string} code Two-letter country code from the built-in catalog.
 * @returns {Promise<void>}
 */
export async function switchCountryOff(code) {
  await sendCommand('test:i18n:country:off', { code })
}

/**
 * Create the fixed language names fixture through its owner and await commit.
 *
 * @returns {Promise<void>}
 */
export async function seedLanguageNamesFixture() {
  await sendCommand('test:i18n:language-names:seed', {})
}

/**
 * Remove only the fixed language names fixture and await the owner's reply.
 *
 * @returns {Promise<void>}
 */
export async function clearLanguageNamesFixture() {
  await sendCommand('test:i18n:language-names:clear', {})
}

/**
 * Create the fixed country names fixture through its owner and await commit.
 *
 * @returns {Promise<void>}
 */
export async function seedCountryNamesFixture() {
  await sendCommand('test:i18n:country-names:seed', {})
}

/**
 * Remove only the fixed country names fixture and await the owner's reply.
 *
 * @returns {Promise<void>}
 */
export async function clearCountryNamesFixture() {
  await sendCommand('test:i18n:country-names:clear', {})
}
