// The Angular country card over the real action lifecycle and page scope: the
// country's block in the shared head of the real admin shell — one h1 with the
// live name, the same as the last crumb, the code apart, the custom mark, the
// currency line and the Overview · Names row — loading against a card that is
// gone, the deletion hints, the
// card window over the country's own locales, the two switches with their live
// verdicts and their own echo, the deletion that returns to the list only after
// its ack, a viewer of the admin view mode, a new country or context under
// open windows, and the country's live name in the page owner and its
// breadcrumb entry. The verdicts themselves are the core's and are tested there.
import { TestBed } from '@angular/core/testing'
import {
  HILOS_I18N_COUNTRY_DELETE_COPY,
  HILOS_I18N_COUNTRY_SWITCH_ON_COPY,
  HilosPages,
  hilosToasts,
} from '@hilos/core'
import { describe, expect, it } from 'vitest'

import { HilosI18nCountryPage } from '../src/admin/i18n/details/HilosI18nCountryPage.js'
import {
  answerAsCountryTab,
  countryCard,
  countryName,
  i18nBench,
  lastCrumb,
  mountOver,
} from './helpers/hilosI18n.js'

function setup(viewer = false) {
  const bench = i18nBench(HilosPages.I18N_COUNTRY, [], viewer)
  const unmounted = bench.listenerCount()
  const view = mountOver(HilosI18nCountryPage, bench, {
    context: bench.context,
  })

  return { ...bench, ...view, unmounted }
}

/** The one h1 of the page as drawn: the shared head's title. */
function title(): string | undefined {
  return document
    .querySelector('[data-id="hilos-page-head-title"]')
    ?.textContent?.trim()
}

const enabledCard = { ...countryCard, enabled: true }
const ownCard = {
  ...countryCard,
  countryId: 147,
  code: 'qy',
  summary: {
    ...countryCard.summary,
    name: null,
    isOwn: true,
    locales: [],
    localeCount: 0,
    nameCount: 0,
    catalogDefaultLocaleCode: null,
    canDelete: true,
    deleteReason: null,
  },
}

describe('HilosI18nCountryPage', () => {
  it('keeps a draft without retargeting a same-code country replacement', () => {
    const h = setup()
    h.act(() => h.countryCard())
    h.click('country-card-edit')
    h.type('i18n-country-edit-currency-symbol', 'draft')
    h.act(() =>
      h.countryCard({ ...countryCard, countryId: countryCard.countryId + 1 }),
    )
    expect(
      h.el<HTMLInputElement>('i18n-country-edit-currency-symbol')!.value,
    ).toBe('draft')
    expect(h.el<HTMLButtonElement>('i18n-country-save')!.disabled).toBe(true)
    expect(h.el('i18n-country-save')!.textContent).toContain('Deleted')
    h.submit()
    expect(h.calls).toHaveLength(0)
  })

  it('tells loading from a card that is gone and draws the live fields and the country block in the shared head', () => {
    const h = setup()
    expect(
      document.querySelector('[aria-label="Loading country"]'),
    ).not.toBeNull()
    // Before the answer the entity head stands on its skeleton, without a block.
    expect(h.el('hilos-page-head-skeleton')).not.toBeNull()
    expect(h.el('country-head-tile')).toBeNull()
    expect(document.querySelector('h1')).toBeNull()
    h.act(() => h.countryCard())
    const headings = document.querySelectorAll('h1')
    expect(headings).toHaveLength(1)
    expect(headings[0]!.getAttribute('data-id')).toBe('hilos-page-head-title')
    expect(title()).toBe('United States')
    expect(lastCrumb()).toBe('United States')
    const tile = h.el('country-head-tile')
    expect(tile?.textContent?.trim()).toBe('us')
    expect(tile?.getAttribute('aria-hidden')).toBe('true')
    expect(h.el('country-head-code')?.textContent?.trim()).toBe('us')
    expect(h.el('country-head-description')?.textContent?.trim()).toBe(
      'Currency $ USD · Default locale not chosen',
    )
    expect(h.el('country-head-own')).toBeNull()
    expect(h.el('hilos-admin-title')).toBeNull()
    expect(h.el('hilos-admin-children')).toBeNull()
    expect(h.el('country-card-currency')?.textContent?.trim()).toBe('$ USD')
    expect(h.el('country-card-default-locale')?.textContent?.trim()).toBe(
      'not chosen',
    )
    expect(h.el('country-card-frozen')).toBeNull()
    expect(h.el('country-card-enabled')?.textContent?.trim()).toBe('Disabled')
    expect(h.el('hilos-page-tabs')?.getAttribute('aria-label')).toBe(
      'Country pages',
    )
    const overview = h.el(`hilos-page-tab-${HilosPages.I18N_COUNTRY}`)
    expect(overview?.textContent?.trim()).toBe('Overview')
    expect(overview?.getAttribute('aria-current')).toBe('page')
    expect(
      h
        .el(`hilos-page-tab-${HilosPages.I18N_COUNTRY_NAMES}`)
        ?.getAttribute('href'),
    ).toBe('/hilos/i18n/countries/us/names')
    expect(h.el('country-card-edit')?.getAttribute('aria-label')).toBe(
      'Edit country',
    )
    expect(h.el('country-card-switch-on')).not.toBeNull()
    expect(h.el('country-card-switch-off')).toBeNull()
    h.act(() =>
      h.countryCard({
        ...enabledCard,
        currencySymbol: '€',
        currencyCode: 'EUR',
        summary: {
          ...countryCard.summary,
          name: 'Renamed',
          defaultLocaleId: 11102,
          defaultLocaleCode: 'en-US',
        },
      }),
    )
    expect(title()).toBe('Renamed')
    expect(lastCrumb()).toBe('Renamed')
    expect(h.el('country-head-description')?.textContent?.trim()).toBe(
      'Currency € EUR · Default locale en-US',
    )
    expect(h.el('country-card-frozen')?.textContent?.trim()).toBe(
      'Country enabled — frozen',
    )
    expect(h.el('country-card-view')?.getAttribute('title')).toBe(
      'View country: nothing to edit',
    )
    expect(h.el('country-card-edit')).toBeNull()
    expect(h.el('country-card-switch-on')).toBeNull()
    expect(h.el('country-card-switch-off')).not.toBeNull()
    h.act(() => h.countryCard([]))
    expect(h.el('country-card-unavailable')?.textContent).toContain(
      'no longer available',
    )
    expect(document.querySelector('[aria-label="Loading country"]')).toBeNull()
    // Without a card the block is empty and the owner keeps its catalog caption.
    expect(h.el('country-head-tile')).toBeNull()
    expect(h.el('country-head-description')).toBeNull()
    expect(title()).toBe('Country')
  })

  it.each([null, ''])(
    'draws an own country named %j as Unnamed country, its code apart in capitals and the custom mark',
    (name) => {
      const h = setup()
      h.act(() =>
        h.countryCard({ ...ownCard, summary: { ...ownCard.summary, name } }),
      )
      expect(title()).toBe('Unnamed country')
      expect(lastCrumb()).toBe('Unnamed country')
      const code = h.el('country-head-code')
      expect(code?.textContent?.trim()).toBe('qy')
      expect(code?.className).toContain('text-uppercase')
      expect(h.el('country-head-tile')?.textContent?.trim()).toBe('qy')
      const own = h.el('country-head-own')
      expect(own?.className).toBe(
        'badge text-bg-warning-subtle text-warning-emphasis',
      )
      expect(own?.getAttribute('title')).toBe(
        'This country is absent from the catalog and source.',
      )
      expect(own?.querySelector('i')?.getAttribute('aria-hidden')).toBe('true')
      expect(own?.querySelector('.visually-hidden')?.textContent?.trim()).toBe(
        'Custom country. This country is absent from the catalog and source.',
      )
      expect(h.el('country-head-description')?.textContent?.trim()).toBe(
        'Currency $ USD · Default locale not chosen',
      )
      expect(
        document.getElementById('country-card-delete-reason')?.textContent,
      ).toBe(HILOS_I18N_COUNTRY_DELETE_COPY.allowed)
      // The mark follows the live card.
      h.act(() =>
        h.countryCard({
          ...ownCard,
          summary: { ...ownCard.summary, name, isOwn: false },
        }),
      )
      expect(h.el('country-head-own')).toBeNull()
      expect(h.el('country-head-code')?.textContent?.trim()).toBe('qy')
    },
  )

  it('names the country in the one h1 from the first paint, the same as its last crumb, and renames both live', () => {
    const bench = i18nBench(HilosPages.I18N_COUNTRY)
    bench.countryCard()
    const h = mountOver(HilosI18nCountryPage, bench, {
      context: bench.context,
    })
    expect(document.querySelectorAll('h1')).toHaveLength(1)
    expect(title()).toBe('United States')
    expect(lastCrumb()).toBe('United States')
    for (const [live, shown] of [
      ['Renamed', 'Renamed'],
      [null, 'Unnamed country'],
      ['', 'Unnamed country'],
    ] as const) {
      h.act(() =>
        bench.countryCard({
          ...countryCard,
          summary: { ...countryCard.summary, name: live },
        }),
      )
      expect(title()).toBe(shown)
      expect(lastCrumb()).toBe(shown)
      expect(h.el('country-head-code')?.textContent?.trim()).toBe('us')
    }
  })

  it('moves the head with the country and the context, and stands on the skeleton without a page answer', () => {
    const h = setup()
    h.act(() => h.countryCard())
    h.act(() =>
      h.route.set({
        page: HilosPages.I18N_COUNTRY,
        params: { countryCode: 'fr' },
        admin: true,
      }),
    )
    h.act(() =>
      h.countryCard({
        ...countryCard,
        countryId: 103,
        code: 'fr',
        summary: { ...countryCard.summary, name: 'France' },
      }),
    )
    expect(title()).toBe('France')
    expect(lastCrumb()).toBe('France')
    expect(h.el('country-head-code')?.textContent?.trim()).toBe('fr')
    expect(
      h
        .el(`hilos-page-tab-${HilosPages.I18N_COUNTRY_NAMES}`)
        ?.getAttribute('href'),
    ).toBe('/hilos/i18n/countries/fr/names')
    // A new context lets the former subject's name and block go.
    const next = i18nBench(HilosPages.I18N_COUNTRY)
    h.fixture.componentRef.setInput('context', next.context)
    h.detect()
    expect(title()).toBe('Country')
    expect(h.el('country-head-tile')).toBeNull()
    // Without a page answer there is no owner: only the entity skeleton stands.
    h.act(() => h.scopes.dropPage())
    expect(h.el('hilos-page-head-skeleton')).not.toBeNull()
    expect(document.querySelector('h1')).toBeNull()
  })

  it.each(['known', 'locales', 'names'] as const)(
    'shows the live %s deletion hint beside a control that opens nothing',
    (deleteReason) => {
      const h = setup()
      h.act(() =>
        h.countryCard({
          ...countryCard,
          summary: { ...countryCard.summary, canDelete: false, deleteReason },
        }),
      )
      const control = h.el<HTMLButtonElement>('country-card-delete')!
      expect(control.getAttribute('aria-disabled')).toBe('true')
      expect(control.getAttribute('aria-describedby')).toBe(
        'country-card-delete-reason',
      )
      expect(
        document.getElementById('country-card-delete-reason')?.textContent,
      ).toBe(HILOS_I18N_COUNTRY_DELETE_COPY.reasons[deleteReason])
      h.click('country-card-delete')
      expect(h.el('modal')).toBeNull()
    },
  )

  it('saves through the card action over the country’s own locales and closes only on the ack', async () => {
    const h = setup()
    h.act(() =>
      h.countryCard({
        ...countryCard,
        summary: {
          ...countryCard.summary,
          defaultLocaleId: 52102,
          defaultLocaleCode: 'gone-US',
        },
      }),
    )
    h.click('country-card-edit')
    expect(h.el('i18n-country-title')?.textContent).toContain(
      'Edit country · United States',
    )
    const select = h.el<HTMLSelectElement>('i18n-country-edit-default-locale')!
    expect([...select.options].map((option) => option.text.trim())).toEqual([
      'en-US',
      'es-US — switched off',
      'gone-US — no longer exists',
      'Not chosen',
    ])
    expect(select.value).toBe('52102')
    select.value = '15102'
    select.dispatchEvent(new Event('change', { bubbles: true }))
    h.detect()
    h.submit()
    h.submit()
    expect(h.calls).toHaveLength(1)
    expect(h.calls[0]).toMatchObject({
      action: 'hilos_i18n_country_update',
      payload: {
        countryId: 102,
        currencySymbol: '$',
        currencyCode: 'USD',
        defaultLocaleId: 15102,
      },
    })
    h.escape()
    expect(h.el('modal')).not.toBeNull()
    expect(h.el<HTMLButtonElement>('i18n-country-cancel')?.disabled).toBe(true)
    await h.settle(() => h.refuse(0, 'The locale is not this country’s.'))
    expect(h.el('i18n-country-error')?.textContent).toContain(
      'The locale is not this country’s.',
    )
    h.click('i18n-country-save')
    h.act(() =>
      h.countryCard({
        ...countryCard,
        summary: {
          ...countryCard.summary,
          defaultLocaleId: 15102,
          defaultLocaleCode: 'es-US',
        },
      }),
    )
    expect(h.el('modal')).not.toBeNull()
    await h.settle(() => h.succeed(1, 'Country saved.'))
    expect(h.el('modal')).toBeNull()
    expect(
      hilosToasts.toasts
        .get()
        .some((toast) => toast.message === 'Country saved.'),
    ).toBe(true)
  })

  it('keeps an edit draft when the country is switched on elsewhere, with the conflict choices', () => {
    const h = setup()
    h.act(() => h.countryCard())
    h.click('country-card-edit')
    h.type('i18n-country-edit-currency-symbol', 'US$')
    h.act(() => h.countryCard(enabledCard))
    expect(h.el('i18n-country-notice')?.textContent).toContain(
      'Switched on elsewhere just now.',
    )
    expect(
      h.el<HTMLInputElement>('i18n-country-edit-currency-symbol')?.value,
    ).toBe('US$')
    expect(h.el<HTMLButtonElement>('i18n-country-save')?.disabled).toBe(true)
    h.act(() => h.countryCard({ ...countryCard, currencySymbol: '＄' }))
    expect(h.el('conflict-accept-theirs')).not.toBeNull()
    h.click('conflict-accept-theirs')
    expect(
      h.el<HTMLInputElement>('i18n-country-edit-currency-symbol')?.value,
    ).toBe('＄')
    h.act(() => h.countryCard([]))
    expect(h.el('modal')).not.toBeNull()
    expect(h.el('i18n-country-notice')?.textContent).toContain('Deleted')
  })

  it('switches on with the live default-locale note and holds its own echo until the ack', async () => {
    const h = setup()
    h.act(() => h.countryCard())
    h.click('country-card-switch-on')
    expect(h.el('modal')?.textContent).toContain(
      'Switch on country · United States',
    )
    expect(h.el('country-card-switch-on-note')?.textContent?.trim()).toBe(
      HILOS_I18N_COUNTRY_SWITCH_ON_COPY.note.missing,
    )
    expect(h.el('country-card-switch-on-note')?.className).toContain(
      'alert-secondary',
    )
    h.act(() =>
      h.countryCard({
        ...countryCard,
        summary: {
          ...countryCard.summary,
          defaultLocaleId: 15102,
          defaultLocaleCode: 'es-US',
          defaultLocale: 'off',
        },
      }),
    )
    expect(h.el('country-card-switch-on-note')?.textContent?.trim()).toBe(
      HILOS_I18N_COUNTRY_SWITCH_ON_COPY.note.off('es-US'),
    )
    expect(h.el('country-card-switch-on-note')?.className).toContain(
      'alert-warning',
    )
    h.act(() =>
      h.countryCard({
        ...countryCard,
        summary: {
          ...countryCard.summary,
          defaultLocaleId: 11102,
          defaultLocaleCode: 'en-US',
          defaultLocale: 'on',
        },
      }),
    )
    expect(h.el('country-card-switch-on-note')).toBeNull()
    h.click('country-card-switch-on-confirm')
    h.click('country-card-switch-on-confirm')
    expect(h.calls).toHaveLength(1)
    expect(h.calls[0]).toMatchObject({
      action: 'hilos_i18n_country_switch_on',
      payload: { countryId: 102 },
    })
    h.act(() => h.countryCard(enabledCard))
    expect(h.el('modal')).not.toBeNull()
    expect(
      h.el('country-card-switch-on-notice')?.textContent?.trim() ?? '',
    ).toBe('')
    expect(
      h.el<HTMLButtonElement>('country-card-switch-on-cancel')?.disabled,
    ).toBe(true)
    await h.settle(() => h.succeed(0, 'Country switched on.'))
    expect(h.el('modal')).toBeNull()
  })

  it('keeps a refused switch open for another attempt and says a switch made elsewhere', async () => {
    const h = setup()
    h.act(() => h.countryCard())
    h.click('country-card-switch-on')
    h.click('country-card-switch-on-confirm')
    await h.settle(() => h.refuse(0, 'The country changed meanwhile.'))
    expect(h.el('country-card-switch-on-error')?.textContent).toContain(
      'The country changed meanwhile.',
    )
    h.click('country-card-switch-on-confirm')
    expect(h.calls).toHaveLength(2)
    await h.settle(() => h.refuse(1))
    h.act(() => h.countryCard(enabledCard))
    expect(h.el('country-card-switch-on-notice')?.textContent).toContain(
      'Switched on elsewhere just now.',
    )
    expect(
      h.el<HTMLButtonElement>('country-card-switch-on-confirm')?.disabled,
    ).toBe(true)
    h.click('country-card-switch-on-cancel')
    h.click('country-card-switch-off')
    expect(h.el('country-card-switch-off-locale-count')?.textContent).toBe('2')
    expect(h.el('country-card-switch-off-name-count')?.textContent).toBe('3')
    h.act(() =>
      h.countryCard({
        ...enabledCard,
        summary: { ...countryCard.summary, localeCount: 4 },
      }),
    )
    expect(h.el('country-card-switch-off-locale-count')?.textContent).toBe('4')
    h.click('country-card-switch-off-confirm')
    expect(h.calls[2]).toMatchObject({
      action: 'hilos_i18n_country_switch_off',
      payload: { countryId: 102 },
    })
    h.act(() => h.countryCard())
    expect(
      h.el('country-card-switch-off-notice')?.textContent?.trim() ?? '',
    ).toBe('')
    await h.settle(() => h.succeed(2))
    expect(h.el('modal')).toBeNull()
  })

  it('closes a switch window when its card disappears', () => {
    const h = setup()
    h.act(() => h.countryCard())
    h.click('country-card-switch-on')
    h.act(() => h.countryCard([]))
    expect(h.el('modal')).toBeNull()
  })

  it('keeps the confirmation and its action when its own deletion takes the card, and returns to the list only after the ack', async () => {
    const h = setup()
    h.act(() => h.countryCard(ownCard))
    h.click('country-card-delete')
    expect(h.el('modal')?.textContent).toContain('Delete country · QY')
    h.click('i18n-country-delete-confirm')
    expect(h.calls[0]).toMatchObject({
      action: 'hilos_i18n_country_delete',
      payload: { countryId: 147 },
    })
    h.act(() => h.countryCard([]))
    expect(h.el('country-card-unavailable')).not.toBeNull()
    expect(h.el('i18n-country-delete-window')).not.toBeNull()
    expect(document.body.textContent).not.toContain('Deleted elsewhere.')
    expect(h.navigate).not.toHaveBeenCalled()
    await h.settle(() => h.succeed(0, 'Country deleted.'))
    expect(h.el('i18n-country-delete-window')).toBeNull()
    expect(h.navigate).toHaveBeenCalledExactlyOnceWith('/hilos/i18n/countries')
  })

  it('keeps a refused card deletion open without navigating, even after a late hint', async () => {
    const h = setup()
    h.act(() => h.countryCard(ownCard))
    h.click('country-card-delete')
    h.act(() =>
      h.countryCard({
        ...ownCard,
        summary: {
          ...ownCard.summary,
          canDelete: false,
          deleteReason: 'locales',
        },
      }),
    )
    expect(
      h.el<HTMLButtonElement>('i18n-country-delete-confirm')?.disabled,
    ).toBe(false)
    h.click('i18n-country-delete-confirm')
    await h.settle(() =>
      h.refuse(0, 'Cannot delete: locales refer to this country'),
    )
    expect(h.el('i18n-country-delete-error')?.textContent).toContain(
      'locales refer',
    )
    expect(h.el('i18n-country-delete-window')).not.toBeNull()
    expect(h.navigate).not.toHaveBeenCalled()
  })

  it('lets a viewer open and read every window while nothing is sent', () => {
    const h = setup(true)
    h.act(() => h.countryCard(ownCard))
    h.click('country-card-edit')
    h.type('i18n-country-edit-currency-symbol', 'Q')
    expect(h.el<HTMLButtonElement>('i18n-country-save')?.disabled).toBe(true)
    h.submit()
    expect(h.calls).toEqual([])
    h.click('i18n-country-cancel')
    h.click('modal-confirm-discard')
    h.click('country-card-switch-on')
    expect(
      h.el<HTMLButtonElement>('country-card-switch-on-confirm')?.disabled,
    ).toBe(true)
    h.click('country-card-switch-on-confirm')
    h.click('country-card-switch-on-cancel')
    h.click('country-card-delete')
    expect(
      h.el<HTMLButtonElement>('i18n-country-delete-confirm')?.disabled,
    ).toBe(true)
    h.click('i18n-country-delete-confirm')
    expect(h.calls).toEqual([])
    h.act(() => h.countryCard([]))
    expect(h.el('i18n-country-delete-notice')?.textContent).toContain(
      'Deleted elsewhere.',
    )
    expect(
      h.el<HTMLButtonElement>('i18n-country-delete-cancel')?.disabled,
    ).toBe(false)
    h.click('i18n-country-delete-cancel')
    expect(h.el('i18n-country-delete-window')).toBeNull()
  })

  it('drops the windows and a pending deletion when the address names another country', async () => {
    const h = setup()
    h.act(() => h.countryCard(ownCard))
    h.click('country-card-delete')
    h.click('i18n-country-delete-confirm')
    h.act(() =>
      h.route.set({
        page: HilosPages.I18N_COUNTRY,
        params: { countryCode: 'fr' },
        admin: true,
      }),
    )
    expect(h.el('modal')).toBeNull()
    h.act(() => h.countryCard(countryCard))
    h.click('country-card-edit')
    await h.settle(() => h.succeed(0))
    expect(h.el('i18n-country-window')).not.toBeNull()
    expect(h.navigate).not.toHaveBeenCalled()
    const next = i18nBench(HilosPages.I18N_COUNTRY)
    h.fixture.componentRef.setInput('context', next.context)
    h.detect()
    expect(h.el('i18n-country-window')).toBeNull()
    expect(h.el('country-head-tile')).toBeNull()
  })

  it.each([
    ['United States', 'United States', false],
    [null, 'Unnamed country', false],
    ['', 'Unnamed country', true],
  ] as const)(
    'names %j as %j from the first paint and renames it live under an open confirmation (viewer %s)',
    (name, expected, viewer) => {
      const named = (live: string | null) => ({
        ...countryCard,
        summary: {
          ...countryCard.summary,
          name: live,
          canDelete: true,
          deleteReason: null,
        },
      })
      const bench = i18nBench(HilosPages.I18N_COUNTRY, [], viewer)
      bench.countryCard(named(name))
      const h = mountOver(HilosI18nCountryPage, bench, {
        context: bench.context,
      })
      expect(lastCrumb()).toBe(expected)
      expect(countryName(bench)).toEqual([expected, expected])
      h.click('country-card-delete')
      for (const [live, shown] of [
        ['Renamed', 'Renamed'],
        [null, 'Unnamed country'],
        ['', 'Unnamed country'],
        ['  Spaced  ', '  Spaced  '],
      ] as const) {
        h.act(() => bench.countryCard(named(live)))
        expect(countryName(bench)).toEqual([shown, shown])
      }
      expect(h.el('i18n-country-delete-window')).not.toBeNull()
      h.act(() => bench.countryCard(null))
      expect(countryName(bench)).toEqual(['Country', 'Country'])
    },
  )

  it('follows the country and the context, keeps the caption off a country tab, and lets only its own name go', () => {
    const h = setup()
    h.act(() => h.countryCard())
    expect(countryName(h)).toEqual(['United States', 'United States'])
    h.act(() =>
      h.route.set({
        page: HilosPages.I18N_COUNTRY,
        params: { countryCode: 'fr' },
        admin: true,
      }),
    )
    h.act(() =>
      h.countryCard({
        ...countryCard,
        countryId: 103,
        code: 'fr',
        summary: { ...countryCard.summary, name: 'France' },
      }),
    )
    expect(countryName(h)).toEqual(['France', 'France'])
    const next = i18nBench(HilosPages.I18N_COUNTRY)
    h.fixture.componentRef.setInput('context', next.context)
    h.detect()
    expect(countryName(h)).toEqual(['Country', 'Country'])
    h.act(() => next.countryCard())
    expect(countryName(next)).toEqual(['United States', 'United States'])
    const other = next.scopes.openPage(HilosPages.I18N_COUNTRIES)
    other.data.set('pageLabel', 'Countries')
    other.data.set('pageBreadcrumb', [
      { page: HilosPages.I18N_COUNTRY, label: 'Country' },
    ])
    other.data.set('countryCard', countryCard)
    expect(next.router.pageIdentity.get()?.breadcrumb[0]?.label).toBe('Country')
    next.scopes.dropPage()
    expect(next.router.pageIdentity.get()).toBeUndefined()
    const names = next.scopes.openPage(HilosPages.I18N_COUNTRY_NAMES)
    answerAsCountryTab(names, HilosPages.I18N_COUNTRY_NAMES)
    expect(countryName(next)).toEqual(['Country', 'Country'])
    names.data.set('countryCard', countryCard)
    expect(countryName(next)).toEqual(['United States', 'United States'])
    const later = TestBed.createComponent(HilosI18nCountryPage)
    later.componentRef.setInput('context', next.context)
    later.detectChanges()
    h.fixture.destroy()
    expect(countryName(next)).toEqual(['United States', 'United States'])
    later.destroy()
    expect(countryName(next)).toEqual(['Country', 'Country'])
  })
})
