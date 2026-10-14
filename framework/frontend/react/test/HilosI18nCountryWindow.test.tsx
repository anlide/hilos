import { act, fireEvent } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { createHilosI18nCountryRowWindow } from '@hilos/core'
import { HilosI18nCountryWindow } from '../src/admin/i18n/details/HilosI18nCountryWindow.js'
import { HilosAdminViewModeContext } from '../src/hilosLookOnly.js'
import {
  harness,
  country,
  el,
  click,
  change,
  settle,
} from './hilosI18nHarness.js'
import { focusSource } from './hilosI18nDetailsHarness.js'

function setup(row = country, viewer = false) {
  const h = harness(),
    focus = focusSource(row)
  const window = createHilosI18nCountryRowWindow(focus.controller, h.context)
  const view = h.mount(
    <HilosAdminViewModeContext.Provider value={viewer}>
      <button
        data-id="open-country"
        onClick={() => window.open('edit', row.code)}
      >
        Open
      </button>
      <HilosI18nCountryWindow window={window} />
    </HilosAdminViewModeContext.Provider>,
  )
  el('open-country').focus()
  act(() => window.open(row.enabled ? 'view' : 'edit', row.code))
  return { ...h, ...focus, window, view }
}
function enter() {
  fireEvent.submit(
    el<HTMLInputElement>('i18n-country-edit-currency-symbol').form!,
  )
}

describe('React country edit/view window', () => {
  it('fixes code and uses valid normalized input with one row send until ack past its echo', async () => {
    const h = setup()
    expect(document.activeElement).toBe(el('i18n-country-edit-currency-symbol'))
    expect(el<HTMLInputElement>('i18n-country-edit-code').disabled).toBe(true)
    expect(el<HTMLInputElement>('i18n-country-edit-code').maxLength).toBe(2)
    expect(
      el<HTMLInputElement>('i18n-country-edit-currency-symbol').maxLength,
    ).toBe(8)
    expect(
      el<HTMLInputElement>('i18n-country-edit-currency-code').maxLength,
    ).toBe(3)
    expect(el<HTMLButtonElement>('i18n-country-save').disabled).toBe(true)
    enter()
    expect(h.calls).toEqual([])
    act(() => h.window.open('edit', country.code))
    change('i18n-country-edit-currency-code', 'ab')
    enter()
    expect(h.calls).toEqual([])
    change('i18n-country-edit-currency-code', ' usd ')
    change('i18n-country-edit-currency-symbol', ' $ ')
    enter()
    enter()
    click('i18n-country-save')
    expect(h.calls).toHaveLength(1)
    expect(h.calls[0]).toMatchObject({
      action: 'hilos_i18n_country_row_update',
      payload: {
        countryCode: 'gg',
        currencySymbol: '$',
        currencyCode: 'USD',
        defaultLocaleCode: null,
      },
    })
    act(() =>
      h.focused.set({ ...country, currencySymbol: '$', currencyCode: 'USD' }),
    )
    click('modal-close')
    fireEvent.keyDown(el('modal'), { key: 'Escape' })
    click('modal')
    expect(el('modal-confirm-discard')).toBeNull()
    expect(h.window.opened.get()).toBe(true)
    expect(el<HTMLButtonElement>('i18n-country-cancel').disabled).toBe(true)
    await settle(() => h.succeed())
    expect(el('modal')).toBeNull()
    expect(h.releaseFocus).toHaveBeenCalled()
  })
  it('suggests only an existing catalog locale, saves it explicitly, and does not ask to discard the suggestion', () => {
    const h = setup({
      ...country,
      locales: [{ code: 'en-GG', enabled: false }],
      catalogDefaultLocaleCode: 'en-GG',
    })
    expect(
      el<HTMLSelectElement>('i18n-country-edit-default-locale').value,
    ).toBe('en-GG')
    expect(el('i18n-country-edit-default-locale-hint').textContent).toContain(
      'save to keep it',
    )
    expect(el<HTMLButtonElement>('i18n-country-save').disabled).toBe(false)
    expect(h.calls).toEqual([])
    click('i18n-country-cancel')
    expect(el('modal')).toBeNull()
    act(() => h.window.open('edit', 'gg'))
    click('i18n-country-save')
    expect(h.calls[0]?.payload).toMatchObject({ defaultLocaleCode: 'en-GG' })
  })
  it('does not suggest a missing catalog locale and retains a vanished selected option until a person clears it', () => {
    const h = setup({
      ...country,
      defaultLocaleCode: 'en-GG',
      catalogDefaultLocaleCode: 'fr-GG',
    })
    expect(
      el<HTMLSelectElement>('i18n-country-edit-default-locale').value,
    ).toBe('en-GG')
    expect(el('i18n-country-edit-default-locale').textContent).toContain(
      'en-GG — no longer exists',
    )
    expect(el('i18n-country-edit-default-locale').textContent).not.toContain(
      'fr-GG',
    )
    act(() =>
      h.focused.set({
        ...country,
        defaultLocaleCode: 'en-GG',
        locales: [{ code: 'fr-GG', enabled: true }],
      }),
    )
    expect(
      el<HTMLSelectElement>('i18n-country-edit-default-locale').value,
    ).toBe('en-GG')
    change('i18n-country-edit-default-locale', '')
    click('i18n-country-save')
    expect(h.calls[0]?.payload).toMatchObject({ defaultLocaleCode: null })
  })
  it('merges per field and offers Keep mine and Take theirs with a fixed opening title', () => {
    const h = setup()
    act(() => h.focused.set({ ...country, currencyCode: 'USD' }))
    expect(el<HTMLInputElement>('i18n-country-edit-currency-code').value).toBe(
      'USD',
    )
    expect(el('i18n-country-notice').textContent).toContain('Updated just now')
    change('i18n-country-edit-currency-symbol', '$')
    act(() =>
      h.focused.set({
        ...country,
        currencyCode: 'USD',
        currencySymbol: '€',
        name: 'Changed',
      }),
    )
    expect(el('i18n-country-title').textContent).toContain('Guernsey')
    expect(el('i18n-country-notice').textContent).toContain(
      'Currency symbol changed elsewhere',
    )
    enter()
    expect(h.calls).toEqual([])
    expect(el('conflict-merge')).toBeNull()
    click('conflict-accept-mine')
    expect(
      el<HTMLInputElement>('i18n-country-edit-currency-symbol').value,
    ).toBe('$')
    act(() =>
      h.focused.set({ ...country, currencyCode: 'USD', currencySymbol: '¥' }),
    )
    click('conflict-accept-theirs')
    expect(
      el<HTMLInputElement>('i18n-country-edit-currency-symbol').value,
    ).toBe('¥')
    expect(el<HTMLButtonElement>('i18n-country-save').disabled).toBe(true)
  })
  it.each([false, true])(
    'keeps the %s catalog/own plate, refusal, and deleted draft',
    async (isOwn) => {
      const h = setup({ ...country, isOwn })
      expect(el('i18n-country-plate').textContent).toContain(
        isOwn ? 'never touches it' : 'currency; the default locale stays yours',
      )
      change('i18n-country-edit-currency-symbol', '$')
      click('i18n-country-save')
      await settle(() => h.refuse())
      expect(el('i18n-country-error').textContent).toContain('Server refused')
      click('hilos-action-error-details')
      expect(document.body.textContent).toContain('The dependency appeared')
      click('modal-close')
      act(() => h.focused.set({ ...country, enabled: true }))
      expect(el('i18n-country-notice').textContent).toContain(
        'Switched on elsewhere',
      )
      expect(
        el<HTMLInputElement>('i18n-country-edit-currency-symbol').value,
      ).toBe('$')
      enter()
      expect(h.calls).toHaveLength(1)
      act(() => h.focused.set(undefined))
      expect(el('i18n-country-save').textContent).toBe('Deleted')
      expect(
        el<HTMLInputElement>('i18n-country-edit-currency-symbol').disabled,
      ).toBe(false)
      enter()
      expect(h.calls).toHaveLength(1)
    },
  )
  it('keeps all four fields disabled in a live view after switch-off', () => {
    const h = setup({ ...country, enabled: true })
    for (const field of [
      'code',
      'currency-symbol',
      'currency-code',
      'default-locale',
    ])
      expect(el<HTMLInputElement>(`i18n-country-edit-${field}`).disabled).toBe(
        true,
      )
    expect(el('i18n-country-save')).toBeNull()
    act(() => h.focused.set({ ...country, currencyCode: 'USD' }))
    expect(el<HTMLInputElement>('i18n-country-edit-currency-code').value).toBe(
      'USD',
    )
    expect(el('i18n-country-notice').textContent).toContain(
      'Switched off elsewhere',
    )
    click('i18n-country-close')
    expect(el('modal')).toBeNull()
  })
  it('asks to discard personal input and returns focus, then releases on unmount', () => {
    const h = setup()
    change('i18n-country-edit-currency-symbol', '$')
    fireEvent.keyDown(el('modal'), { key: 'Escape' })
    click('modal-confirm-discard')
    expect(document.activeElement).toBe(el('open-country'))
    act(() => h.window.open('edit', 'gg'))
    h.view.unmount()
    expect(h.focused.get()).toBeUndefined()
  })
  it('keeps viewer fields readable with Save and Enter locked', () => {
    const h = setup(country, true)
    change('i18n-country-edit-currency-symbol', '$')
    enter()
    click('i18n-country-save')
    expect(h.calls).toEqual([])
    expect(
      el<HTMLInputElement>('i18n-country-edit-currency-symbol').disabled,
    ).toBe(false)
    expect(
      el<HTMLButtonElement>('i18n-country-save').getAttribute(
        'aria-describedby',
      ),
    ).toBeTruthy()
  })
})
