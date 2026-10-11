import { mount, type VueWrapper } from '@vue/test-utils'
import {
  ActionError,
  createHilosI18nCountryAdd,
  createSignal,
  HILOS_I18N_COUNTRY_ADD_ACTION,
  type ActionHandle,
  type ActionLifecycle,
  type ActionResult,
  type HilosI18nCountryContext,
} from '@hilos/core'
import { afterEach, describe, expect, it } from 'vitest'
import { nextTick, ref } from 'vue'

import { hilosAdminViewModeKey } from '../../../hilosAdminViewMode.js'
import HilosI18nCountryAdd from './HilosI18nCountryAdd.vue'

interface Dispatch {
  action: string
  payload: unknown
  settle: (result: ActionResult) => void
  refuse: (failure: ActionError) => void
}

const mounted: VueWrapper[] = []

afterEach(() => {
  for (const view of mounted) view.unmount()
  mounted.length = 0
})

function harness(viewMode = false) {
  const dispatched: Dispatch[] = []
  const actions = {
    dispatch(action: string, payload: unknown): ActionHandle {
      let settle: Dispatch['settle'] = () => {}
      let refuse: Dispatch['refuse'] = () => {}
      const done = new Promise<ActionResult>((resolve, reject) => {
        settle = resolve
        refuse = reject
      })
      dispatched.push({ action, payload, settle, refuse })
      return {
        requestId: String(dispatched.length),
        loading: createSignal(false),
        done,
      }
    },
  } as ActionLifecycle
  const catalog = createSignal<readonly string[] | null>(['us', 'gb'])
  const add = createHilosI18nCountryAdd(
    { actions } as HilosI18nCountryContext,
    catalog,
  )
  const view = mount(HilosI18nCountryAdd, {
    props: { add },
    attachTo: document.body,
    global: { provide: { [hilosAdminViewModeKey as symbol]: ref(viewMode) } },
  })
  mounted.push(view)
  return { add, catalog, dispatched }
}

function control(id: string): HTMLButtonElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

function field(id: string): HTMLInputElement {
  const input = document.querySelector<HTMLInputElement>(`[data-id="${id}"]`)
  if (input === null) throw new Error(`Missing field ${id}`)
  return input
}

async function input(id: string, value: string): Promise<void> {
  const target = field(id)
  target.value = value
  target.dispatchEvent(new Event('input', { bubbles: true }))
  await nextTick()
}

async function open(add: ReturnType<typeof createHilosI18nCountryAdd>) {
  add.open()
  await nextTick()
}

async function fill(code: string): Promise<void> {
  await input('i18n-country-add-code', code)
  await input('i18n-country-add-currency-symbol', ' £ ')
  await input('i18n-country-add-currency-code', ' gbp ')
}

async function settled(): Promise<void> {
  await nextTick()
  await nextTick()
  await nextTick()
}

describe('HilosI18nCountryAdd', () => {
  it('shows the known and own plates only for a complete code, with accessible fields', async () => {
    const h = harness()
    await open(h.add)
    expect(
      document.querySelector('[data-id="i18n-country-add-plate"]'),
    ).toBeNull()
    const locale = document.querySelector<HTMLSelectElement>(
      '[data-id="i18n-country-add-default-locale"]',
    )
    expect(locale?.disabled).toBe(true)
    expect(locale?.getAttribute('aria-describedby')).toBe(
      'i18n-country-add-default-locale-hint',
    )
    expect(
      document.querySelector('[data-id="i18n-country-add-default-locale-hint"]')
        ?.textContent,
    ).toContain('A new country has no locales yet')
    for (const id of [
      'i18n-country-add-code',
      'i18n-country-add-currency-symbol',
      'i18n-country-add-currency-code',
    ]) {
      const target = field(id)
      expect(document.querySelector(`label[for="${target.id}"]`)).not.toBeNull()
      expect(target.getAttribute('aria-describedby')).toBeTruthy()
    }
    await input('i18n-country-add-code', ' U ')
    expect(
      document.querySelector('[data-id="i18n-country-add-plate"]'),
    ).toBeNull()
    await input('i18n-country-add-code', ' US ')
    expect(field('i18n-country-add-code').value).toBe('us')
    expect(
      document
        .querySelector('[data-id="i18n-country-add-plate"]')
        ?.getAttribute('data-kind'),
    ).toBe('known')
    expect(document.body.textContent).toContain(
      'The system knows this country.',
    )
    await input('i18n-country-add-code', ' GG ')
    expect(
      document
        .querySelector('[data-id="i18n-country-add-plate"]')
        ?.getAttribute('data-kind'),
    ).toBe('own')
    expect(document.body.textContent).toContain(
      'This country is not in the built-in catalog.',
    )
    expect(control('i18n-country-add-submit')?.disabled).toBe(true)
  })

  it('confirms an own code without sending, then closes on success', async () => {
    const h = harness()
    await open(h.add)
    await fill(' GG ')
    expect(field('i18n-country-add-currency-code').value).toBe('GBP')
    control('i18n-country-add-submit')?.click()
    await settled()
    expect(h.dispatched).toHaveLength(0)
    expect(control('i18n-country-confirm-submit')).not.toBeNull()
    expect(
      document.querySelector('[data-id="i18n-country-confirm-code"]')
        ?.textContent,
    ).toContain('gg')
    expect(document.body.textContent).toContain(
      'The code gg is not in the built-in catalog of 2 countries.',
    )
    expect(document.body.textContent).toContain('currency entered by you')
    control('i18n-country-confirm-submit')?.click()
    expect(h.dispatched).toHaveLength(1)
    expect(h.dispatched[0]).toMatchObject({
      action: HILOS_I18N_COUNTRY_ADD_ACTION,
      payload: { countryCode: 'gg', currencySymbol: '£', currencyCode: 'GBP' },
    })
    await nextTick()
    expect(control('i18n-country-confirm-cancel')?.disabled).toBe(true)
    h.dispatched[0]?.settle({
      message: 'Custom country gg added and switched off for now.',
    })
    await settled()
    expect(control('i18n-country-confirm-submit')).toBeNull()
  })

  it('sends a known code immediately and leaves its refusal in the form', async () => {
    const h = harness()
    await open(h.add)
    await fill('US')
    control('i18n-country-add-submit')?.click()
    expect(h.dispatched).toHaveLength(1)
    expect(h.dispatched[0]?.payload).toEqual({
      countryCode: 'us',
      currencySymbol: '£',
      currencyCode: 'GBP',
    })
    h.dispatched[0]?.refuse(
      new ActionError(
        HILOS_I18N_COUNTRY_ADD_ACTION,
        'fail',
        "Country 'us' already exists.",
      ),
    )
    await settled()
    expect(
      document.querySelector('[data-id="i18n-country-add-error"]')?.textContent,
    ).toContain("Country 'us' already exists.")
    expect(control('i18n-country-add-submit')).not.toBeNull()
  })

  it('holds confirmation during a send and keeps a refusal there', async () => {
    const h = harness()
    await open(h.add)
    await fill('gg')
    control('i18n-country-add-submit')?.click()
    await settled()
    control('i18n-country-confirm-submit')?.click()
    await nextTick()
    control('i18n-country-confirm-cancel')?.click()
    expect(control('i18n-country-confirm-submit')).not.toBeNull()
    h.dispatched[0]?.refuse(
      new ActionError(HILOS_I18N_COUNTRY_ADD_ACTION, 'fail', 'Server refused'),
    )
    await settled()
    expect(
      document.querySelector('[data-id="i18n-country-confirm-error"]')
        ?.textContent,
    ).toContain('Server refused')
    expect(control('i18n-country-confirm-submit')).not.toBeNull()
  })

  it('cancels confirmation and starts the next opening with empty fields', async () => {
    const h = harness()
    await open(h.add)
    await fill('gg')
    control('i18n-country-add-submit')?.click()
    await settled()
    control('i18n-country-confirm-cancel')?.click()
    await settled()
    expect(control('i18n-country-confirm-submit')).toBeNull()
    await open(h.add)
    expect(field('i18n-country-add-code').value).toBe('')
    expect(field('i18n-country-add-currency-symbol').value).toBe('')
    expect(field('i18n-country-add-currency-code').value).toBe('')
  })

  it('asks before discarding a form with entered values', async () => {
    const h = harness()
    await open(h.add)
    await input('i18n-country-add-code', 'gg')
    control('i18n-country-add-cancel')?.click()
    await settled()
    expect(document.querySelector('[data-id="modal-confirm"]')).not.toBeNull()
    control('modal-confirm-cancel')?.click()
    await settled()
    expect(field('i18n-country-add-code').value).toBe('gg')
    control('i18n-country-add-cancel')?.click()
    await settled()
    control('modal-confirm-discard')?.click()
    await settled()
    expect(
      document.querySelector('[data-id="i18n-country-add-code"]'),
    ).toBeNull()
  })

  it('lets a view-mode reader fill the form but disables Add', async () => {
    const h = harness(true)
    await open(h.add)
    await fill('gg')
    expect(field('i18n-country-add-code').disabled).toBe(false)
    expect(control('i18n-country-add-submit')?.disabled).toBe(true)
    document
      .getElementById('i18n-country-add-form')
      ?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
    await settled()
    expect(control('i18n-country-confirm-submit')).toBeNull()
    expect(h.dispatched).toHaveLength(0)
  })
})
