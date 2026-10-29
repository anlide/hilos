import { mount, type VueWrapper } from '@vue/test-utils'
import {
  ActionError,
  createSignal,
  type ActionHandle,
  type ActionLifecycle,
  type ActionResult,
  type TableViewportController,
} from '@hilos/core/base'
import {
  createHilosI18nLanguageRowWindow,
  HILOS_I18N_LANGUAGE_ROW_UPDATE_ACTION,
  type HilosI18nLanguageContext,
  type HilosI18nLanguageRow,
} from '@hilos/core/admin/i18n'
import { afterEach, describe, expect, it } from 'vitest'
import { nextTick, ref } from 'vue'

import { hilosAdminViewModeKey } from '../../../hilosAdminViewMode.js'
import HilosI18nLanguageWindow from './HilosI18nLanguageWindow.vue'

const known: HilosI18nLanguageRow = {
  languageId: 19,
  rowKey: '19',
  code: 'uk',
  nativeName: 'Українська',
  rtl: false,
  enabled: false,
  isDefault: false,
  isOwn: false,
  canDelete: true,
  deleteReason: null,
  deleteDisabledReason: null,
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

function harness(row = known, viewMode = false) {
  const focused = createSignal<HilosI18nLanguageRow | undefined>(undefined)
  const controller = {
    focusRow: (key: string) => {
      if (key !== row.rowKey) return null
      focused.set(row)
      return row
    },
    focusedRow: focused,
    releaseFocus: () => focused.set(undefined),
  } as unknown as TableViewportController<HilosI18nLanguageRow>
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
  const window = createHilosI18nLanguageRowWindow(controller, context)
  const view = mount(HilosI18nLanguageWindow, {
    props: { window },
    attachTo: document.body,
    global: { provide: { [hilosAdminViewModeKey as symbol]: ref(viewMode) } },
  })
  mounted.push(view)
  window.open(row.rowKey)
  return { view, window, focused, dispatched }
}

function el(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}
function input(field: 'code' | 'native-name' | 'rtl'): HTMLInputElement {
  return el(`i18n-language-edit-${field}`) as HTMLInputElement
}
function button(name: 'submit' | 'cancel' | 'close'): HTMLButtonElement | null {
  return el(`i18n-language-edit-${name}`) as HTMLButtonElement | null
}
async function name(value: string): Promise<void> {
  input('native-name').value = value
  input('native-name').dispatchEvent(new Event('input', { bubbles: true }))
  await nextTick()
}
async function settled(): Promise<void> {
  await nextTick()
  await nextTick()
  await nextTick()
}

describe('HilosI18nLanguageWindow', () => {
  it('keeps the draft when focus reports a new ID with the same code', async () => {
    const h = harness()
    await nextTick()
    await name('Unsaved draft')
    h.focused.set({ ...known, languageId: 20, rowKey: '20' })
    await nextTick()
    expect(input('native-name').value).toBe('Unsaved draft')
    expect(button('submit')!.disabled).toBe(true)
    expect(button('submit')!.textContent).toContain('Deleted')
    expect(h.dispatched).toHaveLength(0)
  })

  it('shows a known language, focuses the dialog, and saves the edited fields on success', async () => {
    const h = harness()
    await settled()
    expect(el('i18n-language-edit-title')?.textContent).toContain(
      'Edit language · Українська',
    )
    expect(document.activeElement?.getAttribute('role')).toBe('dialog')
    expect(input('code').value).toBe('uk')
    expect(input('code').disabled).toBe(true)
    expect(input('native-name').disabled).toBe(false)
    expect(input('native-name').maxLength).toBe(64)
    expect(input('rtl').disabled).toBe(false)
    expect(el('i18n-language-edit-plate')?.getAttribute('data-kind')).toBe(
      'known',
    )
    expect(el('i18n-language-edit-plate')?.textContent).toContain(
      'a framework update that changes the catalog brings its values back',
    )
    expect(button('submit')?.disabled).toBe(true)
    await name('  My name  ')
    input('rtl').checked = true
    input('rtl').dispatchEvent(new Event('change', { bubbles: true }))
    await nextTick()
    button('submit')?.click()
    await nextTick()
    expect(h.dispatched[0]).toMatchObject({
      name: HILOS_I18N_LANGUAGE_ROW_UPDATE_ACTION,
      payload: { languageId: 19, nativeName: 'My name', rtl: true },
    })
    expect(button('cancel')?.disabled).toBe(true)
    expect(button('submit')?.getAttribute('aria-disabled')).toBe('true')
    expect(input('native-name').disabled).toBe(true)
    document.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
    )
    el('modal-close')?.click()
    await nextTick()
    expect(h.window.opened.get()).toBe(true)
    button('submit')?.click()
    expect(h.dispatched).toHaveLength(1)
    h.dispatched[0]?.settle({ message: 'Language saved.' })
    await settled()
    expect(h.window.opened.get()).toBe(false)
    expect(el('i18n-language-edit-window')).toBeNull()
  })

  it('explains an own language, keeps a refusal inline, and clears it on reopening', async () => {
    const h = harness({ ...known, isOwn: true })
    await nextTick()
    expect(el('i18n-language-edit-plate')?.getAttribute('data-kind')).toBe(
      'own',
    )
    expect(el('i18n-language-edit-plate')?.textContent).toContain(
      'It is your own language: nothing refreshes it',
    )
    await name('My name')
    button('submit')?.click()
    h.dispatched[0]?.refuse(
      new ActionError(
        HILOS_I18N_LANGUAGE_ROW_UPDATE_ACTION,
        'fail',
        "Language 'uk' is switched on: switch it off, edit it, switch it on",
      ),
    )
    await settled()
    expect(el('i18n-language-edit-error')?.textContent).toContain(
      "Language 'uk' is switched on",
    )
    expect(h.window.opened.get()).toBe(true)
    h.window.close()
    await nextTick()
    h.window.open('19')
    await nextTick()
    expect(el('i18n-language-edit-error')?.textContent).not.toContain(
      "Language 'uk' is switched on",
    )
  })

  it('locks a switched-on edit and preserves the draft through deletion', async () => {
    const h = harness()
    await nextTick()
    await name('My text to copy')
    h.focused.set({ ...known, enabled: true })
    await nextTick()
    expect(input('native-name').disabled).toBe(true)
    expect(input('rtl').disabled).toBe(true)
    expect(input('native-name').value).toBe('My text to copy')
    expect(button('submit')?.disabled).toBe(true)
    expect(el('i18n-language-edit-plate')?.getAttribute('data-kind')).toBe('on')
    expect(el('i18n-language-edit-notice')?.textContent).toContain(
      'Switched on elsewhere just now.',
    )
    h.focused.set(undefined)
    await nextTick()
    expect(el('i18n-language-edit-notice')?.textContent).toContain(
      'Deleted elsewhere — your text stays to copy.',
    )
    expect(button('submit')?.textContent).toBe('Deleted')
    expect(input('native-name').value).toBe('My text to copy')
  })

  it('shows both conflict choices and keeps the title taken on opening', async () => {
    const h = harness()
    await nextTick()
    await name('Mine')
    h.focused.set({ ...known, nativeName: 'Theirs' })
    await nextTick()
    expect(el('i18n-language-edit-notice')?.textContent).toContain(
      'Native name changed elsewhere to "Theirs".',
    )
    expect(el('i18n-language-edit-title')?.textContent).toContain('Українська')
    expect(el('conflict-badge')).not.toBeNull()
    expect(button('submit')?.disabled).toBe(true)
    el('conflict-accept-mine')?.click()
    await nextTick()
    expect(input('native-name').value).toBe('Mine')
    expect(el('conflict-badge')).toBeNull()
    h.focused.set({ ...known, nativeName: 'New value' })
    await nextTick()
    el('conflict-accept-theirs')?.click()
    await nextTick()
    expect(input('native-name').value).toBe('New value')
    expect(el('conflict-badge')).toBeNull()
  })

  it('keeps an enabled language in view after switch-off, with live fields and only Close', async () => {
    const h = harness({ ...known, enabled: true })
    await nextTick()
    expect(el('i18n-language-edit-title')?.textContent).toContain(
      'Language · Українська',
    )
    for (const field of ['code', 'native-name', 'rtl'] as const)
      expect(input(field).disabled).toBe(true)
    expect(button('submit')).toBeNull()
    expect(button('cancel')).toBeNull()
    expect(button('close')).not.toBeNull()
    expect(el('i18n-language-edit-plate')?.getAttribute('data-kind')).toBe('on')
    expect(el('i18n-language-edit-plate')?.textContent).toContain(
      'switch it off, edit it and switch it back on',
    )
    h.focused.set({ ...known, nativeName: 'Changed live', rtl: true })
    await nextTick()
    expect(input('native-name').value).toBe('Changed live')
    expect(input('rtl').checked).toBe(true)
    expect(input('native-name').disabled).toBe(true)
    expect(el('i18n-language-edit-notice')?.textContent).toContain(
      'Switched off elsewhere just now.',
    )
    expect(button('submit')).toBeNull()
    button('close')?.click()
    await nextTick()
    expect(h.window.mode.get()).toBeNull()
  })

  it('lets a viewer read and change the draft while Save stays disabled', async () => {
    const h = harness(known, true)
    await nextTick()
    await name('Viewer draft')
    expect(h.window.canSave.get()).toBe(true)
    expect(button('submit')?.disabled).toBe(true)
    expect(input('native-name').disabled).toBe(false)
    expect(button('cancel')?.disabled).toBe(false)
    button('submit')?.click()
    expect(h.dispatched).toHaveLength(0)
  })
})
