import { mount, type VueWrapper } from '@vue/test-utils'
import {
  ActionError,
  createSignal,
  HILOS_I18N_LANGUAGE_SWITCH_ON_ACTION,
  type ActionHandle,
  type ActionLifecycle,
  type ActionResult,
  type HilosI18nLanguageCard,
  type HilosI18nLanguageContext,
} from '@hilos/core'
import { afterEach, describe, expect, it } from 'vitest'
import { nextTick, ref } from 'vue'

import { hilosAdminViewModeKey } from '../../../hilosAdminViewMode.js'
import HilosI18nLanguageSwitchOn from './HilosI18nLanguageSwitchOn.vue'

const card: HilosI18nLanguageCard = {
  code: 'fr',
  nativeName: 'Français',
  rtl: false,
  enabled: false,
  summary: {
    isDefault: false,
    isOwn: false,
    localeCount: 0,
    nameCount: 1,
    countrylessLocale: 'missing',
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
  const view = mount(HilosI18nLanguageSwitchOn, {
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

function content(id: string): string | null {
  return (
    document.querySelector(`[data-id="${id}"]`)?.textContent?.trim() ?? null
  )
}

async function openWindow(): Promise<void> {
  control('language-card-switch-on')?.click()
  await nextTick()
}

async function settled(): Promise<void> {
  await nextTick()
  await nextTick()
  await nextTick()
}

describe('HilosI18nLanguageSwitchOn', () => {
  it('offers one live opening control only for a disabled language', () => {
    const disabled = harness()
    expect(control('language-card-switch-on')?.disabled).toBe(false)
    disabled.view.unmount()
    mounted.length = 0
    harness({ ...card, enabled: true })
    expect(control('language-card-switch-on')).toBeNull()
  })

  it('shows the agreed copy, facts, and a nonblocking missing-locale warning', async () => {
    const h = harness()
    await openWindow()
    expect(document.body.textContent).toContain('Switch on language · Français')
    expect(document.body.textContent).toContain(
      'Nothing checks that its translation is complete.',
    )
    expect(document.body.textContent).toContain('Locale without a country:')
    expect(document.body.textContent).toContain('Names in other languages:')
    expect(content('language-card-switch-on-locale')).toBe('not created')
    expect(content('language-card-switch-on-names')).toBe('1')
    expect(content('language-card-switch-on-warning')).toContain(
      'create that locale first.',
    )
    expect(
      document.querySelector('[data-id="language-card-switch-on-error"]'),
    ).not.toBeNull()
    expect(
      document.querySelector('[data-id="language-card-switch-on-notice-slot"]'),
    ).not.toBeNull()
    expect(control('language-card-switch-on-confirm')?.disabled).toBe(false)
    expect(h.dispatched).toHaveLength(0)
  })

  it('updates locale, names, and warning in an open window while confirmation stays live', async () => {
    const h = harness()
    await openWindow()
    await h.view.setProps({
      card: {
        ...card,
        summary: { ...card.summary, countrylessLocale: 'off', nameCount: 2 },
      },
    })
    expect(content('language-card-switch-on-locale')).toBe('switched off')
    expect(content('language-card-switch-on-names')).toBe('2')
    expect(content('language-card-switch-on-warning')).toContain(
      'switch that locale on first.',
    )
    expect(control('language-card-switch-on-confirm')?.disabled).toBe(false)
    await h.view.setProps({
      card: {
        ...card,
        summary: { ...card.summary, countrylessLocale: 'on', nameCount: 3 },
      },
    })
    expect(content('language-card-switch-on-locale')).toBe('switched on')
    expect(content('language-card-switch-on-names')).toBe('3')
    expect(content('language-card-switch-on-warning')).toBeNull()
    expect(control('language-card-switch-on-confirm')?.disabled).toBe(false)
  })

  it('dispatches once while busy and closes on success', async () => {
    const h = harness()
    await openWindow()
    control('language-card-switch-on-confirm')?.click()
    expect(h.dispatched).toHaveLength(1)
    expect(h.dispatched[0]).toMatchObject({
      action: HILOS_I18N_LANGUAGE_SWITCH_ON_ACTION,
      payload: { languageCode: 'fr' },
    })
    await nextTick()
    expect(control('language-card-switch-on-cancel')?.disabled).toBe(true)
    control('language-card-switch-on-confirm')?.click()
    expect(h.dispatched).toHaveLength(1)
    h.dispatched[0]?.settle({ message: 'Language switched on.' })
    await settled()
    expect(control('language-card-switch-on-confirm')).toBeNull()
  })

  it('keeps a refusal in the window and permits retry', async () => {
    const h = harness()
    await openWindow()
    control('language-card-switch-on-confirm')?.click()
    h.dispatched[0]?.refuse(
      new ActionError(
        HILOS_I18N_LANGUAGE_SWITCH_ON_ACTION,
        'fail',
        'Server refused',
      ),
    )
    await settled()
    expect(content('language-card-switch-on-error')).toContain('Server refused')
    expect(control('language-card-switch-on-confirm')?.disabled).toBe(false)
    control('language-card-switch-on-confirm')?.click()
    expect(h.dispatched).toHaveLength(2)
  })

  it('marks another window’s switch-on but ignores the pending request’s own echo', async () => {
    const elsewhere = harness()
    await openWindow()
    await elsewhere.view.setProps({ card: { ...card, enabled: true } })
    expect(content('language-card-switch-on-notice')).toContain(
      'Switched on elsewhere just now.',
    )
    expect(control('language-card-switch-on-confirm')?.disabled).toBe(true)
    elsewhere.view.unmount()
    mounted.length = 0

    const own = harness()
    await openWindow()
    control('language-card-switch-on-confirm')?.click()
    await own.view.setProps({ card: { ...card, enabled: true } })
    expect(content('language-card-switch-on-notice')).toBeNull()
    own.dispatched[0]?.settle({})
    await settled()
    expect(control('language-card-switch-on-confirm')).toBeNull()
  })

  it('lets a view-mode reader open the window but disables confirmation', async () => {
    const h = harness(card, true)
    expect(control('language-card-switch-on')?.disabled).toBe(false)
    await openWindow()
    expect(content('language-card-switch-on-locale')).toBe('not created')
    expect(control('language-card-switch-on-confirm')?.disabled).toBe(true)
    expect(h.dispatched).toHaveLength(0)
  })

  it('closes when the card disappears', async () => {
    const h = harness()
    await openWindow()
    await h.view.setProps({ card: null })
    expect(control('language-card-switch-on-confirm')).toBeNull()
  })
})
