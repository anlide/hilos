import { describe, expect, it, vi } from 'vitest'

import {
  type HilosI18nLanguageCard,
  type HilosI18nLanguageContext,
} from '../../../src/admin/i18n/hilosI18nLanguage.js'
import { type HilosI18nLanguageRow } from '../../../src/admin/i18n/hilosI18nLanguages.js'
import {
  HILOS_I18N_LANGUAGE_ROW_UPDATE_ACTION,
  HILOS_I18N_LANGUAGE_UPDATE_ACTION,
  HILOS_I18N_LANGUAGE_WINDOW_COPY,
  createHilosI18nLanguageCardWindow,
  createHilosI18nLanguageRowWindow,
} from '../../../src/admin/i18n/hilosI18nLanguageWindow.js'
import {
  type ActionHandle,
  type ActionLifecycle,
} from '../../../src/connection/actionLifecycle.js'
import { createSignal } from '../../../src/state/signal.js'
import { type TableViewportController } from '../../../src/table/TableViewportController.js'

const original: HilosI18nLanguageRow = {
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
}
const originalCard: HilosI18nLanguageCard = {
  languageId: original.languageId,
  code: original.code,
  nativeName: original.nativeName,
  rtl: original.rtl,
  enabled: original.enabled,
  summary: {
    isDefault: false,
    isOwn: true,
    localeCount: 0,
    nameCount: 0,
    countrylessLocale: 'missing',
    canDelete: true,
    deleteReason: null,
  },
}

function setup(row = original) {
  const focused = createSignal<HilosI18nLanguageRow | undefined>(undefined)
  const releaseFocus = vi.fn(() => focused.set(undefined))
  const controller = {
    focusRow: (key: string) => {
      if (key !== row.rowKey) return null
      focused.set(row)
      return row
    },
    focusedRow: focused,
    releaseFocus,
  } as unknown as TableViewportController<HilosI18nLanguageRow>
  const dispatch = vi.fn(() => ({}) as ActionHandle)
  const context = {
    actions: { dispatch } as unknown as ActionLifecycle,
  } as HilosI18nLanguageContext
  const window = createHilosI18nLanguageRowWindow(controller, context)
  window.start()
  return { window, focused, context, dispatch, releaseFocus }
}

describe('language window core', () => {
  it('keeps the draft and refuses save when a card is replaced under the same code', async () => {
    const h = setup()
    const card = createSignal<HilosI18nLanguageCard | null>(originalCard)
    const window = createHilosI18nLanguageCardWindow(card, h.context)
    window.start()
    expect(window.open()).toBe(true)
    window.patchForm({ nativeName: 'My draft' })
    card.set({ ...originalCard, languageId: originalCard.languageId + 1 })
    expect(window.state.get().gone).toBe(true)
    expect(window.form.get().nativeName).toBe('My draft')
    expect(window.canSave.get()).toBe(false)
    await window.save(async () => true)
    expect(h.dispatch).not.toHaveBeenCalled()
    window.dispose()
  })

  it('opens from the fresh row, fixes its mode, and clears the mode on close', () => {
    const h = setup()
    expect(h.window.mode.get()).toBeNull()
    expect(h.window.open('missing')).toBe(false)
    expect(h.window.open('19')).toBe(true)
    expect(h.window.mode.get()).toBe('edit')
    expect(h.window.plate.get()).toBe('known')
    h.focused.set({ ...original, enabled: true })
    expect(h.window.mode.get()).toBe('edit')
    h.window.close()
    expect(h.window.mode.get()).toBeNull()
    expect(h.window.notice.get()).toBeNull()
    expect(h.releaseFocus).toHaveBeenCalledOnce()
    h.window.dispose()

    const on = setup({ ...original, enabled: true })
    on.window.open('19')
    expect(on.window.mode.get()).toBe('view')
    expect(on.window.frozen.get()).toBe(true)
    expect(on.window.plate.get()).toBe('on')
    expect(on.window.canSave.get()).toBe(false)
    on.focused.set({ ...original, nativeName: 'Live name' })
    expect(on.window.mode.get()).toBe('view')
    expect(on.window.frozen.get()).toBe(true)
    expect(on.window.form.get().nativeName).toBe('Live name')
    expect(on.window.notice.get()).toEqual({
      kind: 'updated',
      text: 'Switched off elsewhere just now.',
    })
    on.window.dispose()
  })

  it('sends trimmed fields through the row action and closes only on success', async () => {
    const h = setup()
    h.window.open('19')
    expect(h.window.canSave.get()).toBe(false)
    h.window.patchForm({ nativeName: '  My name  ', rtl: true })
    expect(h.window.canSave.get()).toBe(true)
    await h.window.save(() => Promise.resolve(false))
    expect(h.dispatch).toHaveBeenCalledWith(
      HILOS_I18N_LANGUAGE_ROW_UPDATE_ACTION,
      { languageId: 19, nativeName: 'My name', rtl: true },
    )
    expect(h.window.opened.get()).toBe(true)
    await h.window.save(() => Promise.resolve(true))
    expect(h.window.opened.get()).toBe(false)
    expect(h.window.mode.get()).toBeNull()
    h.window.dispose()
  })

  it('maps the card summary and dispatches the card action', async () => {
    const h = setup()
    const card = createSignal<HilosI18nLanguageCard | null>(originalCard)
    const window = createHilosI18nLanguageCardWindow(card, h.context)
    window.start()
    expect(window.open()).toBe(true)
    expect(window.row.get()).toEqual({
      languageId: 19,
      code: 'uk',
      nativeName: 'Українська',
      rtl: false,
      enabled: false,
      isOwn: true,
    })
    expect(window.plate.get()).toBe('own')
    window.patchForm({ nativeName: '  Own name  ', rtl: true })
    await window.save(() => Promise.resolve(true))
    expect(h.dispatch).toHaveBeenCalledWith(HILOS_I18N_LANGUAGE_UPDATE_ACTION, {
      languageId: 19,
      nativeName: 'Own name',
      rtl: true,
    })
    expect(window.mode.get()).toBeNull()
    card.set(null)
    expect(window.open()).toBe(false)
    window.dispose()
    h.window.dispose()
  })

  it('blocks blanks and overlong names, and closes an untouched form without dispatch', async () => {
    const h = setup()
    h.window.open('19')
    await h.window.save(vi.fn())
    expect(h.window.opened.get()).toBe(false)
    expect(h.dispatch).not.toHaveBeenCalled()
    h.window.open('19')
    for (const nativeName of ['   ', 'a'.repeat(65)]) {
      h.window.patchForm({ nativeName })
      expect(h.window.canSave.get()).toBe(false)
      await h.window.save(vi.fn())
      expect(h.dispatch).not.toHaveBeenCalled()
      expect(h.window.opened.get()).toBe(true)
    }
    h.window.patchForm({ nativeName: 'a'.repeat(64) })
    expect(h.window.canSave.get()).toBe(true)
    h.window.dispose()
  })

  it('freezes an edit switched on elsewhere and keeps the draft after deletion', () => {
    const h = setup()
    h.window.open('19')
    h.window.patchForm({ nativeName: 'My draft' })
    h.focused.set({ ...original, enabled: true })
    expect(h.window.frozen.get()).toBe(true)
    expect(h.window.plate.get()).toBe('on')
    expect(h.window.notice.get()).toEqual({
      kind: 'updated',
      text: 'Switched on elsewhere just now.',
    })
    expect(h.window.form.get().nativeName).toBe('My draft')
    expect(h.window.canSave.get()).toBe(false)
    h.focused.set(undefined)
    expect(h.window.notice.get()).toEqual({
      kind: 'deleted',
      text: 'Deleted elsewhere — your text stays to copy.',
    })
    expect(h.window.saveLabel.get()).toBe('Deleted')
    expect(h.window.form.get().nativeName).toBe('My draft')
    expect(h.window.opened.get()).toBe(true)
    h.window.dispose()
  })

  it('names the incoming conflicts, keeps mine or takes theirs, and merges matching edits silently', () => {
    const h = setup()
    h.window.open('19')
    h.window.patchForm({ nativeName: 'Mine' })
    h.focused.set({ ...original, nativeName: 'Theirs', rtl: true })
    expect(h.window.notice.get()).toEqual({
      kind: 'conflict',
      text: 'Native name changed elsewhere to "Theirs".',
    })
    expect(h.window.form.get().rtl).toBe(true)
    expect(h.window.canSave.get()).toBe(false)
    h.window.keepMine()
    expect(h.window.state.get().conflict).toBe(false)
    h.focused.set({ ...original, nativeName: 'New value', rtl: true })
    h.window.takeTheirs()
    expect(h.window.form.get().nativeName).toBe('New value')
    h.window.patchForm({ nativeName: 'Same' })
    h.focused.set({ ...original, nativeName: 'Same', rtl: true })
    expect(h.window.state.get().conflict).toBe(false)
    expect(h.window.notice.get()?.kind).not.toBe('conflict')
    expect(HILOS_I18N_LANGUAGE_WINDOW_COPY.conflict.rtl(true)).toBe(
      'Writing direction changed elsewhere to right to left.',
    )
    expect(HILOS_I18N_LANGUAGE_WINDOW_COPY.conflict.rtl(false)).toBe(
      'Writing direction changed elsewhere to left to right.',
    )
    h.window.dispose()
  })

  it('does not mistake its save echo for a switch elsewhere or dispatch twice', async () => {
    const h = setup()
    h.window.open('19')
    h.window.patchForm({ nativeName: 'Mine' })
    let finish: (saved: boolean) => void = () => {}
    const pending = h.window.save(
      () =>
        new Promise<boolean>((resolve) => {
          finish = resolve
        }),
    )
    expect(h.window.saving.get()).toBe(true)
    expect(h.window.canSave.get()).toBe(false)
    expect(h.window.open('19')).toBe(false)
    h.focused.set({ ...original, nativeName: 'Mine', enabled: true })
    expect(h.window.frozen.get()).toBe(false)
    expect(h.window.notice.get()?.text).not.toBe(
      'Switched on elsewhere just now.',
    )
    await h.window.save(vi.fn())
    expect(h.dispatch).toHaveBeenCalledOnce()
    finish(true)
    await pending
    expect(h.window.mode.get()).toBeNull()
    h.window.dispose()
  })
})
