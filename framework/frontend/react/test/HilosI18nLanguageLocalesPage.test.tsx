import { act } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { HilosPages } from '@hilos/core'
import { HilosI18nLanguageLocalesPage } from '../src/admin/i18n/details/HilosI18nLanguageLocalesPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'
import {
  harness,
  languageCard,
  el,
  click,
  change,
  settle,
} from './hilosI18nHarness.js'
import {
  localeRow,
  absentLocale,
  countryless,
  templates,
} from './hilosI18nDetailsHarness.js'

function setup(viewer = false) {
  const rows = [
    countryless,
    { ...localeRow, enabled: true },
    { ...absentLocale, rowKey: 'uk-US', countryCode: 'us', countryName: null },
  ]
  const h = harness(HilosPages.I18N_LANGUAGE_LOCALES, rows, viewer)
  h.scope.data.set('localeTemplates', templates)
  const view = h.mount(<HilosI18nLanguageLocalesPage context={h.context} />)
  return { ...h, view }
}

describe('React language locales page', () => {
  it('waits for the card, draws live tabs and counts, and distinguishes deletion', () => {
    const h = setup()
    expect(el('language-card-header')).toBeNull()
    expect(el('hilos-viewport-table')).toBeNull()
    expect(
      document.querySelector('[aria-label="Loading language"]'),
    ).not.toBeNull()
    act(() => h.card())
    expect(document.querySelectorAll('h1')).toHaveLength(1)
    expect(
      el('language-card-tab-hilos_i18n_language_locales').getAttribute(
        'aria-current',
      ),
    ).toBe('page')
    expect(
      el('language-card-tab-hilos_i18n_language_names').getAttribute('href'),
    ).toBe('/hilos/i18n/languages/uk/names')
    act(() =>
      h.card({
        ...languageCard,
        summary: { ...languageCard.summary, localeCount: 5 },
      }),
    )
    expect(el('language-card-counts').textContent).toBe('Locales: 5 · Names: 3')
    expect(document.body.textContent).not.toMatch(/Preview|Synchroniz|Flags/)
    act(() => h.card(null))
    expect(el('language-card-unavailable')).not.toBeNull()
    expect(h.calls).toEqual([])
  })
  it('renders countryless and country pairs, absent locales, readable switches and add/edit/view in both projections', () => {
    const h = setup()
    act(() => h.card())
    expect(el('i18n-locales-country-uk').textContent).toBe('— no country')
    expect(el('i18n-locales-country-uk-GB').textContent).toBe(
      'gbUnited Kingdom',
    )
    expect(el('i18n-locales-none-uk-US').textContent).toBe('No locale')
    expect(el('i18n-locales-enabled-uk-US')).toBeNull()
    expect(el<HTMLInputElement>('i18n-locales-enabled-uk-GB').checked).toBe(
      true,
    )
    expect(el<HTMLInputElement>('i18n-locales-enabled-uk-GB').disabled).toBe(
      true,
    )
    expect(el('i18n-locales-enabled-uk-GB').getAttribute('aria-label')).toBe(
      'Enabled',
    )
    expect(
      el('i18n-locales-code-uk').classList.contains('text-body-secondary'),
    ).toBe(true)
    expect(
      el('i18n-locales-code-uk-GB').classList.contains('text-body-secondary'),
    ).toBe(false)
    expect(document.querySelector('[class*="opacity"]')).toBeNull()
    for (const projection of [
      document.querySelector('table')!,
      el('hilos-table-cards'),
    ]) {
      for (const id of [
        'i18n-locales-edit-uk',
        'i18n-locales-view-uk-GB',
        'i18n-locales-add-uk-US',
        'i18n-locales-delete-uk',
        'i18n-locales-delete-uk-GB',
      ])
        expect(projection.querySelector(`[data-id="${id}"]`)).not.toBeNull()
      expect(
        projection.querySelector('[data-id="i18n-locales-delete-uk-US"]'),
      ).toBeNull()
    }
  })
  it('freezes fresh deletion labels and keeps its request through pair and card echoes', async () => {
    const h = setup()
    act(() => {
      h.card()
      h.delta({ ...countryless, countryName: 'Fresh' })
    })
    click('i18n-locales-delete-uk')
    click('i18n-locale-delete-confirm')
    expect(h.calls[0]).toMatchObject({
      action: 'hilos_i18n_locale_delete',
      payload: { languageCode: 'uk', countryCode: null },
    })
    act(() => {
      h.delta({
        ...countryless,
        localeCode: null,
        formats: null,
        enabled: null,
      })
      h.card({
        ...languageCard,
        summary: { ...languageCard.summary, localeCount: 1 },
      })
    })
    expect(el('i18n-locales-none-uk').textContent).toBe('No locale')
    expect(el('i18n-locales-add-uk')).not.toBeNull()
    expect(el('i18n-locales-delete-uk')).toBeNull()
    expect(el('language-card-counts').textContent).toBe('Locales: 1 · Names: 3')
    act(() => h.card(null))
    expect(el('i18n-locale-delete-window')).not.toBeNull()
    expect(el('i18n-locale-delete-notice')).toBeNull()
    await settle(() => h.succeed())
    expect(el('i18n-locale-delete-window')).toBeNull()
    expect(h.focuses.at(-1)).toBe('')
  })
  it('rechecks a stale opener against fresh focus and fixes the current locale mode', () => {
    const h = setup()
    act(() => h.card())
    const stale = el('i18n-locales-delete-uk-GB')
    act(() => {
      h.delta({ ...localeRow, enabled: null, localeCode: null, formats: null })
      stale.click()
    })
    expect(el('i18n-locale-delete-window')).toBeNull()
    expect(h.focuses.at(-1)).toBe('')
    click('i18n-locales-add-uk-GB')
    expect(el('i18n-locale-title').textContent).toContain('Add locale')
  })
  it('holds a fresh target despite late labels and leaves enabled/default dependency checks to the server', async () => {
    const h = setup()
    act(() => {
      h.card()
      h.delta({ ...localeRow, countryName: 'Fresh country' })
    })
    click('i18n-locales-delete-uk-GB')
    act(() =>
      h.delta({
        ...localeRow,
        countryName: 'Another country',
        enabled: true,
        isCountryDefault: true,
      }),
    )
    expect(
      document.querySelector('[role="dialog"]')?.getAttribute('aria-label'),
    ).toContain('Fresh country')
    expect(el<HTMLButtonElement>('i18n-locale-delete-confirm').disabled).toBe(
      false,
    )
    click('i18n-locale-delete-confirm')
    await settle(() => h.refuse(0, 'A name correction blocks deletion'))
    expect(el('i18n-locale-delete-error').textContent).toContain(
      'name correction',
    )
  })
  it.each(['locale', 'formats', 'language'] as const)(
    'holds an idle confirmation after its %s disappears',
    (gone) => {
      const h = setup()
      act(() => h.card())
      click('i18n-locales-delete-uk-GB')
      act(() => {
        if (gone === 'language') h.card(null)
        else
          h.delta({
            ...localeRow,
            ...(gone === 'locale' ? { localeCode: null } : { formats: null }),
          })
      })
      expect(el('i18n-locale-delete-notice').textContent).toContain(
        'Deleted elsewhere',
      )
      expect(el<HTMLButtonElement>('i18n-locale-delete-confirm').disabled).toBe(
        true,
      )
    },
  )
  it('keeps a deleted language draft, releases old subject and ignores its late acknowledgement', async () => {
    const h = setup()
    act(() => h.card())
    click('i18n-locales-edit-uk')
    change('i18n-locale-field-date', 'YYYY-MM-DD')
    act(() => h.card(null))
    expect(el<HTMLSelectElement>('i18n-locale-field-date').value).toBe(
      'YYYY-MM-DD',
    )
    expect(el('i18n-locale-submit').textContent).toBe('Deleted')
    act(() => h.card())
    click('i18n-locale-submit')
    act(() => {
      h.route.set({
        page: HilosPages.I18N_LANGUAGE_LOCALES,
        params: { languageCode: 'fr' },
        admin: true,
      })
      h.card({ ...languageCard, code: 'fr' })
    })
    expect(el('modal')).toBeNull()
    expect(h.unregister).toHaveBeenCalled()
    expect(h.sendViewport).toHaveBeenLastCalledWith(
      HilosPages.I18N_LANGUAGE_LOCALES,
      'hilosI18nLanguageLocales',
      expect.objectContaining({ filter: { language: 'fr' } }),
    )
    click('i18n-locales-edit-uk')
    await settle(() => h.succeed())
    expect(el('i18n-locale-window')).not.toBeNull()
    h.view.unmount()
    expect(h.focuses.at(-1)).toBe('')
    for (const [event, listeners] of h.listeners)
      expect(listeners.size).toBe(h.baseListenerCounts.get(event) ?? 0)
  })
  it('releases old context windows and leaves its late failure out of the new form', async () => {
    const h = setup()
    act(() => h.card())
    click('i18n-locales-edit-uk')
    change('i18n-locale-field-date', 'YYYY-MM-DD')
    click('i18n-locale-submit')
    const next = harness(HilosPages.I18N_LANGUAGE_LOCALES, [countryless])
    next.card()
    next.scope.data.set('localeTemplates', templates)
    h.view.rerender(
      <HilosRouterContext.Provider value={h.navigation}>
        <HilosI18nLanguageLocalesPage context={next.context} />
      </HilosRouterContext.Provider>,
    )
    click('i18n-locales-edit-uk')
    change('i18n-locale-field-date', 'DD.MM.YYYY')
    await settle(() => h.refuse(0, 'Old failure'))
    expect(el<HTMLSelectElement>('i18n-locale-field-date').value).toBe(
      'DD.MM.YYYY',
    )
    expect(el('i18n-locale-error').textContent).not.toContain('Old failure')
  })
  it('applies values live while pair removal waits behind Apply and a refused window stays explicit', () => {
    const h = setup()
    act(() => h.card())
    act(() => h.delta({ ...localeRow, enabled: false }))
    expect(el('i18n-locales-edit-uk-GB')).not.toBeNull()
    expect(el('hilos-table-apply')).toBeNull()
    act(() => h.delta(localeRow, 'row_removed'))
    expect(el('hilos-table-row-uk-GB').textContent).toContain('United Kingdom')
    click('hilos-table-apply')
    expect(el('hilos-table-row-uk-GB').textContent).toContain('Removed')
    act(() =>
      h.emit('tableWindowRefused', {
        data: {
          page: HilosPages.I18N_LANGUAGE_LOCALES,
          tableKey: h.tableKey,
          errorCode: 'source_unavailable',
        },
      }),
    )
    expect(el('hilos-table-unavailable')).not.toBeNull()
    expect(el('language-card-header')).not.toBeNull()
  })

  it('lets a viewer read each window but blocks creation, updates and deletion', () => {
    const h = setup(true)
    act(() => h.card())
    click('i18n-locales-delete-uk')
    expect(el<HTMLButtonElement>('i18n-locale-delete-confirm').disabled).toBe(
      true,
    )
    click('i18n-locale-delete-confirm')
    click('i18n-locale-delete-cancel')
    click('i18n-locales-add-uk-US')
    expect(el<HTMLButtonElement>('i18n-locale-submit').disabled).toBe(true)
    click('i18n-locale-submit')
    click('i18n-locale-cancel')
    click('i18n-locales-edit-uk')
    change('i18n-locale-field-date', 'YYYY-MM-DD')
    click('i18n-locale-submit')
    expect(el<HTMLButtonElement>('i18n-locale-submit').disabled).toBe(true)
    expect(h.calls).toEqual([])
  })
})
