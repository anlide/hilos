import { mount, type VueWrapper } from '@vue/test-utils'
import {
  ActionError,
  createSignal,
  HILOS_I18N_COUNTRY_SWITCH_ON_ACTION,
  HILOS_I18N_COUNTRY_SWITCH_ON_COPY,
  type ActionHandle,
  type ActionLifecycle,
  type ActionResult,
  type HilosI18nCountryCard,
  type HilosI18nCountryContext,
} from '@hilos/core'
import { afterEach, describe, expect, it } from 'vitest'
import { nextTick, ref } from 'vue'

import { hilosAdminViewModeKey } from '../../../hilosAdminViewMode.js'
import HilosI18nCountrySwitchOn from './HilosI18nCountrySwitchOn.vue'

const card: HilosI18nCountryCard = {
  countryId: 106,
  code: 'ca',
  currencySymbol: '$',
  currencyCode: 'CAD',
  enabled: false,
  summary: {
    name: 'Canada',
    isOwn: false,
    defaultLocaleId: null,
    defaultLocaleCode: null,
    defaultLocale: 'missing',
    localeCount: 0,
    nameCount: 0,
    locales: [],
    catalogDefaultLocaleCode: 'en-CA',
    canDelete: true,
    deleteReason: null,
  },
}

interface Dispatch {
  action: string
  payload: unknown
  settle: (result: ActionResult) => void
  refuse: (failure: ActionError) => void
}

const mounted: VueWrapper[] = []

afterEach(() => {
  for (const view of mounted) {
    view.unmount()
  }
  mounted.length = 0
})

function harness(
  initial: HilosI18nCountryCard | null = card,
  viewMode = false,
): { view: VueWrapper; dispatched: Dispatch[] } {
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
  const context = { actions } as HilosI18nCountryContext
  const view = mount(HilosI18nCountrySwitchOn, {
    props: { context, card: initial },
    attachTo: document.body,
    global: { provide: { [hilosAdminViewModeKey as symbol]: ref(viewMode) } },
  })
  mounted.push(view)
  return { view, dispatched }
}

function control(id: string): HTMLButtonElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

async function openWindow(): Promise<void> {
  control('country-card-switch-on')?.click()
  await nextTick()
}

async function settled(): Promise<void> {
  await nextTick()
  await nextTick()
  await nextTick()
}

describe('HilosI18nCountrySwitchOn', () => {
  it('shows the opening control for a disabled card and hides it for an enabled card', async () => {
    const disabled = harness()
    expect(control('country-card-switch-on')?.disabled).toBe(false)
    disabled.view.unmount()
    mounted.length = 0

    harness({ ...card, enabled: true })
    expect(control('country-card-switch-on')).toBeNull()
  })

  it('opens with localized name or uppercase code, shows body and note across all three states, and live-updates note without racing', async () => {
    const h = harness()
    await openWindow()
    expect(document.body.textContent).toContain('Switch on country · Canada')
    expect(document.body.textContent).toContain(
      HILOS_I18N_COUNTRY_SWITCH_ON_COPY.body,
    )
    const noteMissing = document.querySelector(
      '[data-id="country-card-switch-on-note"]',
    )
    expect(noteMissing?.classList.contains('alert-secondary')).toBe(true)
    expect(noteMissing?.textContent).toContain(
      HILOS_I18N_COUNTRY_SWITCH_ON_COPY.note.missing,
    )

    // Live update to 'off'
    await h.view.setProps({
      card: {
        ...card,
        summary: {
          ...card.summary,
          defaultLocale: 'off',
          defaultLocaleId: 11106,
          defaultLocaleCode: 'en-CA',
        },
      },
    })
    const noteOff = document.querySelector(
      '[data-id="country-card-switch-on-note"]',
    )
    expect(noteOff?.classList.contains('alert-warning')).toBe(true)
    expect(noteOff?.textContent).toContain(
      HILOS_I18N_COUNTRY_SWITCH_ON_COPY.note.off('en-CA'),
    )
    expect(control('country-card-switch-on-confirm')?.disabled).toBe(false)

    // Live update to 'on'
    await h.view.setProps({
      card: {
        ...card,
        summary: {
          ...card.summary,
          defaultLocale: 'on',
          defaultLocaleId: 11106,
          defaultLocaleCode: 'en-CA',
        },
      },
    })
    expect(
      document.querySelector('[data-id="country-card-switch-on-note"]'),
    ).toBeNull()
    expect(control('country-card-switch-on-confirm')?.disabled).toBe(false)

    h.view.unmount()
    mounted.length = 0

    // Without localized name: uses uppercase code
    harness({
      ...card,
      countryId: 141,
      code: 'qx',
      summary: { ...card.summary, name: null },
    })
    await openWindow()
    expect(document.body.textContent).toContain('Switch on country · QX')
  })

  it('dispatches the agreed action and closes on success', async () => {
    const h = harness()
    await openWindow()
    expect(
      document.querySelector('[data-id="country-card-switch-on-error"]'),
    ).not.toBeNull()

    control('country-card-switch-on-confirm')?.click()
    expect(h.dispatched).toHaveLength(1)
    expect(h.dispatched[0]).toMatchObject({
      action: HILOS_I18N_COUNTRY_SWITCH_ON_ACTION,
      payload: { countryId: 106 },
    })
    await nextTick()
    expect(control('country-card-switch-on-cancel')?.disabled).toBe(true)

    h.dispatched[0]?.settle({ message: 'Country switched on.' })
    await settled()
    expect(control('country-card-switch-on-confirm')).toBeNull()
  })

  it('keeps the window open with a refusal and allows another attempt', async () => {
    const h = harness()
    await openWindow()
    control('country-card-switch-on-confirm')?.click()
    h.dispatched[0]?.refuse(
      new ActionError(
        HILOS_I18N_COUNTRY_SWITCH_ON_ACTION,
        'fail',
        'Server refused',
      ),
    )
    await settled()
    expect(
      document.querySelector('[data-id="country-card-switch-on-error"]')
        ?.textContent,
    ).toContain('Server refused')
    expect(control('country-card-switch-on-confirm')?.disabled).toBe(false)
    control('country-card-switch-on-confirm')?.click()
    expect(h.dispatched).toHaveLength(2)
  })

  it('marks another window’s switch-on but ignores the echo of its own pending request', async () => {
    const elsewhere = harness()
    await openWindow()
    await elsewhere.view.setProps({ card: { ...card, enabled: true } })
    expect(
      document.querySelector('[data-id="country-card-switch-on-notice"]')
        ?.textContent,
    ).toContain(HILOS_I18N_COUNTRY_SWITCH_ON_COPY.elsewhere)
    expect(control('country-card-switch-on-confirm')?.disabled).toBe(true)
    elsewhere.view.unmount()
    mounted.length = 0

    const own = harness()
    await openWindow()
    control('country-card-switch-on-confirm')?.click()
    await own.view.setProps({ card: { ...card, enabled: true } })
    expect(
      document.querySelector('[data-id="country-card-switch-on-notice"]'),
    ).toBeNull()
    own.dispatched[0]?.settle({})
    await settled()
    expect(control('country-card-switch-on-confirm')).toBeNull()
  })

  it('lets a view-mode reader open and read the window but disables confirmation', async () => {
    const h = harness(card, true)
    expect(control('country-card-switch-on')?.disabled).toBe(false)
    await openWindow()
    expect(document.body.textContent).toContain('Switch on country · Canada')
    expect(control('country-card-switch-on-confirm')?.disabled).toBe(true)
    expect(h.dispatched).toHaveLength(0)
  })

  it('closes when the card disappears', async () => {
    const h = harness()
    await openWindow()
    await h.view.setProps({ card: null })
    expect(control('country-card-switch-on-confirm')).toBeNull()
  })
})
