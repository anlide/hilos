// The Angular edit/view window of a country over the core card window and the
// real page scope: the prefill with the catalog's suggested default locale over
// the country's own locales, the focus on the currency, the one canSave verdict
// Save and Enter both read, a refusal that stays, a busy save that holds every
// close and says nothing of its own echo, a suggestion that asks nothing on
// close against personal input that does, a vanished choice kept beside a new
// option, the view fixed at opening, a viewer of the admin view mode, and a new
// session bound in place of the old one. The session itself is the core's and
// is tested there.
import { signal } from '@angular/core'
import {
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  HilosPages,
  createHilosI18nCountryCard,
  createHilosI18nCountryCardWindow,
} from '@hilos/core'
import { describe, expect, it } from 'vitest'

import { HilosI18nCountryWindow } from '../src/admin/i18n/details/HilosI18nCountryWindow.js'
import { HILOS_ADMIN_VIEW_MODE } from '../src/hilosLookOnly.js'
import { countryCard, i18nBench, mountOver } from './helpers/hilosI18n.js'

function setup(viewer = false) {
  const bench = i18nBench(HilosPages.I18N_COUNTRY, [])
  bench.countryCard()
  const window = createHilosI18nCountryCardWindow(
    createHilosI18nCountryCard(bench.context),
    bench.context,
  )
  const view = mountOver(HilosI18nCountryWindow, bench, { window }, [
    { provide: HILOS_ADMIN_VIEW_MODE, useValue: signal(viewer).asReadonly() },
  ])
  const button = (id: string) => view.el<HTMLButtonElement>(id)!
  const select = () =>
    view.el<HTMLSelectElement>('i18n-country-edit-default-locale')!
  const choose = (value: string): void => {
    select().value = value
    select().dispatchEvent(new Event('change', { bubbles: true }))
    view.detect()
  }

  return { ...bench, ...view, window, button, select, choose }
}

/** The labels of the default-locale options, in order. */
function labels(select: HTMLSelectElement): string[] {
  return [...select.options].map((option) => option.text.trim())
}

describe('HilosI18nCountryWindow', () => {
  it('prefills the suggestion, focuses the currency, waits for valid input and closes only on the ack', async () => {
    const h = setup()
    h.act(() => h.window.open('edit'))
    expect(h.el('i18n-country-title')?.textContent).toContain(
      'Edit country · United States',
    )
    expect(document.activeElement).toBe(
      h.el('i18n-country-edit-currency-symbol'),
    )
    expect(h.el<HTMLInputElement>('i18n-country-edit-code')?.disabled).toBe(
      true,
    )
    expect(h.el('i18n-country-edit-code-hint')?.textContent).toContain(
      'The country code never changes',
    )
    expect(labels(h.select())).toEqual([
      'en-US',
      'es-US — switched off',
      'Not chosen',
    ])
    expect(h.select().value).toBe('en-US')
    expect(h.el('i18n-country-plate')?.className).toContain('alert-info')
    h.type('i18n-country-edit-currency-code', 'us')
    expect(h.button('i18n-country-save').disabled).toBe(true)
    h.submit()
    expect(h.calls).toEqual([])
    h.type('i18n-country-edit-currency-code', 'usn')
    h.type('i18n-country-edit-currency-symbol', '  US$  ')
    h.submit()
    expect(h.calls).toHaveLength(1)
    expect(h.calls[0]).toMatchObject({
      action: 'hilos_i18n_country_update',
      payload: {
        countryCode: 'us',
        currencySymbol: 'US$',
        currencyCode: 'USN',
        defaultLocaleCode: 'en-US',
      },
    })
    await h.settle(() => h.refuse(0, 'The currency code is unknown.'))
    expect(h.window.opened.get()).toBe(true)
    expect(h.el('i18n-country-error')?.textContent).toContain(
      'The currency code is unknown.',
    )
    h.type('i18n-country-edit-currency-code', 'usd')
    h.click('i18n-country-save')
    await h.settle(() => h.succeed(1, 'Country saved.'))
    expect(h.window.opened.get()).toBe(false)
    expect(h.el('modal')).toBeNull()
  })

  it('does not ask to discard a suggestion, but asks for personal input', () => {
    const h = setup()
    h.act(() => h.window.open('edit'))
    h.click('i18n-country-cancel')
    expect(h.window.opened.get()).toBe(false)
    expect(h.el('modal-confirm')).toBeNull()
    h.act(() => h.window.open('edit'))
    h.type('i18n-country-edit-currency-symbol', 'US$')
    h.click('i18n-country-cancel')
    expect(h.el('modal-confirm')?.textContent).toContain('Discard changes?')
    h.click('modal-confirm-cancel')
    expect(
      h.el<HTMLInputElement>('i18n-country-edit-currency-symbol')?.value,
    ).toBe('US$')
    h.escape()
    expect(h.el('modal-confirm')).not.toBeNull()
    h.click('modal-confirm-discard')
    expect(h.window.opened.get()).toBe(false)
  })

  it('blocks repeated sends, Cancel, Escape, the backdrop and the close button while busy and says nothing of its own echo', async () => {
    const h = setup()
    h.act(() => h.window.open('edit'))
    h.type('i18n-country-edit-currency-symbol', 'US$')
    h.click('i18n-country-save')
    h.click('i18n-country-save')
    h.submit()
    expect(h.calls).toHaveLength(1)
    expect(h.button('i18n-country-cancel').disabled).toBe(true)
    expect(h.button('modal-close').disabled).toBe(true)
    h.click('i18n-country-cancel')
    h.escape()
    h.click('modal')
    expect(h.window.opened.get()).toBe(true)
    expect(h.el('modal-confirm')).toBeNull()
    h.act(() =>
      h.countryCard({
        ...countryCard,
        currencySymbol: 'US$',
        summary: { ...countryCard.summary, defaultLocaleCode: 'en-US' },
      }),
    )
    expect(h.el('i18n-country-notice')?.textContent?.trim() ?? '').toBe('')
    await h.settle(() => h.succeed())
    expect(h.window.opened.get()).toBe(false)
  })

  it('keeps a vanished choice and shows a new option without changing the selection', () => {
    const h = setup()
    h.act(() => h.window.open('edit'))
    h.act(() =>
      h.countryCard({
        ...countryCard,
        summary: {
          ...countryCard.summary,
          locales: [{ code: 'haw-US', enabled: true }],
        },
      }),
    )
    expect(h.select().value).toBe('en-US')
    expect(labels(h.select())).toEqual([
      'haw-US',
      'en-US — no longer exists',
      'Not chosen',
    ])
    expect(h.button('i18n-country-save').disabled).toBe(false)
    h.choose('')
    expect(h.window.form.get().defaultLocaleCode).toBeNull()
    expect(h.window.touched.get()).toBe(true)
    expect(
      h.el('i18n-country-edit-default-locale-hint')?.textContent,
    ).toContain("Only this country's locales.")
  })

  it('says a country without locales has nothing to choose', () => {
    const h = setup()
    h.act(() =>
      h.countryCard({
        ...countryCard,
        summary: {
          ...countryCard.summary,
          locales: [],
          catalogDefaultLocaleCode: null,
        },
      }),
    )
    h.act(() => h.window.open('edit'))
    expect(labels(h.select())).toEqual(['Not chosen'])
    expect(
      h.el('i18n-country-edit-default-locale-hint')?.textContent,
    ).toContain('This country has no locales yet')
  })

  it('reads a switched-on country in view, stays in view when it is switched off and closes at once', () => {
    const h = setup()
    h.act(() =>
      h.countryCard({
        ...countryCard,
        enabled: true,
        summary: { ...countryCard.summary, defaultLocaleCode: 'es-US' },
      }),
    )
    h.act(() => h.window.open('view'))
    expect(h.el('i18n-country-title')?.textContent).toContain(
      'Country · United States',
    )
    for (const field of [
      'currency-symbol',
      'currency-code',
      'default-locale',
    ]) {
      const control = h.el<HTMLInputElement>(`i18n-country-edit-${field}`)!
      expect(control.disabled).toBe(true)
      expect(control.getAttribute('aria-describedby')).toBe(
        'i18n-country-plate',
      )
    }
    expect(h.select().value).toBe('es-US')
    expect(h.el('i18n-country-plate')?.className).toContain('alert-warning')
    expect(h.el('i18n-country-save')).toBeNull()
    expect(h.el('i18n-country-error')).toBeNull()
    h.act(() => h.countryCard(countryCard))
    expect(h.el('i18n-country-notice')?.textContent).toContain(
      'Switched off elsewhere just now.',
    )
    expect(h.window.mode.get()).toBe('view')
    h.submit()
    expect(h.calls).toEqual([])
    h.click('i18n-country-close')
    expect(h.window.opened.get()).toBe(false)
  })

  it('leaves viewer fields live and describes the disabled Save with the view-mode strip', () => {
    const h = setup(true)
    h.act(() => h.window.open('edit'))
    h.type('i18n-country-edit-currency-symbol', 'US$')
    expect(
      h.el<HTMLInputElement>('i18n-country-edit-currency-symbol')?.disabled,
    ).toBe(false)
    expect(h.button('i18n-country-save').disabled).toBe(true)
    expect(
      h.button('i18n-country-save').getAttribute('aria-describedby'),
    ).toContain(HILOS_VIEW_MODE_STRIP_TEXT_ID)
    expect(h.button('i18n-country-cancel').disabled).toBe(false)
    h.submit()
    h.button('i18n-country-save').click()
    expect(h.calls).toEqual([])
  })

  it('disposes the old session when a new one is bound in its place', () => {
    const h = setup()
    h.act(() => h.window.open('edit'))
    const next = createHilosI18nCountryCardWindow(
      createHilosI18nCountryCard(h.context),
      h.context,
    )
    h.act(() => h.fixture.componentRef.setInput('window', next))
    expect(h.window.opened.get()).toBe(false)
    expect(h.el('modal')).toBeNull()
    h.act(() => next.open('edit'))
    expect(h.el('i18n-country-window')).not.toBeNull()
    h.fixture.destroy()
    expect(next.opened.get()).toBe(false)
  })
})
