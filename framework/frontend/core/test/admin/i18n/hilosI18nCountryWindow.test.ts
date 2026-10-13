import { describe, expect, it, vi } from 'vitest'
import {
  createHilosI18nCountryWindow,
  createHilosI18nCountryCardWindow,
  createHilosI18nCountryRowWindow,
  hilosI18nCountryEditableFromCard,
  hilosI18nCountryEditableFromRow,
  hilosI18nCountryLocaleOptions,
  hilosI18nCountrySuggestedLocale,
  hilosI18nCountryWindowMode,
  type HilosI18nCountryEditable,
  type HilosI18nCountryEditFields,
} from '../../../src/admin/i18n/hilosI18nCountryWindow.js'
import {
  type HilosI18nCountryCard,
  type HilosI18nCountryContext,
} from '../../../src/admin/i18n/hilosI18nCountry.js'
import { type HilosI18nCountryRow } from '../../../src/admin/i18n/hilosI18nCountries.js'
import {
  type ActionHandle,
  type ActionLifecycle,
} from '../../../src/connection/actionLifecycle.js'
import { hilosSignalRowEditSource } from '../../../src/conflict/rowEditSession.js'
import { createSignal } from '../../../src/state/signal.js'
import { type TableViewportController } from '../../../src/table/TableViewportController.js'

const country: HilosI18nCountryEditable = {
  code: 'ie',
  name: 'Ireland',
  currencySymbol: '€',
  currencyCode: 'EUR',
  defaultLocaleCode: null,
  enabled: false,
  isOwn: false,
  locales: [
    { code: 'ga-IE', enabled: true },
    { code: 'en-IE', enabled: false },
  ],
  catalogDefaultLocaleCode: 'en-IE',
}

function harness(overrides: Partial<HilosI18nCountryEditable> = {}) {
  const source = createSignal<HilosI18nCountryEditable | undefined>({
    ...country,
    ...overrides,
  })
  const send = vi.fn<
    (
      draft: HilosI18nCountryEditFields,
      row: HilosI18nCountryEditable,
    ) => ActionHandle
  >(() => ({}) as ActionHandle)
  const window = createHilosI18nCountryWindow(
    hilosSignalRowEditSource(source),
    send,
  )
  window.start()
  window.open('edit')
  return { source, send, window }
}

function card(row = country): HilosI18nCountryCard {
  return {
    code: row.code,
    currencySymbol: row.currencySymbol,
    currencyCode: row.currencyCode,
    enabled: row.enabled,
    summary: {
      name: row.name,
      isOwn: row.isOwn,
      defaultLocaleCode: row.defaultLocaleCode,
      defaultLocale: 'missing',
      localeCount: row.locales.length,
      nameCount: 1,
      locales: [...row.locales],
      catalogDefaultLocaleCode: row.catalogDefaultLocaleCode,
      canDelete: false,
      deleteReason: 'known',
    },
  }
}

function row(): HilosI18nCountryRow {
  return { ...country, canDelete: false, deleteReason: 'known' }
}

describe('country window', () => {
  it('projects the card and table onto the same country', () => {
    expect(hilosI18nCountryEditableFromCard(card())).toEqual(country)
    expect(hilosI18nCountryEditableFromRow(row())).toEqual(country)
  })

  it('chooses the current mode and freezes the opening label', () => {
    expect(hilosI18nCountryWindowMode(country)).toBe('edit')
    expect(hilosI18nCountryWindowMode({ ...country, enabled: true })).toBe(
      'view',
    )
    const h = harness()
    expect(h.window.title.get()).toBe('Edit country · Ireland')
    h.source.set({ ...country, name: 'Changed' })
    expect(h.window.title.get()).toBe('Edit country · Ireland')
    h.window.close()
    h.source.set({ ...country, name: null })
    h.window.open('edit')
    expect(h.window.title.get()).toBe('Edit country · IE')
    h.window.dispose()
  })

  it.each([
    [{}, 'en-IE'],
    [{ defaultLocaleCode: 'ga-IE' }, null],
    [{ catalogDefaultLocaleCode: null }, null],
    [{ locales: [] }, null],
    [{ locales: [{ code: 'en-IE', enabled: true }] }, 'en-IE'],
    [{ locales: [{ code: 'en-IE', enabled: false }] }, 'en-IE'],
  ] as const)(
    'suggests only an existing catalog locale without a chosen default: %j',
    (changes, expected) => {
      expect(hilosI18nCountrySuggestedLocale({ ...country, ...changes })).toBe(
        expected,
      )
    },
  )

  it('orders locales, annotates disabled and vanished values, and puts Not chosen last', () => {
    expect(hilosI18nCountryLocaleOptions(country, 'fr-IE')).toEqual([
      { value: 'en-IE', label: 'en-IE — switched off' },
      { value: 'ga-IE', label: 'ga-IE' },
      { value: 'fr-IE', label: 'fr-IE — no longer exists' },
      { value: '', label: 'Not chosen' },
    ])
    expect(
      hilosI18nCountryLocaleOptions({ ...country, locales: [] }, null),
    ).toEqual([{ value: '', label: 'Not chosen' }])
  })

  it('makes a suggestion saveable without asking to discard it', () => {
    const h = harness()
    expect(h.window.form.get().defaultLocaleCode).toBe('en-IE')
    expect(h.window.localeHint.get()).toBe(
      'Suggested by the built-in catalog — save to keep it.',
    )
    expect(h.window.canSave.get()).toBe(true)
    expect(h.window.touched.get()).toBe(false)
    h.window.setDefaultLocale('ga-IE')
    expect(h.window.touched.get()).toBe(true)
    expect(h.window.localeHint.get()).toBe(
      "Only this country's locales. Not chosen — formats come from the language.",
    )
    h.window.setDefaultLocale('en-IE')
    expect(h.window.touched.get()).toBe(false)
    expect(h.window.localeHint.get()).not.toContain('Suggested')
    h.window.dispose()
  })

  it('explains an empty locale list and normalizes the empty selection', () => {
    const h = harness({ locales: [] })
    expect(h.window.localeHint.get()).toBe(
      "This country has no locales yet: add one on a language's Locales page.",
    )
    h.window.setDefaultLocale('')
    expect(h.window.form.get().defaultLocaleCode).toBeNull()
    h.window.dispose()
  })

  it.each([
    [' ', false],
    ['123456789', false],
    ['😀😀😀😀😀😀😀😀', true],
    ['😀😀😀😀😀😀😀😀😀', false],
    [' € ', true],
  ])(
    'counts trimmed currency symbols by code points: %s',
    (symbol, expected) => {
      const h = harness()
      h.window.setSymbol(symbol)
      expect(h.window.canSave.get()).toBe(expected)
      h.window.dispose()
    },
  )

  it('normalizes currency code and waits for three uppercase Latin letters', () => {
    const h = harness()
    h.window.setCurrencyCode(' eu ')
    expect(h.window.form.get().currencyCode).toBe('EU')
    expect(h.window.canSave.get()).toBe(false)
    h.window.setCurrencyCode(' eur ')
    expect(h.window.form.get().currencyCode).toBe('EUR')
    expect(h.window.canSave.get()).toBe(true)
    h.window.setCurrencyCode('ЕUR')
    expect(h.window.canSave.get()).toBe(false)
    h.window.dispose()
  })

  it('closes an unchanged form without dispatching', async () => {
    const h = harness({ defaultLocaleCode: 'en-IE' })
    expect(h.window.canSave.get()).toBe(false)
    await h.window.save(vi.fn())
    expect(h.window.opened.get()).toBe(false)
    expect(h.send).not.toHaveBeenCalled()
    h.window.dispose()
  })

  it('holds the draft when switched on elsewhere and offers the frozen plate', () => {
    const h = harness()
    h.window.setSymbol('IE€')
    h.source.set({ ...country, enabled: true })
    expect(h.window.mode.get()).toBe('edit')
    expect(h.window.form.get().currencySymbol).toBe('IE€')
    expect(h.window.canSave.get()).toBe(false)
    expect(h.window.noticeText.get()).toBe('Switched on elsewhere just now.')
    expect(h.window.plate.get()).toMatchObject({
      tone: 'alert-warning',
      icon: 'bi-lock',
    })
    h.window.dispose()
  })

  it('keeps view mode after switching off, shows live values, and never prefills', () => {
    const h = harness({ enabled: true })
    expect(h.window.mode.get()).toBe('view')
    expect(h.window.title.get()).toBe('Country · Ireland')
    expect(h.window.form.get().defaultLocaleCode).toBeNull()
    h.source.set({ ...country, currencySymbol: 'IE€' })
    expect(h.window.mode.get()).toBe('view')
    expect(h.window.form.get().currencySymbol).toBe('IE€')
    expect(h.window.noticeText.get()).toBe('Switched off elsewhere just now.')
    expect(h.window.canSave.get()).toBe(false)
    h.window.setSymbol('ignored')
    expect(h.window.touched.get()).toBe(false)
    h.source.set(undefined)
    expect(h.window.noticeText.get()).toBe('Deleted elsewhere')
    h.window.dispose()
  })

  it('merges server-only changes without marking personal input, and resolves same-field conflicts', () => {
    const h = harness({ defaultLocaleCode: 'en-IE' })
    h.source.set({
      ...country,
      defaultLocaleCode: 'en-IE',
      currencyCode: 'GBP',
    })
    expect(h.window.form.get().currencyCode).toBe('GBP')
    expect(h.window.touched.get()).toBe(false)
    h.window.setSymbol('mine')
    h.source.set({
      ...country,
      defaultLocaleCode: 'en-IE',
      currencyCode: 'GBP',
      currencySymbol: 'theirs',
    })
    expect(h.window.state.get().conflict).toBe(true)
    expect(h.window.noticeText.get()).toContain(
      'Currency symbol changed elsewhere to "theirs"',
    )
    expect(h.window.canSave.get()).toBe(false)
    h.window.keepMine()
    expect(h.window.form.get().currencySymbol).toBe('mine')
    expect(h.window.canSave.get()).toBe(true)
    h.source.set({
      ...country,
      defaultLocaleCode: 'en-IE',
      currencyCode: 'GBP',
      currencySymbol: 'new',
    })
    h.window.takeTheirs()
    expect(h.window.form.get().currencySymbol).toBe('new')
    expect(h.window.state.get().conflict).toBe(false)
    expect(h.window.canSave.get()).toBe(false)
    h.window.dispose()
  })

  it('updates locale options without replacing a deleted selection and sends that choice for refusal', async () => {
    const h = harness()
    h.source.set({ ...country, locales: [{ code: 'fr-IE', enabled: true }] })
    expect(h.window.form.get().defaultLocaleCode).toBe('en-IE')
    expect(h.window.options.get()).toEqual([
      { value: 'fr-IE', label: 'fr-IE' },
      { value: 'en-IE', label: 'en-IE — no longer exists' },
      { value: '', label: 'Not chosen' },
    ])
    await h.window.save(async () => false)
    expect(h.send.mock.calls[0]?.[0]).toMatchObject({
      defaultLocaleCode: 'en-IE',
    })
    expect(h.window.opened.get()).toBe(true)
    h.window.dispose()
  })

  it('keeps extractable text after deletion and labels Save Deleted', async () => {
    const h = harness({ isOwn: true })
    expect(h.window.plate.get().rest).toContain('It is your own country')
    h.window.setSymbol('mine')
    h.source.set(undefined)
    expect(h.window.noticeText.get()).toBe(
      'Deleted elsewhere — your text stays to copy.',
    )
    expect(h.window.saveLabel.get()).toBe('Deleted')
    expect(h.window.canSave.get()).toBe(false)
    await h.window.save(async () => true)
    expect(h.send).not.toHaveBeenCalled()
    expect(h.window.form.get().currencySymbol).toBe('mine')
    h.window.dispose()
  })

  it('blocks duplicate saves and closing while busy and suppresses its own echo', async () => {
    const h = harness()
    let finish: (result: boolean) => void = () => {}
    const run = vi.fn(
      () =>
        new Promise<boolean>((resolve) => {
          finish = resolve
        }),
    )
    h.window.setSymbol(' IE€ ')
    const saved = h.window.save(run)
    expect(h.window.saving.get()).toBe(true)
    expect(h.send.mock.calls[0]?.[0]).toMatchObject({ currencySymbol: 'IE€' })
    h.window.close()
    expect(h.window.opened.get()).toBe(true)
    expect(h.window.open('view')).toBe(false)
    await h.window.save(run)
    expect(run).toHaveBeenCalledTimes(1)
    h.source.set({
      ...country,
      currencySymbol: 'IE€',
      defaultLocaleCode: 'en-IE',
    })
    expect(h.window.noticeKind.get()).toBeNull()
    expect(h.window.noticeText.get()).toBe('')
    finish(true)
    await saved
    expect(h.window.opened.get()).toBe(false)
    h.window.dispose()
  })

  it('uses a distinct tracked action for each door with the same trimmed payload', async () => {
    const dispatch = vi.fn(() => ({}) as ActionHandle)
    const context = {
      actions: { dispatch } as unknown as ActionLifecycle,
    } as HilosI18nCountryContext
    const signal = createSignal<HilosI18nCountryCard | null>(card())
    const cardWindow = createHilosI18nCountryCardWindow(signal, context)
    const focused = createSignal<HilosI18nCountryRow | undefined>(undefined)
    const releaseFocus = vi.fn(() => focused.set(undefined))
    const controller = {
      focusedRow: focused,
      focusRow: () => {
        focused.set(row())
        return row()
      },
      releaseFocus,
    } as unknown as TableViewportController<HilosI18nCountryRow>
    const rowWindow = createHilosI18nCountryRowWindow(controller, context)
    for (const window of [cardWindow, rowWindow]) {
      window.start()
      window.open('edit', 'ie')
      window.setSymbol(' IE€ ')
      await window.save(async () => true)
      window.dispose()
    }
    const payload = {
      countryCode: 'ie',
      currencySymbol: 'IE€',
      currencyCode: 'EUR',
      defaultLocaleCode: 'en-IE',
    }
    expect(dispatch.mock.calls).toEqual([
      ['hilos_i18n_country_update', payload],
      ['hilos_i18n_country_row_update', payload],
    ])
    expect(releaseFocus).toHaveBeenCalled()
  })
})
