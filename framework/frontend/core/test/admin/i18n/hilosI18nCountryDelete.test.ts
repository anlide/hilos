import { describe, expect, it, vi } from 'vitest'

import {
  createHilosI18nCountryDelete,
  createHilosI18nCountryRowDelete,
  hilosI18nCountryDeleteFromCard,
  hilosI18nCountryDeleteFromRow,
  hilosI18nCountryDeleteLabel,
  hilosI18nCountryDeleteReason,
} from '../../../src/admin/i18n/hilosI18nCountryDelete.js'
import { resolveHilosI18nCountryRow } from '../../../src/admin/i18n/hilosI18nCountries.js'
import { type HilosI18nCountryContext } from '../../../src/admin/i18n/hilosI18nCountry.js'
import { createSignal } from '../../../src/state/signal.js'

const slot = {
  countryId: 141,
  rowKey: '141',
  code: 'qx',
  name: null,
  currencySymbol: '¤',
  currencyCode: 'XQX',
  defaultLocaleId: null,
  defaultLocaleCode: null,
  enabled: true,
  isOwn: true,
  locales: [],
  catalogDefaultLocaleCode: null,
  canDelete: true,
  deleteReason: null,
}

describe('country deletion', () => {
  it('dispatches each page door with only countryCode and returns its handle', () => {
    const handle = {
      requestId: '1',
      loading: createSignal(false),
      done: Promise.resolve({ message: 'Country deleted.' }),
    }
    const dispatch = vi.fn(() => handle)
    const context = {
      actions: { dispatch },
    } as unknown as HilosI18nCountryContext
    expect(createHilosI18nCountryDelete(context).deleteCountry(141)).toBe(
      handle,
    )
    expect(createHilosI18nCountryRowDelete(context).deleteCountry(147)).toBe(
      handle,
    )
    expect(dispatch.mock.calls).toEqual([
      ['hilos_i18n_country_delete', { countryId: 141 }],
      ['hilos_i18n_country_row_delete', { countryId: 147 }],
    ])
  })

  it('projects the same server verdict from row and card, irrespective of enabled', () => {
    const row = resolveHilosI18nCountryRow({
      rowKey: 'qx',
      slots: { country: slot },
    })
    const target = {
      countryId: 141,
      code: 'qx',
      name: null,
      canDelete: true,
      deleteReason: null,
    }
    expect(hilosI18nCountryDeleteFromRow(row)).toEqual(target)
    expect(
      hilosI18nCountryDeleteFromCard({
        countryId: 141,
        code: 'qx',
        currencySymbol: '¤',
        currencyCode: 'XQX',
        enabled: false,
        summary: {
          ...target,
          isOwn: true,
          defaultLocaleId: null,
          defaultLocaleCode: null,
          defaultLocale: 'missing',
          localeCount: 0,
          nameCount: 0,
          locales: [],
          catalogDefaultLocaleCode: null,
        },
      }),
    ).toEqual(target)
    expect(hilosI18nCountryDeleteLabel(target)).toBe('QX')
    expect(hilosI18nCountryDeleteLabel({ ...target, name: '' })).toBe('QX')
    expect(hilosI18nCountryDeleteLabel({ ...target, name: 'Custom' })).toBe(
      'Custom',
    )
    expect(hilosI18nCountryDeleteFromCard(null)).toBeNull()
    expect(hilosI18nCountryDeleteFromRow(undefined)).toBeNull()
  })

  it.each(['known', 'locales', 'names'] as const)(
    'keeps the server reason %s',
    (reason) => {
      const row = resolveHilosI18nCountryRow({
        rowKey: 'qx',
        slots: { country: { ...slot, canDelete: false, deleteReason: reason } },
      })
      expect(hilosI18nCountryDeleteFromRow(row)?.deleteReason).toBe(reason)
      expect(hilosI18nCountryDeleteReason(row)).toBe(
        {
          known:
            'Cannot delete: the built-in catalog knows this country and the reflow would bring it back; switch it off instead',
          locales: 'Cannot delete: locales refer to this country',
          names: 'Cannot delete: names refer to this country',
        }[reason],
      )
    },
  )

  it.each([
    undefined,
    { ...slot, canDelete: undefined },
    { ...slot, canDelete: 'true' },
    { ...slot, deleteReason: undefined },
    { ...slot, deleteReason: 'other' },
  ])('never authorizes a missing or malformed slot', (country) => {
    expect(() =>
      resolveHilosI18nCountryRow({ rowKey: '141', slots: { country } }),
    ).toThrow()
  })
})
