import { act, fireEvent } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  createHilosI18nBuiltInCountryCodes,
  createHilosI18nCountryAdd,
  HilosPages,
  hilosToasts,
} from '@hilos/core'
import { HilosI18nCountryAdd } from '../src/admin/i18n/lists/HilosI18nCountryAdd.js'
import { HilosAdminViewModeContext } from '../src/hilosLookOnly.js'
import { harness, el, click, change, settle } from './hilosI18nHarness.js'

function setup(viewer = false) {
  const h = harness(HilosPages.I18N_COUNTRIES, [])
  h.scope.data.set('builtInCountryCodes', ['us', 'gb'])
  const add = createHilosI18nCountryAdd(
    h.context,
    createHilosI18nBuiltInCountryCodes(h.context),
  )
  const view = h.mount(
    <HilosAdminViewModeContext.Provider value={viewer}>
      <HilosI18nCountryAdd add={add} />
    </HilosAdminViewModeContext.Provider>,
  )
  act(() => add.open())
  return { ...h, add, view }
}
function fill(code = 'gg') {
  change('i18n-country-add-code', code)
  change('i18n-country-add-currency-symbol', ' £ ')
  change('i18n-country-add-currency-code', 'gbp')
}
function enter() {
  fireEvent.submit(el<HTMLInputElement>('i18n-country-add-code').form!)
}

// What a creation button tells a sighted person and a screen reader now.
function buttonState(id: string) {
  const button = el<HTMLButtonElement>(id)

  return {
    nativeDisabled: button.disabled,
    disabledLook: button.classList.contains('disabled'),
    spinner:
      button.querySelector('[data-id="loading-button-spinner"]') !== null,
    ariaBusy: button.getAttribute('aria-busy'),
    ariaDisabled: button.getAttribute('aria-disabled'),
  }
}

const RESTING = {
  nativeDisabled: false,
  disabledLook: false,
  spinner: false,
  ariaBusy: null,
  ariaDisabled: null,
}
const OFF = { ...RESTING, nativeDisabled: true, ariaDisabled: 'true' }
const LOCKED = { ...RESTING, ariaBusy: 'true', ariaDisabled: 'true' }
const SPINNING = { ...LOCKED, disabledLook: true, spinner: true }

// Advance the fake clock and let the view render what the timers decided.
function wait(ms: number): void {
  act(() => {
    vi.advanceTimersByTime(ms)
  })
}

describe('React country creation', () => {
  it('focuses code, labels all four fields and uses known/own plates without a request', () => {
    const h = setup()
    expect(document.activeElement).toBe(el('i18n-country-add-code'))
    for (const key of [
      'code',
      'currency-symbol',
      'currency-code',
      'default-locale',
    ]) {
      const field = el(`i18n-country-add-${key}`)
      expect(document.querySelector(`label[for="${field.id}"]`)).not.toBeNull()
      expect(
        document.getElementById(field.getAttribute('aria-describedby')!),
      ).not.toBeNull()
    }
    expect(
      el<HTMLSelectElement>('i18n-country-add-default-locale').disabled,
    ).toBe(true)
    expect(el('i18n-country-add-default-locale-hint').textContent).toContain(
      'no locales yet',
    )
    enter()
    expect(h.calls).toEqual([])
    change('i18n-country-add-code', 'g')
    expect(el('i18n-country-add-plate')).toBeNull()
    change('i18n-country-add-code', 'US')
    expect(el('i18n-country-add-plate').getAttribute('data-kind')).toBe('known')
    expect(el<HTMLInputElement>('i18n-country-add-currency-code').value).toBe(
      '',
    )
    fill()
    expect(el('i18n-country-add-plate').getAttribute('data-kind')).toBe('own')
  })
  it('confirms responsibility for a custom code, sends once, and waits for the server success words', async () => {
    const h = setup()
    fill('GG')
    enter()
    expect(h.calls).toEqual([])
    expect(el('i18n-country-confirm').textContent).toContain(
      'currency entered by you',
    )
    expect(document.body.textContent).toContain('catalog of 2 countries')
    expect(document.activeElement?.getAttribute('role')).toBe('dialog')
    click('i18n-country-confirm-submit')
    click('i18n-country-confirm-submit')
    expect(h.calls).toHaveLength(1)
    expect(h.calls[0]).toMatchObject({
      action: 'hilos_i18n_country_add',
      payload: { countryCode: 'gg', currencySymbol: '£', currencyCode: 'GBP' },
    })
    click('modal-close')
    fireEvent.keyDown(el('modal'), { key: 'Escape' })
    click('modal')
    expect(el<HTMLButtonElement>('i18n-country-confirm-cancel').disabled).toBe(
      true,
    )
    expect(h.add.step.get()).toBe('confirm')
    await settle(() => h.succeed(0, 'Server added the country'))
    expect(el('modal')).toBeNull()
    expect(
      hilosToasts.toasts
        .get()
        .some((row) => row.message === 'Server added the country'),
    ).toBe(true)
  })
  it('sends a known duplicate through the existing action and keeps refusal in its form', async () => {
    const h = setup()
    fill('us')
    enter()
    enter()
    expect(h.calls).toHaveLength(1)
    expect(h.add.step.get()).toBe('form')
    expect(h.calls[0]?.payload).toMatchObject({ countryCode: 'us' })
    await settle(() => h.refuse(0, "Country 'us' already exists."))
    expect(el('i18n-country-add-error').textContent).toContain('already exists')
    expect(el<HTMLInputElement>('i18n-country-add-currency-symbol').value).toBe(
      ' £ ',
    )
    click('hilos-action-error-details')
    expect(document.body.textContent).toContain('The dependency appeared')
  })
  it('keeps confirmation refusal and starts the next opening with empty fields', async () => {
    const h = setup()
    fill()
    click('i18n-country-add-submit')
    click('i18n-country-confirm-submit')
    const room = el('i18n-country-confirm-error')
    await settle(() => h.refuse())
    expect(el('i18n-country-confirm-error')).toBe(room)
    expect(room.textContent).toContain('Server refused')
    click('i18n-country-confirm-cancel')
    act(() => h.add.open())
    for (const field of ['code', 'currency-symbol', 'currency-code'])
      expect(el<HTMLInputElement>(`i18n-country-add-${field}`).value).toBe('')
  })
  it('requires a valid currency and asks before discarding personal input', () => {
    const h = setup()
    fill()
    change('i18n-country-add-currency-code', 'ab')
    enter()
    expect(h.calls).toEqual([])
    expect(el<HTMLButtonElement>('i18n-country-add-submit').disabled).toBe(true)
    fireEvent.keyDown(el('modal'), { key: 'Escape' })
    expect(el('modal-confirm-discard')).not.toBeNull()
    click('modal-confirm-discard')
    expect(el('modal')).toBeNull()
  })
  it('lets a viewer read and fill while Save and Enter cannot write', () => {
    const h = setup(true)
    fill('us')
    enter()
    click('i18n-country-add-submit')
    expect(h.calls).toEqual([])
    expect(el<HTMLInputElement>('i18n-country-add-code').disabled).toBe(false)
    expect(el<HTMLButtonElement>('i18n-country-add-submit').disabled).toBe(true)
  })
})

// The real creation factory, LoadingButton and ActionLifecycle together: the
// button looks by submitAvailable, so its own send only locks it and the look
// comes with the spinner at 300 ms, while the guarded canSubmit keeps a second
// press or Enter from sending; an unfit form or the admin view mode turns it
// off natively from the first moment.
describe('React country creation in flight', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('locks Add at once for a known code and greys it only with its spinner at 300 ms', async () => {
    const h = setup()
    fill('us')
    expect(buttonState('i18n-country-add-submit')).toEqual(RESTING)

    click('i18n-country-add-submit')
    expect(h.calls).toHaveLength(1)
    expect(buttonState('i18n-country-add-submit')).toEqual(LOCKED)
    expect(el<HTMLButtonElement>('i18n-country-add-cancel').disabled).toBe(true)
    click('i18n-country-add-submit')
    enter()
    expect(h.calls).toHaveLength(1)

    wait(299)
    expect(buttonState('i18n-country-add-submit')).toEqual(LOCKED)
    wait(1)
    expect(buttonState('i18n-country-add-submit')).toEqual(SPINNING)
    click('i18n-country-add-submit')
    enter()
    expect(h.calls).toHaveLength(1)

    await settle(() => h.refuse(0, "Country 'us' already exists."))
    expect(buttonState('i18n-country-add-submit')).toEqual(RESTING)
    click('i18n-country-add-submit')
    expect(h.calls).toHaveLength(2)
  })
  it('locks the responsibility confirm at once and spins it at 300 ms', async () => {
    const h = setup()
    fill('gg')
    enter()
    expect(buttonState('i18n-country-confirm-submit')).toEqual(RESTING)

    click('i18n-country-confirm-submit')
    expect(h.calls).toHaveLength(1)
    expect(buttonState('i18n-country-confirm-submit')).toEqual(LOCKED)
    expect(el<HTMLButtonElement>('i18n-country-confirm-cancel').disabled).toBe(
      true,
    )
    click('i18n-country-confirm-submit')
    expect(h.calls).toHaveLength(1)

    wait(299)
    expect(buttonState('i18n-country-confirm-submit')).toEqual(LOCKED)
    wait(1)
    expect(buttonState('i18n-country-confirm-submit')).toEqual(SPINNING)

    await settle(() => h.succeed(0, 'Server added the country'))
    expect(el('modal')).toBeNull()
    expect(h.calls).toHaveLength(1)
  })
  it('turns Add off at once for a currency that does not pass', () => {
    const h = setup()
    fill()
    change('i18n-country-add-currency-code', 'ab')
    expect(buttonState('i18n-country-add-submit')).toEqual(OFF)

    click('i18n-country-add-submit')
    enter()
    wait(300)
    expect(buttonState('i18n-country-add-submit')).toEqual(OFF)
    expect(h.calls).toEqual([])
  })
  it('turns Add off at once in the admin view mode', () => {
    const h = setup(true)
    fill('us')
    expect(buttonState('i18n-country-add-submit')).toEqual(OFF)

    click('i18n-country-add-submit')
    enter()
    wait(300)
    expect(buttonState('i18n-country-add-submit')).toEqual(OFF)
    expect(h.calls).toEqual([])
  })
})
