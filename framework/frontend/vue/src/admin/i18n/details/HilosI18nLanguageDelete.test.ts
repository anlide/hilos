import { mount, type VueWrapper } from '@vue/test-utils'
import {
  ActionError,
  createSignal,
  HILOS_I18N_LANGUAGE_DELETE_ACTION,
  HILOS_I18N_LANGUAGE_ROW_DELETE_ACTION,
  HILOS_I18N_LANGUAGE_DELETE_COPY,
  hilosToasts,
  type ActionHandle,
  type ActionLifecycle,
  type ActionResult,
  type HilosI18nLanguageContext,
  type HilosI18nLanguageRow,
} from '@hilos/core'
import { afterEach, describe, expect, it } from 'vitest'
import { defineComponent, h, nextTick, ref } from 'vue'

import { hilosAdminViewModeKey } from '../../../hilosAdminViewMode.js'
import HilosI18nLanguageDelete from './HilosI18nLanguageDelete.vue'

const language: HilosI18nLanguageRow = {
  languageId: 13,
  rowKey: '13',
  code: 'fr',
  nativeName: 'Français',
  rtl: false,
  enabled: true,
  isDefault: false,
  isOwn: false,
  canDelete: true,
  deleteReason: null,
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

function harness(action = HILOS_I18N_LANGUAGE_DELETE_ACTION, viewMode = false) {
  const source = ref<HilosI18nLanguageRow | null>(language)
  const open = ref(true)
  const viewer = ref(viewMode)
  const dispatched: Dispatch[] = []
  let deleted = 0
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
        h(HilosI18nLanguageDelete, {
          modelValue: open.value,
          context,
          source: source.value,
          actionName: action as
            | typeof HILOS_I18N_LANGUAGE_DELETE_ACTION
            | typeof HILOS_I18N_LANGUAGE_ROW_DELETE_ACTION,
          'onUpdate:modelValue': (next: boolean) => {
            open.value = next
          },
          onDeleted: () => {
            deleted++
          },
        }),
    }),
    {
      attachTo: document.body,
      global: { provide: { [hilosAdminViewModeKey as symbol]: viewer } },
    },
  )
  mounted.push(view)
  return { view, source, open, viewer, dispatched, deleted: () => deleted }
}

function control(id: string): HTMLButtonElement {
  return document.querySelector(`[data-id="i18n-language-delete-${id}"]`)!
}

async function settled(): Promise<void> {
  await nextTick()
  await nextTick()
  await nextTick()
}

describe('HilosI18nLanguageDelete', () => {
  it.each([
    HILOS_I18N_LANGUAGE_DELETE_ACTION,
    HILOS_I18N_LANGUAGE_ROW_DELETE_ACTION,
  ])(
    'freezes code/name and waits for %s success even when the source disappears',
    async (action) => {
      const s = harness(action)
      await settled()
      expect(document.body.textContent).toContain('Delete language · Français')
      expect(document.body.textContent).toContain(
        HILOS_I18N_LANGUAGE_DELETE_COPY.body,
      )
      expect(document.body.textContent).toContain(
        HILOS_I18N_LANGUAGE_DELETE_COPY.note,
      )
      expect(control('error')).not.toBeNull()
      s.source.value = { ...language, nativeName: 'Changed elsewhere' }
      await settled()
      expect(document.body.textContent).toContain('Delete language · Français')
      control('confirm').click()
      await settled()
      expect(s.dispatched).toHaveLength(1)
      expect(s.dispatched[0]).toMatchObject({
        action,
        payload: { languageId: 13 },
      })
      expect(control('cancel').disabled).toBe(true)
      expect(control('confirm').disabled).toBe(true)
      control('confirm').click()
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
      expect(s.deleted()).toBe(0)
      expect(s.dispatched).toHaveLength(1)
      expect(document.body.textContent).not.toContain('Deleted elsewhere.')
      s.dispatched[0]!.settle({ message: 'Language deleted.' })
      await settled()
      expect(s.open.value).toBe(false)
      expect(s.deleted()).toBe(1)
      expect(
        document.querySelector('[data-id="i18n-language-delete-confirm"]'),
      ).toBeNull()
      expect(
        hilosToasts.toasts
          .get()
          .some((toast) => toast.message === 'Language deleted.'),
      ).toBe(true)
    },
  )

  it('lets the server refuse a dependency created after opening and keeps the reserved error row', async () => {
    const s = harness()
    await settled()
    const room = control('error')
    s.source.value = { ...language, canDelete: false, deleteReason: 'locales' }
    await settled()
    expect(control('confirm').disabled).toBe(false)
    control('confirm').click()
    s.dispatched[0]!.refuse(
      new ActionError(
        HILOS_I18N_LANGUAGE_DELETE_ACTION,
        'fail',
        'The language now has locales.',
        'LanguageHasLocales',
        'A locale was added before deletion.',
      ),
    )
    await settled()
    expect(s.open.value).toBe(true)
    expect(control('error')).toBe(room)
    expect(control('error').textContent).toContain(
      'The language now has locales.',
    )
    expect(control('cancel').disabled).toBe(false)
    document
      .querySelector<HTMLButtonElement>(
        '[data-id="hilos-action-error-details"]',
      )
      ?.click()
    await settled()
    expect(document.body.textContent).toContain("Couldn't delete the language")
    expect(document.body.textContent).toContain(
      'A locale was added before deletion.',
    )
    expect(s.deleted()).toBe(0)
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
        HILOS_I18N_LANGUAGE_DELETE_ACTION,
        'fail',
        'Language no longer exists.',
      ),
    )
    await settled()
    expect(s.open.value).toBe(true)
    expect(control('error').textContent).toContain('Language no longer exists.')
    expect(control('confirm').disabled).toBe(true)
    expect(control('cancel').disabled).toBe(false)
  })

  it('allows reading in admin view mode and disables confirmation, including a live mode change', async () => {
    const s = harness(HILOS_I18N_LANGUAGE_DELETE_ACTION, true)
    await settled()
    expect(control('confirm').disabled).toBe(true)
    expect(control('cancel').disabled).toBe(false)
    control('confirm').click()
    expect(s.dispatched).toHaveLength(0)
    s.viewer.value = false
    await settled()
    expect(control('confirm').disabled).toBe(false)
    s.viewer.value = true
    await settled()
    expect(control('confirm').disabled).toBe(true)
  })
})
