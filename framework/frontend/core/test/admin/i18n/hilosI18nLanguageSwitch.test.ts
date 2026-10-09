import { describe, expect, it } from 'vitest'

import {
  HILOS_I18N_LANGUAGE_SWITCH_OFF_ACTION,
  HILOS_I18N_LANGUAGE_SWITCH_OFF_COPY,
  createHilosI18nLanguageSwitchOff,
  hilosI18nLanguageSwitchOff,
} from '../../../src/admin/i18n/hilosI18nLanguageSwitch.js'
import {
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
