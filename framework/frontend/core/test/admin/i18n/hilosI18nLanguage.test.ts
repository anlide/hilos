import { describe, expect, it } from 'vitest'

import {
  createHilosI18nLanguageCard,
  LANGUAGE_CARD_DATA,
  languageCardSchema,
  type HilosI18nLanguageContext,
} from '../../../src/admin/i18n/hilosI18nLanguage.js'
import { HilosPages } from '../../../src/routing/hilosPages.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'

const card = {
  languageId: 19,
  code: 'uk',
  nativeName: 'Українська',
  rtl: false,
  enabled: false,
  summary: {
    isDefault: false,
    isOwn: false,
    localeCount: 3,
    enabledLocaleCount: 0,
    nameCount: 2,
    countrylessLocale: 'off',
    canDelete: false,
    deleteReason: 'locales',
  },
}

describe('language card enabled locale count', () => {
  it.each([0, 2])('preserves an enabled locale count of %i', (count) => {
    const value = {
      ...card,
      summary: { ...card.summary, enabledLocaleCount: count },
    }
    expect(languageCardSchema.parse(value)).toEqual(value)
  })

  it('requires the enabled locale count even when there are no enabled locales', () => {
    const summary: Record<string, unknown> = { ...card.summary }
    delete summary.enabledLocaleCount
    expect(languageCardSchema.safeParse({ ...card, summary }).success).toBe(
      false,
    )
  })

  it.each([-1, 0.5, '2', null])('rejects an invalid count of %j', (count) => {
    expect(
      languageCardSchema.safeParse({
        ...card,
        summary: { ...card.summary, enabledLocaleCount: count },
      }).success,
    ).toBe(false)
  })

  it('rejects an unknown summary key', () => {
    expect(
      languageCardSchema.safeParse({
        ...card,
        summary: { ...card.summary, extra: true },
      }).success,
    ).toBe(false)
  })

  it.each([
    HilosPages.I18N_LANGUAGE,
    HilosPages.I18N_LANGUAGE_NAMES,
    HilosPages.I18N_LANGUAGE_LOCALES,
  ])('reads live counts and clears the source on %s', (page) => {
    const scopes = new ScopeManager()
    const scope = scopes.openPage(page)
    const current = createHilosI18nLanguageCard({
      scopes,
    } as HilosI18nLanguageContext)
    expect(current.get()).toBeNull()
    scope.data.set(LANGUAGE_CARD_DATA, card)
    expect(current.get()).toEqual(card)
    const updated = {
      ...card,
      summary: { ...card.summary, enabledLocaleCount: 2 },
    }
    scope.data.set(LANGUAGE_CARD_DATA, updated)
    expect(current.get()).toEqual(updated)
    const malformed = {
      ...card,
      summary: { ...card.summary, enabledLocaleCount: '2' },
    }
    scope.data.set(LANGUAGE_CARD_DATA, malformed)
    expect(current.get()).toBeNull()
    scope.data.set(LANGUAGE_CARD_DATA, updated)
    expect(current.get()).toEqual(updated)
    scope.data.set(LANGUAGE_CARD_DATA, {})
    expect(current.get()).toBeNull()
  })
})
