import { mount, type VueWrapper } from '@vue/test-utils'
import {
  ActionError,
  createSignal,
  hilosToasts,
  type ActionHandle,
  type ActionLifecycle,
  type ActionResult,
} from '@hilos/core/base'
import {
  HILOS_I18N_LOCALE_DELETE_ACTION,
  HILOS_I18N_LOCALE_DELETE_COPY,
  type HilosI18nLanguageContext,
  type HilosI18nLocaleDeleteTarget,
  createHilosI18nLocaleDelete,
} from '@hilos/core/admin/i18n'
import { afterEach, describe, expect, it } from 'vitest'
import { defineComponent, h, nextTick, ref } from 'vue'

import { hilosAdminViewModeKey } from '../../../hilosAdminViewMode.js'
import LoadingButton from '../../../LoadingButton.vue'
import HilosI18nLocaleDelete from './HilosI18nLocaleDelete.vue'

const locale: HilosI18nLocaleDeleteTarget = {
  languageId: 12,
  countryId: 108,
  localeId: 12108,
  languageCode: 'ru',
  countryCode: 'ua',
  localeCode: 'ru-UA',
  nativeName: 'Русский',
  countryLabel: 'Украина',
}

interface Dispatch {
  action: string
  payload: unknown
  loading: ReturnType<typeof createSignal<boolean>>
  settle: (result: ActionResult) => void
  refuse: (failure: ActionError) => void
}

const mounted: VueWrapper[] = []
afterEach(() => {
  for (const view of mounted) view.unmount()
  mounted.length = 0
  hilosToasts.clear()
})

function harness(viewMode = false) {
  const source = ref<HilosI18nLocaleDeleteTarget | null>(locale)
  const open = ref(true)
  const viewer = ref(viewMode)
  const dispatched: Dispatch[] = []
  const actions = {
    dispatch(action: string, payload: unknown): ActionHandle {
      let settle: Dispatch['settle'] = () => {}
      let refuse: Dispatch['refuse'] = () => {}
      const done = new Promise<ActionResult>((resolve, reject) => {
        settle = resolve
        refuse = reject
      })
      const loading = createSignal(false)
      dispatched.push({ action, payload, loading, settle, refuse })
      return { requestId: String(dispatched.length), loading, done }
    },
  } as ActionLifecycle
  const context = { actions } as HilosI18nLanguageContext
  const view = mount(
    defineComponent({
      setup: () => () =>
        h(HilosI18nLocaleDelete, {
          modelValue: open.value,
          target: locale,
          source: source.value,
          deleteLocale: createHilosI18nLocaleDelete(context).deleteLocale,
          'onUpdate:modelValue': (next: boolean) => {
            open.value = next
          },
        }),
    }),
    {
      attachTo: document.body,
      global: { provide: { [hilosAdminViewModeKey as symbol]: viewer } },
    },
  )
  mounted.push(view)
  return { view, source, open, viewer, dispatched }
}

function control(id: string): HTMLButtonElement {
  return document.querySelector(`[data-id="i18n-locale-delete-${id}"]`)!
}

async function settled(): Promise<void> {
  await nextTick()
  await nextTick()
  await nextTick()
}

describe('HilosI18nLocaleDelete', () => {
  it('freezes its labels and waits for success even when the source disappears', async () => {
    const s = harness()
    await settled()
    expect(document.body.textContent).toContain(
      'Delete locale · Русский in Украина',
    )
    expect(document.body.textContent).toContain(
      HILOS_I18N_LOCALE_DELETE_COPY.body('ru', 'ua'),
    )
    expect(document.body.textContent).toContain(
      HILOS_I18N_LOCALE_DELETE_COPY.note,
    )
    expect(control('error')).not.toBeNull()
    s.source.value = {
      ...locale,
      nativeName: 'Changed elsewhere',
      countryLabel: 'Changed country',
    }
    await settled()
    expect(document.body.textContent).toContain(
      'Delete locale · Русский in Украина',
    )
    control('confirm').click()
    await settled()
    expect(s.dispatched).toHaveLength(1)
    expect(s.dispatched[0]).toMatchObject({
      action: 'hilos_i18n_locale_delete',
      payload: { languageId: 12, countryId: 108, localeId: 12108 },
    })
    expect(control('cancel').disabled).toBe(true)
    expect(control('confirm').disabled).toBe(true)
    control('confirm').click()
    s.view.findComponent(LoadingButton).vm.$emit('click')
    control('cancel').click()
    document
      .querySelector<HTMLButtonElement>('[data-id="modal-close"]')
      ?.click()
    document
      .querySelector<HTMLElement>('[data-id="modal"]')!
      .dispatchEvent(
        new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
      )
    document.querySelector<HTMLElement>('[data-id="modal"]')!.click()
    s.source.value = null
    s.dispatched[0]!.loading.set(true)
    await settled()
    expect(control('confirm').getAttribute('aria-busy')).toBe('true')
    expect(s.open.value).toBe(true)
    expect(s.dispatched).toHaveLength(1)
    expect(document.body.textContent).not.toContain('Deleted elsewhere.')
    s.dispatched[0]!.settle({ message: 'Locale deleted.' })
    await settled()
    expect(s.open.value).toBe(false)
    expect(
      document.querySelector('[data-id="i18n-locale-delete-confirm"]'),
    ).toBeNull()
    expect(
      hilosToasts.toasts
        .get()
        .some((toast) => toast.message === 'Locale deleted.'),
    ).toBe(true)
  })

  it('lets the server refuse a dependency created after opening and keeps the reserved error row', async () => {
    const s = harness()
    await settled()
    const room = control('error')
    expect(room.querySelector('.invisible')).not.toBeNull()
    expect(control('confirm').disabled).toBe(false)
    control('confirm').click()
    s.dispatched[0]!.refuse(
      new ActionError(
        HILOS_I18N_LOCALE_DELETE_ACTION,
        'fail',
        "Locale 'ru-UA' is switched on: switch it off before deleting it.",
        'I18nRowFrozenException',
        'The locale was switched on before deletion.',
      ),
    )
    await settled()
    expect(s.open.value).toBe(true)
    expect(control('error')).toBe(room)
    expect(control('error').textContent).toContain(
      "Locale 'ru-UA' is switched on: switch it off before deleting it.",
    )
    expect(control('cancel').disabled).toBe(false)
    document
      .querySelector<HTMLButtonElement>(
        '[data-id="hilos-action-error-details"]',
      )
      ?.click()
    await settled()
    expect(document.body.textContent).toContain("Couldn't delete the locale")
    expect(document.body.textContent).toContain(
      'The locale was switched on before deletion.',
    )
  })

  it('keeps an idle window open on another deletion and never sends another confirmation', async () => {
    const s = harness()
    await settled()
    s.source.value = null
    await settled()
    expect(s.open.value).toBe(true)
    expect(document.body.textContent).toContain('Deleted elsewhere.')
    expect(control('confirm').disabled).toBe(true)
    control('confirm').click()
    expect(s.dispatched).toHaveLength(0)
    control('cancel').click()
    await settled()
    expect(s.open.value).toBe(false)
  })

  it('retains a server refusal when the vanished source failed its request', async () => {
    const s = harness()
    await settled()
    control('confirm').click()
    s.source.value = null
    s.dispatched[0]!.refuse(
      new ActionError(
        HILOS_I18N_LOCALE_DELETE_ACTION,
        'fail',
        'Locale no longer exists.',
      ),
    )
    await settled()
    expect(s.open.value).toBe(true)
    expect(control('error').textContent).toContain('Locale no longer exists.')
    expect(control('confirm').disabled).toBe(true)
    expect(control('cancel').disabled).toBe(false)
  })

  it('allows reading in admin view mode and disables confirmation, including a live mode change', async () => {
    const s = harness(true)
    await settled()
    expect(control('confirm').disabled).toBe(true)
    expect(control('cancel').disabled).toBe(false)
    control('confirm').click()
    s.view.findComponent(LoadingButton).vm.$emit('click')
    expect(s.dispatched).toHaveLength(0)
    s.viewer.value = false
    await settled()
    expect(control('confirm').disabled).toBe(false)
    s.viewer.value = true
    await settled()
    expect(control('confirm').disabled).toBe(true)
  })

  it.each([
    ['timeout', 'The action timed out. Please try again.'],
    [
      'disconnected',
      'The connection dropped before the action completed. Please try again.',
    ],
  ] as const)(
    'keeps %s in the window and permits a retry',
    async (outcome, message) => {
      const s = harness()
      await settled()
      control('confirm').click()
      s.dispatched[0]!.refuse(
        new ActionError(HILOS_I18N_LOCALE_DELETE_ACTION, outcome, message),
      )
      await settled()
      expect(s.open.value).toBe(true)
      expect(control('error').textContent).toContain(message)
      expect(control('confirm').disabled).toBe(false)
      control('confirm').click()
      expect(s.dispatched).toHaveLength(2)
      s.dispatched[1]!.settle({ message: 'Locale deleted.' })
      await settled()
      expect(s.open.value).toBe(false)
    },
  )

  it.each([
    { ...locale, languageId: 11 },
    { ...locale, countryId: 101 },
    { ...locale, localeId: 12101, localeCode: 'ru-GB' },
  ])(
    'never substitutes another source identity for the frozen target',
    async (source) => {
      const s = harness()
      await settled()
      s.source.value = source
      await settled()
      expect(document.body.textContent).toContain('Deleted elsewhere.')
      expect(control('confirm').disabled).toBe(true)
      s.view.findComponent(LoadingButton).vm.$emit('click')
      expect(s.dispatched).toHaveLength(0)
      expect(document.body.textContent).toContain(
        'Delete locale · Русский in Украина',
      )
    },
  )
})
