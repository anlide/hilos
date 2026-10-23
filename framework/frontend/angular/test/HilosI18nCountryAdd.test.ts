// The Angular country creation in flight, over the real creation factory, the
// production LoadingButton and the real action lifecycle: Add and the
// responsibility confirm look by the flow's submitAvailable, so their own send
// only locks them and the disabled look comes with the spinner at 300 ms, while
// the core's guarded canSubmit keeps a second press or Enter from sending; an
// unfit form or the admin view mode turns Add off natively from the first
// moment. The creation itself, from the table's main action, is covered with
// the countries page.
import { signal } from '@angular/core'
import {
  HilosPages,
  createHilosI18nBuiltInCountryCodes,
  createHilosI18nCountryAdd,
} from '@hilos/core'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { HilosI18nCountryAdd } from '../src/admin/i18n/lists/HilosI18nCountryAdd.js'
import { HILOS_ADMIN_VIEW_MODE } from '../src/hilosLookOnly.js'
import { i18nBench, mountOver } from './helpers/hilosI18n.js'

function setup(viewer = false) {
  const bench = i18nBench(HilosPages.I18N_COUNTRIES, [])
  bench.scope.data.set('builtInCountryCodes', ['us', 'gb'])
  const add = createHilosI18nCountryAdd(
    bench.context,
    createHilosI18nBuiltInCountryCodes(bench.context),
  )
  const view = mountOver(HilosI18nCountryAdd, bench, { add }, [
    {
      provide: HILOS_ADMIN_VIEW_MODE,
      useValue: signal(viewer).asReadonly(),
    },
  ])
  view.act(() => add.open())
  const button = (id: string) => view.el<HTMLButtonElement>(id)!
  const fill = (code = 'gg'): void => {
    view.type('i18n-country-add-code', code)
    view.type('i18n-country-add-currency-symbol', ' £ ')
    view.type('i18n-country-add-currency-code', 'gbp')
  }
  /** Enter in the form, before the view catches up. */
  const enter = (): void => {
    document
      .querySelector('#i18n-country-add-form')
      ?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
  }
  /** Let the fake clock run and the view take what its timers decided. */
  const wait = (ms: number): void => {
    vi.advanceTimersByTime(ms)
    view.detect()
  }
  /** Let the reply of an action land under the fake clock, then render it. */
  const settle = async (change: () => void): Promise<void> => {
    change()
    for (let tick = 0; tick < 4; tick += 1) await Promise.resolve()
    view.detect()
  }

  return { ...bench, ...view, add, button, fill, enter, wait, settle }
}

// What a creation button tells a sighted person and a screen reader now.
function buttonState(button: HTMLButtonElement) {
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

describe('HilosI18nCountryAdd in flight', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('locks Add at once for a known code and greys it only with its spinner at 300 ms', async () => {
    const h = setup()
    h.fill('us')
    expect(buttonState(h.button('i18n-country-add-submit'))).toEqual(RESTING)

    h.button('i18n-country-add-submit').click()
    expect(h.calls).toHaveLength(1)
    expect(h.calls[0]?.action).toBe('hilos_i18n_country_add')
    // Pressed again before the view has caught up: the flow's own door holds.
    h.button('i18n-country-add-submit').click()
    h.enter()
    expect(h.calls).toHaveLength(1)

    h.detect()
    expect(buttonState(h.button('i18n-country-add-submit'))).toEqual(LOCKED)
    expect(h.button('i18n-country-add-cancel').disabled).toBe(true)
    h.click('i18n-country-add-submit')
    h.enter()
    expect(h.calls).toHaveLength(1)

    h.wait(299)
    expect(buttonState(h.button('i18n-country-add-submit'))).toEqual(LOCKED)
    h.wait(1)
    expect(buttonState(h.button('i18n-country-add-submit'))).toEqual(SPINNING)
    h.click('i18n-country-add-submit')
    h.enter()
    expect(h.calls).toHaveLength(1)

    await h.settle(() => h.refuse(0, "Country 'us' already exists."))
    expect(h.el('i18n-country-add-error')?.textContent).toContain(
      'already exists',
    )
    expect(buttonState(h.button('i18n-country-add-submit'))).toEqual(RESTING)
    h.click('i18n-country-add-submit')
    expect(h.calls).toHaveLength(2)
  })

  it('locks the responsibility confirm at once and spins it at 300 ms', async () => {
    const h = setup()
    h.fill('gg')
    h.click('i18n-country-add-submit')
    expect(h.calls).toEqual([])
    expect(buttonState(h.button('i18n-country-confirm-submit'))).toEqual(
      RESTING,
    )

    h.click('i18n-country-confirm-submit')
    expect(h.calls).toHaveLength(1)
    expect(buttonState(h.button('i18n-country-confirm-submit'))).toEqual(LOCKED)
    expect(h.button('i18n-country-confirm-cancel').disabled).toBe(true)
    h.click('i18n-country-confirm-submit')
    expect(h.calls).toHaveLength(1)

    h.wait(299)
    expect(buttonState(h.button('i18n-country-confirm-submit'))).toEqual(LOCKED)
    h.wait(1)
    expect(buttonState(h.button('i18n-country-confirm-submit'))).toEqual(
      SPINNING,
    )
    h.click('i18n-country-confirm-submit')
    expect(h.calls).toHaveLength(1)

    await h.settle(() => h.succeed(0, 'Server added the country'))
    expect(h.el('modal')).toBeNull()
    expect(h.calls).toHaveLength(1)
  })

  it('turns Add off at once for a currency that does not pass', () => {
    const h = setup()
    h.fill()
    h.type('i18n-country-add-currency-code', 'ab')
    expect(buttonState(h.button('i18n-country-add-submit'))).toEqual(OFF)

    h.click('i18n-country-add-submit')
    h.enter()
    h.wait(300)
    expect(buttonState(h.button('i18n-country-add-submit'))).toEqual(OFF)
    expect(h.calls).toEqual([])
  })

  it('turns Add off at once in the admin view mode', () => {
    const h = setup(true)
    h.fill('us')
    expect(h.el<HTMLInputElement>('i18n-country-add-code')?.disabled).toBe(
      false,
    )
    expect(buttonState(h.button('i18n-country-add-submit'))).toEqual(OFF)

    h.click('i18n-country-add-submit')
    h.enter()
    h.wait(300)
    expect(buttonState(h.button('i18n-country-add-submit'))).toEqual(OFF)
    expect(h.calls).toEqual([])
  })
})
