// The Angular countries list over the real table binder and action lifecycle:
// the declared projections and marks, the catalog note and legend, creation
// from the table's main action with the confirmation of an own code, the row
// window in edit and view with the country's own locales, the delete
// confirmation over the row in focus through its own echo, a viewer of the
// admin view mode, and a context replaced under open windows. The rules
// themselves are the core's and are tested there; this file covers the Angular
// wiring.
import {
  HILOS_I18N_COUNTRY_DELETE_COPY,
  HilosPages,
  hilosToasts,
} from '@hilos/core'
import { describe, expect, it } from 'vitest'

import { HilosI18nCountriesPage } from '../src/admin/i18n/lists/HilosI18nCountriesPage.js'
import { country, i18nBench, mountOver } from './helpers/hilosI18n.js'

const own = {
  ...country,
  code: 'zz',
  name: null,
  currencySymbol: 'Z',
  currencyCode: 'ZZZ',
  isOwn: true,
  locales: [],
  catalogDefaultLocaleCode: null,
  canDelete: true,
  deleteReason: null,
}
const france = {
  ...country,
  code: 'fr',
  name: 'France',
  currencySymbol: '€',
  currencyCode: 'EUR',
  defaultLocaleCode: 'fr-FR',
  enabled: true,
  locales: [{ code: 'fr-FR', enabled: true }],
  catalogDefaultLocaleCode: 'fr-FR',
}

function setup(rows = [country, own, france], viewer = false) {
  const bench = i18nBench(HilosPages.I18N_COUNTRIES, rows, viewer)
  const unmounted = bench.listenerCount()
  const view = mountOver(HilosI18nCountriesPage, bench, {
    context: bench.context,
  })

  return { ...bench, ...view, unmounted }
}

/** The labels of a select's options, in order. */
function options(select: HTMLSelectElement | null): string[] {
  return [...(select?.options ?? [])].map((option) => option.text.trim())
}

describe('HilosI18nCountriesPage', () => {
  it('draws the declared projections: linked codes, a missing name and locale, the own mark, read-only ticks and both openers', () => {
    const h = setup()
    expect(h.el('i18n-countries-link-us')?.getAttribute('href')).toBe(
      '/hilos/i18n/countries/us',
    )
    expect(h.el('i18n-countries-link-us')?.className).toContain(
      'link-secondary',
    )
    expect(h.el('i18n-countries-link-fr')?.className).not.toContain(
      'link-secondary',
    )
    expect(h.el('hilos-table-row-zz')?.textContent).toContain('ZZ')
    expect(h.el('i18n-countries-own-zz')?.textContent).toContain(
      'Custom country',
    )
    expect(h.el('i18n-countries-own-us')).toBeNull()
    expect(h.el('i18n-countries-locale-none-us')?.textContent).toContain(
      'Not chosen',
    )
    expect(h.el('i18n-countries-locale-none-fr')).toBeNull()
    expect(h.el('hilos-table-row-fr')?.textContent).toContain('fr-FR')
    expect(h.el('hilos-table-row-fr')?.textContent).toContain('EUR')
    expect(h.el<HTMLInputElement>('i18n-countries-enabled-fr')?.checked).toBe(
      true,
    )
    expect(h.el<HTMLInputElement>('i18n-countries-enabled-fr')?.disabled).toBe(
      true,
    )
    expect(h.el('i18n-countries-view-fr')).not.toBeNull()
    expect(h.el('i18n-countries-edit-us')).not.toBeNull()
    expect(h.all('i18n-countries-link-us')).toHaveLength(2)
    expect(h.fixture.nativeElement.textContent).not.toMatch(
      /Preview|Synchronize|Translate|restcountries/,
    )
  })

  it('draws a code without an address as plain text', () => {
    const h = setup([own])
    h.paths.resolvePath = () => undefined
    h.act(() => h.pushWindow([{ ...own, currencySymbol: 'Y' }]))
    expect(h.el('i18n-countries-link-zz')?.tagName).toBe('SPAN')
  })

  it.each(['known', 'locales', 'names'] as const)(
    'keeps a %s deletion blocked in both projections with its own visible reason',
    (deleteReason) => {
      const h = setup([{ ...own, canDelete: false, deleteReason }])
      const controls = h.all('i18n-countries-delete-zz') as HTMLButtonElement[]
      const ids = controls.map((control) =>
        control.getAttribute('aria-describedby'),
      )
      expect(controls).toHaveLength(2)
      expect(new Set(ids).size).toBe(2)
      for (const control of controls) {
        expect(control.getAttribute('aria-disabled')).toBe('true')
        expect(control.hasAttribute('title')).toBe(false)
        expect(
          document
            .getElementById(control.getAttribute('aria-describedby')!)
            ?.textContent?.trim(),
        ).toBe(HILOS_I18N_COUNTRY_DELETE_COPY.reasons[deleteReason])
      }
      h.click('i18n-countries-delete-zz')
      expect(h.focuses).toEqual([])
      expect(h.el('modal')).toBeNull()
    },
  )

  it('shows the live catalog and legend, and sends a known code from the main action, keeping its refusal in the form', async () => {
    const h = setup([])
    expect(h.el('i18n-countries-catalog')).toBeNull()
    expect(h.el('i18n-countries-legend-own')).not.toBeNull()
    expect(h.el('i18n-countries-legend-deleteBlocked')).not.toBeNull()
    h.act(() => {
      h.scope.data.set('builtInCatalog', {
        languageCount: 50,
        countryCount: 51,
      })
      h.scope.data.set('builtInCountryCodes', ['us', 'fr'])
    })
    expect(h.el('i18n-countries-catalog')?.textContent).toContain(
      'holds 51 countries',
    )
    h.click('hilos-table-main-action')
    expect(document.activeElement).toBe(h.el('i18n-country-add-code'))
    expect(
      h.el<HTMLSelectElement>('i18n-country-add-default-locale')?.disabled,
    ).toBe(true)
    expect(
      options(h.el<HTMLSelectElement>('i18n-country-add-default-locale')),
    ).toEqual(['Not chosen'])
    expect(h.el<HTMLButtonElement>('i18n-country-add-submit')?.disabled).toBe(
      true,
    )
    h.type('i18n-country-add-code', 'US')
    expect(h.el('i18n-country-add-plate')?.getAttribute('data-kind')).toBe(
      'known',
    )
    h.type('i18n-country-add-currency-symbol', '$')
    h.type('i18n-country-add-currency-code', 'usd')
    expect(
      h.el<HTMLInputElement>('i18n-country-add-currency-code')?.value,
    ).toBe('USD')
    h.submit()
    h.submit()
    expect(h.calls).toHaveLength(1)
    expect(h.calls[0]).toMatchObject({
      action: 'hilos_i18n_country_add',
      payload: { countryCode: 'us', currencySymbol: '$', currencyCode: 'USD' },
    })
    h.escape()
    expect(h.el('modal')).not.toBeNull()
    expect(h.el<HTMLButtonElement>('i18n-country-add-cancel')?.disabled).toBe(
      true,
    )
    await h.settle(() => h.refuse(0, 'This country already exists.'))
    expect(h.el('i18n-country-add-error')?.textContent).toContain(
      'This country already exists.',
    )
    h.type('i18n-country-add-code', 'fr')
    h.type('i18n-country-add-currency-code', 'eur')
    h.click('i18n-country-add-submit')
    await h.settle(() => h.succeed(1, 'Country added.'))
    expect(h.el('modal')).toBeNull()
    expect(
      hilosToasts.toasts
        .get()
        .some((toast) => toast.message === 'Country added.'),
    ).toBe(true)
    expect(h.calls).toHaveLength(2)
  })

  it('asks to confirm an own code, sends it only from the confirmation and asks before discarding typed values', async () => {
    const h = setup([])
    h.act(() => h.scope.data.set('builtInCountryCodes', ['us']))
    h.click('hilos-table-main-action')
    h.type('i18n-country-add-code', 'gg')
    h.escape()
    expect(h.el('modal-confirm-discard')).not.toBeNull()
    h.click('modal-confirm-cancel')
    h.type('i18n-country-add-currency-symbol', '£')
    h.type('i18n-country-add-currency-code', 'gbp')
    expect(h.el('i18n-country-add-plate')?.getAttribute('data-kind')).toBe(
      'own',
    )
    h.click('i18n-country-add-submit')
    expect(h.calls).toEqual([])
    expect(h.el('i18n-country-confirm-code')?.textContent?.trim()).toBe('gg')
    expect(document.body.textContent).toContain('catalog of 1 countries')
    expect(document.activeElement?.getAttribute('role')).toBe('dialog')
    h.click('i18n-country-confirm-submit')
    h.click('i18n-country-confirm-submit')
    expect(h.calls).toHaveLength(1)
    expect(h.calls[0]).toMatchObject({
      action: 'hilos_i18n_country_add',
      payload: { countryCode: 'gg', currencySymbol: '£', currencyCode: 'GBP' },
    })
    expect(
      h.el<HTMLButtonElement>('i18n-country-confirm-cancel')?.disabled,
    ).toBe(true)
    await h.settle(() => h.refuse())
    expect(h.el('i18n-country-confirm-error')?.textContent).toContain(
      'Server refused the language',
    )
    h.click('i18n-country-confirm-cancel')
    expect(h.el('modal')).toBeNull()
    h.click('hilos-table-main-action')
    expect(h.el<HTMLInputElement>('i18n-country-add-code')?.value).toBe('')
    expect(h.el('i18n-country-add-error')?.textContent).not.toContain(
      'Server refused',
    )
  })

  it("edits a switched-off row over its own locales, suggests the catalog's default, and closes only on the ack after its own echo", async () => {
    const h = setup()
    h.click('i18n-countries-edit-us')
    expect(h.focuses).toEqual(['us'])
    expect(h.el<HTMLInputElement>('i18n-country-edit-code')?.value).toBe('us')
    expect(h.el<HTMLInputElement>('i18n-country-edit-code')?.disabled).toBe(
      true,
    )
    expect(document.activeElement).toBe(
      h.el('i18n-country-edit-currency-symbol'),
    )
    const select = h.el<HTMLSelectElement>('i18n-country-edit-default-locale')
    expect(options(select)).toEqual([
      'en-US',
      'es-US — switched off',
      'Not chosen',
    ])
    expect(select?.value).toBe('en-US')
    expect(
      h.el('i18n-country-edit-default-locale-hint')?.textContent,
    ).toContain('Suggested by the built-in catalog')
    expect(h.el('i18n-country-plate')?.textContent).toContain(
      'switched off — edit freely',
    )
    h.escape()
    expect(h.el('modal-confirm-discard')).toBeNull()
    expect(h.el('modal')).toBeNull()
    expect(h.focuses.at(-1)).toBe('')
    h.click('i18n-countries-edit-us')
    h.type('i18n-country-edit-currency-symbol', 'US$')
    h.click('i18n-country-save')
    h.click('i18n-country-save')
    expect(h.calls).toHaveLength(1)
    expect(h.calls[0]).toMatchObject({
      action: 'hilos_i18n_country_row_update',
      payload: {
        countryCode: 'us',
        currencySymbol: 'US$',
        currencyCode: 'USD',
        defaultLocaleCode: 'en-US',
      },
    })
    expect(h.el<HTMLButtonElement>('i18n-country-cancel')?.disabled).toBe(true)
    h.escape()
    expect(h.el('modal')).not.toBeNull()
    h.act(() =>
      h.delta({
        ...country,
        currencySymbol: 'US$',
        defaultLocaleCode: 'en-US',
      }),
    )
    expect(h.el('modal')).not.toBeNull()
    expect(h.el('i18n-country-notice')?.textContent?.trim() ?? '').toBe('')
    await h.settle(() => h.succeed())
    expect(h.el('modal')).toBeNull()
    expect(h.focuses.at(-1)).toBe('')
  })

  it('keeps a refused save open with the reason, then a deleted row keeps the draft', async () => {
    const h = setup()
    h.click('i18n-countries-edit-us')
    h.type('i18n-country-edit-currency-code', 'usn')
    h.click('i18n-country-save')
    await h.settle(() => h.refuse(0, 'The currency code is unknown.'))
    expect(h.el('modal')).not.toBeNull()
    expect(h.el('i18n-country-error')?.textContent).toContain(
      'The currency code is unknown.',
    )
    h.act(() => h.delta(country, 'row_removed'))
    expect(h.el('i18n-country-notice')?.textContent).toContain('Deleted')
    expect(
      h.el<HTMLInputElement>('i18n-country-edit-currency-code')?.value,
    ).toBe('USN')
    expect(h.el<HTMLButtonElement>('i18n-country-save')?.disabled).toBe(true)
  })

  it('opens a switched-on row in view and keeps it in view when it is switched off elsewhere', () => {
    const h = setup()
    h.click('i18n-countries-view-fr')
    expect(h.el('i18n-country-title')?.textContent).toContain(
      'Country · France',
    )
    expect(
      h.el<HTMLInputElement>('i18n-country-edit-currency-symbol')?.disabled,
    ).toBe(true)
    expect(
      h.el<HTMLSelectElement>('i18n-country-edit-default-locale')?.disabled,
    ).toBe(true)
    expect(
      h
        .el('i18n-country-edit-currency-symbol')
        ?.getAttribute('aria-describedby'),
    ).toBe('i18n-country-plate')
    expect(h.el('i18n-country-save')).toBeNull()
    h.act(() => h.delta({ ...france, enabled: false }))
    expect(h.el('i18n-country-notice')?.textContent).toContain(
      'Switched off elsewhere just now.',
    )
    expect(h.el('i18n-country-save')).toBeNull()
    h.submit()
    expect(h.calls).toEqual([])
    h.click('i18n-country-close')
    expect(h.el('modal')).toBeNull()
    expect(h.focuses.at(-1)).toBe('')
  })

  it('opens deletion over the fresh row in focus, holds its own echo until the ack and keeps the list address', async () => {
    const h = setup([own])
    h.act(() => h.delta({ ...own, name: 'Fresh land' }))
    h.click('i18n-countries-delete-zz')
    expect(h.el('modal')?.textContent).toContain('Delete country · Fresh land')
    expect(h.focuses).toEqual(['zz'])
    h.click('i18n-country-delete-confirm')
    h.click('i18n-country-delete-confirm')
    expect(h.calls).toHaveLength(1)
    expect(h.calls[0]).toMatchObject({
      action: 'hilos_i18n_country_row_delete',
      payload: { countryCode: 'zz' },
    })
    expect(
      h.el<HTMLButtonElement>('i18n-country-delete-cancel')?.disabled,
    ).toBe(true)
    h.escape()
    h.act(() => h.delta(own, 'row_removed'))
    expect(h.el('modal')).not.toBeNull()
    expect(h.el('i18n-country-delete-notice')).toBeNull()
    await h.settle(() => h.succeed(0, 'Country deleted.'))
    expect(h.el('modal')).toBeNull()
    expect(h.focuses.at(-1)).toBe('')
    expect(h.navigate).not.toHaveBeenCalled()
    expect(
      hilosToasts.toasts
        .get()
        .some((toast) => toast.message === 'Country deleted.'),
    ).toBe(true)
    h.click('hilos-table-apply')
    expect(h.el('hilos-table-placeholder')?.textContent).toContain('Removed')
    h.fixture.destroy()
    expect(h.unregister).toHaveBeenCalled()
    expect(h.listenerCount()).toBe(h.unmounted)
  })

  it('keeps a refused deletion open with the reason and says when the country left elsewhere', async () => {
    const h = setup([own])
    h.click('i18n-countries-delete-zz')
    h.click('i18n-country-delete-confirm')
    await h.settle(() => h.refuse(0, 'Names refer to this country.'))
    expect(h.el('modal')).not.toBeNull()
    expect(h.el('i18n-country-delete-error')?.textContent).toContain(
      'Names refer to this country.',
    )
    h.act(() => h.delta(own, 'row_removed'))
    expect(h.el('i18n-country-delete-notice')?.textContent).toContain(
      'Deleted elsewhere.',
    )
    expect(
      h.el<HTMLButtonElement>('i18n-country-delete-confirm')?.disabled,
    ).toBe(true)
    h.click('i18n-country-delete-cancel')
    expect(h.el('modal')).toBeNull()
    expect(h.focuses.at(-1)).toBe('')
  })

  it('declines a delete click on a row the server no longer lets go and holds no focus', () => {
    const h = setup([own])
    h.act(() => h.delta({ ...own, canDelete: false, deleteReason: 'names' }))
    h.click('i18n-countries-delete-zz')
    expect(h.el('modal')).toBeNull()
    expect(h.focuses).toEqual([])
  })

  it('lets a viewer open and read every window while nothing is sent', () => {
    const h = setup([country, own], true)
    h.click('i18n-countries-edit-us')
    h.type('i18n-country-edit-currency-symbol', 'Draft')
    expect(
      h.el<HTMLInputElement>('i18n-country-edit-currency-symbol')?.disabled,
    ).toBe(false)
    expect(h.el<HTMLButtonElement>('i18n-country-save')?.disabled).toBe(true)
    h.submit()
    expect(h.calls).toEqual([])
    h.escape()
    h.click('modal-confirm-discard')
    h.click('i18n-countries-delete-zz')
    expect(
      h.el<HTMLButtonElement>('i18n-country-delete-confirm')?.disabled,
    ).toBe(true)
    h.click('i18n-country-delete-confirm')
    expect(h.calls).toEqual([])
    h.click('i18n-country-delete-cancel')
    expect(h.focuses.at(-1)).toBe('')
    h.click('hilos-table-main-action')
    h.type('i18n-country-add-code', 'gg')
    h.type('i18n-country-add-currency-symbol', '£')
    h.type('i18n-country-add-currency-code', 'GBP')
    expect(h.el<HTMLButtonElement>('i18n-country-add-submit')?.disabled).toBe(
      true,
    )
    h.submit()
    expect(h.calls).toEqual([])
    h.click('i18n-country-add-cancel')
    h.click('modal-confirm-discard')
    expect(h.el('modal')).toBeNull()
  })

  it('drops the windows of a former context and lets its pending deletion reach nothing of the new one', async () => {
    const h = setup([country, own])
    h.click('i18n-countries-edit-us')
    const next = i18nBench(HilosPages.I18N_COUNTRIES, [france])
    h.fixture.componentRef.setInput('context', next.context)
    h.detect()
    expect(h.el('i18n-country-window')).toBeNull()
    expect(h.focuses.at(-1)).toBe('')
    expect(h.unregister).toHaveBeenCalled()
    expect(h.el('i18n-countries-view-fr')).not.toBeNull()
    h.fixture.componentRef.setInput('context', h.context)
    h.detect()
    h.click('i18n-countries-delete-zz')
    h.click('i18n-country-delete-confirm')
    const last = i18nBench(HilosPages.I18N_COUNTRIES, [own])
    h.fixture.componentRef.setInput('context', last.context)
    h.detect()
    expect(h.el('modal')).toBeNull()
    h.click('i18n-countries-delete-zz')
    await h.settle(() => h.succeed(0))
    expect(h.el('modal')?.textContent).toContain('Delete country · ZZ')
    expect(
      h.el<HTMLButtonElement>('i18n-country-delete-confirm')?.disabled,
    ).toBe(false)
    h.fixture.destroy()
    expect(last.focuses.at(-1)).toBe('')
  })
})
