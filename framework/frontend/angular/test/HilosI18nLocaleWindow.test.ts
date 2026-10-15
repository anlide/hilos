// The Angular locale window over the core sessions and the real locales table:
// add, edit and view fixed at opening; seven closed selects filled from the
// catalog or left on "Choose…" with Add unavailable until all are chosen, and the
// pair without a country sent as null; an edit's merge with Keep mine / Take
// theirs, a locale gone or switched on elsewhere; the switch-on and switch-off
// steps inside the same window with Back sending nothing, a dirty draft asked to
// be saved first, the protected countryless locale of the default language and
// the ready warnings; refusals that stay, a busy action that holds every close,
// the invisible twins out of forms, controls, locators and the accessibility
// tree, and a viewer of the admin view mode. The verdicts are the core's.
import { signal } from '@angular/core'
import {
  HilosPages,
  computedSignal,
  createHilosI18nLanguageLocalesTable,
} from '@hilos/core'
import type {
  HilosI18nLanguageCard,
  HilosI18nLocaleRow,
  HilosI18nLocaleWindowMode,
} from '@hilos/core'
import { describe, expect, it } from 'vitest'

import { HilosI18nLocaleWindow } from '../src/admin/i18n/details/HilosI18nLocaleWindow.js'
import { HILOS_ADMIN_VIEW_MODE } from '../src/hilosLookOnly.js'
import {
  catalogFormats,
  countrylessLocale,
  i18nBench,
  languageCard,
  localeTemplates,
  mountOver,
  polandPair,
  ukraineLocale,
} from './helpers/hilosI18n.js'

/** A pair without a locale that the built-in catalog knows. */
const moldovaPair: HilosI18nLocaleRow = {
  ...polandPair,
  rowKey: 'uk-MD',
  countryCode: 'md',
  countryName: 'Молдова',
  catalogFormats,
}
/** The language's own pair before it has a locale. */
const countrylessPair: HilosI18nLocaleRow = {
  ...countrylessLocale,
  localeCode: null,
  enabled: null,
  formats: null,
}

function setup(
  options: {
    card?: HilosI18nLanguageCard
    viewer?: boolean
  } = {},
) {
  const bench = i18nBench(HilosPages.I18N_LANGUAGE_LOCALES, [
    countrylessLocale,
    ukraineLocale,
    polandPair,
    moldovaPair,
  ])
  const language = computedSignal(() =>
    String(bench.route.get().params['languageCode'] ?? ''),
  )
  const locales = createHilosI18nLanguageLocalesTable(bench.context, language)
  locales.start()
  const view = mountOver(
    HilosI18nLocaleWindow,
    bench,
    {
      context: bench.context,
      controller: locales.controller,
      card: options.card ?? languageCard,
      templates: localeTemplates,
      opened: null,
    },
    [
      {
        provide: HILOS_ADMIN_VIEW_MODE,
        useValue: signal(options.viewer ?? false).asReadonly(),
      },
    ],
  )
  let closed = 0
  view.fixture.componentInstance.closed.subscribe(() => {
    closed++
    view.fixture.componentRef.setInput('opened', null)
  })
  const open = (rowKey: string, mode: HilosI18nLocaleWindowMode): void =>
    view.act(() =>
      view.fixture.componentRef.setInput('opened', { rowKey, mode }),
    )
  const select = (field: string) =>
    view.el<HTMLSelectElement>(`i18n-locale-field-${field}`)!
  const choose = (field: string, value: string): void => {
    select(field).value = value
    select(field).dispatchEvent(new Event('change', { bubbles: true }))
    view.detect()
  }
  const button = (id: string) => view.el<HTMLButtonElement>(id)!

  return {
    ...bench,
    ...view,
    locales,
    open,
    select,
    choose,
    button,
    closed: () => closed,
  }
}

const FIELDS = [
  'date',
  'time',
  'number',
  'phone',
  'address',
  'measurement',
  'collation',
] as const

describe('HilosI18nLocaleWindow — add', () => {
  it('asks for all seven closed choices of an unknown pair before Add, then sends them', async () => {
    const h = setup()
    h.open('uk-PL', 'add')
    expect(h.focuses.at(-1)).toBe('uk-PL')
    expect(h.el('i18n-locale-title')?.textContent).toContain(
      'Add locale · Українська in PL',
    )
    expect(h.el('i18n-locale-code')?.textContent).toBe('uk-PL')
    for (const field of FIELDS) {
      expect(h.select(field).tagName).toBe('SELECT')
      expect(h.select(field).value).toBe('')
      expect(h.select(field).options[0]?.textContent?.trim()).toBe('Choose…')
    }
    expect(
      [...h.select('measurement').options].map((item) =>
        item.textContent?.trim(),
      ),
    ).toEqual(['Choose…', 'Metric', 'Imperial'])
    expect(h.el('i18n-locale-plate')?.textContent).toContain(
      'The built-in catalog does not know this pair',
    )
    expect(h.button('i18n-locale-submit').disabled).toBe(true)
    for (const field of FIELDS.slice(0, -1)) {
      h.choose(field, localeTemplates[field][1]!)
    }
    expect(h.button('i18n-locale-submit').disabled).toBe(true)
    h.submit()
    expect(h.calls).toHaveLength(0)
    h.choose('collation', 'en')
    h.click('i18n-locale-submit')
    h.click('i18n-locale-submit')
    expect(h.calls).toHaveLength(1)
    expect(h.calls[0]).toMatchObject({
      action: 'hilos_i18n_locale_add',
      payload: {
        languageCode: 'uk',
        countryCode: 'pl',
        formats: {
          date: 'yyyy-MM-dd',
          time: 'h:mm a',
          number: '1,234.5',
          phone: '+1 XXX XXX XXXX',
          address: 'city, street',
          measurement: 'imperial',
          collation: 'en',
        },
      },
    })
    expect(h.button('i18n-locale-cancel').disabled).toBe(true)
    h.escape()
    expect(h.el('modal')).not.toBeNull()
    await h.settle(() => h.succeed())
    expect(h.closed()).toBe(1)
    expect(h.el('modal')).toBeNull()
  })

  it.each([
    ['uk-MD', 'md'],
    ['uk', null],
  ] as const)(
    'prefills the catalog formats of %s and sends its country as %s',
    async (rowKey, countryCode) => {
      const h = setup()
      // The language's own pair loses its locale first: the row stays.
      if (countryCode === null) h.act(() => h.delta(countrylessPair))
      h.open(rowKey, 'add')
      expect(h.select('date').value).toBe(catalogFormats.date)
      expect(h.el('i18n-locale-plate')?.textContent).toContain(
        'Filled from the built-in catalog.',
      )
      h.submit()
      expect(h.calls[0]).toMatchObject({
        action: 'hilos_i18n_locale_add',
        payload: { languageCode: 'uk', countryCode, formats: catalogFormats },
      })
      await h.settle(() => h.refuse(0, 'A locale of this pair exists.'))
      expect(h.el('modal')).not.toBeNull()
      expect(h.el('i18n-locale-error')?.textContent).toContain(
        'A locale of this pair exists.',
      )
    },
  )

  it('keeps the draft when the pair gains a locale elsewhere and draws no twins', () => {
    const h = setup()
    h.open('uk-PL', 'add')
    h.choose('date', 'yyyy-MM-dd')
    h.act(() =>
      h.delta({
        ...polandPair,
        localeCode: 'uk-PL',
        enabled: false,
        formats: catalogFormats,
      }),
    )
    expect(h.el('i18n-locale-notice')?.textContent).toContain(
      'Added elsewhere just now.',
    )
    expect(h.select('date').value).toBe('yyyy-MM-dd')
    expect(h.button('i18n-locale-submit').disabled).toBe(true)
    expect(document.querySelector('.hilos-stack.invisible')).toBeNull()
  })
})

describe('HilosI18nLocaleWindow — edit and switch on', () => {
  it('saves the chosen formats and closes only on the ack', async () => {
    const h = setup()
    h.open('uk-UA', 'edit')
    expect(h.select('date').value).toBe(catalogFormats.date)
    expect(h.button('i18n-locale-submit').disabled).toBe(true)
    h.choose('date', 'yyyy-MM-dd')
    h.submit()
    expect(h.calls[0]).toMatchObject({
      action: 'hilos_i18n_locale_update',
      payload: {
        languageCode: 'uk',
        countryCode: 'ua',
        formats: { ...catalogFormats, date: 'yyyy-MM-dd' },
      },
    })
    await h.settle(() => h.succeed())
    expect(h.el('modal')).toBeNull()
  })

  it('opens the switch-on step inside the window, goes Back without sending, and switches on through the ack', async () => {
    const h = setup()
    h.open('uk-UA', 'edit')
    h.click('i18n-locale-switch-on')
    expect(document.activeElement?.getAttribute('role')).toBe('dialog')
    expect(h.el('i18n-locale-title')?.textContent).toContain(
      'Switch on locale · Українська in Україна',
    )
    expect(h.el('i18n-locale-switch-on-body')).not.toBeNull()
    expect(h.el('i18n-locale-switch-on-note')).not.toBeNull()
    expect(h.el('i18n-locale-field-date')).toBeNull()
    h.click('i18n-locale-switch-on-back')
    expect(h.calls).toHaveLength(0)
    expect(h.el('i18n-locale-field-date')).not.toBeNull()
    h.click('i18n-locale-switch-on')
    h.click('i18n-locale-switch-on-confirm')
    h.click('i18n-locale-switch-on-confirm')
    expect(h.calls).toHaveLength(1)
    expect(h.calls[0]).toMatchObject({
      action: 'hilos_i18n_locale_switch_on',
      payload: { languageCode: 'uk', countryCode: 'ua' },
    })
    expect(h.button('i18n-locale-switch-on-back').disabled).toBe(true)
    expect(h.button('modal-close').disabled).toBe(true)
    h.escape()
    // Its own echo switches the locale on while the action is in flight.
    h.act(() => h.delta({ ...ukraineLocale, enabled: true }))
    expect(h.el('modal')).not.toBeNull()
    await h.settle(() => h.succeed())
    expect(h.el('modal')).toBeNull()
  })

  it('asks to save a dirty draft or an open conflict before switching on', async () => {
    const h = setup()
    h.open('uk-UA', 'edit')
    h.choose('date', 'yyyy-MM-dd')
    h.click('i18n-locale-switch-on')
    expect(h.el('i18n-locale-switch-on-unsaved')).not.toBeNull()
    expect(h.button('i18n-locale-switch-on-confirm').disabled).toBe(true)
    h.button('i18n-locale-switch-on-confirm').click()
    expect(h.calls).toHaveLength(0)
    h.click('i18n-locale-switch-on-back')
    expect(h.select('date').value).toBe('yyyy-MM-dd')
    h.submit()
    await h.settle(() => h.refuse(0, 'Formats were refused.'))
    expect(h.el('i18n-locale-error')?.textContent).toContain(
      'Formats were refused.',
    )
    h.click('i18n-locale-switch-on')
    expect(h.el('i18n-locale-error')?.textContent).not.toContain(
      'Formats were refused.',
    )
  })

  it('merges a change elsewhere and offers Keep mine and Take theirs', () => {
    const h = setup()
    h.open('uk-UA', 'edit')
    h.choose('date', 'yyyy-MM-dd')
    h.act(() =>
      h.delta({
        ...ukraineLocale,
        formats: { ...catalogFormats, date: 'dd.MM.yyyy', time: 'h:mm a' },
      }),
    )
    // A field only the other side changed is taken as it is.
    expect(h.select('time').value).toBe('h:mm a')
    h.act(() =>
      h.delta({
        ...ukraineLocale,
        formats: { ...catalogFormats, date: 'MM/dd/yyyy', time: 'h:mm a' },
      }),
    )
    expect(h.el('i18n-locale-notice')?.textContent).toContain(
      'Date changed elsewhere to "MM/dd/yyyy"; your choice stays selected.',
    )
    expect(h.button('i18n-locale-submit').disabled).toBe(true)
    h.click('conflict-accept-mine')
    expect(h.select('date').value).toBe('yyyy-MM-dd')
    h.submit()
    expect(h.calls[0]?.payload).toMatchObject({
      formats: { date: 'yyyy-MM-dd', time: 'h:mm a' },
    })
  })

  it('keeps the draft of a locale deleted or switched on elsewhere and sends nothing from it', () => {
    const h = setup()
    h.open('uk-UA', 'edit')
    h.choose('date', 'yyyy-MM-dd')
    h.act(() => h.delta({ ...ukraineLocale, enabled: true }))
    expect(h.el('i18n-locale-notice')?.textContent).toContain(
      'Switched on elsewhere just now.',
    )
    expect(h.select('date').disabled).toBe(true)
    expect(h.button('i18n-locale-switch-on').disabled).toBe(true)
    h.act(() =>
      h.delta({
        ...ukraineLocale,
        localeCode: null,
        enabled: null,
        formats: null,
      }),
    )
    expect(h.el('modal')).not.toBeNull()
    expect(h.button('i18n-locale-submit').textContent?.trim()).toBe('Deleted')
    expect(h.select('date').value).toBe('yyyy-MM-dd')
    h.submit()
    expect(h.calls).toHaveLength(0)
  })
})

describe('HilosI18nLocaleWindow — view and switch off', () => {
  it('reads a switched-on locale and switches it off through its own step and ack', async () => {
    const h = setup({ card: { ...languageCard, enabled: false } })
    h.open('uk', 'view')
    for (const field of FIELDS) expect(h.select(field).disabled).toBe(true)
    expect(h.el('i18n-locale-plate')?.textContent).toContain(
      'This locale is switched on, so there is nothing to edit.',
    )
    expect(h.el('i18n-locale-title')?.textContent).toContain(
      'Locale · Українська without a country',
    )
    h.click('i18n-locale-switch-off')
    expect(h.el('i18n-locale-switch-off-body')).not.toBeNull()
    expect(h.el('i18n-locale-switch-off-warning')).toBeNull()
    h.click('i18n-locale-switch-off-back')
    expect(h.calls).toHaveLength(0)
    h.click('i18n-locale-switch-off')
    h.click('i18n-locale-switch-off-confirm')
    expect(h.calls[0]).toMatchObject({
      action: 'hilos_i18n_locale_switch_off',
      payload: { languageCode: 'uk', countryCode: null },
    })
    await h.settle(() => h.refuse(0, 'The locale is in use.'))
    expect(h.el('i18n-locale-switch-off-error')?.textContent).toContain(
      'The locale is in use.',
    )
    expect(h.el('modal')).not.toBeNull()
    h.click('i18n-locale-switch-off-confirm')
    await h.settle(() => h.succeed(1))
    expect(h.el('modal')).toBeNull()
  })

  it('protects the countryless locale of the default language with its reason', () => {
    const h = setup({
      card: {
        ...languageCard,
        enabled: true,
        summary: { ...languageCard.summary, isDefault: true },
      },
    })
    h.open('uk', 'view')
    h.click('i18n-locale-switch-off')
    expect(h.el('i18n-locale-switch-off-reason')?.textContent).toContain(
      'cannot be switched off',
    )
    expect(h.button('i18n-locale-switch-off-confirm').disabled).toBe(true)
    h.button('i18n-locale-switch-off-confirm').click()
    expect(h.calls).toHaveLength(0)
  })

  it.each([
    ['uk', 'This language is switched on.'],
    ['uk-UA', "This is the country's default locale."],
  ] as const)('warns before switching off %s', (rowKey, warning) => {
    const h = setup({ card: { ...languageCard, enabled: true } })
    h.act(() =>
      h.delta({ ...ukraineLocale, enabled: true, isCountryDefault: true }),
    )
    h.open(rowKey, 'view')
    h.click('i18n-locale-switch-off')
    expect(h.el('i18n-locale-switch-off-warning')?.textContent).toContain(
      warning,
    )
    expect(h.button('i18n-locale-switch-off-confirm').disabled).toBe(false)
  })

  it('says a locale switched off elsewhere and stays a view', () => {
    const h = setup()
    h.open('uk', 'view')
    h.act(() => h.delta({ ...countrylessLocale, enabled: false }))
    expect(h.el('i18n-locale-notice')?.textContent).toContain(
      'Switched off elsewhere just now.',
    )
    expect(h.select('date').disabled).toBe(true)
    expect(h.button('i18n-locale-switch-off').disabled).toBe(true)
  })
})

describe('HilosI18nLocaleWindow — room, viewer and teardown', () => {
  it.each(['edit', 'view'] as const)(
    'holds every step of %s in invisible twins that carry no form, control, locator or focus',
    (mode) => {
      const h = setup()
      h.open(mode === 'edit' ? 'uk-UA' : 'uk', mode)
      const twins = [
        ...document.querySelectorAll<HTMLElement>('.hilos-stack.invisible'),
      ]
      // The header, the body's two parts and the footer each hold their room.
      expect(twins.length).toBeGreaterThanOrEqual(4)
      for (const twin of twins) {
        expect(twin.getAttribute('aria-hidden')).toBe('true')
        expect(twin.hasAttribute('inert')).toBe(true)
        expect(
          twin.querySelector(
            'form, input, select, button, [data-id], [data-autofocus]',
          ),
        ).toBeNull()
      }
      expect(document.querySelectorAll('form')).toHaveLength(1)
    },
  )

  it('lets a viewer change the draft, open the step and go Back, while Save, Enter and the confirms send nothing', () => {
    const h = setup({ viewer: true })
    h.open('uk-UA', 'edit')
    h.choose('date', 'yyyy-MM-dd')
    expect(h.select('date').value).toBe('yyyy-MM-dd')
    expect(h.button('i18n-locale-submit').disabled).toBe(true)
    h.submit()
    expect(h.button('i18n-locale-switch-on').disabled).toBe(false)
    h.click('i18n-locale-switch-on')
    expect(h.button('i18n-locale-switch-on-confirm').disabled).toBe(true)
    h.button('i18n-locale-switch-on-confirm').click()
    h.click('i18n-locale-switch-on-back')
    expect(h.el('i18n-locale-field-date')).not.toBeNull()
    expect(h.calls).toHaveLength(0)
  })

  it('lets the pair go on Cancel and when the view ends', () => {
    const h = setup()
    h.open('uk-UA', 'edit')
    expect(h.focuses.at(-1)).toBe('uk-UA')
    h.click('i18n-locale-cancel')
    expect(h.closed()).toBe(1)
    expect(h.focuses.at(-1)).toBe('')
    h.open('uk', 'view')
    expect(h.focuses.at(-1)).toBe('uk')
    h.fixture.destroy()
    expect(h.focuses.at(-1)).toBe('')
  })
})
