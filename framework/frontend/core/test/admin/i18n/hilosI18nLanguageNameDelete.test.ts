import { describe, expect, it } from 'vitest'

import {
  createHilosI18nLanguageNameDelete,
  type HilosI18nLanguageNameDeleteTarget,
} from '../../../src/admin/i18n/hilosI18nLanguageNameDelete.js'
import { type HilosI18nLanguageContext } from '../../../src/admin/i18n/hilosI18nLanguage.js'
import { type ActionLifecycle } from '../../../src/connection/actionLifecycle.js'

const coordinates = {
  languageCode: 'qv',
  languageNativeName: 'Target',
  inLanguageCode: 'qw',
  inLanguageNativeName: 'Writing',
  expectedName: '',
}

describe('language name deletion', () => {
  it.each(['base', 'override'] as const)(
    'dispatches the exact %s payload, including a saved empty string',
    (kind) => {
      const sent: Array<{ action: string; payload: unknown }> = []
      const handle = { requestId: 'delete-name' }
      const actions = {
        dispatch: (action: string, payload: unknown) => {
          sent.push({ action, payload })
          return handle
        },
      } as unknown as ActionLifecycle
      // No store or connection is available to mutate: deletion only dispatches.
      const context = { actions } as HilosI18nLanguageContext
      const target: HilosI18nLanguageNameDeleteTarget =
        kind === 'base'
          ? { ...coordinates, kind }
          : { ...coordinates, kind, localeCode: 'qw-US' }
      const before = { ...target }
      expect(
        createHilosI18nLanguageNameDelete(context).deleteName(target),
      ).toBe(handle)
      expect(sent).toEqual([
        {
          action:
            kind === 'base'
              ? 'hilos_i18n_language_name_delete'
              : 'hilos_i18n_language_name_override_delete',
          payload: {
            languageCode: 'qv',
            inLanguageCode: 'qw',
            expectedName: '',
            ...(kind === 'override' ? { localeCode: 'qw-US' } : {}),
          },
        },
      ])
      expect(target).toEqual(before)
    },
  )
})
