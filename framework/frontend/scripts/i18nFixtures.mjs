import * as commands from './i18n.mjs'

export const I18N_EXAMPLE_INSTALLATION = Object.freeze({
  languages: Object.freeze([
    Object.freeze({ code: 'ru', enabled: true }),
    Object.freeze({ code: 'de', enabled: true }),
    Object.freeze({ code: 'pt', enabled: true }),
    Object.freeze({ code: 'uk', enabled: false }),
  ]),
  countries: Object.freeze([
    Object.freeze({ code: 'by', own: true, enabled: false }),
    Object.freeze({ code: 'kz', own: true, enabled: false }),
  ]),
  locales: Object.freeze([
    // ru:
    Object.freeze({ code: 'ru', enabled: true }),
    Object.freeze({ code: 'ru-BY', enabled: true }),
    Object.freeze({ code: 'ru-RU', enabled: true }),
    Object.freeze({ code: 'ru-UA', enabled: true }),
    Object.freeze({ code: 'ru-KZ', enabled: false, manual: true }),
    // de:
    Object.freeze({ code: 'de', enabled: true, manual: true }),
    Object.freeze({ code: 'de-AT', enabled: true }),
    Object.freeze({ code: 'de-DE', enabled: true }),
    // pt:
    Object.freeze({ code: 'pt', enabled: true }),
    Object.freeze({ code: 'pt-BR', enabled: true }),
    Object.freeze({ code: 'pt-PT', enabled: true }),
    // en:
    Object.freeze({ code: 'en-GB', enabled: true, reserved: true }),
    Object.freeze({ code: 'en-US', enabled: true, reserved: true }),
  ]),
})

/** @typedef {ReturnType<typeof createI18nFixtureScope>} I18nFixtureScope */

/**
 * Create one test's fixture operations and awaited LIFO teardown stack.
 *
 * Declare roots before dependents, so locales and names clear first. This stack
 * belongs only to this test's span of work; no other worker or node reads it.
 *
 * @returns An independent scope; use i18nFixtures for Playwright-owned teardown.
 */
export function createI18nFixtureScope() {
  /** @type {Array<() => Promise<unknown>>} */
  const teardowns = []

  /**
   * Register test-local teardown, such as closing an observer or extra page,
   * restoring view mode, or switching a borrowed known country off. Borrowed
   * countries must start off with no default locale; never country-clear them.
   *
   * @param {() => Promise<unknown>} teardown Action awaited during cleanup.
   * @returns {void}
   */
  function defer(teardown) {
    teardowns.push(teardown)
  }

  /**
   * Attempt every registered teardown, then report all refusals together.
   * Consumed actions are not run again on a second cleanup call.
   *
   * @returns {Promise<void>}
   * @throws {AggregateError} After all actions run, if any failed.
   */
  async function cleanup() {
    const errors = []
    while (teardowns.length > 0) {
      const teardown = teardowns.pop()
      try {
        await teardown()
      } catch (error) {
        errors.push(error)
      }
    }
    if (errors.length > 0) {
      throw new AggregateError(errors, 'I18n fixture cleanup failed')
    }
  }

  /**
   * Reserve only an explicitly test-owned UI subject. Clear its old fixture,
   * then arm cleanup before UI-add, even if the test never reads the new row.
   *
   * @param {(code: string) => Promise<unknown>} clear Owner's clear operation.
   * @param {string} code Address reserved by this test, never a business row.
   * @returns {Promise<string>} The reserved address, without creating its row.
   */
  async function own(clear, code) {
    await clear(code)
    defer(() => clear(code))
    return code
  }

  const scope = {
    defer,
    cleanup,

    /**
     * Create a language precondition; refusal grants no right to clear its code.
     *
     * @param {Parameters<typeof commands.createLanguage>[0]} options Language.
     * @returns {Promise<import('./i18n.mjs').CreatedI18nFixture>}
     */
    async language(options) {
      const row = await commands.createLanguage(options)
      const { code } = row
      defer(() => commands.clearLanguage(code))
      return row
    },

    /**
     * Create a country precondition and register its committed address.
     *
     * @param {Parameters<typeof commands.createCountry>[0]} options Country.
     * @returns {Promise<import('./i18n.mjs').CreatedI18nFixture>}
     */
    async country(options) {
      const row = await commands.createCountry(options)
      const { code } = row
      defer(() => commands.clearCountry(code))
      return row
    },

    /**
     * Create a locale precondition and register the library-assigned code.
     *
     * @param {Parameters<typeof commands.createLocale>[0]} options Locale.
     * @returns {Promise<import('./i18n.mjs').CreatedI18nFixture>}
     */
    async locale(options) {
      const row = await commands.createLocale(options)
      const { code } = row
      defer(() => commands.clearLocale(code))
      return row
    },

    /**
     * Prepare the fixed language-name fixture, respecting its marker guards.
     * Failed seed does not register a blanket clear of an unowned row.
     *
     * @returns {Promise<void>}
     */
    async languageNames() {
      await commands.clearLanguageNamesFixture()
      await commands.seedLanguageNamesFixture()
      defer(commands.clearLanguageNamesFixture)
    },

    /**
     * Prepare the fixed country-name fixture, respecting its marker guards.
     * Failed seed does not register a blanket clear of an unowned row.
     *
     * @returns {Promise<void>}
     */
    async countryNames() {
      await commands.clearCountryNamesFixture()
      await commands.seedCountryNamesFixture()
      defer(commands.clearCountryNamesFixture)
    },

    /**
     * Reserve this test's language UI-add subject without creating it.
     *
     * @param {string} code Explicitly test-owned reserved language address.
     * @returns {Promise<string>}
     */
    ownLanguage(code) {
      return own(commands.clearLanguage, code)
    },

    /**
     * Reserve this test's country UI-add subject without creating it.
     *
     * @param {string} code Explicitly test-owned reserved country address.
     * @returns {Promise<string>}
     */
    ownCountry(code) {
      return own(commands.clearCountry, code)
    },

    /**
     * Reserve this test's locale UI-add subject without creating it.
     *
     * @param {string} code Explicitly test-owned reserved locale address.
     * @returns {Promise<string>}
     */
    ownLocale(code) {
      return own(commands.clearLocale, code)
    },

    /**
     * Set up the example installation from mockups for one test and arm its teardown.
     *
     * Roots are created before dependents; teardown runs in reverse order.
     * Fails with refusal if any address is already occupied.
     *
     * @returns {Promise<{
     *   languages: Record<'ru'|'de'|'pt'|'uk', import('./i18n.mjs').CreatedI18nFixture>,
     *   countries: Record<'by'|'kz', import('./i18n.mjs').CreatedI18nFixture>,
     *   locales: typeof I18N_EXAMPLE_INSTALLATION.locales,
     * }>}
     */
    async exampleInstallation() {
      const ru = await scope.language({
        code: 'ru',
        nativeName: 'Русский',
        enabled: true,
      })
      const de = await scope.language({
        code: 'de',
        nativeName: 'Deutsch',
        enabled: true,
      })
      const pt = await scope.language({
        code: 'pt',
        nativeName: 'Português',
        enabled: true,
      })
      const uk = await scope.language({
        code: 'uk',
        nativeName: 'Українська',
        enabled: false,
      })
      const languages = { ru, de, pt, uk }

      const by = await scope.country({
        code: 'by',
        currencySymbol: 'Br',
        currencyCode: 'BYN',
        enabled: false,
      })
      const kz = await scope.country({
        code: 'kz',
        currencySymbol: '₸',
        currencyCode: 'KZT',
        enabled: false,
      })
      const countries = { by, kz }

      await scope.ownLocale('en-GB')
      await scope.ownLocale('en-US')

      await scope.locale({
        languageId: languages.de.id,
        countryId: null,
        enabled: true,
      })
      for (const code of ['de-AT', 'de-DE']) {
        await commands.switchLocaleOn(code)
        defer(() => commands.clearLocale(code))
      }
      for (const code of ['ru', 'ru-BY', 'ru-RU', 'ru-UA']) {
        await commands.switchLocaleOn(code)
        defer(() => commands.clearLocale(code))
      }
      await scope.locale({
        languageId: languages.ru.id,
        countryId: countries.kz.id,
        enabled: false,
      })
      for (const code of ['pt', 'pt-BR', 'pt-PT']) {
        await commands.switchLocaleOn(code)
        defer(() => commands.clearLocale(code))
      }
      for (const code of ['en-GB', 'en-US']) {
        await commands.switchLocaleOn(code)
      }

      return {
        languages,
        countries,
        locales: I18N_EXAMPLE_INSTALLATION.locales,
      }
    },
  }

  return scope
}

/* eslint-disable no-empty-pattern -- Playwright parses this pattern to discover fixture dependencies; an unused name or rest pattern is refused. */
/**
 * Playwright test-scoped fixture: bind as base.extend<{i18n: I18nFixtureScope}>
 * ({i18n: i18nFixtures}) in the spec that imports its own Playwright value.
 * Playwright retains test failures itself while use resolves at teardown; a
 * cleanup refusal is thrown separately for the runner to report as well.
 * If use itself rejects, preserve it together with every cleanup failure.
 *
 * @param {object} _fixtures No browser or session dependencies.
 * @param {(scope: I18nFixtureScope) => Promise<void>} use Test's use callback.
 * @returns {Promise<void>}
 * @throws {AggregateError} Cleanup failures, with a rejected use's error first.
 */
export async function i18nFixtures({}, use) {
  /* eslint-enable no-empty-pattern */
  const scope = createI18nFixtureScope()
  let useFailed = false
  /** @type {unknown[]} */
  const errors = []
  try {
    await use(scope)
  } catch (error) {
    useFailed = true
    errors.push(error)
  } finally {
    try {
      await scope.cleanup()
    } catch (error) {
      errors.push(...error.errors)
    }
  }
  if (useFailed && errors.length === 1) {
    throw errors[0]
  }
  if (errors.length > 0) {
    throw new AggregateError(errors, 'I18n fixture teardown failed', {
      cause: errors[0],
    })
  }
}
