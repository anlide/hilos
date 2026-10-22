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
  HILOS_I18N_COUNTRY_NAME_DELETE_ACTION,
  HILOS_I18N_COUNTRY_NAME_OVERRIDE_DELETE_ACTION,
  HILOS_I18N_COUNTRY_NAME_DELETE_COPY,
  type HilosI18nCountryContext,
  type HilosI18nNameRow,
  type HilosI18nCountryNameDeleteTarget,
} from '@hilos/core/admin/i18n'
import { afterEach, describe, expect, it } from 'vitest'
import { defineComponent, h, nextTick, ref } from 'vue'

import { hilosAdminViewModeKey } from '../../../hilosAdminViewMode.js'
import HilosI18nCountryNameDelete from './HilosI18nCountryNameDelete.vue'

const row: HilosI18nNameRow = {
  inLanguageId: 51,
  nameId: 301,
  rowKey: '51',
  code: 'qk',
  nativeName: 'Writing',
  name: 'Base name',
  corrections: [
    {
      localeId: 501,
      nameId: 401,
      countryId: 101,
      localeCode: 'qk-GB',
      countryCode: 'gb',
      countryName: 'United Kingdom',
      name: 'Correction',
    },
    {
      localeId: 502,
      nameId: 402,
      countryId: 102,
      localeCode: 'qk-US',
      countryCode: 'us',
      countryName: 'United States',
      name: '',
    },
  ],
}
const baseTarget: HilosI18nCountryNameDeleteTarget = {
  kind: 'base',
  inLanguageId: 51,
  nameId: 301,
  countryId: 149,
  countryCode: 'qj',
  countryName: 'Target',
  inLanguageCode: 'qk',
  inLanguageNativeName: 'Writing',
  expectedName: 'Base name',
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

function harness(
  initial: HilosI18nCountryNameDeleteTarget = baseTarget,
  viewMode = false,
) {
  const target = ref(initial)
  const source = ref<HilosI18nNameRow | null>(row)
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
  const context = { actions } as HilosI18nCountryContext
  const view = mount(
    defineComponent({
      setup: () => () =>
        h(HilosI18nCountryNameDelete, {
          modelValue: open.value,
          context,
          source: source.value,
          target: target.value,
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
  return { view, source, target, open, viewer, dispatched }
}

function control(id: string): HTMLButtonElement {
  return document.querySelector(`[data-id="i18n-country-name-delete-${id}"]`)!
}

async function settled(): Promise<void> {
  await nextTick()
  await nextTick()
  await nextTick()
}

describe('HilosI18nCountryNameDelete', () => {
  it('does not close a new route confirmation when an unmounted request succeeds', async () => {
    const s = harness()
    await settled()
    control('confirm').click()
    s.view.unmount()
    mounted.splice(mounted.indexOf(s.view), 1)
    s.dispatched[0]!.settle({})
    await settled()
    expect(s.open.value).toBe(true)
  })

  it.each(['base', 'override'] as const)(
    'freezes the %s target, blocks closing while busy, and closes on tracked success',
    async (kind) => {
      const target: HilosI18nCountryNameDeleteTarget =
        kind === 'base'
          ? baseTarget
          : {
              ...baseTarget,
              kind,
              localeId: 501,
              nameId: 401,
              localeCode: 'qk-GB',
              expectedName: 'Correction',
            }
      const s = harness(target)
      await settled()
      expect(document.activeElement?.getAttribute('role')).toBe('dialog')
      expect(document.body.textContent).toContain(
        kind === 'base'
          ? HILOS_I18N_COUNTRY_NAME_DELETE_COPY.cascade
          : HILOS_I18N_COUNTRY_NAME_DELETE_COPY.overrideBody,
      )
      if (kind === 'base') {
        expect(document.body.textContent).toContain(
          'If the built-in catalog knows this name, it returns immediately as an unlocked name. Otherwise, there will be no name in this language.',
        )
        expect(document.body.textContent).not.toMatch(/36|hours/)
      } else {
        expect(document.body.textContent).toContain(
          'This correction will not return by itself.',
        )
        expect(document.body.textContent).toContain('qk-GB')
      }
      expect(document.body.textContent).toContain('Target (QJ)')
      expect(document.body.textContent).toContain('Writing (qk)')
      expect(document.body.textContent).toContain(target.expectedName)
      s.source.value = { ...row, name: 'New base' }
      s.target.value = {
        ...target,
        expectedName: 'Do not silently confirm this',
      }
      await settled()
      if (kind === 'override')
        expect(document.body.textContent).toContain('New base')
      control('confirm').click()
      await settled()
      expect(s.dispatched).toHaveLength(1)
      expect(s.dispatched[0]).toMatchObject({
        action:
          kind === 'base'
            ? HILOS_I18N_COUNTRY_NAME_DELETE_ACTION
            : HILOS_I18N_COUNTRY_NAME_OVERRIDE_DELETE_ACTION,
        payload: {
          countryId: 149,
          inLanguageId: 51,
          nameId: target.nameId,
          expectedName: target.expectedName,
          ...(kind === 'override' ? { localeId: 501 } : {}),
        },
      })
      expect(control('confirm').getAttribute('aria-disabled')).toBe('true')
      expect(control('cancel').disabled).toBe(true)
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
      expect(s.open.value).toBe(true)
      expect(control('confirm').getAttribute('aria-busy')).toBe('true')
      expect(s.dispatched).toHaveLength(1)
      const message =
        kind === 'base' ? 'Country name removed.' : 'Locale correction removed.'
      s.dispatched[0]!.settle({ message })
      await settled()
      expect(s.open.value).toBe(false)
      expect(
        hilosToasts.toasts.get().some((toast) => toast.message === message),
      ).toBe(true)
    },
  )

  it.each(['base', 'override'] as const)(
    'shows and dispatches a saved empty %s value literally',
    async (kind) => {
      const target: HilosI18nCountryNameDeleteTarget =
        kind === 'base'
          ? { ...baseTarget, expectedName: '' }
          : {
              ...baseTarget,
              kind,
              localeId: 502,
              nameId: 402,
              localeCode: 'qk-US',
              expectedName: '',
            }
      const s = harness(target)
      s.source.value = { ...row, name: '' }
      await settled()
      expect(document.body.textContent).toContain('Empty value')
      expect(document.body.textContent).toContain(
        HILOS_I18N_COUNTRY_NAME_DELETE_COPY.emptyDescription,
      )
      expect(control('confirm').disabled).toBe(false)
      control('confirm').click()
      expect(s.dispatched[0]!.payload).toMatchObject({ expectedName: '' })
      s.dispatched[0]!.settle({})
      await settled()
    },
  )

  it.each(['fail', 'timeout', 'disconnected'] as const)(
    'keeps a %s refusal and its details in the reserved row',
    async (outcome) => {
      const s = harness()
      await settled()
      const room = control('error')
      control('confirm').click()
      s.dispatched[0]!.refuse(
        new ActionError(
          HILOS_I18N_COUNTRY_NAME_DELETE_ACTION,
          outcome,
          'The name changed. Close this window and confirm again.',
          'CountryNameChanged',
          'A newer name was saved.',
        ),
      )
      await settled()
      expect(s.open.value).toBe(true)
      expect(control('error')).toBe(room)
      expect(room.textContent).toContain(
        outcome === 'fail'
          ? 'The name changed.'
          : outcome === 'timeout'
            ? 'timed out'
            : 'connection dropped',
      )
      document
        .querySelector<HTMLButtonElement>(
          '[data-id="hilos-action-error-details"]',
        )!
        .click()
      await settled()
      expect(document.body.textContent).toContain('A newer name was saved.')
      expect(control('confirm').disabled).toBe(false)
    },
  )

  it.each(['base', 'override', 'row', 'coordinate'] as const)(
    'keeps the window and blocks confirmation when the %s disappears',
    async (missing) => {
      const s = harness({
        ...baseTarget,
        kind: 'override',
        localeId: 501,
        nameId: 401,
        localeCode: 'qk-GB',
        expectedName: 'Correction',
      })
      await settled()
      s.source.value =
        missing === 'row'
          ? null
          : missing === 'base'
            ? { ...row, nameId: null, name: null }
            : missing === 'coordinate'
              ? { ...row, inLanguageId: 11, rowKey: '11', code: 'en' }
              : { ...row, corrections: [] }
      await settled()
      expect(s.open.value).toBe(true)
      expect(document.body.textContent).toContain('The name no longer exists.')
      expect(control('confirm').disabled).toBe(true)
      control('confirm').click()
      expect(s.dispatched).toHaveLength(0)
      control('cancel').click()
      await settled()
      expect(s.open.value).toBe(false)
    },
  )

  it.each(['cancel', 'escape', 'backdrop'] as const)(
    'closes by %s without writing',
    async (way) => {
      const s = harness()
      await settled()
      if (way === 'cancel') control('cancel').click()
      else if (way === 'escape')
        document
          .querySelector<HTMLElement>('[data-id="modal"]')!
          .dispatchEvent(
            new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
          )
      else document.querySelector<HTMLElement>('[data-id="modal"]')!.click()
      await settled()
      expect(s.open.value).toBe(false)
      expect(s.dispatched).toHaveLength(0)
    },
  )

  it('disables confirmation for a viewer, including a live mode change', async () => {
    const s = harness(baseTarget, true)
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
