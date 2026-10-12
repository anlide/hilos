import { describe, expect, it, vi } from 'vitest'

import { type HilosI18nLanguageContext } from '../../../src/admin/i18n/hilosI18nLanguage.js'
import {
  HILOS_I18N_LANGUAGE_MARKS,
  HILOS_I18N_LANGUAGES_COLUMNS,
  HILOS_I18N_LANGUAGES_FRAME,
  HILOS_I18N_LANGUAGES_LEGEND,
  HILOS_I18N_LANGUAGES_TABLE,
  createHilosI18nLanguagesTable,
  resolveHilosI18nLanguageRow,
} from '../../../src/admin/i18n/hilosI18nLanguages.js'
import { type TableRow } from '../../../src/state/TableRowsStore.js'
import { type HilosConnection } from '../../../src/connection/HilosConnection.js'
import { HilosPages } from '../../../src/routing/hilosPages.js'

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
      'actions',
    ])
    expect(
      HILOS_I18N_LANGUAGES_COLUMNS.slice(0, 4).every(
        (column) => column.sortable,
      ),
    ).toBe(true)
    expect(HILOS_I18N_LANGUAGES_COLUMNS[4]?.sortable).toBeUndefined()
    expect(HILOS_I18N_LANGUAGES_COLUMNS[4]?.reads).toEqual([
      'enabled',
      'nativeName',
      'rtl',
      'isOwn',
    ])
    expect(HILOS_I18N_LANGUAGES_COLUMNS[0]?.card).toBe('title')
  })

  it('takes the edited row into server focus and releases it when the window closes', () => {
    const sendTableRowFocus = vi.fn()
    const context = {
      connection: { sendTableRowFocus } as unknown as HilosConnection,
    } as HilosI18nLanguageContext
    const table = createHilosI18nLanguagesTable(context, { openAdd: vi.fn() })
    table.controller.ingestWindow(
      [
        languageRow('uk', {
          code: 'uk',
          nativeName: 'Українська',
          rtl: false,
          enabled: false,
          isDefault: false,
          isOwn: false,
        }),
      ],
      1,
      true,
      null,
      null,
      100,
    )
    expect(table.controller.focusRow('uk')?.code).toBe('uk')
    expect(sendTableRowFocus).toHaveBeenCalledWith(
      HilosPages.I18N_LANGUAGES,
      HILOS_I18N_LANGUAGES_TABLE,
      'uk',
    )
    table.controller.releaseFocus()
    expect(sendTableRowFocus).toHaveBeenLastCalledWith(
      HilosPages.I18N_LANGUAGES,
      HILOS_I18N_LANGUAGES_TABLE,
      '',
    )
    table.dispose()
  })

  it('binds Add language to the opener of each table instance', () => {
    const firstOpen = vi.fn()
    const secondOpen = vi.fn()
    const context = {} as HilosI18nLanguageContext
    const first = createHilosI18nLanguagesTable(context, { openAdd: firstOpen })
    const second = createHilosI18nLanguagesTable(context, {
      openAdd: secondOpen,
    })
    const action = first.controller.frame.declaration?.mainAction
    expect(action?.label).toBe('Add language')
    action?.press()
    expect(firstOpen).toHaveBeenCalledOnce()
    expect(secondOpen).not.toHaveBeenCalled()
    second.controller.frame.declaration?.mainAction?.press()
    expect(secondOpen).toHaveBeenCalledOnce()
    expect(HILOS_I18N_LANGUAGES_FRAME.mainAction).toBeUndefined()
    first.dispose()
    second.dispose()
  })
})

describe('HILOS_I18N_LANGUAGES_LEGEND', () => {
  it('references default and own marks and maps their meanings', () => {
    expect(HILOS_I18N_LANGUAGES_LEGEND).toEqual([
      {
        key: 'default',
        mark: HILOS_I18N_LANGUAGE_MARKS.default,
        meaning:
          'the language from the environment; it can be neither switched off nor deleted',
      },
      {
        key: 'own',
        mark: HILOS_I18N_LANGUAGE_MARKS.own,
        meaning:
          'its code is not in the built-in catalog: nothing refreshes it, keeping it up is yours',
      },
    ])
  })
})
