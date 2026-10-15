import { describe, expect, it, vi } from 'vitest'

import {
  createHilosI18nLocaleDelete,
  HILOS_I18N_LOCALE_DELETE_ACTION,
  HILOS_I18N_LOCALE_DELETE_COPY,
} from '../../../src/admin/i18n/hilosI18nLocaleDelete.js'
import { type HilosI18nLanguageContext } from '../../../src/admin/i18n/hilosI18nLanguage.js'
import { createSignal } from '../../../src/state/signal.js'

describe('locale deletion', () => {
  it.each([
    [108, 12108],
    [null, 12000],
  ] as const)(
    'dispatches the captured locale and parent IDs, including countryId=%s',
    (countryId, localeId) => {
      const handle = {
        requestId: 'delete-locale',
        loading: createSignal(false),
        done: Promise.resolve({ message: 'Locale deleted.' }),
      }
      const dispatch = vi.fn(() => handle)
      const context = {
        actions: { dispatch },
      } as unknown as HilosI18nLanguageContext
      expect(
        createHilosI18nLocaleDelete(context).deleteLocale(
          12,
          countryId,
          localeId,
        ),
      ).toBe(handle)
      expect(HILOS_I18N_LOCALE_DELETE_ACTION).toBe('hilos_i18n_locale_delete')
      expect(dispatch.mock.calls).toEqual([
        ['hilos_i18n_locale_delete', { languageId: 12, countryId, localeId }],
      ])
    },
  )

  it('distinguishes a pair from the countryless locale and explains all three refusals', () => {
    const copy = HILOS_I18N_LOCALE_DELETE_COPY
    expect(copy.title('Русский', 'Украина')).toBe(
      'Delete locale · Русский in Украина',
    )
    expect(copy.title('Русский', null)).toBe(
      'Delete locale · Русский without a country',
    )
    expect(copy.body('ru', 'ua')).toContain('seven formats of this pair')
    expect(copy.body('ru', 'ua')).toContain('countryless locale — ru')
    expect(copy.body('ru', null)).toContain('seven base formats of ru')
    expect(copy.body('ru', null)).toContain('fall back to the default language')
    expect(copy.note).toContain('Switch the locale off')
    expect(copy.note).toContain('choose another locale for that country first')
    expect(copy.note).toContain('language or country name overrides')
    expect(copy.note).toContain('deletion never removes them')
  })
})
