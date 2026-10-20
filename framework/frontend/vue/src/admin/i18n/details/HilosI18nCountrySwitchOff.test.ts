import { mount, type VueWrapper } from '@vue/test-utils'
import {
  ActionError,
  createSignal,
  type ActionHandle,
  type ActionLifecycle,
  type ActionResult,
} from '@hilos/core/base'
import {
  HILOS_I18N_COUNTRY_SWITCH_OFF_ACTION,
  HILOS_I18N_COUNTRY_SWITCH_OFF_COPY,
  type HilosI18nCountryCard,
  type HilosI18nCountryContext,
} from '@hilos/core/admin/i18n'
import { afterEach, describe, expect, it } from 'vitest'
import { nextTick, ref } from 'vue'

import { hilosAdminViewModeKey } from '../../../hilosAdminViewMode.js'
import HilosI18nCountrySwitchOff from './HilosI18nCountrySwitchOff.vue'

const card: HilosI18nCountryCard = {
  countryId: 106,
  code: 'ca',
  currencySymbol: '$',
  currencyCode: 'CAD',
  enabled: true,
  summary: {
    name: 'Canada',
    isOwn: false,
    defaultLocaleId: null,
    defaultLocaleCode: null,
    defaultLocale: 'missing',
    localeCount: 1,
    nameCount: 50,
    locales: [{ localeId: 11106, code: 'en-CA', enabled: false }],
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
  const view = mount(HilosI18nCountrySwitchOff, {
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
  control('country-card-switch-off')?.click()
  await nextTick()
}

async function settled(): Promise<void> {
  await nextTick()
  await nextTick()
  await nextTick()
}

describe('HilosI18nCountrySwitchOff', () => {
  it('shows the opening control for an enabled card and hides it for a disabled card', async () => {
    const enabled = harness()
    expect(control('country-card-switch-off')?.disabled).toBe(false)
    enabled.view.unmount()
    mounted.length = 0

    harness({ ...card, enabled: false })
    expect(control('country-card-switch-off')).toBeNull()
  })

  it('opens with localized name or uppercase code, shows body, counts, and note, and live-updates counts without racing', async () => {
    const h = harness()
    await openWindow()
    expect(document.body.textContent).toContain('Switch off country · Canada')
    expect(document.body.textContent).toContain(
      HILOS_I18N_COUNTRY_SWITCH_OFF_COPY.body,
    )
    expect(document.body.textContent).toContain(
      HILOS_I18N_COUNTRY_SWITCH_OFF_COPY.note,
    )
    expect(
      document.querySelector('[data-id="country-card-switch-off-locale-count"]')
        ?.textContent,
    ).toBe('1')
    expect(
      document.querySelector('[data-id="country-card-switch-off-name-count"]')
        ?.textContent,
    ).toBe('50')

    // Live update counts while window is open
    await h.view.setProps({
      card: {
        ...card,
        summary: {
          ...card.summary,
          localeCount: 3,
          nameCount: 52,
        },
      },
    })
    expect(
      document.querySelector('[data-id="country-card-switch-off-locale-count"]')
        ?.textContent,
    ).toBe('3')
    expect(
      document.querySelector('[data-id="country-card-switch-off-name-count"]')
        ?.textContent,
    ).toBe('52')
    expect(
      document.querySelector('[data-id="country-card-switch-off-notice"]'),
    ).toBeNull()
    expect(control('country-card-switch-off-confirm')?.disabled).toBe(false)

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
    expect(document.body.textContent).toContain('Switch off country · QX')
  })

  it('dispatches the agreed action and closes on success', async () => {
    const h = harness()
    await openWindow()
    expect(
      document.querySelector('[data-id="country-card-switch-off-error"]'),
    ).not.toBeNull()

    control('country-card-switch-off-confirm')?.click()
    expect(h.dispatched).toHaveLength(1)
    expect(h.dispatched[0]).toMatchObject({
      action: HILOS_I18N_COUNTRY_SWITCH_OFF_ACTION,
      payload: { countryId: 106 },
    })
    await nextTick()
    expect(control('country-card-switch-off-cancel')?.disabled).toBe(true)

    h.dispatched[0]?.settle({ message: 'Country switched off.' })
    await settled()
    expect(control('country-card-switch-off-confirm')).toBeNull()
  })

  it('keeps the window open with a refusal and allows another attempt', async () => {
    const h = harness()
    await openWindow()
    control('country-card-switch-off-confirm')?.click()
    h.dispatched[0]?.refuse(
      new ActionError(
        HILOS_I18N_COUNTRY_SWITCH_OFF_ACTION,
        'fail',
        'Server refused',
      ),
    )
    await settled()
    expect(
      document.querySelector('[data-id="country-card-switch-off-error"]')
        ?.textContent,
    ).toContain('Server refused')
    expect(control('country-card-switch-off-confirm')?.disabled).toBe(false)
    control('country-card-switch-off-confirm')?.click()
    expect(h.dispatched).toHaveLength(2)
  })

  it('marks another window’s switch-off but ignores the echo of its own pending request', async () => {
    const elsewhere = harness()
    await openWindow()
    await elsewhere.view.setProps({ card: { ...card, enabled: false } })
    expect(
      document.querySelector('[data-id="country-card-switch-off-notice"]')
        ?.textContent,
    ).toContain(HILOS_I18N_COUNTRY_SWITCH_OFF_COPY.elsewhere)
    expect(control('country-card-switch-off-confirm')?.disabled).toBe(true)
    elsewhere.view.unmount()
    mounted.length = 0

    const own = harness()
    await openWindow()
    control('country-card-switch-off-confirm')?.click()
    await own.view.setProps({ card: { ...card, enabled: false } })
    expect(
      document.querySelector('[data-id="country-card-switch-off-notice"]'),
    ).toBeNull()
    own.dispatched[0]?.settle({})
    await settled()
    expect(control('country-card-switch-off-confirm')).toBeNull()
  })

  it('lets a view-mode reader open and read the window but disables confirmation', async () => {
    const h = harness(card, true)
    expect(control('country-card-switch-off')?.disabled).toBe(false)
    await openWindow()
    expect(document.body.textContent).toContain('Switch off country · Canada')
    expect(control('country-card-switch-off-confirm')?.disabled).toBe(true)
    expect(h.dispatched).toHaveLength(0)
  })

  it('closes when the card disappears', async () => {
    const h = harness()
    await openWindow()
    await h.view.setProps({ card: null })
    expect(control('country-card-switch-off-confirm')).toBeNull()
  })
})
