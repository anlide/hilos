import { describe, expect, it } from 'vitest'

import { resolveHilosI18nCountryRow } from '../../../src/admin/i18n/hilosI18nCountries.js'
import {
  COUNTRY_CARD_DATA,
  countryCardSchema,
  createHilosI18nCountryCard,
  type HilosI18nCountryContext,
} from '../../../src/admin/i18n/hilosI18nCountry.js'
import { HilosPages } from '../../../src/routing/hilosPages.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'

/** The complete country payload, including locale choices and deletion hints. */
const card = {
  countryId: 140,
  code: 'ie',
  currencySymbol: '€',
  currencyCode: 'EUR',
  enabled: false,
  summary: {
    name: 'Ireland',
    isOwn: false,
    defaultLocaleId: null,
    defaultLocaleCode: null,
    defaultLocale: 'missing',
    localeCount: 2,
    nameCount: 1,
    locales: [
      { localeId: 11140, code: 'en-IE', enabled: false },
      { localeId: 46140, code: 'ga-IE', enabled: true },
    ],
    catalogDefaultLocaleCode: 'en-IE',
    canDelete: false,
    deleteReason: 'known',
  },
}

describe('country locale payloads', () => {
  it('accepts the complete card without losing locale choices or deletion hints', () => {
    expect(countryCardSchema.parse(card)).toEqual(card)
  })

  it('preserves both fields in a country table row', () => {
    const slot = {
      countryId: card.countryId,
      rowKey: String(card.countryId),
      code: card.code,
      currencySymbol: card.currencySymbol,
      currencyCode: card.currencyCode,
      enabled: card.enabled,
      name: card.summary.name,
      isOwn: card.summary.isOwn,
      defaultLocaleId: card.summary.defaultLocaleId,
      defaultLocaleCode: card.summary.defaultLocaleCode,
      locales: card.summary.locales,
      catalogDefaultLocaleCode: card.summary.catalogDefaultLocaleCode,
      canDelete: card.summary.canDelete,
      deleteReason: card.summary.deleteReason,
    }
    expect(
      resolveHilosI18nCountryRow({
        rowKey: 'ie',
        slots: { country: slot },
      }),
    ).toEqual(slot)
  })

  it('accepts an own country with no locales and no catalog suggestion', () => {
    const own = {
      ...card,
      countryId: 141,
      code: 'qx',
      summary: {
        ...card.summary,
        name: null,
        isOwn: true,
        localeCount: 0,
        nameCount: 0,
        locales: [],
        catalogDefaultLocaleCode: null,
        canDelete: true,
        deleteReason: null,
      },
    }
    expect(countryCardSchema.parse(own)).toEqual(own)
  })

  it.each(['locales', 'catalogDefaultLocaleCode'])(
    'requires the %s key even when its value can be empty',
    (key) => {
      const summary: Record<string, unknown> = { ...card.summary }
      delete summary[key]
      expect(countryCardSchema.safeParse({ ...card, summary }).success).toBe(
        false,
      )
    },
  )

  it.each([
    { locales: null },
    { locales: [{ localeId: 11140, code: 'en-IE', enabled: 'false' }] },
    { locales: [{ code: 'en-IE' }] },
    { locales: [{ code: 1, enabled: false }] },
    { locales: [{ code: 'en-IE', enabled: false, extra: true }] },
    { catalogDefaultLocaleCode: 1 },
    { extra: true },
  ])('rejects malformed or unexpected summary fields: %j', (fields) => {
    expect(
      countryCardSchema.safeParse({
        ...card,
        summary: { ...card.summary, ...fields },
      }).success,
    ).toBe(false)
  })

  it('reads locale changes from the page scope and clears a removed card', () => {
    const scopes = new ScopeManager()
    const scope = scopes.openPage(HilosPages.I18N_COUNTRY)
    const current = createHilosI18nCountryCard({
      scopes,
    } as HilosI18nCountryContext)
    expect(current.get()).toBeNull()
    scope.data.set(COUNTRY_CARD_DATA, card)
    expect(current.get()).toEqual(card)
    const updated = {
      ...card,
      summary: {
        ...card.summary,
        localeCount: 1,
        locales: [{ localeId: 11140, code: 'en-IE', enabled: true }],
      },
    }
    scope.data.set(COUNTRY_CARD_DATA, updated)
    expect(current.get()).toEqual(updated)
    scope.data.set(COUNTRY_CARD_DATA, {})
    expect(current.get()).toBeNull()
  })
})
