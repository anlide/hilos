import { createStandCommandChannel } from './standCommand.mjs'

const REPLY_TIMEOUT_MS = 15_000
const sendCommand = createStandCommandChannel({ timeoutMs: REPLY_TIMEOUT_MS })

/**
 * @typedef {object} CreatedI18nFixture
 * @property {number} id Committed row id.
 * @property {string} code Address assigned by the library.
 * @property {boolean} enabled Committed switch value.
 */

/**
 * Create a language precondition without replacing an existing row.
 *
 * @param {object} options
 * @param {string} options.code Reserved language code.
 * @param {string} options.nativeName Native language name.
 * @param {boolean} [options.rtl=false] Whether text reads right to left.
 * @param {boolean} [options.enabled=false] Initial switch value.
 * @returns {Promise<CreatedI18nFixture>}
 */
export async function createLanguage({
  code,
  nativeName,
  rtl = false,
  enabled = false,
}) {
  return /** @type {Promise<CreatedI18nFixture>} */ (
    sendCommand('test:i18n:language:create', { code, nativeName, rtl, enabled })
  )
}

/**
 * Create a country precondition without replacing an existing row.
 *
 * @param {object} options
 * @param {string} options.code Reserved country code.
 * @param {string} options.currencySymbol Currency symbol.
 * @param {string} options.currencyCode Currency code.
 * @param {boolean} [options.enabled=false] Initial switch value.
 * @returns {Promise<CreatedI18nFixture>}
 */
export async function createCountry({
  code,
  currencySymbol,
  currencyCode,
  enabled = false,
}) {
  return /** @type {Promise<CreatedI18nFixture>} */ (
    sendCommand('test:i18n:country:create', {
      code,
      currencySymbol,
      currencyCode,
      enabled,
    })
  )
}

/**
 * Create a locale precondition using formats chosen by the library.
 *
 * @param {object} options
 * @param {number} options.languageId Language row id.
 * @param {number | null} [options.countryId=null] Country row id, or countryless.
 * @param {boolean} [options.enabled=false] Initial switch value.
 * @returns {Promise<CreatedI18nFixture>}
 */
export async function createLocale({
  languageId,
  countryId = null,
  enabled = false,
}) {
  return /** @type {Promise<CreatedI18nFixture>} */ (
    sendCommand('test:i18n:locale:create', { languageId, countryId, enabled })
  )
}

/**
 * Clear an owned language and its dependencies; an absent row is already clean.
 *
 * @param {string} code Owned language code.
 * @returns {Promise<{code: string}>}
 */
export async function clearLanguage(code) {
  return /** @type {Promise<{code: string}>} */ (
    sendCommand('test:i18n:language:clear', { code })
  )
}

/**
 * Clear an owned country through its owner, preserving built-in guards.
 *
 * @param {string} code Owned country code.
 * @returns {Promise<{code: string}>}
 */
export async function clearCountry(code) {
  return /** @type {Promise<{code: string}>} */ (
    sendCommand('test:i18n:country:clear', { code })
  )
}

/**
 * Clear an owned locale through its owner, preserving default-locale guards.
 *
 * @param {string} code Owned locale code.
 * @returns {Promise<{code: string}>}
 */
export async function clearLocale(code) {
  return /** @type {Promise<{code: string}>} */ (
    sendCommand('test:i18n:locale:clear', { code })
  )
}

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
