import { describe, expect, it } from 'vitest'

import {
  createHilosI18nLanguageLocalesTable,
  HILOS_I18N_LANGUAGE_LOCALES_COLUMNS,
  HILOS_I18N_LANGUAGE_LOCALES_FRAME,
  HILOS_I18N_LANGUAGE_LOCALES_TABLE,
  resolveHilosI18nLocaleRow,
  HilosI18nLocaleRowKey,
} from '../../../src/admin/i18n/hilosI18nLocales.js'
import { type HilosI18nLanguageContext } from '../../../src/admin/i18n/hilosI18nLanguage.js'
import { type ActionLifecycle } from '../../../src/connection/actionLifecycle.js'
import {
  type HilosConnection,
  type TableViewportDescriptor,
} from '../../../src/connection/HilosConnection.js'
import { HilosPages } from '../../../src/routing/hilosPages.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'
import { createSignal } from '../../../src/state/signal.js'
import { type TableRow } from '../../../src/state/TableRowsStore.js'

/** A locales row as the backend sends it, its slot keyed `locale`. */
function localeRow(_rowKey: string, slot: Record<string, unknown>): TableRow {
  return { rowKey: String(slot.rowKey), slots: { locale: slot } }
}

const formats = {
  date: 'DD/MM/YYYY',
  time: 'HH:mm:ss',
  number: '1,000.00',
  phone: '+XX-XXXX-XXXX',
  address: 'Street, House, City, Index',
  measurement: 'metric',
  collation: 'und',
}

/** A context whose connection records every window asked for, and where. */
function recordingContext(
  sent: TableViewportDescriptor[],
  targets: string[] = [],
): HilosI18nLanguageContext {
  const connection = {
    on: () => () => {},
    registerTableWindow: () => {},
    unregisterTableWindow: () => {},
    sendTableRowFocus: () => {},
    sendTableViewport: (
      page: string,
      tableKey: string,
      descriptor: TableViewportDescriptor,
    ) => {
      targets.push(`${page}/${tableKey}`)

      return sent.push(descriptor) > 0
    },
  } as unknown as HilosConnection

  return {
    connection,
    scopes: new ScopeManager(),
    actions: {} as ActionLifecycle,
  }
}

describe('resolveHilosI18nLocaleRow', () => {
  it('waits for a subject ID and resets its window after that subject disappears', () => {
    const sent: TableViewportDescriptor[] = []
    const subject = createSignal<number | null>(null)
    const table = createHilosI18nLanguageLocalesTable(
      recordingContext(sent),
      subject,
    )
    table.start()
    table.controller.ingestWindow([], 0, true, null, null, 100)
    expect(sent).toEqual([])
    subject.set(12)
    expect(sent.at(-1)?.filter).toEqual({ language: 12 })
    subject.set(null)
    expect(sent.at(-1)?.filter).toEqual({})
    expect(sent).toHaveLength(2)
    table.dispose()
  })

  it('reads the pair, the country and the locale with its switch', () => {
    const row = resolveHilosI18nLocaleRow(
      localeRow('ru-UA', {
        languageId: 12,
        countryId: 108,
        localeId: 12108,
        rowKey: '12:108',
        countryCode: 'ua',
        countryName: 'Украина',
        localeCode: 'ru-UA',
        enabled: false,
        isCountryDefault: true,
        formats,
        catalogFormats: null,
      }),
    )

    expect(row).toEqual({
      languageId: 12,
      countryId: 108,
      localeId: 12108,
      rowKey: '12:108',
      countryCode: 'ua',
      countryName: 'Украина',
      localeCode: 'ru-UA',
      enabled: false,
      isCountryDefault: true,
      formats,
      catalogFormats: null,
    })
  })

  it('reads the language alone and a pair without a locale as nulls', () => {
    const own = resolveHilosI18nLocaleRow(
      localeRow('ru', {
        languageId: 12,
        countryId: null,
        localeId: 12000,
        rowKey: '12:null',
        countryCode: null,
        countryName: null,
        localeCode: 'ru',
        enabled: true,
        isCountryDefault: false,
        formats,
        catalogFormats: formats,
      }),
    )
    const none = resolveHilosI18nLocaleRow(
      localeRow('ru-AD', {
        languageId: 12,
        countryId: 142,
        localeId: null,
        rowKey: '12:142',
        countryCode: 'ad',
        countryName: null,
        localeCode: null,
        enabled: null,
        isCountryDefault: false,
        formats: null,
        catalogFormats: null,
      }),
    )

    expect(own.countryCode).toBeNull()
    expect(own.enabled).toBe(true)
    expect(none.countryName).toBeNull()
    expect(none.localeCode).toBeNull()
    expect(none.enabled).toBeNull()
    expect(own.formats).toEqual(formats)
    expect(own.catalogFormats).toEqual(formats)
    expect(own.isCountryDefault).toBe(false)
    expect(none.isCountryDefault).toBe(false)
    expect(none.formats).toBeNull()
  })

  it.each([undefined, null, 'true', 1])(
    'refuses a missing or non-boolean isCountryDefault (%s)',
    (isCountryDefault) => {
      const slot = {
        languageId: 12,
        countryId: 108,
        localeId: 12108,
        rowKey: '12:108',
        countryCode: 'ua',
        countryName: 'Украина',
        localeCode: 'ru-UA',
        enabled: true,
        formats,
        catalogFormats: null,
        ...(isCountryDefault === undefined ? {} : { isCountryDefault }),
      }
      expect(() =>
        resolveHilosI18nLocaleRow(localeRow('12:108', slot)),
      ).toThrow()
    },
  )

  it('rejects a row without its typed IDs', () => {
    expect(() =>
      resolveHilosI18nLocaleRow({ rowKey: '12:108', slots: {} }),
    ).toThrow()
  })
})

describe('createHilosI18nLanguageLocalesTable', () => {
  it('keeps the country-default verdict live in a focused locale across row updates', () => {
    const table = createHilosI18nLanguageLocalesTable(
      recordingContext([]),
      createSignal(12),
    )
    const slot = {
      languageId: 12,
      countryId: 108,
      localeId: 12108,
      rowKey: '12:108',
      countryCode: 'ua',
      countryName: 'Украина',
      localeCode: 'ru-UA',
      enabled: true,
      isCountryDefault: false,
      formats,
      catalogFormats: formats,
    }
    table.controller.ingestWindow(
      [localeRow('ru-UA', slot)],
      1,
      true,
      null,
      null,
      20,
    )
    expect(table.controller.focusRow('12:108')?.isCountryDefault).toBe(false)
    for (const isCountryDefault of [true, false]) {
      table.controller.ingestDelta({
        kind: 'row_updated',
        rowKey: '12:108',
        row: localeRow('ru-UA', { ...slot, isCountryDefault }),
      })
      expect(table.controller.focusedRow.get()?.isCountryDefault).toBe(
        isCountryDefault,
      )
      expect(table.controller.focusedRow.get()?.localeCode).toBe('ru-UA')
    }
    table.controller.releaseFocus()
    expect(table.controller.focusedRow.get()).toBeUndefined()
  })

  it('declares the country, code, enabled and action columns', () => {
    const table = createHilosI18nLanguageLocalesTable(
      recordingContext([]),
      createSignal(12),
    )
    const declaration = table.controller.frame.declaration

    expect(declaration).toBe(HILOS_I18N_LANGUAGE_LOCALES_FRAME)
    expect(declaration?.title).toBeUndefined()
    expect(declaration?.search).toBeUndefined()
    expect(declaration?.empty).toBeUndefined()
    expect(
      HILOS_I18N_LANGUAGE_LOCALES_COLUMNS.map((column) => [
        column.key,
        column.label,
      ]),
    ).toEqual([
      ['countryCode', 'Country'],
      ['localeCode', 'Code'],
      ['enabled', 'Enabled'],
      ['actions', ''],
    ])
    expect(HILOS_I18N_LANGUAGE_LOCALES_COLUMNS[0]).toMatchObject({
      card: 'title',
      reads: ['countryName', 'localeCode', 'enabled'],
    })
    expect(HILOS_I18N_LANGUAGE_LOCALES_COLUMNS[1]?.reads).toEqual(['enabled'])
    expect(HILOS_I18N_LANGUAGE_LOCALES_COLUMNS[3]?.reads).toEqual([
      HilosI18nLocaleRowKey.languageId,
      HilosI18nLocaleRowKey.countryId,
      HilosI18nLocaleRowKey.localeId,
      HilosI18nLocaleRowKey.localeCode,
      HilosI18nLocaleRowKey.enabled,
      HilosI18nLocaleRowKey.formats,
      HilosI18nLocaleRowKey.catalogFormats,
      HilosI18nLocaleRowKey.isCountryDefault,
    ])
  })

  it('presets the language as the filter and follows the address to another language', () => {
    const sent: TableViewportDescriptor[] = []
    const targets: string[] = []
    const language = createSignal(12)
    const table = createHilosI18nLanguageLocalesTable(
      recordingContext(sent, targets),
      language,
    )

    expect(table.controller.filter.get()).toEqual({ language: 12 })

    table.start()
    language.set(11)

    expect(sent.at(-1)?.filter).toEqual({ language: 11 })
    expect(targets.at(-1)).toBe(
      `${HilosPages.I18N_LANGUAGE_LOCALES}/${HILOS_I18N_LANGUAGE_LOCALES_TABLE}`,
    )
    table.dispose()
  })
})
