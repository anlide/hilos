import { describe, expect, it } from 'vitest'

import {
  HILOS_I18N_LANGUAGE_SWITCH_OFF_ACTION,
  HILOS_I18N_LANGUAGE_SWITCH_OFF_COPY,
  HILOS_I18N_LANGUAGE_SWITCH_ON_ACTION,
  HILOS_I18N_LANGUAGE_SWITCH_ON_COPY,
  createHilosI18nLanguageSwitchOff,
  createHilosI18nLanguageSwitchOn,
  hilosI18nLanguageSwitchOff,
  hilosI18nLanguageSwitchOn,
} from '../../../src/admin/i18n/hilosI18nLanguageSwitch.js'
import {
  languageCardSchema,
  type HilosI18nLanguageCard,
  type HilosI18nLanguageContext,
} from '../../../src/admin/i18n/hilosI18nLanguage.js'
import { type ActionLifecycle } from '../../../src/connection/actionLifecycle.js'

const card: HilosI18nLanguageCard = {
  code: 'fr',
  nativeName: 'Français',
  rtl: false,
  enabled: true,
  summary: {
    isDefault: false,
    isOwn: false,
    localeCount: 0,
    nameCount: 0,
    countrylessLocale: 'missing',
    canDelete: true,
    deleteReason: null,
  },
}

describe('language switch-off', () => {
  it('shows only for enabled languages and explains the default refusal', () => {
    expect(hilosI18nLanguageSwitchOff(card)).toEqual({
      shown: true,
      disabled: false,
      reason: null,
    })
    expect(
      hilosI18nLanguageSwitchOff({
        ...card,
        summary: { ...card.summary, isDefault: true },
      }),
    ).toEqual({
      shown: true,
      disabled: true,
      reason: HILOS_I18N_LANGUAGE_SWITCH_OFF_COPY.defaultReason,
    })
    expect(hilosI18nLanguageSwitchOff({ ...card, enabled: false }).shown).toBe(
      false,
    )
    expect(hilosI18nLanguageSwitchOff(null).shown).toBe(false)
  })

  it('dispatches the agreed action and languageCode payload', () => {
    const sent: Array<{ action: string; payload: unknown }> = []
    const handle = { requestId: 'switch-off-request' }
    const actions = {
      dispatch: (action: string, payload: unknown) => {
        sent.push({ action, payload })
        return handle
      },
    } as unknown as ActionLifecycle
    const context = { actions } as HilosI18nLanguageContext

    expect(createHilosI18nLanguageSwitchOff(context).switchOff('fr')).toBe(
      handle,
    )
    expect(sent).toEqual([
      {
        action: HILOS_I18N_LANGUAGE_SWITCH_OFF_ACTION,
        payload: { languageCode: 'fr' },
      },
    ])
  })
})

describe('language switch-on', () => {
  it('parses the live countryless locale field and rejects an old summary', () => {
    for (const countrylessLocale of ['missing', 'off', 'on'] as const) {
      expect(
        languageCardSchema.safeParse({
          ...card,
          summary: { ...card.summary, countrylessLocale },
        }).success,
      ).toBe(true)
    }
    const { countrylessLocale, ...oldSummary } = card.summary
    expect(countrylessLocale).toBe('missing')
    expect(
      languageCardSchema.safeParse({ ...card, summary: oldSummary }).success,
    ).toBe(false)
  })

  it('shows only for disabled languages and reads all three locale states', () => {
    const disabled = { ...card, enabled: false }
    expect(hilosI18nLanguageSwitchOn(disabled)).toEqual({
      shown: true,
      locale: 'missing',
      warning: HILOS_I18N_LANGUAGE_SWITCH_ON_COPY.warning.missing,
      nameCount: 0,
    })
    expect(
      hilosI18nLanguageSwitchOn({
        ...disabled,
        summary: { ...card.summary, countrylessLocale: 'off', nameCount: 3 },
      }),
    ).toEqual({
      shown: true,
      locale: 'off',
      warning: HILOS_I18N_LANGUAGE_SWITCH_ON_COPY.warning.off,
      nameCount: 3,
    })
    expect(
      hilosI18nLanguageSwitchOn({
        ...disabled,
        summary: { ...card.summary, countrylessLocale: 'on' },
      }),
    ).toEqual({ shown: true, locale: 'on', warning: null, nameCount: 0 })
    expect(hilosI18nLanguageSwitchOn(card).shown).toBe(false)
    expect(hilosI18nLanguageSwitchOn(null)).toEqual({
      shown: false,
      locale: 'missing',
      warning: null,
      nameCount: 0,
    })
  })

  it('dispatches the agreed action and languageCode payload', () => {
    const sent: Array<{ action: string; payload: unknown }> = []
    const handle = { requestId: 'switch-on-request' }
    const actions = {
      dispatch: (action: string, payload: unknown) => {
        sent.push({ action, payload })
        return handle
      },
    } as unknown as ActionLifecycle
    const context = { actions } as HilosI18nLanguageContext

    expect(createHilosI18nLanguageSwitchOn(context).switchOn('fr')).toBe(handle)
    expect(sent).toEqual([
      {
        action: HILOS_I18N_LANGUAGE_SWITCH_ON_ACTION,
        payload: { languageCode: 'fr' },
      },
    ])
  })
})
