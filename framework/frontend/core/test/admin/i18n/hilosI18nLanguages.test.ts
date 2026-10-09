import { describe, expect, it } from 'vitest'

import {
  HILOS_I18N_LANGUAGES_COLUMNS,
  HILOS_I18N_LANGUAGES_FRAME,
  HILOS_I18N_LANGUAGES_TABLE,
  resolveHilosI18nLanguageRow,
} from '../../../src/admin/i18n/hilosI18nLanguages.js'
import { type TableRow } from '../../../src/state/TableRowsStore.js'

/** A language row as the backend sends it, its slot keyed `language`. */
function languageRow(
  rowKey: string,
  slot: Record<string, unknown> | undefined,
): TableRow {
  return { rowKey, slots: slot === undefined ? {} : { language: slot } }
}

describe('resolveHilosI18nLanguageRow', () => {
  it('maps every slot field onto the view-model', () => {
    expect(
      resolveHilosI18nLanguageRow(
        languageRow('en', {
          code: 'en',
          nativeName: 'English',
          rtl: false,
          enabled: true,
          isDefault: true,
          isOwn: false,
        }),
      ),
    ).toEqual({
      code: 'en',
      nativeName: 'English',
      rtl: false,
      enabled: true,
      isDefault: true,
      isOwn: false,
    })
  })

  it('keeps a row whose slot the schema refuses, named by its key', () => {
    expect(resolveHilosI18nLanguageRow(languageRow('qx', undefined))).toEqual({
      code: 'qx',
      nativeName: 'qx',
      rtl: false,
      enabled: false,
      isDefault: false,
      isOwn: false,
    })
  })
})

describe('HILOS_I18N_LANGUAGES_FRAME', () => {
  it('names the table key, searches, and sorts the four stored columns', () => {
    expect(HILOS_I18N_LANGUAGES_TABLE).toBe('hilosI18nLanguages')
    expect(HILOS_I18N_LANGUAGES_FRAME.search).toEqual({
      placeholder: 'Search languages…',
    })
    expect(HILOS_I18N_LANGUAGES_FRAME.title).toBeUndefined()
    expect(HILOS_I18N_LANGUAGES_FRAME.empty).toBeUndefined()
    expect(HILOS_I18N_LANGUAGES_COLUMNS.map((column) => column.key)).toEqual([
      'code',
      'nativeName',
      'rtl',
      'enabled',
    ])
    expect(
      HILOS_I18N_LANGUAGES_COLUMNS.every((column) => column.sortable),
    ).toBe(true)
    expect(HILOS_I18N_LANGUAGES_COLUMNS[0]?.card).toBe('title')
  })
})
