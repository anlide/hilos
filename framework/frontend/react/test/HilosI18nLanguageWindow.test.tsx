import { act, fireEvent } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import {
  createHilosI18nLanguageRowWindow,
  createSignal,
  type HilosI18nLanguageRow,
  type TableViewportController,
} from '@hilos/core'
import { HilosI18nLanguageWindow } from '../src/admin/i18n/details/HilosI18nLanguageWindow.js'
import { HilosAdminViewModeContext } from '../src/hilosLookOnly.js'
import {
  harness,
  language,
  el,
  click,
  change,
  settle,
} from './hilosI18nHarness.js'

function setup(row = language, viewer = false) {
  const h = harness()
  const focused = createSignal<HilosI18nLanguageRow | undefined>(undefined)
  let releases = 0
  const controller = {
    focusedRow: focused,
    focusRow: () => {
      focused.set(row)
      return row
    },
    releaseFocus: () => {
      releases++
      focused.set(undefined)
    },
  } as unknown as TableViewportController<HilosI18nLanguageRow>
  const window = createHilosI18nLanguageRowWindow(controller, h.context)
  const view = h.mount(
    <HilosAdminViewModeContext.Provider value={viewer}>
      <button
        type="button"
        data-id="open-edit"
        onClick={() => window.open(row.rowKey)}
      >
        Open language
      </button>
      <HilosI18nLanguageWindow window={window} />
    </HilosAdminViewModeContext.Provider>,
  )
  el('open-edit').focus()
  act(() => window.open(row.rowKey))
  return { ...h, window, focused, view, releases: () => releases }
}

describe('React language edit/view window', () => {
  it('fixes code, refuses unchanged Enter, trims an edit and keeps its own echo pending until ack', async () => {
    const h = setup()
    expect(document.activeElement?.getAttribute('role')).toBe('dialog')
    expect(el<HTMLInputElement>('i18n-language-edit-code').disabled).toBe(true)
    expect(
      el<HTMLInputElement>('i18n-language-edit-native-name').maxLength,
    ).toBe(64)
    expect(el<HTMLButtonElement>('i18n-language-edit-submit').disabled).toBe(
      true,
    )
    fireEvent.submit(document.querySelector('form')!)
    expect(h.calls).toHaveLength(0)
    act(() => h.window.open('19'))
    change('i18n-language-edit-native-name', '  Mine  ')
    fireEvent.click(el('i18n-language-edit-rtl'))
    fireEvent.submit(document.querySelector('form')!)
    fireEvent.submit(document.querySelector('form')!)
    expect(h.calls).toHaveLength(1)
    expect(h.calls[0]).toMatchObject({
      action: 'hilos_i18n_language_row_update',
      payload: { languageId: 19, nativeName: 'Mine', rtl: true },
    })
    act(() => h.focused.set({ ...language, nativeName: 'Mine', rtl: true }))
    click('modal-close')
    fireEvent.keyDown(el('modal'), { key: 'Escape' })
    expect(h.window.opened.get()).toBe(true)
    expect(el<HTMLButtonElement>('i18n-language-edit-cancel').disabled).toBe(
      true,
    )
    await settle(() => h.succeed())
    expect(h.window.opened.get()).toBe(false)
    expect(h.releases()).toBeGreaterThan(0)
  })
  it('draws the catalog and own explanations, keeps refusal and clears it on reopening', async () => {
    const h = setup({ ...language, isOwn: true })
    expect(el('i18n-language-edit-plate').getAttribute('data-kind')).toBe('own')
    expect(el('i18n-language-edit-plate').textContent).toContain(
      'nothing refreshes it',
    )
    change('i18n-language-edit-native-name', 'Mine')
    click('i18n-language-edit-submit')
    await settle(() => h.refuse())
    expect(h.window.opened.get()).toBe(true)
    expect(el('i18n-language-edit-error').textContent).toContain(
      'Server refused',
    )
    act(() => h.window.close())
    act(() => h.window.open('19'))
    expect(el('i18n-language-edit-error').textContent).not.toContain(
      'Server refused',
    )
  })
  it('takes untouched incoming fields, offers both conflict choices, and keeps the opening title', () => {
    const h = setup()
    act(() => h.focused.set({ ...language, rtl: true }))
    expect(el<HTMLInputElement>('i18n-language-edit-rtl').checked).toBe(true)
    expect(el('i18n-language-edit-notice').textContent).toContain(
      'Updated just now',
    )
    change('i18n-language-edit-native-name', 'Mine')
    act(() => h.focused.set({ ...language, rtl: true, nativeName: 'Theirs' }))
    expect(el('i18n-language-edit-title').textContent).toContain('Українська')
    expect(el('i18n-language-edit-notice').textContent).toContain(
      'Native name changed elsewhere',
    )
    expect(el<HTMLButtonElement>('i18n-language-edit-submit').disabled).toBe(
      true,
    )
    expect(el('conflict-merge')).toBeNull()
    click('conflict-accept-mine')
    expect(el<HTMLInputElement>('i18n-language-edit-native-name').value).toBe(
      'Mine',
    )
    expect(el<HTMLButtonElement>('i18n-language-edit-submit').disabled).toBe(
      false,
    )
    act(() => h.focused.set({ ...language, rtl: true, nativeName: 'Third' }))
    click('conflict-accept-theirs')
    expect(el<HTMLInputElement>('i18n-language-edit-native-name').value).toBe(
      'Third',
    )
    expect(el<HTMLButtonElement>('i18n-language-edit-submit').disabled).toBe(
      true,
    )
  })
  it('keeps a frozen and then deleted draft extractable, with no dispatch', () => {
    const h = setup()
    expect(el('i18n-language-edit-plate').getAttribute('data-kind')).toBe(
      'known',
    )
    change('i18n-language-edit-native-name', 'Unsaved text')
    act(() => h.focused.set({ ...language, enabled: true }))
    expect(el<HTMLInputElement>('i18n-language-edit-native-name').value).toBe(
      'Unsaved text',
    )
    expect(el('i18n-language-edit-notice').textContent).toContain(
      'Switched on elsewhere',
    )
    act(() => h.focused.set(undefined))
    expect(el<HTMLInputElement>('i18n-language-edit-native-name').value).toBe(
      'Unsaved text',
    )
    expect(el('i18n-language-edit-submit').textContent).toBe('Deleted')
    fireEvent.submit(document.querySelector('form')!)
    expect(h.calls).toHaveLength(0)
    h.view.unmount()
    expect(h.releases()).toBeGreaterThan(0)
  })
  it('keeps an enabled language in view after external switch-off', () => {
    const h = setup({ ...language, enabled: true })
    expect(el('i18n-language-edit-submit')).toBeNull()
    act(() => h.focused.set({ ...language, nativeName: 'Live name' }))
    expect(el<HTMLInputElement>('i18n-language-edit-native-name').value).toBe(
      'Live name',
    )
    expect(
      el<HTMLInputElement>('i18n-language-edit-native-name').disabled,
    ).toBe(true)
    expect(el('i18n-language-edit-notice').textContent).toContain(
      'Switched off elsewhere',
    )
    click('i18n-language-edit-close')
    expect(el('modal')).toBeNull()
  })
  it('confirms dirty Escape and returns focus to the opener', () => {
    const h = setup()
    change('i18n-language-edit-native-name', 'Mine')
    fireEvent.keyDown(el('modal'), { key: 'Escape' })
    expect(el('modal-confirm-discard')).not.toBeNull()
    click('modal-confirm-discard')
    expect(el('modal')).toBeNull()
    expect(document.activeElement).toBe(el('open-edit'))
    expect(h.calls).toEqual([])
  })
  it('blocks a viewer Save and Enter while allowing draft choices', () => {
    const h = setup(language, true)
    change('i18n-language-edit-native-name', 'Mine')
    expect(
      el<HTMLInputElement>('i18n-language-edit-native-name').disabled,
    ).toBe(false)
    expect(el<HTMLButtonElement>('i18n-language-edit-submit').disabled).toBe(
      true,
    )
    fireEvent.submit(document.querySelector('form')!)
    expect(h.calls).toEqual([])
  })
})
