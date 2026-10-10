import { describe, expect, it } from 'vitest'

import {
  HILOS_I18N_COUNTRIES_COLUMNS,
  HILOS_I18N_COUNTRIES_FRAME,
  HILOS_I18N_COUNTRIES_TABLE,
  resolveHilosI18nCountryRow,
} from '../../../src/admin/i18n/hilosI18nCountries.js'
import { type TableRow } from '../../../src/state/TableRowsStore.js'

/** A country row as the backend sends it, its slot keyed `country`. */
function countryRow(
  rowKey: string,
  slot: Record<string, unknown> | undefined,
): TableRow {
  return { rowKey, slots: slot === undefined ? {} : { country: slot } }
}

describe('resolveHilosI18nCountryRow', () => {
  it('maps a row with an empty name and no default locale, and no id', () => {
    expect(
      resolveHilosI18nCountryRow(
        countryRow('us', {
          code: 'us',
          name: null,
          currencySymbol: '$',
          currencyCode: 'USD',
          defaultLocaleCode: null,
          enabled: false,
          isOwn: false,
        }),
      ),
    ).toEqual({
      code: 'us',
      name: null,
      currencySymbol: '$',
      currencyCode: 'USD',
      defaultLocaleCode: null,
      enabled: false,
      isOwn: false,
    })
  })

  it('keeps a row whose slot the schema refuses, named by its key', () => {
    expect(resolveHilosI18nCountryRow(countryRow('qx', undefined))).toEqual({
      code: 'qx',
      name: null,
      currencySymbol: '',
      currencyCode: '',
      defaultLocaleCode: null,
      enabled: false,
      isOwn: false,
    })
  })
})

describe('HILOS_I18N_COUNTRIES_FRAME', () => {
  it('names the table key, searches, and sorts the five columns', () => {
    expect(HILOS_I18N_COUNTRIES_TABLE).toBe('hilosI18nCountries')
    expect(HILOS_I18N_COUNTRIES_FRAME.search).toEqual({
      placeholder: 'Search countries…',
    })
    expect(HILOS_I18N_COUNTRIES_FRAME.title).toBeUndefined()
    expect(HILOS_I18N_COUNTRIES_FRAME.empty).toBeUndefined()
    expect(HILOS_I18N_COUNTRIES_COLUMNS.map((column) => column.key)).toEqual([
      'code',
      'name',
      'currencyCode',
      'defaultLocaleCode',
      'enabled',
    ])
    expect(
      HILOS_I18N_COUNTRIES_COLUMNS.every((column) => column.sortable),
    ).toBe(true)
    expect(HILOS_I18N_COUNTRIES_COLUMNS[0]?.card).toBe('title')
  })
})
