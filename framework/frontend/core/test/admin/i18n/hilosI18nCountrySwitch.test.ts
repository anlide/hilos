import { describe, expect, it } from 'vitest'

import {
  HILOS_I18N_COUNTRY_SWITCH_OFF_ACTION,
  HILOS_I18N_COUNTRY_SWITCH_ON_ACTION,
  HILOS_I18N_COUNTRY_SWITCH_ON_COPY,
  createHilosI18nCountrySwitchOff,
  createHilosI18nCountrySwitchOn,
  hilosI18nCountryLabel,
  hilosI18nCountrySwitchOn,
} from '../../../src/admin/i18n/hilosI18nCountrySwitch.js'
import {
  countryCardSchema,
  type HilosI18nCountryCard,
  type HilosI18nCountryContext,
} from '../../../src/admin/i18n/hilosI18nCountry.js'
import { type ActionLifecycle } from '../../../src/connection/actionLifecycle.js'

const card: HilosI18nCountryCard = {
  code: 'ca',
  currencySymbol: '$',
  currencyCode: 'CAD',
  enabled: false,
  summary: {
    name: 'Canada',
    isOwn: false,
    defaultLocaleCode: null,
    defaultLocale: 'missing',
    localeCount: 0,
    nameCount: 0,
    canDelete: true,
    deleteReason: null,
  },
}

describe('country label', () => {
  it('returns the localized name if present, or the uppercase code when name is null', () => {
    expect(hilosI18nCountryLabel(card)).toBe('Canada')
    expect(
      hilosI18nCountryLabel({
        ...card,
        code: 'ca',
        summary: { ...card.summary, name: null },
      }),
    ).toBe('CA')
  })
})

describe('country switch-on', () => {
  it('parses the defaultLocale field and rejects an old summary without it', () => {
    for (const defaultLocale of ['missing', 'off', 'on'] as const) {
      expect(
        countryCardSchema.safeParse({
          ...card,
          summary: { ...card.summary, defaultLocale },
        }).success,
      ).toBe(true)
    }
    const { defaultLocale, ...oldSummary } = card.summary
    expect(defaultLocale).toBe('missing')
    expect(
      countryCardSchema.safeParse({ ...card, summary: oldSummary }).success,
    ).toBe(false)
  })

  it('shows only for disabled countries and reads all three default locale states', () => {
    expect(hilosI18nCountrySwitchOn(card)).toEqual({
      shown: true,
      defaultLocale: 'missing',
      note: HILOS_I18N_COUNTRY_SWITCH_ON_COPY.note.missing,
    })

    expect(
      hilosI18nCountrySwitchOn({
        ...card,
        summary: {
          ...card.summary,
          defaultLocale: 'off',
          defaultLocaleCode: 'en-CA',
        },
      }),
    ).toEqual({
      shown: true,
      defaultLocale: 'off',
      note: HILOS_I18N_COUNTRY_SWITCH_ON_COPY.note.off('en-CA'),
    })

    expect(
      hilosI18nCountrySwitchOn({
        ...card,
        summary: {
          ...card.summary,
          defaultLocale: 'on',
          defaultLocaleCode: 'en-CA',
        },
      }),
    ).toEqual({
      shown: true,
      defaultLocale: 'on',
      note: null,
    })

    expect(hilosI18nCountrySwitchOn({ ...card, enabled: true }).shown).toBe(
      false,
    )

    expect(hilosI18nCountrySwitchOn(null)).toEqual({
      shown: false,
      defaultLocale: 'missing',
      note: null,
    })
  })

  it('dispatches the agreed action and countryCode payload', () => {
    const sent: Array<{ action: string; payload: unknown }> = []
    const handle = { requestId: 'switch-on-country-request' }
    const actions = {
      dispatch: (action: string, payload: unknown) => {
        sent.push({ action, payload })
        return handle
      },
    } as unknown as ActionLifecycle
    const context = { actions } as HilosI18nCountryContext

    expect(createHilosI18nCountrySwitchOn(context).switchOn('ca')).toBe(handle)
    expect(sent).toEqual([
      {
        action: HILOS_I18N_COUNTRY_SWITCH_ON_ACTION,
        payload: { countryCode: 'ca' },
      },
    ])
  })
})

describe('country switch-off', () => {
  it('dispatches the agreed action and countryCode payload', () => {
    const sent: Array<{ action: string; payload: unknown }> = []
    const handle = { requestId: 'switch-off-country-request' }
    const actions = {
      dispatch: (action: string, payload: unknown) => {
        sent.push({ action, payload })
        return handle
      },
    } as unknown as ActionLifecycle
    const context = { actions } as HilosI18nCountryContext

    expect(createHilosI18nCountrySwitchOff(context).switchOff('ca')).toBe(
      handle,
    )
    expect(sent).toEqual([
      {
        action: HILOS_I18N_COUNTRY_SWITCH_OFF_ACTION,
        payload: { countryCode: 'ca' },
      },
    ])
  })
})
