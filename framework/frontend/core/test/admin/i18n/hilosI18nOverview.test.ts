import { describe, expect, it } from 'vitest'
import {
  I18N_OVERVIEW_DATA,
  createHilosI18nOverview,
  i18nOverviewSchema,
} from '../../../src/admin/i18n/hilosI18nOverview.js'
import { HilosPages } from '../../../src/routing/hilosPages.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'

const overview = {
  languages: {
    enabledCount: 2,
    totalCount: 3,
    defaultLanguageCode: 'en',
    defaultLanguageName: 'English',
  },
  countries: {
    enabledCount: 4,
    totalCount: 6,
    builtInCount: 4,
    ownCount: 2,
  },
}

describe('i18nOverviewSchema', () => {
  it('accepts the complete overview shape', () => {
    expect(i18nOverviewSchema.parse(overview)).toEqual(overview)
  })

  it.each([
    ['an extra overview field', { ...overview, extra: true }],
    [
      'an extra languages field',
      { ...overview, languages: { ...overview.languages, extra: true } },
    ],
    [
      'a missing required language name',
      {
        ...overview,
        languages: { ...overview.languages, defaultLanguageName: undefined },
      },
    ],
    [
      'a non-string default language code',
      {
        ...overview,
        languages: { ...overview.languages, defaultLanguageCode: 42 },
      },
    ],
    [
      'a fractional count',
      {
        ...overview,
        languages: { ...overview.languages, enabledCount: 1.5 },
      },
    ],
    [
      'a negative count',
      {
        ...overview,
        countries: { ...overview.countries, ownCount: -1 },
      },
    ],
    [
      'more enabled languages than total languages',
      {
        ...overview,
        languages: { ...overview.languages, enabledCount: 4 },
      },
    ],
    [
      'more enabled countries than total countries',
      {
        ...overview,
        countries: { ...overview.countries, enabledCount: 7 },
      },
    ],
    [
      'country counts that do not sum to the total',
      {
        ...overview,
        countries: { ...overview.countries, ownCount: 1 },
      },
    ],
    ['a missing countries record', { languages: overview.languages }],
    ['null', null],
  ])('rejects %s', (_description, data) => {
    expect(i18nOverviewSchema.safeParse(data).success).toBe(false)
  })
})

describe('createHilosI18nOverview', () => {
  it('reads complete page data and follows both tally updates', () => {
    const scopes = new ScopeManager()
    const page = scopes.openPage(HilosPages.I18N)
    const signal = createHilosI18nOverview({ scopes })

    expect(signal.get()).toBeNull()
    page.data.set(I18N_OVERVIEW_DATA, overview)
    expect(signal.get()).toEqual(overview)

    const updated = {
      languages: {
        enabledCount: 3,
        totalCount: 5,
        defaultLanguageCode: 'fr',
        defaultLanguageName: 'Français',
      },
      countries: {
        enabledCount: 6,
        totalCount: 7,
        builtInCount: 4,
        ownCount: 3,
      },
    }
    page.data.set(I18N_OVERVIEW_DATA, updated)
    expect(signal.get()).toEqual(updated)
  })

  it('clears on missing or invalid data and never retains values across scopes', () => {
    const scopes = new ScopeManager()
    const page = scopes.openPage(HilosPages.I18N)
    const signal = createHilosI18nOverview({ scopes })

    page.data.set(I18N_OVERVIEW_DATA, overview)
    expect(signal.get()).toEqual(overview)

    page.data.set(I18N_OVERVIEW_DATA, { ...overview, extra: true })
    expect(signal.get()).toBeNull()

    page.data.set(I18N_OVERVIEW_DATA, undefined)
    expect(signal.get()).toBeNull()

    page.data.set(I18N_OVERVIEW_DATA, overview)
    expect(signal.get()).toEqual(overview)

    scopes.openPage(HilosPages.I18N_COUNTRIES)
    expect(signal.get()).toBeNull()

    scopes.dropPage()
    expect(signal.get()).toBeNull()

    scopes.openPage(HilosPages.I18N)
    expect(signal.get()).toBeNull()
  })
})
