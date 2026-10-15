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

  it('distinguishes loading from a deleted card and renders live fields, marks, counts and code-addressed tabs', () => {
    const h = setup()
    expect(
      document.querySelector('[aria-label="Loading country"]'),
    ).not.toBeNull()
    expect(el('country-card-header')).toBeNull()
    act(() => h.card())
    expect(el('country-card-code').textContent).toBe('gg')
    expect(el('country-card-currency').textContent).toBe('£ GBP')
    expect(el('country-card-default-locale').textContent).toBe('not chosen')
    expect(
      el('country-card-tab-hilos_i18n_country').getAttribute('aria-current'),
    ).toBe('page')
    expect(
      el('country-card-tab-hilos_i18n_country_names').getAttribute('href'),
    ).toBe('/hilos/i18n/countries/gg/names')
    expect(
      document.querySelectorAll('[data-id^="country-card-tab-"]'),
    ).toHaveLength(2)
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
    expect(el('country-card-title').textContent).toBe('Changed')
    expect(el('country-card-line').textContent).toContain(
      'Currency $ USD · Default locale en-GG',
    )
    expect(el('country-card-own')).not.toBeNull()
    expect(el('country-card-frozen')).not.toBeNull()
    expect(el('country-card-edit')).toBeNull()
    act(() => h.card([]))
    expect(el('country-card-unavailable').textContent).toContain(
      'no longer available',
    )
    expect(document.querySelector('[aria-label="Loading country"]')).toBeNull()
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
  it('renders unavailable tab paths as text and keeps the card frozen in view', () => {
    const h = setup()
    h.navigation.resolvePath = () => undefined
    act(() => h.card({ ...countryCard, enabled: true }))
    expect(el('country-card-tab-hilos_i18n_country_names').tagName).toBe('SPAN')
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
  it('shows an uppercase code for a missing name and preserves an empty own title', () => {
    const h = setup()
    act(() =>
      h.card({
        ...countryCard,
        summary: { ...countryCard.summary, name: null },
      }),
    )
    expect(el('country-card-title').textContent).toBe('gg')
    expect(el('country-card-code-badge')).toBeNull()
    act(() =>
      h.card({ ...countryCard, summary: { ...countryCard.summary, name: '' } }),
    )
    expect(el('country-card-title').textContent).toBe('')
    expect(el('country-card-code-badge')).not.toBeNull()
  })
})
