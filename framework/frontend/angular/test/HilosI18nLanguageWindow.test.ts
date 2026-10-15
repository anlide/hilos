// The Angular view/edit window of a language over the core row window: the code
// that never changes, one door for Save and Enter, its own echo held until the
// ack, refusals that stay, independent fields taken and a conflict offered as
// Keep mine / Take theirs, a draft that survives freezing and deletion, the
// dirty close, focus returned to the opener, a viewer of the admin view mode,
// and the row let go when the view ends. The merge itself is the core's.
import { signal } from '@angular/core'
import {
  createHilosI18nLanguageRowWindow,
  createSignal,
  type HilosI18nLanguageRow,
  type TableViewportController,
} from '@hilos/core'
import { describe, expect, it } from 'vitest'

import { HilosI18nLanguageWindow } from '../src/admin/i18n/details/HilosI18nLanguageWindow.js'
import { HILOS_ADMIN_VIEW_MODE } from '../src/hilosLookOnly.js'
import { i18nBench, language, mountOver } from './helpers/hilosI18n.js'

function setup(row: HilosI18nLanguageRow = language, viewer = false) {
  const bench = i18nBench()
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
  const window = createHilosI18nLanguageRowWindow(controller, bench.context)
  const opener = document.createElement('button')
  opener.dataset['id'] = 'open-edit'
  document.body.append(opener)
  const view = mountOver(HilosI18nLanguageWindow, bench, { window }, [
    { provide: HILOS_ADMIN_VIEW_MODE, useValue: signal(viewer).asReadonly() },
  ])
  opener.focus()
  view.act(() => window.open(row.code))

  return { ...bench, ...view, window, focused, releases: () => releases }
}

describe('HilosI18nLanguageWindow', () => {
  it('fixes the code, closes on an unchanged Enter, trims the edit and holds its own echo until the ack', async () => {
    const h = setup()
    expect(document.activeElement?.getAttribute('role')).toBe('dialog')
    expect(h.el<HTMLInputElement>('i18n-language-edit-code')?.disabled).toBe(
      true,
    )
    expect(
      h.el<HTMLInputElement>('i18n-language-edit-native-name')?.maxLength,
    ).toBe(64)
    expect(h.el<HTMLButtonElement>('i18n-language-edit-submit')?.disabled).toBe(
      true,
    )
    h.submit()
    expect(h.calls).toHaveLength(0)
    expect(h.el('modal')).toBeNull()
    h.act(() => h.window.open('uk'))
    h.type('i18n-language-edit-native-name', '  Mine  ')
    h.toggle('i18n-language-edit-rtl')
    h.submit()
    h.submit()
    expect(h.calls).toHaveLength(1)
    expect(h.calls[0]).toMatchObject({
      action: 'hilos_i18n_language_row_update',
      payload: { languageCode: 'uk', nativeName: 'Mine', rtl: true },
    })
    h.act(() => h.focused.set({ ...language, nativeName: 'Mine', rtl: true }))
    expect(h.el<HTMLButtonElement>('modal-close')?.disabled).toBe(true)
    h.escape()
    expect(h.window.opened.get()).toBe(true)
    expect(h.el<HTMLButtonElement>('i18n-language-edit-cancel')?.disabled).toBe(
      true,
    )
    await h.settle(() => h.succeed())
    expect(h.window.opened.get()).toBe(false)
    expect(h.el('modal')).toBeNull()
    expect(h.releases()).toBeGreaterThan(0)
  })

  it('draws the own-language plate, keeps a refusal and clears it on reopening', async () => {
    const h = setup({ ...language, isOwn: true })
    expect(h.el('i18n-language-edit-plate')?.getAttribute('data-kind')).toBe(
      'own',
    )
    expect(h.el('i18n-language-edit-plate')?.textContent).toContain(
      'nothing refreshes it',
    )
    h.type('i18n-language-edit-native-name', 'Mine')
    h.click('i18n-language-edit-submit')
    await h.settle(() => h.refuse())
    expect(h.window.opened.get()).toBe(true)
    expect(h.el('i18n-language-edit-error')?.textContent).toContain(
      'Server refused the language',
    )
    h.act(() => h.window.close())
    h.act(() => h.window.open('uk'))
    expect(h.el('i18n-language-edit-error')?.textContent).not.toContain(
      'Server refused',
    )
  })

  it('takes an untouched incoming field and offers both choices on a conflict, keeping the opening title', () => {
    const h = setup()
    h.act(() => h.focused.set({ ...language, rtl: true }))
    expect(h.el<HTMLInputElement>('i18n-language-edit-rtl')?.checked).toBe(true)
    expect(h.el('i18n-language-edit-notice')?.textContent).toContain(
      'Updated just now',
    )
    h.type('i18n-language-edit-native-name', 'Mine')
    h.act(() => h.focused.set({ ...language, rtl: true, nativeName: 'Theirs' }))
    expect(h.el('i18n-language-edit-title')?.textContent).toContain(
      'Українська',
    )
    expect(h.el('conflict-badge')).not.toBeNull()
    expect(h.el('i18n-language-edit-notice')?.textContent).toContain(
      'Native name changed elsewhere',
    )
    expect(h.el<HTMLButtonElement>('i18n-language-edit-submit')?.disabled).toBe(
      true,
    )
    expect(h.el('conflict-merge')).toBeNull()
    h.click('conflict-accept-mine')
    expect(
      h.el<HTMLInputElement>('i18n-language-edit-native-name')?.value,
    ).toBe('Mine')
    expect(h.el<HTMLButtonElement>('i18n-language-edit-submit')?.disabled).toBe(
      false,
    )
    h.act(() => h.focused.set({ ...language, rtl: true, nativeName: 'Third' }))
    h.click('conflict-accept-theirs')
    expect(
      h.el<HTMLInputElement>('i18n-language-edit-native-name')?.value,
    ).toBe('Third')
    expect(h.el<HTMLButtonElement>('i18n-language-edit-submit')?.disabled).toBe(
      true,
    )
  })

  it('keeps a draft extractable when the language is switched on and then deleted elsewhere', () => {
    const h = setup()
    expect(h.el('i18n-language-edit-plate')?.getAttribute('data-kind')).toBe(
      'known',
    )
    h.type('i18n-language-edit-native-name', 'Unsaved text')
    h.act(() => h.focused.set({ ...language, enabled: true }))
    expect(
      h.el<HTMLInputElement>('i18n-language-edit-native-name')?.value,
    ).toBe('Unsaved text')
    expect(
      h.el<HTMLInputElement>('i18n-language-edit-native-name')?.disabled,
    ).toBe(true)
    expect(h.el('i18n-language-edit-notice')?.textContent).toContain(
      'Switched on elsewhere',
    )
    h.act(() => h.focused.set(undefined))
    expect(
      h.el<HTMLInputElement>('i18n-language-edit-native-name')?.value,
    ).toBe('Unsaved text')
    expect(h.el('i18n-language-edit-submit')?.textContent?.trim()).toBe(
      'Deleted',
    )
    h.submit()
    expect(h.calls).toHaveLength(0)
    h.fixture.destroy()
    expect(h.releases()).toBeGreaterThan(0)
  })

  it('keeps a switched-on language in view after it is switched off elsewhere', () => {
    const h = setup({ ...language, enabled: true })
    expect(h.el('i18n-language-edit-submit')).toBeNull()
    expect(h.el('i18n-language-edit-plate')?.getAttribute('data-kind')).toBe(
      'on',
    )
    h.act(() => h.focused.set({ ...language, nativeName: 'Live name' }))
    expect(
      h.el<HTMLInputElement>('i18n-language-edit-native-name')?.value,
    ).toBe('Live name')
    expect(
      h.el<HTMLInputElement>('i18n-language-edit-native-name')?.disabled,
    ).toBe(true)
    expect(h.el('i18n-language-edit-notice')?.textContent).toContain(
      'Switched off elsewhere',
    )
    h.click('i18n-language-edit-close')
    expect(h.el('modal')).toBeNull()
  })

  it('confirms a dirty Escape and returns focus to the opener', () => {
    const h = setup()
    h.type('i18n-language-edit-native-name', 'Mine')
    h.escape()
    expect(h.el('modal-confirm-discard')).not.toBeNull()
    h.click('modal-confirm-discard')
    expect(h.el('modal')).toBeNull()
    expect(document.activeElement).toBe(h.el('open-edit'))
    expect(h.calls).toEqual([])
  })

  it('lets a viewer fill the form and resolve a conflict while Save and Enter send nothing', () => {
    const h = setup(language, true)
    h.type('i18n-language-edit-native-name', 'Mine')
    expect(
      h.el<HTMLInputElement>('i18n-language-edit-native-name')?.disabled,
    ).toBe(false)
    expect(h.el<HTMLButtonElement>('i18n-language-edit-submit')?.disabled).toBe(
      true,
    )
    h.submit()
    expect(h.calls).toEqual([])
    h.act(() => h.focused.set({ ...language, nativeName: 'Theirs' }))
    h.click('conflict-accept-theirs')
    expect(
      h.el<HTMLInputElement>('i18n-language-edit-native-name')?.value,
    ).toBe('Theirs')
    h.click('i18n-language-edit-cancel')
    expect(h.el('modal')).toBeNull()
  })

  it('disposes the bound session when another one takes its place', () => {
    const h = setup()
    const next = createHilosI18nLanguageRowWindow(
      {
        focusedRow: createSignal(undefined),
        focusRow: () => null,
        releaseFocus: () => {},
      } as unknown as TableViewportController<HilosI18nLanguageRow>,
      h.context,
    )
    h.fixture.componentRef.setInput('window', next)
    h.detect()
    expect(h.window.opened.get()).toBe(false)
    expect(h.releases()).toBeGreaterThan(0)
    expect(h.el('modal')).toBeNull()
  })
})
