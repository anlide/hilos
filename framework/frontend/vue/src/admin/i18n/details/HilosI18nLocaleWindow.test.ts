import { mount, type VueWrapper } from '@vue/test-utils'
import {
  ActionError,
  createSignal,
  HILOS_I18N_LOCALE_ADD_ACTION,
  HILOS_I18N_LOCALE_UPDATE_ACTION,
  type ActionHandle,
  type ActionLifecycle,
  type ActionResult,
  type HilosI18nLanguageCard,
  type HilosI18nLanguageContext,
  type HilosI18nLocaleRow,
  type HilosI18nLocaleTemplates,
  type HilosI18nLocaleWindowMode,
  type TableViewportController,
} from '@hilos/core'
import { afterEach, describe, expect, it } from 'vitest'
import { nextTick, ref } from 'vue'

import { hilosAdminViewModeKey } from '../../../hilosAdminViewMode.js'
import HilosI18nLocaleWindow from './HilosI18nLocaleWindow.vue'

const formats = {
  date: 'DD/MM/YYYY',
  time: 'HH:mm:ss',
  number: '1,000.00',
  phone: '+XX-XXXX-XXXX',
  address: 'Street, House, City, Index',
  measurement: 'metric' as const,
  collation: 'und',
}
const templates: HilosI18nLocaleTemplates = {
  date: ['DD/MM/YYYY', 'YYYY-MM-DD', 'DD.MM.YYYY', 'MM/DD/YYYY'],
  time: ['HH:mm:ss'],
  number: ['1,000.00'],
  phone: ['+XX-XXXX-XXXX'],
  address: ['Street, House, City, Index'],
  measurement: ['metric', 'imperial'],
  collation: ['und'],
}
const card: HilosI18nLanguageCard = {
  code: 'en',
  nativeName: 'English',
  rtl: false,
  enabled: true,
  summary: {
    isDefault: true,
    isOwn: false,
    localeCount: 1,
    nameCount: 0,
    canDelete: false,
    deleteReason: 'default',
  },
}
const known: HilosI18nLocaleRow = {
  rowKey: 'en-GB',
  countryCode: 'gb',
  countryName: 'United Kingdom',
  localeCode: null,
  enabled: null,
  formats: null,
  catalogFormats: formats,
}
const own: HilosI18nLocaleRow = {
  rowKey: 'en-AD',
  countryCode: 'ad',
  countryName: null,
  localeCode: null,
  enabled: null,
  formats: null,
  catalogFormats: null,
}
const editable: HilosI18nLocaleRow = {
  ...known,
  localeCode: 'en-GB',
  enabled: false,
  formats,
}
const visible: HilosI18nLocaleRow = {
  ...editable,
  rowKey: 'en',
  countryCode: null,
  countryName: null,
  localeCode: 'en',
  enabled: true,
}

interface Dispatch {
  name: string
  payload: unknown
  settle: (result: ActionResult) => void
  refuse: (failure: ActionError) => void
}

const mounted: VueWrapper[] = []
afterEach(() => {
  for (const view of mounted) view.unmount()
  mounted.length = 0
})

function harness(
  row: HilosI18nLocaleRow,
  mode: HilosI18nLocaleWindowMode,
  viewMode = false,
) {
  const focused = createSignal<HilosI18nLocaleRow | undefined>(undefined)
  const controller = {
    focusRow: (key: string) => {
      if (key !== row.rowKey) return null
      focused.set(row)
      return row
    },
    focusedRow: focused,
    releaseFocus: () => focused.set(undefined),
  } as unknown as TableViewportController<HilosI18nLocaleRow>
  const dispatched: Dispatch[] = []
  const context = {
    actions: {
      dispatch(name: string, payload: unknown): ActionHandle {
        let settle: Dispatch['settle'] = () => {}
        let refuse: Dispatch['refuse'] = () => {}
        const done = new Promise<ActionResult>((resolve, reject) => {
          settle = resolve
          refuse = reject
        })
        dispatched.push({ name, payload, settle, refuse })
        return {
          requestId: String(dispatched.length),
          loading: createSignal(false),
          done,
        }
      },
    } as ActionLifecycle,
  } as HilosI18nLanguageContext
  const view = mount(HilosI18nLocaleWindow, {
    props: {
      context,
      controller,
      card,
      templates,
      opened: { rowKey: row.rowKey, mode },
    },
    attachTo: document.body,
    global: { provide: { [hilosAdminViewModeKey as symbol]: ref(viewMode) } },
  })
  mounted.push(view)
  return { view, focused, dispatched }
}

function el(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

async function settled(): Promise<void> {
  await nextTick()
  await nextTick()
  await nextTick()
}

describe('HilosI18nLocaleWindow', () => {
  it('prefills a known pair, blocks an unknown pair until all choices, and closes on success', async () => {
    const knownWindow = harness(known, 'add')
    await nextTick()
    expect(el('i18n-locale-title')?.textContent).toContain(
      'Add locale · English in United Kingdom',
    )
    expect((el('i18n-locale-field-date') as HTMLSelectElement).value).toBe(
      'DD/MM/YYYY',
    )
    expect(el('i18n-locale-plate')?.textContent).toContain(
      'Filled from the built-in catalog',
    )
    el('i18n-locale-submit')?.click()
    expect(knownWindow.dispatched[0]).toMatchObject({
      name: HILOS_I18N_LOCALE_ADD_ACTION,
      payload: { languageCode: 'en', countryCode: 'gb', formats },
    })
    await nextTick()
    expect((el('i18n-locale-cancel') as HTMLButtonElement).disabled).toBe(true)
    knownWindow.dispatched[0]?.settle({ message: 'Locale added.' })
    await settled()
    expect(knownWindow.view.emitted('close')).toHaveLength(1)
    knownWindow.view.unmount()
    mounted.length = 0

    harness(own, 'add')
    await nextTick()
    expect((el('i18n-locale-submit') as HTMLButtonElement).disabled).toBe(true)
    expect(el('i18n-locale-plate')?.textContent).toContain(
      'choose all seven formats',
    )
  })

  it('merges edits, locks when switched on, and keeps refusal in the window', async () => {
    const h = harness(editable, 'edit')
    await nextTick()
    expect((el('i18n-locale-submit') as HTMLButtonElement).disabled).toBe(true)
    const date = el('i18n-locale-field-date') as HTMLSelectElement
    date.value = 'YYYY-MM-DD'
    date.dispatchEvent(new Event('change', { bubbles: true }))
    await nextTick()
    expect((el('i18n-locale-submit') as HTMLButtonElement).disabled).toBe(false)
    el('i18n-locale-submit')?.click()
    expect(h.dispatched[0]).toMatchObject({
      name: HILOS_I18N_LOCALE_UPDATE_ACTION,
      payload: {
        languageCode: 'en',
        countryCode: 'gb',
        formats: { ...formats, date: 'YYYY-MM-DD' },
      },
    })
    h.dispatched[0]?.refuse(
      new ActionError(HILOS_I18N_LOCALE_UPDATE_ACTION, 'fail', 'Refused'),
    )
    await settled()
    expect(el('i18n-locale-error')?.textContent).toContain('Refused')
    expect(el('i18n-locale-window')).not.toBeNull()
    h.focused.set({ ...editable, enabled: true })
    await nextTick()
    expect(el('i18n-locale-notice')?.textContent).toContain(
      'Switched on elsewhere just now.',
    )
    expect((el('i18n-locale-submit') as HTMLButtonElement).disabled).toBe(true)
    expect((el('i18n-locale-field-date') as HTMLSelectElement).disabled).toBe(
      true,
    )
  })

  it('keeps the opened view read-only when the locale switches off, and view mode locks Add', async () => {
    const h = harness(visible, 'view')
    await nextTick()
    expect(el('i18n-locale-title')?.textContent).toContain(
      'Locale · English without a country',
    )
    expect((el('i18n-locale-field-date') as HTMLSelectElement).disabled).toBe(
      true,
    )
    expect(el('i18n-locale-submit')).toBeNull()
    h.focused.set({ ...visible, enabled: false })
    await nextTick()
    expect(el('i18n-locale-notice')?.textContent).toContain(
      'Switched off elsewhere just now.',
    )
    h.view.unmount()
    mounted.length = 0

    harness(known, 'add', true)
    await nextTick()
    expect((el('i18n-locale-submit') as HTMLButtonElement).disabled).toBe(true)
  })

  it('shows the incoming format beside the selected draft and offers both conflict choices', async () => {
    const h = harness(editable, 'edit')
    await nextTick()
    const date = el('i18n-locale-field-date') as HTMLSelectElement
    date.value = 'YYYY-MM-DD'
    date.dispatchEvent(new Event('change', { bubbles: true }))
    h.focused.set({ ...editable, formats: { ...formats, date: 'DD.MM.YYYY' } })
    await nextTick()
    expect(el('i18n-locale-notice')?.textContent).toContain(
      'Date changed elsewhere to "DD.MM.YYYY"',
    )
    expect((el('i18n-locale-field-date') as HTMLSelectElement).value).toBe(
      'YYYY-MM-DD',
    )
    expect(el('conflict-badge')).not.toBeNull()
    el('conflict-accept-mine')?.click()
    await nextTick()
    expect(el('conflict-badge')).toBeNull()

    h.focused.set({ ...editable, formats: { ...formats, date: 'MM/DD/YYYY' } })
    await nextTick()
    expect(el('conflict-accept-theirs')).not.toBeNull()
    el('conflict-accept-theirs')?.click()
    await nextTick()
    expect((el('i18n-locale-field-date') as HTMLSelectElement).value).toBe(
      'MM/DD/YYYY',
    )
    expect(el('conflict-badge')).toBeNull()
  })
})
