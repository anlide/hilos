import { describe, expect, it, vi } from 'vitest'
import {
  HILOS_I18N_LOCALE_ADD_ACTION,
  HILOS_I18N_LOCALE_UPDATE_ACTION,
  HILOS_I18N_LOCALE_COPY,
  LOCALE_TEMPLATES_DATA,
  createHilosI18nLocaleAdd,
  createHilosI18nLocaleEdit,
  createHilosI18nLocaleTemplates,
  hilosI18nLocaleCountryLabel,
  hilosI18nLocaleWindowMode,
} from '../../../src/admin/i18n/hilosI18nLocaleWindow.js'
import { type HilosI18nLocaleRow } from '../../../src/admin/i18n/hilosI18nLocales.js'
import { type HilosI18nLanguageContext } from '../../../src/admin/i18n/hilosI18nLanguage.js'
import {
  type ActionHandle,
  type ActionLifecycle,
} from '../../../src/connection/actionLifecycle.js'
import { type HilosConnection } from '../../../src/connection/HilosConnection.js'
import { HilosPages } from '../../../src/routing/hilosPages.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'
import { createSignal } from '../../../src/state/signal.js'
import { type TableViewportController } from '../../../src/table/TableViewportController.js'

const formats = {
  date: 'DD/MM/YYYY',
  time: 'HH:mm:ss',
  number: '1,000.00',
  phone: '+XX-XXXX-XXXX',
  address: 'Street, House, City, Index',
  measurement: 'metric' as const,
  collation: 'und',
}
const templates = {
  date: ['DD/MM/YYYY', 'YYYY-MM-DD'],
  time: ['HH:mm:ss'],
  number: ['1,000.00'],
  phone: ['+XX-XXXX-XXXX'],
  address: ['Street, House, City, Index'],
  measurement: ['metric', 'imperial'],
  collation: ['und'],
}

function row(
  rowKey: string,
  formatsOfRow: typeof formats | null,
  catalogFormats: typeof formats | null,
): HilosI18nLocaleRow {
  return {
    rowKey,
    countryCode: rowKey === 'en' ? null : rowKey.slice(3).toLowerCase(),
    countryName: rowKey === 'en-GB' ? 'United Kingdom' : null,
    localeCode: formatsOfRow === null ? null : rowKey,
    enabled: formatsOfRow === null ? null : false,
    formats: formatsOfRow,
    catalogFormats,
  }
}

function setup(rows: HilosI18nLocaleRow[]) {
  const focused = createSignal<HilosI18nLocaleRow | undefined>(undefined)
  const controller = {
    focusRow: (key: string) => {
      const found = rows.find((candidate) => candidate.rowKey === key) ?? null
      focused.set(found ?? undefined)
      return found
    },
    focusedRow: focused,
    releaseFocus: () => focused.set(undefined),
  } as unknown as TableViewportController<HilosI18nLocaleRow>
  const sent: Array<[string, unknown]> = []
  const scopes = new ScopeManager()
  scopes
    .openPage(HilosPages.I18N_LANGUAGE_LOCALES)
    .data.set(LOCALE_TEMPLATES_DATA, templates)
  const context = {
    connection: {} as HilosConnection,
    scopes,
    actions: {
      dispatch: (name: string, payload: unknown) => {
        sent.push([name, payload])
        return {} as ActionHandle
      },
    } as ActionLifecycle,
  } satisfies HilosI18nLanguageContext
  return { controller, focused, context, sent }
}

describe('locale window core', () => {
  it('reads the page templates and chooses a fixed mode and country heading', () => {
    const { context } = setup([])
    expect(createHilosI18nLocaleTemplates(context).get()).toEqual(templates)
    expect(hilosI18nLocaleWindowMode(row('en-GB', null, formats))).toBe('add')
    expect(hilosI18nLocaleWindowMode(row('en-GB', formats, formats))).toBe(
      'edit',
    )
    expect(
      hilosI18nLocaleWindowMode({
        ...row('en', formats, formats),
        enabled: true,
      }),
    ).toBe('view')
    expect(hilosI18nLocaleCountryLabel(row('en-GB', null, formats))).toBe(
      'United Kingdom',
    )
    expect(hilosI18nLocaleCountryLabel(row('en-AD', null, null))).toBe('AD')
    expect(hilosI18nLocaleCountryLabel(row('en', formats, formats))).toBeNull()
    expect(
      HILOS_I18N_LOCALE_COPY.title('add', 'English', 'United Kingdom'),
    ).toBe('Add locale · English in United Kingdom')
    expect(HILOS_I18N_LOCALE_COPY.title('view', 'English', null)).toBe(
      'Locale · English without a country',
    )
  })

  it('edits the seven formats and blocks save when switched on or removed', async () => {
    const original = row('en-GB', formats, formats)
    const { controller, focused, context, sent } = setup([original])
    const edit = createHilosI18nLocaleEdit(
      controller,
      context,
      createSignal('en'),
    )
    edit.start()
    expect(edit.open('en-GB')).toBe(true)
    edit.patchForm({ date: 'YYYY-MM-DD' })
    expect(edit.canSave.get()).toBe(true)
    await edit.save(vi.fn(() => Promise.resolve(false)))
    expect(sent).toEqual([
      [
        HILOS_I18N_LOCALE_UPDATE_ACTION,
        {
          languageCode: 'en',
          countryCode: 'gb',
          formats: { ...formats, date: 'YYYY-MM-DD' },
        },
      ],
    ])
    focused.set({ ...original, enabled: true })
    expect(edit.canSave.get()).toBe(false)
    focused.set({ ...original, formats: null, localeCode: null })
    expect(edit.state.get().gone).toBe(true)
    expect(edit.saveLabel.get()).toBe('Deleted')
    edit.dispose()
  })

  it('starts creation from the catalog, keeps the pair live, and sends only a full form', async () => {
    const known = row('en-GB', null, formats)
    const own = row('en-AD', null, null)
    const { controller, focused, context, sent } = setup([known, own])
    const add = createHilosI18nLocaleAdd(
      controller,
      context,
      createSignal('en'),
    )
    expect(add.open('en-GB')).toBe(true)
    expect(add.form.get()).toEqual(formats)
    expect(add.canAdd.get()).toBe(true)
    let finish: (success: boolean) => void = () => {}
    const running = add.add(
      () =>
        new Promise<boolean>((resolve) => {
          finish = resolve
        }),
    )
    focused.set({ ...known, localeCode: 'en-GB', formats })
    expect(add.elsewhere.get()).toBe(false)
    finish(true)
    await running
    expect(sent).toEqual([
      [
        HILOS_I18N_LOCALE_ADD_ACTION,
        {
          languageCode: 'en',
          countryCode: 'gb',
          formats,
        },
      ],
    ])
    expect(add.opened.get()).toBe(false)

    expect(add.open('en-AD')).toBe(true)
    expect(add.canAdd.get()).toBe(false)
    for (const [field, value] of Object.entries(formats)) {
      add.setField(field as keyof typeof formats, value)
    }
    expect(add.canAdd.get()).toBe(true)
    focused.set({ ...own, localeCode: 'en-AD', formats })
    expect(add.elsewhere.get()).toBe(true)
    expect(add.canAdd.get()).toBe(false)
    add.dispose()
  })

  it('names each conflicting format and its incoming value before a choice', () => {
    const original = row('en-GB', formats, formats)
    const { controller, focused, context } = setup([original])
    const edit = createHilosI18nLocaleEdit(
      controller,
      context,
      createSignal('en'),
    )
    edit.start()
    edit.open('en-GB')
    edit.patchForm({ date: 'YYYY-MM-DD' })
    focused.set({ ...original, formats: { ...formats, date: 'DD.MM.YYYY' } })
    expect(edit.state.get().conflict).toBe(true)
    expect(edit.noticeText.get()).toContain(
      'Date changed elsewhere to "DD.MM.YYYY"',
    )
    expect(edit.form.get().date).toBe('YYYY-MM-DD')

    edit.keepMine()
    expect(edit.state.get().conflict).toBe(false)
    focused.set({ ...original, formats: { ...formats, date: 'MM/DD/YYYY' } })
    expect(edit.state.get().conflict).toBe(true)
    edit.takeTheirs()
    expect(edit.state.get().conflict).toBe(false)
    expect(edit.form.get().date).toBe('MM/DD/YYYY')
    edit.dispose()
  })
})
