import { describe, expect, it } from 'vitest'

import {
  HILOS_I18N_LANGUAGE_DELETE_ACTION,
  HILOS_I18N_LANGUAGE_ROW_DELETE_ACTION,
  HILOS_I18N_LANGUAGE_DELETE_COPY,
  createHilosI18nLanguageDelete,
  hilosI18nLanguageDelete,
} from '../../../src/admin/i18n/hilosI18nLanguageDelete.js'
import { type HilosI18nLanguageContext } from '../../../src/admin/i18n/hilosI18nLanguage.js'
import { type ActionLifecycle } from '../../../src/connection/actionLifecycle.js'

describe('language deletion', () => {
  it('reads the same server hint for a row and a card summary', () => {
    expect(
      hilosI18nLanguageDelete({ canDelete: true, deleteReason: null }),
    ).toEqual({ disabled: false, reason: null })
    for (const deleteReason of ['default', 'locales', 'names'] as const) {
      expect(
        hilosI18nLanguageDelete({ canDelete: false, deleteReason }),
      ).toEqual({
        disabled: true,
        reason: HILOS_I18N_LANGUAGE_DELETE_COPY.reasons[deleteReason],
      })
    }
    expect(hilosI18nLanguageDelete(null)).toEqual({
      disabled: true,
      reason: null,
    })
  })

  it.each([
    HILOS_I18N_LANGUAGE_DELETE_ACTION,
    HILOS_I18N_LANGUAGE_ROW_DELETE_ACTION,
  ] as const)(
    'dispatches %s with only the language code through the context',
    (action) => {
      const sent: Array<{ action: string; payload: unknown }> = []
      const handle = { requestId: 'delete-request' }
      const actions = {
        dispatch: (name: string, payload: unknown) => {
          sent.push({ action: name, payload })
          return handle
        },
      } as unknown as ActionLifecycle
      const context = { actions } as HilosI18nLanguageContext
      expect(
        createHilosI18nLanguageDelete(context, action).deleteLanguage('fr'),
      ).toBe(handle)
      expect(sent).toEqual([{ action, payload: { languageCode: 'fr' } }])
    },
  )
})
