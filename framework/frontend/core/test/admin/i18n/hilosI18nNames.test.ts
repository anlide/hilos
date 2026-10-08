import { describe, expect, it } from 'vitest'

import {
  createHilosI18nNamesTable,
  HILOS_I18N_COUNTRY_NAMES,
  HILOS_I18N_LANGUAGE_NAMES,
  hilosI18nNameRowExpandable,
  resolveHilosI18nNameRow,
} from '../../../src/admin/i18n/hilosI18nNames.js'
import { type HilosI18nLanguageContext } from '../../../src/admin/i18n/hilosI18nLanguage.js'
import {
  type HilosConnection,
  type TableViewportDescriptor,
} from '../../../src/connection/HilosConnection.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'
import { createSignal } from '../../../src/state/signal.js'
import { type TableRow } from '../../../src/state/TableRowsStore.js'

/** A names row as the backend sends it, its slot keyed `language`. */
function nameRow(code: string, slot: Record<string, unknown>): TableRow {
  return { rowKey: code, slots: { language: slot } }
}

const BRITISH = {
  localeCode: 'en-GB',
  countryCode: 'gb',
  countryName: 'United Kingdom',
  name: 'German (UK)',
}

/** A context whose connection records every window asked for. */
function recordingContext(
  sent: TableViewportDescriptor[],
): HilosI18nLanguageContext {
  const connection = {
    on: () => () => {},
    registerTableWindow: () => {},
    unregisterTableWindow: () => {},
    sendTableViewport: (
      _page: string,
      _tableKey: string,
      descriptor: TableViewportDescriptor,
    ) => sent.push(descriptor) > 0,
  } as unknown as HilosConnection

  return { connection, scopes: new ScopeManager() }
}

describe('resolveHilosI18nNameRow', () => {
  it('reads the language, its base name and the corrections under it', () => {
    const row = resolveHilosI18nNameRow(
      nameRow('en', {
        rowKey: 'en',
        code: 'en',
        nativeName: 'English',
        name: 'German',
        corrections: [BRITISH],
      }),
    )

    expect(row).toEqual({
      code: 'en',
      nativeName: 'English',
      name: 'German',
      corrections: [BRITISH],
    })
  })

  it('keeps "no name" apart from a stored empty name, and an inherited correction as null', () => {
    const none = resolveHilosI18nNameRow(
      nameRow('fr', {
        code: 'fr',
        nativeName: 'Français',
        name: null,
        corrections: [],
      }),
    )
    const empty = resolveHilosI18nNameRow(
      nameRow('es', {
        code: 'es',
        nativeName: 'Español',
        name: '',
        corrections: [{ ...BRITISH, countryName: null, name: null }],
      }),
    )

    expect(none.name).toBeNull()
    expect(empty.name).toBe('')
    expect(empty.corrections[0]?.name).toBeNull()
    expect(empty.corrections[0]?.countryName).toBeNull()
  })

  it('shows a row it cannot read by its key with nothing written, rather than dropping it', () => {
    expect(resolveHilosI18nNameRow({ rowKey: 'de', slots: {} })).toEqual({
      code: 'de',
      nativeName: 'de',
      name: null,
      corrections: [],
    })
  })
})

describe('hilosI18nNameRowExpandable', () => {
  it('opens only a row with corrections to show', () => {
    const base = { code: 'en', nativeName: 'English', name: 'German' }

    expect(
      hilosI18nNameRowExpandable({ ...base, corrections: [BRITISH] }),
    ).toBe(true)
    expect(hilosI18nNameRowExpandable({ ...base, corrections: [] })).toBe(false)
  })
})

describe('createHilosI18nNamesTable', () => {
  it('declares the names columns, the wide corrections field and each table its own empty state', () => {
    const table = createHilosI18nNamesTable(
      recordingContext([]),
      HILOS_I18N_LANGUAGE_NAMES,
      createSignal('de'),
    )
    const declaration = table.controller.frame.declaration

    expect(declaration?.title).toBeUndefined()
    expect(declaration?.search).toBeUndefined()
    expect(declaration?.empty).toEqual({
      title: 'No other languages yet',
      hint: 'A name in another language can be written once that language is added.',
    })
    expect(HILOS_I18N_COUNTRY_NAMES.frame.empty).toEqual({
      title: 'No languages yet',
    })
    const corrections = HILOS_I18N_LANGUAGE_NAMES.frame.columns
    expect(
      Array.isArray(corrections) ? corrections.at(-1) : undefined,
    ).toMatchObject({
      key: 'corrections',
      detail: true,
      detailWide: true,
    })
  })

  it('tells the controller which rows have nothing to expand', () => {
    const table = createHilosI18nNamesTable(
      recordingContext([]),
      HILOS_I18N_LANGUAGE_NAMES,
      createSignal('de'),
    )
    table.controller.ingestWindow(
      [
        nameRow('en', {
          code: 'en',
          nativeName: 'English',
          name: 'German',
          corrections: [BRITISH],
        }),
        nameRow('fr', {
          code: 'fr',
          nativeName: 'Français',
          name: null,
          corrections: [],
        }),
      ],
      2,
      true,
      null,
      null,
      100,
    )

    expect(table.controller.rows.get().map((view) => view.expandable)).toEqual([
      true,
      false,
    ])
  })

  it('presets the subject as the filter and follows the address to another subject', () => {
    const sent: TableViewportDescriptor[] = []
    const subject = createSignal('de')
    const table = createHilosI18nNamesTable(
      recordingContext(sent),
      HILOS_I18N_COUNTRY_NAMES,
      subject,
    )

    expect(table.controller.filter.get()).toEqual({ country: 'de' })

    table.start()
    subject.set('gb')

    expect(sent.at(-1)?.filter).toEqual({ country: 'gb' })
    table.dispose()
  })
})
