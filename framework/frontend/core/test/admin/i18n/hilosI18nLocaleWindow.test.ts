import { describe, expect, it, vi } from 'vitest'
import {
  HILOS_I18N_LOCALE_ADD_ACTION,
  HILOS_I18N_LOCALE_UPDATE_ACTION,
  HILOS_I18N_LOCALE_SWITCH_ON_ACTION,
  HILOS_I18N_LOCALE_SWITCH_OFF_ACTION,
  HILOS_I18N_LOCALE_COPY,
  LOCALE_TEMPLATES_DATA,
  createHilosI18nLocaleAdd,
  createHilosI18nLocaleEdit,
  createHilosI18nLocaleSwitchOn,
  createHilosI18nLocaleSwitchOff,
  createHilosI18nLocaleTemplates,
  hilosI18nLocaleCountryLabel,
  hilosI18nLocaleSwitchOff,
  hilosI18nLocaleWindowMode,
} from '../../../src/admin/i18n/hilosI18nLocaleWindow.js'
import { type HilosI18nLocaleRow } from '../../../src/admin/i18n/hilosI18nLocales.js'
import {
  type HilosI18nLanguageCard,
  type HilosI18nLanguageContext,
} from '../../../src/admin/i18n/hilosI18nLanguage.js'
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
    languageId: 11,
    countryId: rowKey === 'en' ? null : rowKey === 'en-GB' ? 101 : 142,
    localeId:
      formatsOfRow === null
        ? null
        : rowKey === 'en'
          ? 11000
          : rowKey === 'en-GB'
            ? 11101
            : 11142,
    rowKey:
      rowKey === 'en' ? '11:null' : rowKey === 'en-GB' ? '11:101' : '11:142',
    countryCode: rowKey === 'en' ? null : rowKey.slice(3).toLowerCase(),
    countryName: rowKey === 'en-GB' ? 'United Kingdom' : null,
    localeCode: formatsOfRow === null ? null : rowKey,
    enabled: formatsOfRow === null ? null : false,
    isCountryDefault: false,
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

const card: HilosI18nLanguageCard = {
  languageId: 11,
  code: 'en',
  nativeName: 'English',
  rtl: false,
  enabled: true,
  summary: {
    isDefault: false,
    isOwn: false,
    localeCount: 1,
    nameCount: 0,
    countrylessLocale: 'on',
    canDelete: false,
    deleteReason: 'locales',
  },
}

describe('locale window core', () => {
  it('keeps a draft when a locale is recreated under the same pair and code', async () => {
    const original = row('en-GB', formats, formats)
    const h = setup([original])
    const edit = createHilosI18nLocaleEdit(h.controller, h.context)
    edit.start()
    expect(edit.open(original.rowKey)).toBe(true)
    edit.patchForm({ date: 'YYYY-MM-DD' })
    h.focused.set({ ...original, localeId: 999 })
    expect(edit.state.get().gone).toBe(true)
    expect(edit.form.get().date).toBe('YYYY-MM-DD')
    expect(edit.canSave.get()).toBe(false)
    await edit.save(async () => true)
    expect(h.sent).toEqual([])
    edit.dispose()
  })

  it('names the switch-off step with the shared pair suffix', () => {
    expect(HILOS_I18N_LOCALE_COPY.switchOff.title('English', 'Australia')).toBe(
      'Switch off locale · English in Australia',
    )
    expect(HILOS_I18N_LOCALE_COPY.switchOff.title('English', null)).toBe(
      'Switch off locale · English without a country',
    )
  })

  it('dispatches switch-off with only the language and mandatory nullable country', () => {
    const { context, sent } = setup([])
    const switcher = createHilosI18nLocaleSwitchOff(context)
    switcher.switchOff(11, 107, 11107)
    switcher.switchOff(19, null, 19000)
    expect(sent).toEqual([
      [
        'hilos_i18n_locale_switch_off',
        { languageId: 11, countryId: 107, localeId: 11107 },
      ],
      [
        'hilos_i18n_locale_switch_off',
        { languageId: 19, countryId: null, localeId: 19000 },
      ],
    ])
    expect(HILOS_I18N_LOCALE_SWITCH_OFF_ACTION).toBe(
      'hilos_i18n_locale_switch_off',
    )
  })

  it('allows ordinary locales and protects only the default language without a country', () => {
    const countryless = { ...row('en', formats, formats), enabled: true }
    const country = { ...row('en-GB', formats, formats), enabled: true }
    const defaultCard = {
      ...card,
      summary: { ...card.summary, isDefault: true },
    }
    expect(hilosI18nLocaleSwitchOff(card, country)).toEqual({
      disabled: false,
      reason: null,
      warning: null,
    })
    expect(hilosI18nLocaleSwitchOff(defaultCard, countryless)).toEqual({
      disabled: true,
      reason:
        'The default language needs its locale without a country: it cannot be switched off.',
      warning: null,
    })
    expect(hilosI18nLocaleSwitchOff(defaultCard, country).disabled).toBe(false)
  })

  it('warns about the language fallback only while the language is enabled', () => {
    const countryless = { ...row('en', formats, formats), enabled: true }
    expect(hilosI18nLocaleSwitchOff(card, countryless)).toEqual({
      disabled: false,
      reason: null,
      warning:
        'This language is switched on. While its locale without a country is off, formats fall back to the default language.',
    })
    expect(
      hilosI18nLocaleSwitchOff({ ...card, enabled: false }, countryless),
    ).toEqual({
      disabled: false,
      reason: null,
      warning: null,
    })
  })

  it('warns about a country default independently of the language being on', () => {
    const country = {
      ...row('en-GB', formats, formats),
      enabled: true,
      isCountryDefault: true,
    }
    for (const enabled of [true, false]) {
      expect(hilosI18nLocaleSwitchOff({ ...card, enabled }, country)).toEqual({
        disabled: false,
        reason: null,
        warning:
          "This is the country's default locale. While it is off, the country uses the language's formats; its chosen locale stays in place.",
      })
    }
  })

  it('blocks a missing card, missing locale and an already switched-off row', () => {
    const country = { ...row('en-GB', formats, formats), enabled: true }
    const refused = { disabled: true, reason: null, warning: null }
    expect(hilosI18nLocaleSwitchOff(null, country)).toEqual(refused)
    for (const live of [
      undefined,
      null,
      { ...country, localeId: null, localeCode: null },
      { ...country, formats: null },
      { ...country, enabled: false },
      row('en-GB', null, formats),
    ]) {
      expect(hilosI18nLocaleSwitchOff(card, live)).toEqual(refused)
    }
  })

  it('names the switch-on step with and without a country', () => {
    expect(HILOS_I18N_LOCALE_COPY.switchOn.title('English', 'Australia')).toBe(
      'Switch on locale · English in Australia',
    )
    expect(HILOS_I18N_LOCALE_COPY.switchOn.title('English', null)).toBe(
      'Switch on locale · English without a country',
    )
  })

  it('dispatches switch-on for the current language and either country address', () => {
    const { context, sent } = setup([])
    const switcher = createHilosI18nLocaleSwitchOn(context)
    switcher.switchOn(11, 107, 11107)
    switcher.switchOn(19, null, 19000)
    expect(sent).toEqual([
      [
        HILOS_I18N_LOCALE_SWITCH_ON_ACTION,
        { languageId: 11, countryId: 107, localeId: 11107 },
      ],
      [
        HILOS_I18N_LOCALE_SWITCH_ON_ACTION,
        { languageId: 19, countryId: null, localeId: 19000 },
      ],
    ])
  })

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
    const edit = createHilosI18nLocaleEdit(controller, context)
    edit.start()
    expect(edit.open('11:101')).toBe(true)
    edit.patchForm({ date: 'YYYY-MM-DD' })
    expect(edit.canSave.get()).toBe(true)
    await edit.save(vi.fn(() => Promise.resolve(false)))
    expect(sent).toEqual([
      [
        HILOS_I18N_LOCALE_UPDATE_ACTION,
        {
          localeId: 11101,
          languageId: 11,
          countryId: 101,
          formats: { ...formats, date: 'YYYY-MM-DD' },
        },
      ],
    ])
    focused.set({ ...original, enabled: true })
    expect(edit.canSave.get()).toBe(false)
    focused.set({
      ...original,
      formats: null,
      localeId: null,
      localeCode: null,
    })
    expect(edit.state.get().gone).toBe(true)
    expect(edit.saveLabel.get()).toBe('Deleted')
    edit.dispose()
  })

  it('starts creation from the catalog, keeps the pair live, and sends only a full form', async () => {
    const known = row('en-GB', null, formats)
    const own = row('en-AD', null, null)
    const { controller, focused, context, sent } = setup([known, own])
    const add = createHilosI18nLocaleAdd(controller, context)
    expect(add.open('11:101')).toBe(true)
    expect(add.form.get()).toEqual(formats)
    expect(add.canAdd.get()).toBe(true)
    let finish: (success: boolean) => void = () => {}
    const running = add.add(
      () =>
        new Promise<boolean>((resolve) => {
          finish = resolve
        }),
    )
    focused.set({ ...known, localeId: 11101, localeCode: 'en-GB', formats })
    expect(add.elsewhere.get()).toBe(false)
    expect(add.canAdd.get()).toBe(false)
    expect(add.addAvailable.get()).toBe(true)
    finish(true)
    await running
    expect(sent).toEqual([
      [
        HILOS_I18N_LOCALE_ADD_ACTION,
        {
          languageId: 11,
          countryId: 101,
          formats,
        },
      ],
    ])
    expect(add.opened.get()).toBe(false)

    expect(add.open('11:142')).toBe(true)
    expect(add.canAdd.get()).toBe(false)
    for (const [field, value] of Object.entries(formats)) {
      add.setField(field as keyof typeof formats, value)
    }
    expect(add.canAdd.get()).toBe(true)
    focused.set({ ...own, localeId: 11142, localeCode: 'en-AD', formats })
    expect(add.elsewhere.get()).toBe(true)
    expect(add.canAdd.get()).toBe(false)
    expect(add.addAvailable.get()).toBe(false)
    add.dispose()
  })

  it('stays fit to add through its own add in flight, until the pair is gone', async () => {
    const known = row('en-GB', null, formats)
    const { controller, focused, context, sent } = setup([known])
    const add = createHilosI18nLocaleAdd(controller, context)
    expect(add.addAvailable.get()).toBe(false)
    expect(add.open('11:101')).toBe(true)
    expect(add.addAvailable.get()).toBe(true)
    add.setField('date', '')
    expect(add.addAvailable.get()).toBe(false)
    add.setField('date', formats.date)
    add.setField('measurement', 'nautical')
    expect(add.addAvailable.get()).toBe(false)
    add.setField('measurement', formats.measurement)
    expect(add.addAvailable.get()).toBe(true)
    let finish: (success: boolean) => void = () => {}
    const run = () =>
      new Promise<boolean>((resolve) => {
        finish = resolve
      })
    const running = add.add(run)
    expect(add.saving.get()).toBe(true)
    expect(add.canAdd.get()).toBe(false)
    expect(add.addAvailable.get()).toBe(true)
    await add.add(run)
    expect(sent).toHaveLength(1)
    focused.set(undefined)
    expect(add.gone.get()).toBe(true)
    expect(add.addAvailable.get()).toBe(false)
    finish(false)
    await running
    expect(add.saving.get()).toBe(false)
    expect(add.opened.get()).toBe(true)
    expect(add.canAdd.get()).toBe(false)
    add.dispose()
  })

  it('names each conflicting format and its incoming value before a choice', () => {
    const original = row('en-GB', formats, formats)
    const { controller, focused, context } = setup([original])
    const edit = createHilosI18nLocaleEdit(controller, context)
    edit.start()
    edit.open('11:101')
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
