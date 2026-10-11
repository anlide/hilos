import { describe, expect, it } from 'vitest'
import {
  BUILT_IN_CATALOG_DATA,
  BUILT_IN_COUNTRY_CODES_DATA,
  createHilosI18nBuiltInCatalog,
  createHilosI18nBuiltInCountryCodes,
} from '../../../src/admin/i18n/hilosI18nBuiltInCatalog.js'
import { HilosPages } from '../../../src/routing/hilosPages.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'

describe('createHilosI18nBuiltInCatalog', () => {
  it('resolves the tally when page data carries a valid shape', () => {
    const scopes = new ScopeManager()
    const page = scopes.openPage(HilosPages.I18N_LANGUAGES)
    page.data.set(BUILT_IN_CATALOG_DATA, {
      languageCount: 50,
      countryCount: 51,
    })

    const signal = createHilosI18nBuiltInCatalog({ scopes })
    expect(signal.get()).toEqual({
      languageCount: 50,
      countryCount: 51,
    })
  })

  it('resolves to null when page data is missing or undefined', () => {
    const scopes = new ScopeManager()
    scopes.openPage(HilosPages.I18N_LANGUAGES)

    const signal = createHilosI18nBuiltInCatalog({ scopes })
    expect(signal.get()).toBeNull()
  })

  it('resolves to null when page data has an extra key', () => {
    const scopes = new ScopeManager()
    const page = scopes.openPage(HilosPages.I18N_LANGUAGES)
    page.data.set(BUILT_IN_CATALOG_DATA, {
      languageCount: 50,
      countryCount: 51,
      extra: true,
    })

    const signal = createHilosI18nBuiltInCatalog({ scopes })
    expect(signal.get()).toBeNull()
  })

  it('resolves to null when counts contain fractions', () => {
    const scopes = new ScopeManager()
    const page = scopes.openPage(HilosPages.I18N_LANGUAGES)
    page.data.set(BUILT_IN_CATALOG_DATA, {
      languageCount: 50.5,
      countryCount: 51,
    })

    const signal = createHilosI18nBuiltInCatalog({ scopes })
    expect(signal.get()).toBeNull()
  })

  it('resolves to null when counts contain negative numbers', () => {
    const scopes = new ScopeManager()
    const page = scopes.openPage(HilosPages.I18N_LANGUAGES)
    page.data.set(BUILT_IN_CATALOG_DATA, {
      languageCount: 50,
      countryCount: -1,
    })

    const signal = createHilosI18nBuiltInCatalog({ scopes })
    expect(signal.get()).toBeNull()
  })
})

describe('createHilosI18nBuiltInCountryCodes', () => {
  it('reads the ordered list of country codes from page data', () => {
    const scopes = new ScopeManager()
    const page = scopes.openPage(HilosPages.I18N_COUNTRIES)
    page.data.set(BUILT_IN_COUNTRY_CODES_DATA, ['gb', 'us'])

    expect(createHilosI18nBuiltInCountryCodes({ scopes }).get()).toEqual([
      'gb',
      'us',
    ])
  })

  it('returns null for missing or malformed data', () => {
    const scopes = new ScopeManager()
    const page = scopes.openPage(HilosPages.I18N_COUNTRIES)
    const codes = createHilosI18nBuiltInCountryCodes({ scopes })
    expect(codes.get()).toBeNull()
    page.data.set(BUILT_IN_COUNTRY_CODES_DATA, ['us', 42])
    expect(codes.get()).toBeNull()
  })
})
