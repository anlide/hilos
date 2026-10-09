import { mount, type VueWrapper } from '@vue/test-utils'
import {
  ActionError,
  createSignal,
  HILOS_I18N_LANGUAGE_SWITCH_OFF_ACTION,
  type ActionHandle,
  type ActionLifecycle,
  type ActionResult,
  type HilosI18nLanguageCard,
  type HilosI18nLanguageContext,
} from '@hilos/core'
import { afterEach, describe, expect, it } from 'vitest'
import { nextTick, ref } from 'vue'

import { hilosAdminViewModeKey } from '../../../hilosAdminViewMode.js'
import HilosI18nLanguageSwitchOff from './HilosI18nLanguageSwitchOff.vue'

const card: HilosI18nLanguageCard = {
  code: 'fr',
  nativeName: 'Français',
  rtl: false,
  enabled: true,
  summary: {
    isDefault: false,
    isOwn: false,
    localeCount: 1,
    nameCount: 1,
    canDelete: false,
    deleteReason: 'locales',
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
  initial: HilosI18nLanguageCard | null = card,
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
  const context = { actions } as HilosI18nLanguageContext
  const view = mount(HilosI18nLanguageSwitchOff, {
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
  control('language-card-switch-off')?.click()
  await nextTick()
}

async function settled(): Promise<void> {
  await nextTick()
  await nextTick()
  await nextTick()
}

describe('HilosI18nLanguageSwitchOff', () => {
  it('shows the opening control, default-language reason, and no control for a disabled card', async () => {
    const enabled = harness()
    expect(control('language-card-switch-off')?.disabled).toBe(false)
    enabled.view.unmount()
    mounted.length = 0

    const defaultLanguage = harness({
      ...card,
      summary: { ...card.summary, isDefault: true },
    })
    expect(control('language-card-switch-off')?.disabled).toBe(true)
    expect(
      control('language-card-switch-off')?.getAttribute('aria-describedby'),
    ).toBe('language-card-switch-off-reason')
    expect(
      document.querySelector('[data-id="language-card-switch-off-reason"]')
        ?.textContent,
    ).toContain('default language')
    defaultLanguage.view.unmount()
    mounted.length = 0

    harness({ ...card, enabled: false })
    expect(control('language-card-switch-off')).toBeNull()
  })

  it('opens with the agreed copy and closes only on server success', async () => {
    const h = harness()
    await openWindow()
    expect(document.body.textContent).toContain(
      'Switch off language · Français',
    )
    expect(document.body.textContent).toContain(
      'nothing keeps its fields frozen',
    )
    expect(document.body.textContent).toContain(
      'Translations, locales and names stay in place',
    )
    expect(
      document.querySelector('[data-id="language-card-switch-off-error"]'),
    ).not.toBeNull()
    expect(
      document.querySelector(
        '[data-id="language-card-switch-off-notice-slot"]',
      ),
    ).not.toBeNull()

    control('language-card-switch-off-confirm')?.click()
    expect(h.dispatched).toHaveLength(1)
    expect(h.dispatched[0]).toMatchObject({
      action: HILOS_I18N_LANGUAGE_SWITCH_OFF_ACTION,
      payload: { languageCode: 'fr' },
    })
    await nextTick()
    expect(control('language-card-switch-off-cancel')?.disabled).toBe(true)
    control('language-card-switch-off-confirm')?.click()
    expect(h.dispatched).toHaveLength(1)
    h.dispatched[0]?.settle({ message: 'Language switched off.' })
    await settled()
    expect(control('language-card-switch-off-confirm')).toBeNull()
  })

  it('keeps the window open with a refusal and allows another attempt', async () => {
    const h = harness()
    await openWindow()
    control('language-card-switch-off-confirm')?.click()
    h.dispatched[0]?.refuse(
      new ActionError(
        HILOS_I18N_LANGUAGE_SWITCH_OFF_ACTION,
        'fail',
        'Server refused',
      ),
    )
    await settled()
    expect(
      document.querySelector('[data-id="language-card-switch-off-error"]')
        ?.textContent,
    ).toContain('Server refused')
    expect(control('language-card-switch-off-confirm')?.disabled).toBe(false)
    control('language-card-switch-off-confirm')?.click()
    expect(h.dispatched).toHaveLength(2)
  })

  it('marks another window’s switch-off but ignores the echo of its own pending request', async () => {
    const elsewhere = harness()
    await openWindow()
    await elsewhere.view.setProps({ card: { ...card, enabled: false } })
    expect(
      document.querySelector('[data-id="language-card-switch-off-notice"]')
        ?.textContent,
    ).toContain('Switched off elsewhere just now.')
    expect(control('language-card-switch-off-confirm')?.disabled).toBe(true)
    elsewhere.view.unmount()
    mounted.length = 0

    const own = harness()
    await openWindow()
    control('language-card-switch-off-confirm')?.click()
    await own.view.setProps({ card: { ...card, enabled: false } })
    expect(
      document.querySelector('[data-id="language-card-switch-off-notice"]'),
    ).toBeNull()
    own.dispatched[0]?.settle({})
    await settled()
    expect(control('language-card-switch-off-confirm')).toBeNull()
  })

  it('lets a view-mode reader open and read the window but disables confirmation', async () => {
    const h = harness(card, true)
    expect(control('language-card-switch-off')?.disabled).toBe(false)
    await openWindow()
    expect(document.body.textContent).toContain(
      'Switch off language · Français',
    )
    expect(control('language-card-switch-off-confirm')?.disabled).toBe(true)
    expect(h.dispatched).toHaveLength(0)
  })

  it('closes when the card disappears', async () => {
    const h = harness()
    await openWindow()
    await h.view.setProps({ card: null })
    expect(control('language-card-switch-off-confirm')).toBeNull()
  })
})
