import { act, fireEvent } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { HilosPages, HILOS_I18N_COUNTRY_DELETE_COPY } from '@hilos/core'
import { HilosI18nCountryPage } from '../src/admin/i18n/details/HilosI18nCountryPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'
import {
  harness,
  countryCard,
  el,
  click,
  change,
  lastCrumb,
  retiredCountryHeaderLeft,
  settle,
} from './hilosI18nHarness.js'

function setup(viewer = false) {
  const h = harness(HilosPages.I18N_COUNTRY, [], viewer)
  const view = h.mount(<HilosI18nCountryPage context={h.context} />)
  return { ...h, view }
}

describe('React country card', () => {
  it('keeps a draft without retargeting a same-code country replacement', () => {
    const h = setup()
    act(() => h.card())
    click('country-card-edit')
    change('i18n-country-edit-currency-symbol', 'draft')
    act(() => h.card({ ...countryCard, countryId: countryCard.countryId + 1 }))
    expect(
      el<HTMLInputElement>('i18n-country-edit-currency-symbol').value,
    ).toBe('draft')
    expect(el<HTMLButtonElement>('i18n-country-save').disabled).toBe(true)
    expect(el('i18n-country-save').textContent).toContain('Deleted')
    fireEvent.submit(
      el<HTMLInputElement>('i18n-country-edit-currency-symbol').form!,
    )
    expect(h.calls).toHaveLength(0)
  })

  it('distinguishes loading from a deleted card and draws the country block, live fields, marks and the Overview · Names row', () => {
    const h = setup()
    expect(
      document.querySelector('[aria-label="Loading country"]'),
    ).not.toBeNull()
    expect(el('hilos-page-head-skeleton')).not.toBeNull()
    expect(el('country-head-tile')).toBeNull()
    expect(el('hilos-page-tabs')).toBeNull()
    act(() => h.card())
    const headings = document.querySelectorAll('h1')
    expect(headings).toHaveLength(1)
    expect(headings[0]!.getAttribute('data-id')).toBe('hilos-page-head-title')
    expect(headings[0]!.textContent).toBe('Guernsey')
    const block = el('hilos-page-head-block')
    const tile = block.querySelector('[data-id="country-head-tile"]')!
    expect(tile.textContent).toBe('gg')
    expect(tile.getAttribute('aria-hidden')).toBe('true')
    expect([...tile.classList]).toEqual(
      expect.arrayContaining(['text-uppercase', 'rounded-3']),
    )
    expect(tile.querySelector('img')).toBeNull()
    const code = block.querySelector('[data-id="country-head-code"]')!
    expect(code.textContent).toBe('gg')
    expect(code.classList.contains('text-uppercase')).toBe(true)
    expect(
      block.querySelector('[data-id="country-head-description"]')!.textContent,
    ).toBe('Currency £ GBP · Default locale not chosen')
    const own = block.querySelector('[data-id="country-head-own"]')!
    expect([...own.classList]).toEqual(
      expect.arrayContaining([
        'badge',
        'text-bg-warning-subtle',
        'text-warning-emphasis',
      ]),
    )
    expect(own.getAttribute('title')).toBe(
      'This country is absent from the catalog and source.',
    )
    expect(
      own.querySelector('i')!.classList.contains('bi-question-circle'),
    ).toBe(true)
    expect(own.querySelector('i')!.getAttribute('aria-hidden')).toBe('true')
    expect(own.querySelector('.visually-hidden')!.textContent).toBe(
      'Custom country. This country is absent from the catalog and source.',
    )
    // The counters stay out of the head: Overview owns them.
    expect(block.textContent).not.toMatch(/[0-9]/)
    expect(retiredCountryHeaderLeft()).toBe(false)
    expect(el('hilos-admin-children')).toBeNull()
    expect(
      document.querySelectorAll('[data-id="hilos-page-tabs"]'),
    ).toHaveLength(1)
    const row = el('hilos-page-tabs')
    expect(row.getAttribute('aria-label')).toBe('Country pages')
    const links = [...row.querySelectorAll('a')]
    expect(links.map((link) => link.textContent)).toEqual(['Overview', 'Names'])
    expect(links.map((link) => link.getAttribute('href'))).toEqual([
      '/hilos/i18n/countries/gg',
      '/hilos/i18n/countries/gg/names',
    ])
    expect(links[0]!.getAttribute('aria-current')).toBe('page')
    expect(links[1]!.getAttribute('aria-current')).toBeNull()
    expect(el('country-card-code').textContent).toBe('gg')
    expect(el('country-card-currency').textContent).toBe('£ GBP')
    expect(el('country-card-default-locale').textContent).toBe('not chosen')
    act(() =>
      h.card({
        ...countryCard,
        currencySymbol: '$',
        currencyCode: 'USD',
        enabled: true,
        summary: {
          ...countryCard.summary,
          name: 'Changed',
          defaultLocaleId: 11143,
          defaultLocaleCode: 'en-GG',
          isOwn: true,
          localeCount: 4,
          nameCount: 5,
        },
      }),
    )
    expect(el('hilos-page-head-title').textContent).toBe('Changed')
    expect(el('country-head-description').textContent).toBe(
      'Currency $ USD · Default locale en-GG',
    )
    expect(el('country-head-own')).not.toBeNull()
    expect(el('country-card-frozen')).not.toBeNull()
    expect(el('country-card-edit')).toBeNull()
    act(() =>
      h.card({
        ...countryCard,
        summary: { ...countryCard.summary, isOwn: false },
      }),
    )
    expect(el('country-head-own')).toBeNull()
    expect(el('country-head-code')).not.toBeNull()
    act(() => h.card([]))
    expect(el('country-card-unavailable').textContent).toContain(
      'no longer available',
    )
    expect(document.querySelector('[aria-label="Loading country"]')).toBeNull()
    expect(el('hilos-page-head-title').textContent).toBe('Country')
    for (const id of [
      'country-head-tile',
      'country-head-code',
      'country-head-own',
      'country-head-description',
    ])
      expect(el(id)).toBeNull()
  })
  it.each(['known', 'locales', 'names'] as const)(
    'shows the live %s deletion hint with its disabled control',
    (deleteReason) => {
      const h = setup()
      act(() =>
        h.card({
          ...countryCard,
          summary: { ...countryCard.summary, canDelete: false, deleteReason },
        }),
      )
      expect(el('country-card-delete').getAttribute('aria-disabled')).toBe(
        'true',
      )
      click('country-card-delete')
      expect(el('modal')).toBeNull()
      expect(el('country-card-delete').getAttribute('aria-describedby')).toBe(
        'country-card-delete-reason',
      )
      expect(el('country-card-delete-reason').textContent).toBe(
        HILOS_I18N_COUNTRY_DELETE_COPY.reasons[deleteReason],
      )
    },
  )
  it('sends the card edit action and keeps a deleted draft until the window closes', () => {
    const h = setup()
    act(() => h.card())
    click('country-card-edit')
    change('i18n-country-edit-currency-symbol', 'Mine')
    act(() => h.card([]))
    expect(
      el<HTMLInputElement>('i18n-country-edit-currency-symbol').value,
    ).toBe('Mine')
    expect(el('i18n-country-save').textContent).toBe('Deleted')
    fireEvent.submit(document.querySelector('form')!)
    expect(h.calls).toEqual([])
  })
  it('saves through the card action with its captured country ID', async () => {
    const h = setup()
    act(() => h.card())
    click('country-card-edit')
    change('i18n-country-edit-currency-symbol', 'Mine')
    click('i18n-country-save')
    expect(h.calls[0]).toMatchObject({
      action: 'hilos_i18n_country_update',
      payload: {
        countryId: 143,
        currencySymbol: 'Mine',
        currencyCode: 'GBP',
        defaultLocaleId: null,
      },
    })
    act(() => h.card({ ...countryCard, currencySymbol: 'Mine' }))
    expect(el('modal')).not.toBeNull()
    await settle(() => h.succeed())
    expect(el('modal')).toBeNull()
  })
  it('waits for successful card deletion before returning to the list', async () => {
    const h = setup()
    act(() => h.card())
    click('country-card-delete')
    click('i18n-country-delete-confirm')
    act(() => h.card([]))
    expect(el('modal')).not.toBeNull()
    expect(el('country-card-unavailable')).toBeNull()
    expect(h.navigation.navigate).not.toHaveBeenCalled()
    await settle(() => h.succeed())
    expect(h.navigation.navigate).toHaveBeenCalledWith('/hilos/i18n/countries')
  })
  it('keeps a viewer delete window open on external deletion without dispatching', () => {
    const h = setup(true)
    act(() => h.card())
    click('country-card-delete')
    expect(el<HTMLButtonElement>('i18n-country-delete-confirm').disabled).toBe(
      true,
    )
    act(() => h.card([]))
    expect(el('i18n-country-delete-notice').textContent).toContain(
      'Deleted elsewhere.',
    )
    click('i18n-country-delete-confirm')
    expect(h.calls).toEqual([])
  })
  it('releases old edit windows on a subject change and ignores an old delete ack', async () => {
    const h = setup()
    act(() => h.card())
    click('country-card-edit')
    change('i18n-country-edit-currency-symbol', 'Old draft')
    act(() => {
      h.route.set({
        page: HilosPages.I18N_COUNTRY,
        params: { countryCode: 'fr' },
        admin: true,
      })
      h.card({
        ...countryCard,
        countryId: 103,
        code: 'fr',
        summary: { ...countryCard.summary, name: 'Français' },
      })
    })
    expect(el('i18n-country-window')).toBeNull()
    expect(el('country-card-code').textContent).toBe('fr')
    click('country-card-delete')
    click('i18n-country-delete-confirm')
    act(() => {
      h.route.set({
        page: HilosPages.I18N_COUNTRY,
        params: { countryCode: 'ar' },
        admin: true,
      })
      h.card({
        ...countryCard,
        countryId: 148,
        code: 'ar',
        summary: { ...countryCard.summary, name: 'العربية' },
      })
    })
    click('country-card-delete')
    await settle(() => h.succeed())
    expect(h.navigation.navigate).not.toHaveBeenCalled()
    expect(el('modal').textContent).toContain('Delete country · العربية')
  })
  it('drops a tab without an address, hides a row of one, keeps the block and the card frozen in view', () => {
    const h = setup()
    const resolve = h.navigation.resolvePath
    h.navigation.resolvePath = (key, params) =>
      key === HilosPages.I18N_COUNTRY_NAMES ? undefined : resolve(key, params)
    act(() => h.card({ ...countryCard, enabled: true }))
    expect(el('hilos-page-tab-hilos_i18n_country_names')).toBeNull()
    expect(el('hilos-page-tabs')).toBeNull()
    expect(el('hilos-page-head-title').textContent).toBe('Guernsey')
    expect(el('country-head-code').textContent).toBe('gg')
    expect(retiredCountryHeaderLeft()).toBe(false)
    click('country-card-view')
    expect(
      el<HTMLInputElement>('i18n-country-edit-currency-symbol').disabled,
    ).toBe(true)
    expect(el('i18n-country-save')).toBeNull()
    click('i18n-country-close')
    expect(el('modal')).toBeNull()
  })
  it('disposes an edit session when the project context changes', () => {
    const h = setup()
    act(() => h.card())
    click('country-card-edit')
    const next = harness(HilosPages.I18N_COUNTRY)
    next.card({ ...countryCard, countryId: 103, code: 'fr' })
    h.view.rerender(
      <HilosRouterContext.Provider value={h.navigation}>
        <HilosI18nCountryPage context={next.context} />
      </HilosRouterContext.Provider>,
    )
    expect(el('i18n-country-window')).toBeNull()
  })
  it('releases a pending old-context deletion before a new confirmation can open', async () => {
    const h = setup()
    act(() => h.card())
    click('country-card-delete')
    click('i18n-country-delete-confirm')
    const next = harness(HilosPages.I18N_COUNTRY)
    next.card({
      ...countryCard,
      countryId: 103,
      code: 'fr',
      summary: { ...countryCard.summary, name: 'Français' },
    })
    h.view.rerender(
      <HilosRouterContext.Provider value={h.navigation}>
        <HilosI18nCountryPage context={next.context} />
      </HilosRouterContext.Provider>,
    )
    expect(el('modal')).toBeNull()
    click('country-card-delete')
    await settle(() => h.succeed())
    expect(h.navigation.navigate).not.toHaveBeenCalled()
    expect(el('modal').textContent).toContain('Delete country · Français')
    expect(el<HTMLButtonElement>('i18n-country-delete-confirm').disabled).toBe(
      false,
    )
  })
  it.each([null, ''] as const)(
    'shows Unnamed country for the name %j beside the separate uppercase code',
    (name) => {
      const h = setup()
      act(() =>
        h.card({ ...countryCard, summary: { ...countryCard.summary, name } }),
      )
      const title = el('hilos-page-head-title')
      expect(title.textContent).toBe('Unnamed country')
      expect(title.classList.contains('text-uppercase')).toBe(false)
      expect(lastCrumb()).toBe('Unnamed country')
      expect(el('country-head-tile').textContent).toBe('gg')
      expect(el('country-head-code').textContent).toBe('gg')
      expect(retiredCountryHeaderLeft()).toBe(false)
    },
  )
  it.each([
    ['Guernsey', 'Guernsey', false],
    [null, 'Unnamed country', false],
    ['', 'Unnamed country', true],
    ['  ', '  ', true],
  ] as const)(
    'binds %j as %j in the crumb, the one h1 and the owner, live until unmount (viewer %s)',
    (name, expected, viewer) => {
      const h = setup(viewer)
      const identity = h.navigation.pageIdentity
      act(() =>
        h.card({ ...countryCard, summary: { ...countryCard.summary, name } }),
      )
      expect(lastCrumb()).toBe(expected)
      expect(document.querySelectorAll('h1')).toHaveLength(1)
      expect(el('hilos-page-head-title').textContent).toBe(expected)
      expect(identity.get()?.pageHeadOwner?.label).toBe(expected)
      expect(identity.get()?.label).toBe('Country')
      expect(
        el('hilos-breadcrumb-hilos_i18n_countries').getAttribute('href'),
      ).toBe('/hilos/i18n/countries')
      expect(el('country-head-code').textContent).toBe('gg')
      expect(el('country-head-description')).not.toBeNull()
      expect(
        el('hilos-page-tab-hilos_i18n_country_names').getAttribute('href'),
      ).toBe('/hilos/i18n/countries/gg/names')
      for (const liveName of ['Renamed', null, '', ' Spaced ']) {
        const display =
          liveName === null || liveName === '' ? 'Unnamed country' : liveName
        act(() =>
          h.card({
            ...countryCard,
            summary: { ...countryCard.summary, name: liveName },
          }),
        )
        expect(lastCrumb()).toBe(display)
        expect(el('hilos-page-head-title').textContent).toBe(display)
        expect(identity.get()?.pageHeadOwner?.label).toBe(display)
      }
      h.view.unmount()
      expect(identity.get()?.pageHeadOwner?.label).toBe('Country')
      expect(identity.get()?.breadcrumb.at(-1)?.label).toBe('Country')
    },
  )
  it('binds the next country on a subject change and gives no name to another page', () => {
    const h = setup()
    const identity = h.navigation.pageIdentity
    act(() => h.card())
    expect(identity.get()?.pageHeadOwner?.label).toBe('Guernsey')
    const france = {
      ...countryCard,
      countryId: 103,
      code: 'fr',
      summary: { ...countryCard.summary, name: 'France' },
    }
    act(() => {
      h.route.set({
        page: HilosPages.I18N_COUNTRY,
        params: { countryCode: 'fr' },
        admin: true,
      })
      h.card(france)
    })
    expect(el('hilos-page-head-title').textContent).toBe('France')
    expect(lastCrumb()).toBe('France')
    expect(identity.get()?.pageHeadOwner?.label).toBe('France')
    expect(el('country-head-code').textContent).toBe('fr')
    expect(
      el('hilos-page-tab-hilos_i18n_country_names').getAttribute('href'),
    ).toBe('/hilos/i18n/countries/fr/names')
    act(() =>
      h.card({ ...france, summary: { ...france.summary, name: 'République' } }),
    )
    expect(el('hilos-page-head-title').textContent).toBe('République')
    expect(identity.get()?.pageHeadOwner?.label).toBe('République')
    act(() => {
      const other = h.scopes.openPage(HilosPages.SETTINGS)
      other.data.set('pageLabel', 'Settings')
      other.data.set('pageBreadcrumb', [
        { page: HilosPages.I18N_COUNTRY, label: 'Country' },
        { page: HilosPages.SETTINGS, label: 'Settings' },
      ])
      other.data.set('countryCard', france)
    })
    expect(identity.get()?.breadcrumb[0]?.label).toBe('Country')
  })
  it('moves the name to the scopes of a new context and releases the old ones', () => {
    const h = setup()
    act(() => h.card())
    expect(h.navigation.pageIdentity.get()?.pageHeadOwner?.label).toBe(
      'Guernsey',
    )
    const next = harness(HilosPages.I18N_COUNTRY)
    next.card({
      ...countryCard,
      countryId: 103,
      code: 'fr',
      summary: { ...countryCard.summary, name: 'France' },
    })
    expect(next.navigation.pageIdentity.get()?.pageHeadOwner?.label).toBe(
      'Country',
    )
    h.view.rerender(
      <HilosRouterContext.Provider value={h.navigation}>
        <HilosI18nCountryPage context={next.context} />
      </HilosRouterContext.Provider>,
    )
    expect(h.navigation.pageIdentity.get()?.pageHeadOwner?.label).toBe(
      'Country',
    )
    expect(next.navigation.pageIdentity.get()?.pageHeadOwner?.label).toBe(
      'France',
    )
    h.view.unmount()
    expect(next.navigation.pageIdentity.get()?.pageHeadOwner?.label).toBe(
      'Country',
    )
  })
})
