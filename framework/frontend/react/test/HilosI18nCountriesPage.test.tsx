import { act, fireEvent, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { HilosPages } from '@hilos/core'
import { HilosI18nCountriesPage } from '../src/admin/i18n/lists/HilosI18nCountriesPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'
import {
  harness,
  country,
  el,
  click,
  change,
  settle,
} from './hilosI18nHarness.js'

const english = {
  ...country,
  countryId: 144,
  rowKey: '144',
  code: 'en',
  name: null,
  enabled: true,
  canDelete: false,
  deleteReason: 'known' as const,
}
const own = {
  ...country,
  countryId: 141,
  rowKey: '141',
  code: 'qx',
  name: 'Own country',
  isOwn: true,
}
const SERVER_REASONS = {
  known:
    'This country cannot be deleted because the built-in catalog knows it and the reflow would bring it back; switch it off instead.',
  locales: 'This country cannot be deleted because locales refer to it.',
  names: 'This country cannot be deleted because names refer to it.',
} as const

function setup(rows = [english, own], viewer = false) {
  const h = harness(HilosPages.I18N_COUNTRIES, rows, viewer)
  const view = h.mount(<HilosI18nCountriesPage context={h.context} />)
  return { ...h, view }
}

describe('React countries list', () => {
  it('names the section row Internationalization pages and keeps Countries current', () => {
    const h = setup()
    act(() => {
      h.scope.data.set('pageLabel', 'Countries')
      h.scope.data.set('pageHeadOwner', {
        page: HilosPages.I18N,
        label: 'Internationalization',
        lead: 'Languages, countries, locales, and translation screens.',
        icon: 'bi-translate',
      })
      h.scope.data.set('pageBreadcrumb', [
        { page: HilosPages.DASHBOARD, label: 'Hilos' },
        { page: HilosPages.I18N, label: 'Internationalization' },
      ])
      h.scope.data.set('pageTabs', [
        { page: HilosPages.I18N, label: 'Overview' },
        { page: HilosPages.I18N_LANGUAGES, label: 'Languages' },
        { page: HilosPages.I18N_COUNTRIES, label: 'Countries' },
      ])
      h.scope.data.set('pageChildren', [])
    })
    const tabs = screen.getByRole('navigation', {
      name: 'Internationalization pages',
    })
    expect(tabs.querySelector('[aria-current="page"]')?.textContent).toBe(
      'Countries',
    )
  })

  it('uses the declared table and card projections, links codes, marks custom countries and ticks state', () => {
    const h = setup()
    expect(h.view.container.querySelector('table')).not.toBeNull()
    expect(
      el<HTMLAnchorElement>('i18n-countries-link-en').getAttribute('href'),
    ).toBe('/hilos/i18n/countries/en')
    expect(el('i18n-countries-own-qx').textContent).toContain('Custom')
    expect(el('hilos-table-row-144').textContent).toContain('EN')
    expect(el('i18n-countries-locale-none-en').textContent).toContain(
      'Not chosen',
    )
    expect(el<HTMLInputElement>('i18n-countries-enabled-qx').checked).toBe(
      false,
    )
    expect(el<HTMLInputElement>('i18n-countries-enabled-qx').disabled).toBe(
      true,
    )
    expect(
      document.querySelectorAll('[data-id="i18n-countries-link-qx"]'),
    ).toHaveLength(2)
    expect(el('i18n-countries-view-en')).not.toBeNull()
    expect(el('i18n-countries-edit-qx')).not.toBeNull()
    expect(h.view.container.textContent).not.toMatch(
      /Preview|Synchronize|Translate/,
    )
  })
  it.each(['known', 'locales', 'names'] as const)(
    'shows the server %s refusal on the disabled action and in its explanation',
    (deleteReason) => {
      const reason = SERVER_REASONS[deleteReason]
      const h = setup([
        {
          ...own,
          canDelete: false,
          deleteReason,
          deleteDisabledReason: reason,
        },
      ])
      const deleteButtons = screen.getAllByRole('button', {
        name: 'Delete country Own country',
      })
      const ids = deleteButtons.map((button) =>
        button.getAttribute('aria-describedby'),
      )
      expect(deleteButtons).toHaveLength(2)
      expect(ids.every((id) => id !== null && id !== '')).toBe(true)
      expect(new Set(ids).size).toBe(2)
      const descriptions = [
        ...h.view.container.querySelectorAll<HTMLElement>(
          '[data-id="i18n-countries-delete-reason-qx"]',
        ),
      ]
      expect(descriptions).toHaveLength(2)
      for (const button of deleteButtons) {
        expect(button.tagName).toBe('BUTTON')
        expect((button as HTMLButtonElement).type).toBe('button')
        expect((button as HTMLButtonElement).disabled).toBe(true)
        expect(button.hasAttribute('aria-disabled')).toBe(false)
        expect(button.hasAttribute('title')).toBe(false)
        const description = document.getElementById(
          button.getAttribute('aria-describedby')!,
        )
        expect(description?.getAttribute('data-id')).toBe(
          'i18n-countries-delete-reason-qx',
        )
        expect(description?.textContent).toBe(reason)
        expect(description?.hidden).toBe(false)
        expect(description?.hasAttribute('aria-hidden')).toBe(false)
        fireEvent.click(button)
      }
      expect(h.focuses).toEqual([])
      expect(h.calls).toEqual([])
      expect(screen.queryByRole('dialog')).toBeNull()

      const whyButtons = screen.getAllByRole('button', {
        name: 'Why this action is unavailable',
      })
      expect(whyButtons).toHaveLength(2)
      for (const why of whyButtons) {
        expect((why as HTMLButtonElement).type).toBe('button')
        expect(why.title).toBe(reason)
      }
      const opener = whyButtons[0]!
      opener.focus()
      fireEvent.click(opener)
      const dialog = screen.getByRole('dialog', { name: 'Action unavailable' })
      expect(dialog.getAttribute('aria-modal')).toBe('true')
      expect(document.activeElement).toBe(dialog)
      expect(
        dialog
          .querySelector('[data-id="hilos-action-reason-body"]')
          ?.textContent?.trim(),
      ).toBe(reason)
      expect(
        screen.queryByRole('dialog', { name: /Delete country/ }),
      ).toBeNull()
      expect(h.calls).toEqual([])
      fireEvent.click(el('hilos-action-reason-close'))
      expect(document.activeElement).toBe(opener)
    },
  )
  it('keeps an available delete action free of an explanation', () => {
    const h = setup([own])
    const deleteButtons = screen.getAllByRole('button', {
      name: 'Delete country Own country',
    })

    expect(deleteButtons).toHaveLength(2)
    for (const button of deleteButtons) {
      expect((button as HTMLButtonElement).disabled).toBe(false)
      expect(button.getAttribute('aria-describedby')).toBeNull()
    }
    expect(
      h.view.container.querySelectorAll(
        '[data-id="i18n-countries-delete-reason-qx"]',
      ),
    ).toHaveLength(0)
    expect(
      screen.queryByRole('button', { name: 'Why this action is unavailable' }),
    ).toBeNull()
  })
  it('updates the explanation with the live delete verdict', () => {
    const h = setup([own])
    act(() =>
      h.delta({
        ...own,
        canDelete: false,
        deleteReason: 'locales',
        deleteDisabledReason: SERVER_REASONS.locales,
      }),
    )

    expect(
      screen.getAllByRole('button', { name: 'Why this action is unavailable' }),
    ).toHaveLength(2)
    act(() => h.delta(own))

    expect(
      screen.queryByRole('button', { name: 'Why this action is unavailable' }),
    ).toBeNull()
    expect(h.calls).toEqual([])
  })
  it('shows the live catalog and legend, and opens Add without another fetch', () => {
    const h = setup([])
    expect(el('i18n-countries-catalog')).toBeNull()
    expect(el('i18n-countries-legend')).not.toBeNull()
    act(() => {
      h.scope.data.set('builtInCatalog', {
        languageCount: 50,
        countryCount: 51,
      })
      h.scope.data.set('builtInCountryCodes', ['us'])
    })
    expect(el('i18n-countries-catalog').textContent).toContain(
      '50 languages and 51 countries',
    )
    click('hilos-table-main-action')
    change('i18n-country-add-code', 'us')
    expect(el('i18n-country-add-plate').getAttribute('data-kind')).toBe('known')
    expect(h.calls).toEqual([])
    act(() =>
      h.scope.data.set('builtInCatalog', {
        languageCount: 52,
        countryCount: 53,
      }),
    )
    expect(el('i18n-countries-catalog').textContent).toContain(
      '52 languages and 53 countries',
    )
  })
  it('opens the fresh focused row, holds a delete echo through ack and preserves the list address', async () => {
    const h = setup([own])
    act(() => h.delta({ ...own, name: 'Fresh name' }))
    click('i18n-countries-delete-qx')
    expect(el('modal').textContent).toContain('Delete country · Fresh name')
    expect(h.focuses).toEqual(['141'])
    click('i18n-country-delete-confirm')
    expect(h.calls[0]).toMatchObject({
      action: 'hilos_i18n_country_row_delete',
      payload: { countryId: 141 },
    })
    act(() => h.delta(own, 'row_removed'))
    expect(el('modal')).not.toBeNull()
    expect(el('hilos-table-row-141')).not.toBeNull()
    await settle(() => h.succeed())
    expect(el('modal')).toBeNull()
    expect(h.focuses.at(-1)).toBe('')
    expect(h.navigation.navigate).not.toHaveBeenCalled()
    expect(el('hilos-table-apply')).not.toBeNull()
    click('hilos-table-apply')
    expect(el('hilos-table-placeholder').textContent).toContain('Removed')
    h.view.unmount()
    expect(h.unregister).toHaveBeenCalled()
    expect(h.listeners.get('tableWindow')?.size).toBe(0)
  })
  it('declines a stale delete click after a removed row and leaves focus released', () => {
    const h = setup([own])
    act(() => h.delta(own, 'row_removed'))
    click('i18n-countries-delete-qx')
    expect(el('modal')).toBeNull()
    expect(h.focuses).toEqual([])
  })
  it('opens row edit and releases its focus on cancel and context replacement', () => {
    const h = setup([own])
    click('i18n-countries-edit-qx')
    expect(el<HTMLInputElement>('i18n-country-edit-code').value).toBe('qx')
    click('i18n-country-cancel')
    expect(h.focuses.at(-1)).toBe('')
    click('i18n-countries-edit-qx')
    const next = harness(HilosPages.I18N_COUNTRIES, [english])
    h.view.rerender(
      <HilosRouterContext.Provider value={h.navigation}>
        <HilosI18nCountriesPage context={next.context} />
      </HilosRouterContext.Provider>,
    )
    expect(el('i18n-country-window')).toBeNull()
    expect(h.unregister).toHaveBeenCalled()
  })
  it('opens read-only windows for a viewer while every mutation stays locked', () => {
    const h = setup([own], true)
    click('i18n-countries-edit-qx')
    change('i18n-country-edit-currency-symbol', 'Draft')
    expect(el<HTMLButtonElement>('i18n-country-save').disabled).toBe(true)
    fireEvent.submit(document.querySelector('form')!)
    expect(h.calls).toEqual([])
    h.view.unmount()
  })
  it('releases delete focus on context replacement and protects the new window from the old ack', async () => {
    const h = setup([own])
    click('i18n-countries-delete-qx')
    click('i18n-country-delete-confirm')
    const next = harness(HilosPages.I18N_COUNTRIES, [country])
    h.view.rerender(
      <HilosRouterContext.Provider value={h.navigation}>
        <HilosI18nCountriesPage context={next.context} />
      </HilosRouterContext.Provider>,
    )
    expect(h.focuses.at(-1)).toBe('')
    expect(el('modal')).toBeNull()
    click('i18n-countries-delete-gg')
    await settle(() => h.succeed())
    expect(el('modal').textContent).toContain('Delete country · Guernsey')
    expect(el<HTMLButtonElement>('i18n-country-delete-confirm').disabled).toBe(
      false,
    )
    h.view.unmount()
    expect(next.focuses.at(-1)).toBe('')
  })
  it('lets a viewer open row deletion with confirmation disabled', () => {
    const h = setup([own], true)
    click('i18n-countries-delete-qx')
    expect(el<HTMLButtonElement>('i18n-country-delete-confirm').disabled).toBe(
      true,
    )
    click('i18n-country-delete-confirm')
    expect(h.calls).toEqual([])
    click('i18n-country-delete-cancel')
    expect(h.focuses.at(-1)).toBe('')
  })
})
