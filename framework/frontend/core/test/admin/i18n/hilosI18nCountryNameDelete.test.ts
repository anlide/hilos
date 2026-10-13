import { describe, expect, it } from 'vitest'

import {
  createHilosI18nCountryNameDelete,
  type HilosI18nCountryNameDeleteTarget,
} from '../../../src/admin/i18n/hilosI18nCountryNameDelete.js'
import { type HilosI18nCountryContext } from '../../../src/admin/i18n/hilosI18nCountry.js'
import { type ActionLifecycle } from '../../../src/connection/actionLifecycle.js'

const coordinates = {
  countryCode: 'qj',
  countryName: 'Target',
  inLanguageCode: 'qk',
  inLanguageNativeName: 'Writing',
  expectedName: '',
}

describe('country name deletion', () => {
  it.each([
    { kind: 'base', expectedName: '' },
    { kind: 'base', expectedName: '  Saved base  ' },
    { kind: 'override', expectedName: '' },
    { kind: 'override', expectedName: 'Saved correction' },
  ] as const)(
    'dispatches $kind with the literal value [$expectedName]',
    ({ kind, expectedName }) => {
      const sent: Array<{ action: string; payload: unknown }> = []
      const handle = { requestId: 'delete-name' }
      const actions = {
        dispatch: (action: string, payload: unknown) => {
          sent.push({ action, payload })
          return handle
        },
      } as unknown as ActionLifecycle
      // No store or connection is available to mutate: deletion only dispatches.
      const context = { actions } as HilosI18nCountryContext
      const target: HilosI18nCountryNameDeleteTarget =
        kind === 'base'
          ? { ...coordinates, kind, expectedName }
          : { ...coordinates, kind, expectedName, localeCode: 'qk-US' }
      const before = { ...target }
      expect(createHilosI18nCountryNameDelete(context).deleteName(target)).toBe(
        handle,
      )
      expect(sent).toEqual([
        {
          action:
            kind === 'base'
              ? 'hilos_i18n_country_name_delete'
              : 'hilos_i18n_country_name_override_delete',
          payload: {
            countryCode: 'qj',
            inLanguageCode: 'qk',
            expectedName,
            ...(kind === 'override' ? { localeCode: 'qk-US' } : {}),
          },
        },
      ])
      expect(target).toEqual(before)
    },
  )
})
