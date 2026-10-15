import { describe, expect, it, vi } from 'vitest'

import {
  createHilosI18nNamesTable,
  HILOS_I18N_COUNTRY_NAMES,
  HILOS_I18N_LANGUAGE_NAMES,
  HILOS_I18N_NAMES_COLUMNS,
  hilosI18nNameRowExpandable,
  resolveHilosI18nNameRow,
} from '../../../src/admin/i18n/hilosI18nNames.js'
import { type HilosI18nLanguageContext } from '../../../src/admin/i18n/hilosI18nLanguage.js'
import { type ActionLifecycle } from '../../../src/connection/actionLifecycle.js'
import {
  type HilosConnection,
  type TableViewportDescriptor,
} from '../../../src/connection/HilosConnection.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'
import { createSignal } from '../../../src/state/signal.js'
import { type TableRow } from '../../../src/state/TableRowsStore.js'

/** A names row as the backend sends it, its slot keyed `language`. */
function nameRow(_code: string, slot: Record<string, unknown>): TableRow {
  return { rowKey: String(slot.inLanguageId), slots: { language: slot } }
}

const BRITISH = {
  localeId: 501,
  nameId: 401,
  countryId: 101,
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

  return {
    connection,
    scopes: new ScopeManager(),
    actions: {} as ActionLifecycle,
  }
}

describe('resolveHilosI18nNameRow', () => {
  it('waits for a subject ID and resets its window after that subject disappears', () => {
    const sent: TableViewportDescriptor[] = []
    const subject = createSignal<number | null>(null)
    const table = createHilosI18nNamesTable(
      recordingContext(sent),
      HILOS_I18N_LANGUAGE_NAMES,
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

  it('reads the language, its base name and the corrections under it', () => {
    const row = resolveHilosI18nNameRow(
      nameRow('en', {
        inLanguageId: 11,
        nameId: 301,
        rowKey: '11',
        code: 'en',
        nativeName: 'English',
        name: 'German',
        corrections: [BRITISH],
      }),
    )

    expect(row).toEqual({
      inLanguageId: 11,
      nameId: 301,
      rowKey: '11',
      code: 'en',
      nativeName: 'English',
      name: 'German',
      corrections: [BRITISH],
    })
  })

  it('keeps "no name" apart from a stored empty name, and an inherited correction as null', () => {
    const none = resolveHilosI18nNameRow(
      nameRow('fr', {
        inLanguageId: 13,
        nameId: null,
        rowKey: '13',
        code: 'fr',
        nativeName: 'Français',
        name: null,
        corrections: [],
      }),
    )
    const empty = resolveHilosI18nNameRow(
      nameRow('es', {
        inLanguageId: 15,
        nameId: 301,
        rowKey: '15',
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

  it('retains a stored empty correction under a stored empty base', () => {
    const row = resolveHilosI18nNameRow(
      nameRow('en', {
        inLanguageId: 11,
        nameId: 301,
        rowKey: '11',
        code: 'en',
        nativeName: 'English',
        name: '',
        corrections: [{ ...BRITISH, name: '' }],
      }),
    )

    expect(row.name).toBe('')
    expect(row.corrections[0]?.name).toBe('')
    expect(hilosI18nNameRowExpandable(row)).toBe(true)
  })

  it.each([undefined, '31', true, false, 0, -1, 1.5])(
    'rejects a malformed child or parent ID %s',
    (invalid) => {
      const row = {
        rowKey: '11',
        inLanguageId: 11,
        nameId: 301,
        code: 'en',
        nativeName: 'English',
        name: 'Name',
        corrections: [BRITISH],
      }
      for (const key of ['inLanguageId', 'nameId']) {
        expect(() =>
          resolveHilosI18nNameRow(nameRow('en', { ...row, [key]: invalid })),
        ).toThrow()
      }
      for (const key of ['localeId', 'countryId', 'nameId']) {
        expect(() =>
          resolveHilosI18nNameRow(
            nameRow('en', {
              ...row,
              corrections: [{ ...BRITISH, [key]: invalid }],
            }),
          ),
        ).toThrow()
      }
    },
  )

  it('rejects a row without its writing-language ID', () => {
    expect(() => resolveHilosI18nNameRow({ rowKey: '14', slots: {} })).toThrow()
  })
})

describe('hilosI18nNameRowExpandable', () => {
  it('opens only a row with corrections to show', () => {
    const base = {
      inLanguageId: 11,
      nameId: 301,
      rowKey: '11',
      code: 'en',
      nativeName: 'English',
      name: 'German',
    }

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
      createSignal(14),
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
      Array.isArray(corrections)
        ? corrections.find((column) => column.key === 'corrections')
        : undefined,
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
      createSignal(14),
    )
    table.controller.ingestWindow(
      [
        nameRow('en', {
          inLanguageId: 11,
          nameId: 301,
          rowKey: '11',
          code: 'en',
          nativeName: 'English',
          name: 'German',
          corrections: [BRITISH],
        }),
        nameRow('fr', {
          inLanguageId: 13,
          nameId: null,
          rowKey: '13',
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
    const subject = createSignal(14)
    const table = createHilosI18nNamesTable(
      recordingContext(sent),
      HILOS_I18N_COUNTRY_NAMES,
      subject,
    )

    expect(table.controller.filter.get()).toEqual({ country: 14 })

    table.start()
    subject.set(55)

    expect(sent.at(-1)?.filter).toEqual({ country: 55 })
    table.dispose()
  })
})

describe('names table actions and focus', () => {
  it.each([HILOS_I18N_LANGUAGE_NAMES, HILOS_I18N_COUNTRY_NAMES])(
    'declares the actions and their rendered fields for $page',
    (target) => {
      const columns = target.frame.columns
      expect(Array.isArray(columns) && columns.slice(0, -1)).toEqual(
        HILOS_I18N_NAMES_COLUMNS,
      )
      expect(
        Array.isArray(columns) &&
          columns.filter((column) => column.key === 'actions'),
      ).toEqual([
        {
          key: 'actions',
          label: 'Actions',
          reads: [
            'inLanguageId',
            'nameId',
            'code',
            'nativeName',
            'name',
            'corrections',
          ],
        },
      ])
    },
  )

  it.each([HILOS_I18N_LANGUAGE_NAMES, HILOS_I18N_COUNTRY_NAMES])(
    'sends native focus frames for $page and follows the live row without removing it',
    (target) => {
      const context = recordingContext([])
      const sendFocus = vi.fn()
      context.connection.sendTableRowFocus = sendFocus
      context.connection.sendTableRendered = vi.fn()
      const { controller } = createHilosI18nNamesTable(
        context,
        target,
        createSignal(14),
      )
      const slot = {
        inLanguageId: 11,
        nameId: 301,
        rowKey: '11',
        code: 'en',
        nativeName: 'English',
        name: '',
        corrections: [BRITISH],
      }
      controller.ingestWindow([nameRow('en', slot)], 1, true, null, null, 100)
      expect(controller.focusRow('11')?.name).toBe('')
      expect(sendFocus).toHaveBeenLastCalledWith(
        target.page,
        target.tableKey,
        '11',
      )
      controller.ingestDelta({
        kind: 'row_updated',
        rowKey: '11',
        row: nameRow('en', { ...slot, name: null, corrections: [] }),
      })
      expect(controller.focusedRow.get()?.name).toBeNull()
      expect(controller.rows.get()).toHaveLength(1)
      controller.ingestDelta({
        kind: 'row_removed',
        rowKey: '11',
        reason: 'left_set',
        row: nameRow('en', { ...slot, name: 'Live outside the filter' }),
      })
      expect(controller.focusedRow.get()?.name).toBe('Live outside the filter')
      expect(controller.rows.get()).toHaveLength(1)
      controller.releaseFocus()
      expect(controller.focusedRow.get()).toBeUndefined()
      expect(sendFocus).toHaveBeenLastCalledWith(
        target.page,
        target.tableKey,
        '',
      )
    },
  )
})
