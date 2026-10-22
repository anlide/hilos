import { enableAutoUnmount, mount } from '@vue/test-utils'
import {
  ActionError,
  ActionLifecycle,
  createSignal,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  hilosToasts,
  type ActionHandle,
  type ActionLifecycleSource,
  type ActionResult,
} from '@hilos/core/base'
import {
  createHilosI18nCountryCardWindow,
  type HilosI18nCountryCard,
  type HilosI18nCountryContext,
} from '@hilos/core/admin/i18n'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { nextTick, ref } from 'vue'
import { hilosAdminViewModeKey } from '../../../hilosAdminViewMode.js'
import HilosI18nCountryWindow from './HilosI18nCountryWindow.vue'

enableAutoUnmount(afterEach)
const disposals: Array<() => void> = []
afterEach(() => {
  for (const dispose of disposals.splice(0)) dispose()
  hilosToasts.clear()
  vi.useRealTimers()
})

const country: HilosI18nCountryCard = {
  countryId: 140,
  code: 'ie',
  currencySymbol: '€',
  currencyCode: 'EUR',
  enabled: false,
  summary: {
    name: 'Ireland',
    isOwn: false,
    defaultLocaleId: null,
    defaultLocaleCode: null,
    defaultLocale: 'missing',
    localeCount: 1,
    nameCount: 1,
    locales: [{ localeId: 11140, code: 'en-IE', enabled: false }],
    catalogDefaultLocaleCode: 'en-IE',
    canDelete: false,
    deleteReason: 'known',
  },
}
interface Dispatch {
  action: string
  payload: unknown
  settle: (result: ActionResult) => void
  refuse: (failure: ActionError) => void
}

function harness(initial = country, viewer = false) {
  const sent: Dispatch[] = []
  const loading = createSignal(false)
  const actions = {
    dispatch(action: string, payload: unknown): ActionHandle {
      let settle: Dispatch['settle'] = () => {}
      let refuse: Dispatch['refuse'] = () => {}
      const done = new Promise<ActionResult>((resolve, reject) => {
        settle = resolve
        refuse = reject
      })
      sent.push({ action, payload, settle, refuse })
      return { requestId: String(sent.length), loading, done }
    },
  } as ActionLifecycle
  return { ...mountWindow(actions, initial, viewer), sent, loading }
}

/**
 * The production lifecycle — its own requestId, its own deferred loading at
 * 300 ms — over a connection that records each frame and answers on demand.
 */
function liveHarness(viewer = false) {
  const frames: Array<{ action: string; requestId?: string }> = []
  const replies = new Set<(signal: Record<string, unknown>) => void>()
  const actions = new ActionLifecycle({
    sendAction: (action: string, _data: unknown, requestId?: string) => {
      frames.push({ action, requestId })
      return true
    },
    on: (
      event: string,
      listener: (signal: Record<string, unknown>) => void,
    ) => {
      if (event !== 'actionSuccess') return () => {}
      replies.add(listener)
      return () => replies.delete(listener)
    },
  } as unknown as ActionLifecycleSource)
  return {
    ...mountWindow(actions, country, viewer),
    frames,
    // Answer the last frame sent the way the server confirms it.
    confirm(): void {
      const last = frames[frames.length - 1]
      for (const listener of [...replies]) {
        listener({
          kind: 'actionSuccess',
          action: last?.action,
          requestId: last?.requestId,
        })
      }
    },
  }
}

function mountWindow(
  actions: ActionLifecycle,
  initial: HilosI18nCountryCard,
  viewer: boolean,
) {
  const source = createSignal<HilosI18nCountryCard | null>(initial)
  const window = createHilosI18nCountryCardWindow(source, {
    actions,
  } as HilosI18nCountryContext)
  window.start()
  disposals.push(window.dispose)
  mount(HilosI18nCountryWindow, {
    props: { window },
    attachTo: document.body,
    global: { provide: { [hilosAdminViewModeKey as symbol]: ref(viewer) } },
  })
  window.open(initial.enabled ? 'view' : 'edit')
  return { window, source }
}

function element<T extends HTMLElement = HTMLElement>(id: string): T {
  const found = document.querySelector<T>(`[data-id="${id}"]`)
  if (!found) throw new Error(`Missing element ${id}`)
  return found
}
function button(id: string): HTMLButtonElement {
  return element(id)
}
async function settle(): Promise<void> {
  await nextTick()
  await nextTick()
  await nextTick()
}
async function input(id: string, value: string): Promise<void> {
  const field = element<HTMLInputElement>(`i18n-country-edit-${id}`)
  field.value = value
  field.dispatchEvent(new Event('input', { bubbles: true }))
  await settle()
}
function submitForm(): void {
  document
    .getElementById('i18n-country-edit-form')
    ?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
}
function spinner(save: HTMLButtonElement): Element | null {
  return save.querySelector('[data-id="loading-button-spinner"]')
}

describe('HilosI18nCountryWindow', () => {
  it('keeps a draft without retargeting a same-code country replacement', async () => {
    const h = harness()
    await nextTick()
    await input('currency-symbol', 'draft')
    h.source.set({ ...country, countryId: country.countryId + 1 })
    await nextTick()
    expect(
      document.querySelector<HTMLInputElement>(
        '[data-id="i18n-country-edit-currency-symbol"]',
      )!.value,
    ).toBe('draft')
    expect(button('i18n-country-save').disabled).toBe(true)
    expect(button('i18n-country-save').textContent).toContain('Deleted')
    document
      .getElementById('i18n-country-edit-form')
      ?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
    await settle()
    expect(h.sent).toHaveLength(0)
  })

  it('prefills, focuses currency, waits for valid input and closes only on success', async () => {
    const h = harness()
    await settle()
    expect(element('i18n-country-title').textContent).toContain(
      'Edit country · Ireland',
    )
    expect(
      element<HTMLSelectElement>('i18n-country-edit-default-locale').value,
    ).toBe('11140')
    expect(
      element('i18n-country-edit-default-locale-hint').textContent,
    ).toContain('Suggested by the built-in catalog — save to keep it.')
    expect(document.activeElement).toBe(
      element('i18n-country-edit-currency-symbol'),
    )
    expect(button('i18n-country-save').disabled).toBe(false)
    await input('currency-code', ' eu ')
    expect(
      element<HTMLInputElement>('i18n-country-edit-currency-code').value,
    ).toBe('EU')
    expect(button('i18n-country-save').disabled).toBe(true)
    await input('currency-code', ' eur ')
    await input('currency-symbol', ' IE€ ')
    button('i18n-country-save').click()
    await settle()
    expect(h.sent[0]).toMatchObject({
      action: 'hilos_i18n_country_update',
      payload: {
        countryId: 140,
        currencySymbol: 'IE€',
        currencyCode: 'EUR',
        defaultLocaleId: 11140,
      },
    })
    expect(h.window.opened.get()).toBe(true)
    h.sent[0]?.settle({ message: 'Country saved.' })
    await settle()
    expect(document.querySelector('[data-id="i18n-country-window"]')).toBeNull()
    expect(
      hilosToasts.toasts
        .get()
        .some((toast) => toast.message === 'Country saved.'),
    ).toBe(true)
  })

  it('keeps a server refusal in the reserved row and allows correction and retry', async () => {
    const h = harness()
    await settle()
    expect(element('i18n-country-error')).toBeTruthy()
    button('i18n-country-save').click()
    h.sent[0]?.refuse(
      new ActionError(
        'hilos_i18n_country_update',
        'fail',
        "Locale 'en-IE' no longer exists.",
      ),
    )
    await settle()
    expect(element('i18n-country-error').textContent).toContain(
      "Locale 'en-IE' no longer exists.",
    )
    expect(h.window.opened.get()).toBe(true)
    expect(button('i18n-country-save').disabled).toBe(false)
    await input('currency-symbol', 'IE€')
    button('i18n-country-save').click()
    expect(h.sent).toHaveLength(2)
    h.sent[1]?.settle({})
    await settle()
    expect(h.window.opened.get()).toBe(false)
    h.window.open('edit')
    await settle()
    expect(element('i18n-country-error').textContent).not.toContain(
      "Locale 'en-IE'",
    )
  })

  it('does not ask to discard a suggestion, but asks for personal input', async () => {
    const h = harness()
    await settle()
    button('i18n-country-cancel').click()
    await settle()
    expect(h.window.opened.get()).toBe(false)
    expect(document.querySelector('[data-id="modal-confirm"]')).toBeNull()
    h.window.open('edit')
    await settle()
    await input('currency-symbol', 'IE€')
    button('i18n-country-cancel').click()
    await settle()
    expect(element('modal-confirm').textContent).toContain('Discard changes?')
    button('modal-confirm-cancel').click()
    await settle()
    expect(
      element<HTMLInputElement>('i18n-country-edit-currency-symbol').value,
    ).toBe('IE€')
    element('modal').dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
    )
    await settle()
    expect(element('modal-confirm')).toBeTruthy()
    button('modal-confirm-discard').click()
    await settle()
    expect(h.window.opened.get()).toBe(false)
  })

  it('blocks repeated sends, Cancel, Escape and backdrop while busy and hides its echo notice', async () => {
    const h = harness()
    await settle()
    await input('currency-symbol', 'IE€')
    button('i18n-country-save').click()
    await settle()
    h.loading.set(true)
    await settle()
    expect(button('i18n-country-save').getAttribute('aria-busy')).toBe('true')
    expect(button('i18n-country-cancel').disabled).toBe(true)
    button('i18n-country-save').click()
    button('i18n-country-cancel').click()
    element('modal').dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
    )
    element('modal').click()
    document
      .getElementById('i18n-country-edit-form')
      ?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
    await settle()
    expect(h.sent).toHaveLength(1)
    expect(h.window.opened.get()).toBe(true)
    expect(document.querySelector('[data-id="modal-confirm"]')).toBeNull()
    h.source.set({
      ...country,
      currencySymbol: 'IE€',
      summary: {
        ...country.summary,
        defaultLocaleId: 11140,
        defaultLocaleCode: 'en-IE',
      },
    })
    await settle()
    expect(document.querySelector('[data-id="i18n-country-notice"]')).toBeNull()
    h.sent[0]?.settle({})
    await settle()
    expect(h.window.opened.get()).toBe(false)
  })

  it('renders a live read-only view, keeps that mode after switch-off, and closes immediately', async () => {
    const h = harness({ ...country, enabled: true })
    await settle()
    expect(element('i18n-country-title').textContent).toContain(
      'Country · Ireland',
    )
    expect(document.activeElement).toBe(element('modal'))
    const controls = element('i18n-country-window').querySelectorAll<
      HTMLInputElement | HTMLSelectElement
    >('input, select')
    expect(controls).toHaveLength(4)
    for (const field of controls) {
      expect(field.disabled).toBe(true)
      expect(field.getAttribute('aria-describedby')).toBe('i18n-country-plate')
    }
    expect(
      element<HTMLSelectElement>('i18n-country-edit-default-locale').value,
    ).toBe('')
    expect(element('i18n-country-plate').textContent).toContain(
      'This country is switched on, so there is nothing to edit.',
    )
    expect(element('i18n-country-plate').getAttribute('role')).toBeNull()
    expect(document.querySelector('[data-id="i18n-country-error"]')).toBeNull()
    expect(document.querySelector('[data-id="i18n-country-save"]')).toBeNull()
    h.source.set({ ...country, currencySymbol: 'IE€' })
    await settle()
    expect(element('i18n-country-notice').textContent).toContain(
      'Switched off elsewhere just now.',
    )
    expect(
      element<HTMLInputElement>('i18n-country-edit-currency-symbol').value,
    ).toBe('IE€')
    expect(
      element<HTMLInputElement>('i18n-country-edit-currency-symbol').disabled,
    ).toBe(true)
    h.source.set(null)
    await settle()
    expect(element('i18n-country-notice').textContent).toContain(
      'Deleted elsewhere',
    )
    button('i18n-country-close').click()
    await settle()
    expect(h.window.opened.get()).toBe(false)
    expect(document.querySelector('[data-id="modal-confirm"]')).toBeNull()
  })

  it('holds a switched-on draft, offers conflict choices, and preserves deleted text', async () => {
    const h = harness()
    await settle()
    await input('currency-symbol', 'mine')
    h.source.set({ ...country, currencySymbol: 'theirs' })
    await settle()
    expect(button('i18n-country-save').disabled).toBe(true)
    expect(element('conflict-choices').textContent).toContain('Keep mine')
    button('conflict-accept-mine').click()
    await settle()
    expect(
      element<HTMLInputElement>('i18n-country-edit-currency-symbol').value,
    ).toBe('mine')
    h.source.set({ ...country, currencySymbol: 'new' })
    await settle()
    button('conflict-accept-theirs').click()
    await settle()
    expect(
      element<HTMLInputElement>('i18n-country-edit-currency-symbol').value,
    ).toBe('new')
    h.source.set({ ...country, currencySymbol: 'new', enabled: true })
    await settle()
    expect(element('i18n-country-notice').textContent).toContain(
      'Switched on elsewhere just now.',
    )
    expect(button('i18n-country-save').disabled).toBe(true)
    expect(
      element('i18n-country-plate').classList.contains('alert-warning'),
    ).toBe(true)
    h.source.set(null)
    await settle()
    expect(element('i18n-country-notice').textContent).toContain(
      'Deleted elsewhere — your text stays to copy.',
    )
    expect(button('i18n-country-save').textContent).toContain('Deleted')
    expect(
      element<HTMLInputElement>('i18n-country-edit-currency-symbol').value,
    ).toBe('new')
  })

  it('retains a vanished selected locale and shows a new option without changing the selection', async () => {
    const h = harness()
    await settle()
    h.source.set({
      ...country,
      summary: {
        ...country.summary,
        locales: [{ localeId: 46140, code: 'ga-IE', enabled: true }],
      },
    })
    await settle()
    const select = element<HTMLSelectElement>(
      'i18n-country-edit-default-locale',
    )
    expect(select.value).toBe('11140')
    expect([...select.options].map((option) => option.textContent)).toEqual([
      'ga-IE',
      'en-IE — no longer exists',
      'Not chosen',
    ])
    expect(button('i18n-country-save').disabled).toBe(false)
    select.value = ''
    select.dispatchEvent(new Event('change', { bubbles: true }))
    await settle()
    expect(h.window.form.get().defaultLocaleId).toBeNull()
    expect(h.window.touched.get()).toBe(true)
  })

  it('leaves viewer fields live and describes disabled Save with the view-mode strip', async () => {
    const h = harness(country, true)
    await settle()
    await input('currency-symbol', 'IE€')
    expect(
      element<HTMLInputElement>('i18n-country-edit-currency-symbol').disabled,
    ).toBe(false)
    expect(button('i18n-country-save').disabled).toBe(true)
    expect(
      button('i18n-country-save').getAttribute('aria-describedby'),
    ).toContain(HILOS_VIEW_MODE_STRIP_TEXT_ID)
    expect(button('i18n-country-cancel').disabled).toBe(false)
    document
      .getElementById('i18n-country-edit-form')
      ?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
    await settle()
    expect(h.sent).toHaveLength(0)
  })

  // The production LoadingButton and ActionLifecycle together: the button's
  // own 300 ms timer is the only delay, so the spinner never waits for the
  // lifecycle's deferred loading on top of it.
  it('locks Save at once on the first send and greys it only with its spinner at 300 ms', async () => {
    vi.useFakeTimers()
    const h = liveHarness()
    await settle()
    await input('currency-symbol', 'IE€')
    const save = button('i18n-country-save')
    expect(save.disabled).toBe(false)
    save.click()
    await settle()
    expect(h.frames.map((frame) => frame.action)).toEqual([
      'hilos_i18n_country_update',
    ])
    expect(save.getAttribute('aria-busy')).toBe('true')
    expect(save.getAttribute('aria-disabled')).toBe('true')
    expect(save.disabled).toBe(false)
    expect(save.classList.contains('disabled')).toBe(false)
    save.click()
    submitForm()
    await settle()
    expect(h.frames).toHaveLength(1)
    await vi.advanceTimersByTimeAsync(299)
    await settle()
    expect(save.getAttribute('aria-disabled')).toBe('true')
    expect(save.disabled).toBe(false)
    expect(save.classList.contains('disabled')).toBe(false)
    expect(spinner(save)).toBeNull()
    await vi.advanceTimersByTimeAsync(1)
    await settle()
    expect(spinner(save)).not.toBeNull()
    expect(save.classList.contains('disabled')).toBe(true)
    expect(save.disabled).toBe(false)
    save.click()
    submitForm()
    await settle()
    expect(h.frames).toHaveLength(1)
    h.confirm()
    await settle()
    expect(h.window.opened.get()).toBe(false)
  })

  it('turns Save off at once for a form unfit to send', async () => {
    vi.useFakeTimers()
    const h = liveHarness()
    await settle()
    await input('currency-code', ' eu ')
    const save = button('i18n-country-save')
    expect(save.disabled).toBe(true)
    expect(spinner(save)).toBeNull()
    save.click()
    submitForm()
    await settle()
    expect(h.frames).toHaveLength(0)
  })

  it('turns Save off at once in the admin view mode', async () => {
    vi.useFakeTimers()
    const h = liveHarness(true)
    await settle()
    await input('currency-symbol', 'IE€')
    const save = button('i18n-country-save')
    expect(save.disabled).toBe(true)
    expect(spinner(save)).toBeNull()
    save.click()
    submitForm()
    await settle()
    expect(h.frames).toHaveLength(0)
  })
})
