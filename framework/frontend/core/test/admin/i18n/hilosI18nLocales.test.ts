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
function localeRow(rowKey: string, slot: Record<string, unknown>): TableRow {
  return { rowKey, slots: { locale: slot } }
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
  it('reads the pair, the country and the locale with its switch', () => {
    const row = resolveHilosI18nLocaleRow(
      localeRow('ru-UA', {
        rowKey: 'ru-UA',
        countryCode: 'ua',
        countryName: 'Украина',
        localeCode: 'ru-UA',
        enabled: false,
        formats,
        catalogFormats: null,
      }),
    )

    expect(row).toEqual({
      rowKey: 'ru-UA',
      countryCode: 'ua',
      countryName: 'Украина',
      localeCode: 'ru-UA',
      enabled: false,
      formats,
      catalogFormats: null,
    })
  })

  it('reads the language alone and a pair without a locale as nulls', () => {
    const own = resolveHilosI18nLocaleRow(
      localeRow('ru', {
        rowKey: 'ru',
        countryCode: null,
        countryName: null,
        localeCode: 'ru',
        enabled: true,
        formats,
        catalogFormats: formats,
      }),
    )
    const none = resolveHilosI18nLocaleRow(
      localeRow('ru-AD', {
        rowKey: 'ru-AD',
        countryCode: 'ad',
        countryName: null,
        localeCode: null,
        enabled: null,
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
    expect(none.formats).toBeNull()
  })

  it('shows a row it cannot read by its key with no locale, rather than dropping it', () => {
    expect(resolveHilosI18nLocaleRow({ rowKey: 'ru-UA', slots: {} })).toEqual({
      rowKey: 'ru-UA',
      countryCode: null,
      countryName: null,
      localeCode: null,
      enabled: null,
      formats: null,
      catalogFormats: null,
    })
  })
})

describe('createHilosI18nLanguageLocalesTable', () => {
  it('declares the country, code, enabled and action columns', () => {
    const table = createHilosI18nLanguageLocalesTable(
      recordingContext([]),
      createSignal('ru'),
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
      HilosI18nLocaleRowKey.localeCode,
      HilosI18nLocaleRowKey.enabled,
      HilosI18nLocaleRowKey.formats,
      HilosI18nLocaleRowKey.catalogFormats,
    ])
  })

  it('presets the language as the filter and follows the address to another language', () => {
    const sent: TableViewportDescriptor[] = []
    const targets: string[] = []
    const language = createSignal('ru')
    const table = createHilosI18nLanguageLocalesTable(
      recordingContext(sent, targets),
      language,
    )

    expect(table.controller.filter.get()).toEqual({ language: 'ru' })

    table.start()
    language.set('en')

    expect(sent.at(-1)?.filter).toEqual({ language: 'en' })
    expect(targets.at(-1)).toBe(
      `${HilosPages.I18N_LANGUAGE_LOCALES}/${HILOS_I18N_LANGUAGE_LOCALES_TABLE}`,
    )
    table.dispose()
  })
})
