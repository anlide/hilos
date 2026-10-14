import { describe, expect, it, vi } from 'vitest'

import {
  type HilosI18nLanguageCard,
  type HilosI18nLanguageContext,
} from '../../../src/admin/i18n/hilosI18nLanguage.js'
import { createHilosI18nLanguageNameWindow } from '../../../src/admin/i18n/hilosI18nLanguageNameWindow.js'
import {
  HILOS_I18N_LANGUAGE_NAMES,
  type HilosI18nNameRow,
  resolveHilosI18nNameRow,
} from '../../../src/admin/i18n/hilosI18nNames.js'
import {
  type ActionHandle,
  type ActionLifecycle,
} from '../../../src/connection/actionLifecycle.js'
import { createSignal } from '../../../src/state/signal.js'
import { TableViewportController } from '../../../src/table/TableViewportController.js'

const original: HilosI18nNameRow = {
  code: 'qw',
  nativeName: 'Writing',
  name: 'Original',
  corrections: [
    {
      localeCode: 'qw-GB',
      countryCode: 'gb',
      countryName: 'UK',
      name: 'Correction',
    },
  ],
}
const originalCard: HilosI18nLanguageCard = {
  code: 'qx',
  nativeName: 'Subject',
  rtl: false,
  enabled: true,
  summary: {
    isDefault: false,
    isOwn: true,
    localeCount: 0,
    nameCount: 1,
    countrylessLocale: 'missing',
    canDelete: false,
    deleteReason: 'names',
  },
}
function harness(row = original) {
  const sendFocus = vi.fn()
  const controller = new TableViewportController<HilosI18nNameRow>({
    resolve: resolveHilosI18nNameRow,
    sendViewport: vi.fn(),
    sendFocus,
    frame: HILOS_I18N_LANGUAGE_NAMES.frame,
  })
  controller.ingestWindow(
    [{ rowKey: row.code, slots: { language: row } }],
    1,
    true,
    null,
    null,
    100,
  )
  const card = createSignal<HilosI18nLanguageCard | null>(originalCard)
  const language = createSignal('qx')
  const dispatch = vi.fn(() => ({}) as ActionHandle)
  const context = {
    actions: { dispatch } as unknown as ActionLifecycle,
  } as HilosI18nLanguageContext
  const window = createHilosI18nLanguageNameWindow(
    controller,
    card,
    language,
    context,
  )
  window.start()
  function update(next: HilosI18nNameRow) {
    controller.ingestDelta({
      kind: 'row_updated',
      rowKey: next.code,
      row: { rowKey: next.code, slots: { language: next } },
    })
  }
  return { window, controller, sendFocus, card, language, dispatch, update }
}

function pending() {
  let finish: (saved: boolean) => void = () => {}
  const run = () =>
    new Promise<boolean>((resolve) => {
      finish = resolve
    })
  return { run, finish: (saved: boolean) => finish(saved) }
}

describe('language name window', () => {
  it.each([null, '', 'Stored'])(
    'takes a fresh base name %s and fixes its coordinates and mode',
    (name) => {
      const h = harness()
      h.update({ ...original, name, nativeName: 'Fresh writing name' })
      expect(h.window.open('missing')).toBe(false)
      expect(h.window.open('qw')).toBe(true)
      expect(h.sendFocus).toHaveBeenLastCalledWith('qw')
      expect(h.window.mode.get()).toBe(name === null ? 'add' : 'edit')
      expect(h.window.form.get()).toEqual({ name: name ?? '' })
      expect(h.window.target.get()).toMatchObject({
        languageCode: 'qx',
        languageNativeName: 'Subject',
        inLanguageCode: 'qw',
        inLanguageNativeName: 'Fresh writing name',
        localeCode: null,
      })
      h.card.set({ ...originalCard, nativeName: 'Renamed subject' })
      h.update({ ...original, name, nativeName: 'Renamed writing language' })
      expect(h.window.target.get()?.languageNativeName).toBe('Subject')
      expect(h.window.target.get()?.inLanguageNativeName).toBe(
        'Fresh writing name',
      )
      h.window.close()
      expect(h.controller.focusedRow.get()).toBeUndefined()
      expect(h.window.mode.get()).toBeNull()
      h.window.dispose()
    },
  )

  it.each([null, '', 'Stored correction'])(
    'selects correction mode by null, including under an empty base (%s)',
    (name) => {
      const h = harness({
        ...original,
        name: '',
        corrections: [{ ...original.corrections[0]!, name }],
      })
      expect(h.window.open('qw', 'qw-GB')).toBe(true)
      expect(h.window.mode.get()).toBe(name === null ? 'add' : 'edit')
      expect(h.window.form.get().name).toBe(name ?? '')
      expect(h.window.target.get()?.localeCode).toBe('qw-GB')
      h.window.dispose()
    },
  )

  it('refuses missing coordinates without leaving focus held', () => {
    const h = harness({ ...original, name: null })
    expect(h.window.open('qw', 'qw-GB')).toBe(false)
    h.update(original)
    for (const locale of ['qw', '', 'qw-US'])
      expect(h.window.open('qw', locale)).toBe(false)
    h.card.set(null)
    expect(h.window.open('qw')).toBe(false)
    h.card.set({ ...originalCard, code: 'other' })
    expect(h.window.open('qw')).toBe(false)
    expect(h.controller.focusedRow.get()).toBeUndefined()
    h.window.dispose()
  })

  it.each([null, 'qw-GB'])(
    'creates an empty value at %s, keeps add on a late duplicate, and preserves literal text',
    async (localeCode) => {
      const h = harness({
        ...original,
        name: localeCode === null ? null : '',
        corrections: [{ ...original.corrections[0]!, name: null }],
      })
      h.window.open('qw', localeCode)
      expect(h.window.canSubmit.get()).toBe(true)
      expect(h.window.dirty.get()).toBe(false)
      await h.window.save(() => Promise.resolve(false))
      expect(h.dispatch).toHaveBeenLastCalledWith(
        'hilos_i18n_language_name_add',
        { languageCode: 'qx', inLanguageCode: 'qw', localeCode, name: '' },
      )
      h.window.patchForm({ name: '  My value  ' })
      h.update(original)
      expect(h.window.mode.get()).toBe('add')
      expect(h.window.canSubmit.get()).toBe(true)
      expect(h.window.form.get().name).toBe('  My value  ')
      await h.window.save(() => Promise.resolve(false))
      expect(h.window.opened.get()).toBe(true)
      expect(h.dispatch).toHaveBeenLastCalledWith(
        'hilos_i18n_language_name_add',
        {
          languageCode: 'qx',
          inLanguageCode: 'qw',
          localeCode,
          name: '  My value  ',
        },
      )
      await h.window.save(() => Promise.resolve(true))
      expect(h.window.opened.get()).toBe(false)
      expect(h.controller.focusedRow.get()).toBeUndefined()
      h.window.dispose()
    },
  )

  it.each([null, 'qw-GB'])(
    'edits stored empty text literally at %s and refuses unchanged dispatch',
    async (localeCode) => {
      const h = harness({
        ...original,
        name: '',
        corrections: [{ ...original.corrections[0]!, name: '' }],
      })
      h.window.open('qw', localeCode)
      expect(h.window.canSave.get()).toBe(false)
      await h.window.save(vi.fn())
      expect(h.dispatch).not.toHaveBeenCalled()
      expect(h.window.opened.get()).toBe(true)
      h.window.patchForm({ name: '   ' })
      expect(h.window.canSave.get()).toBe(true)
      await h.window.save(() => Promise.resolve(false))
      expect(h.dispatch).toHaveBeenCalledWith(
        'hilos_i18n_language_name_update',
        {
          languageCode: 'qx',
          inLanguageCode: 'qw',
          localeCode,
          name: '   ',
          expectedName: '',
        },
      )
      expect(h.window.form.get().name).toBe('   ')
      h.window.dispose()
    },
  )

  it.each([null, 'qw-GB'])(
    'sends the live expectedName after server-only merge, Keep mine and Take theirs at %s',
    async (localeCode) => {
      const h = harness()
      const change = (name: string) =>
        h.update(
          localeCode === null
            ? { ...original, name }
            : {
                ...original,
                corrections: [{ ...original.corrections[0]!, name }],
              },
        )
      h.window.open('qw', localeCode)
      change('Server only')
      expect(h.window.form.get().name).toBe('Server only')
      expect(h.window.notice.get()?.kind).toBe('updated')
      h.window.patchForm({ name: 'Mine' })
      await h.window.save(() => Promise.resolve(false))
      expect(h.dispatch).toHaveBeenLastCalledWith(
        'hilos_i18n_language_name_update',
        {
          languageCode: 'qx',
          inLanguageCode: 'qw',
          localeCode,
          name: 'Mine',
          expectedName: 'Server only',
        },
      )
      change('Theirs')
      expect(h.window.notice.get()).toEqual({
        kind: 'conflict',
        text: 'Name changed elsewhere to "Theirs".',
      })
      await h.window.save(vi.fn())
      expect(h.dispatch).toHaveBeenCalledTimes(1)
      h.window.keepMine()
      expect(h.window.canSave.get()).toBe(true)
      await h.window.save(() => Promise.resolve(false))
      expect(h.dispatch).toHaveBeenLastCalledWith(
        'hilos_i18n_language_name_update',
        {
          languageCode: 'qx',
          inLanguageCode: 'qw',
          localeCode,
          name: 'Mine',
          expectedName: 'Theirs',
        },
      )
      change('Another')
      h.window.takeTheirs()
      expect(h.window.form.get().name).toBe('Another')
      expect(h.window.canSave.get()).toBe(false)
      h.window.patchForm({ name: '' })
      await h.window.save(() => Promise.resolve(true))
      expect(h.dispatch).toHaveBeenLastCalledWith(
        'hilos_i18n_language_name_update',
        {
          languageCode: 'qx',
          inLanguageCode: 'qw',
          localeCode,
          name: '',
          expectedName: 'Another',
        },
      )
      expect(h.window.opened.get()).toBe(false)
      h.window.dispose()
    },
  )

  it.each(['name', 'base', 'locale', 'row', 'subject'])(
    'keeps the draft with Deleted after losing %s',
    async (lost) => {
      const h = harness()
      h.window.open('qw', 'qw-GB')
      h.window.patchForm({ name: 'Copy this' })
      if (lost === 'name')
        h.update({
          ...original,
          corrections: [{ ...original.corrections[0]!, name: null }],
        })
      if (lost === 'base') h.update({ ...original, name: null })
      if (lost === 'locale') h.update({ ...original, corrections: [] })
      if (lost === 'row')
        h.controller.ingestDelta({
          kind: 'row_removed',
          rowKey: 'qw',
          reason: 'deleted',
        })
      if (lost === 'subject') h.card.set(null)
      expect(h.window.form.get().name).toBe('Copy this')
      expect(h.window.opened.get()).toBe(true)
      expect(h.window.canSave.get()).toBe(false)
      expect(h.window.saveLabel.get()).toBe('Deleted')
      expect(h.window.notice.get()?.kind).toBe('deleted')
      await h.window.save(vi.fn())
      expect(h.dispatch).not.toHaveBeenCalled()
      h.window.dispose()
    },
  )

  it.each(['base', 'locale', 'subject'])(
    'blocks creation when its %s disappears',
    async (lost) => {
      const h = harness({
        ...original,
        corrections: [{ ...original.corrections[0]!, name: null }],
      })
      h.window.open('qw', 'qw-GB')
      h.window.patchForm({ name: 'Copy add' })
      if (lost === 'base') h.update({ ...original, name: null })
      if (lost === 'locale') h.update({ ...original, corrections: [] })
      if (lost === 'subject') h.card.set(null)
      expect(h.window.canSubmit.get()).toBe(false)
      expect(h.window.saveLabel.get()).toBe('Deleted')
      expect(h.window.form.get().name).toBe('Copy add')
      await h.window.save(vi.fn())
      expect(h.dispatch).not.toHaveBeenCalled()
      h.window.dispose()
    },
  )

  it.each([null, 'Original'])(
    'guards busy close/reentry and ignores an old route ack (%s)',
    async (name) => {
      const h = harness({ ...original, name })
      h.window.open('qw')
      h.window.patchForm({ name: 'Mine' })
      const p = pending()
      const saved = h.window.save(p.run)
      expect(h.window.saving.get()).toBe(true)
      h.window.close()
      expect(h.window.opened.get()).toBe(true)
      expect(h.window.open('qw')).toBe(false)
      await h.window.save(vi.fn())
      expect(h.dispatch).toHaveBeenCalledOnce()
      h.language.set('qz')
      expect(h.window.opened.get()).toBe(false)
      expect(h.controller.focusedRow.get()).toBeUndefined()
      h.card.set({ ...originalCard, code: 'qz' })
      h.window.open('qw')
      h.window.patchForm({ name: 'New draft' })
      const next = pending()
      const newSave = h.window.save(next.run)
      p.finish(true)
      await saved
      expect(h.window.opened.get()).toBe(true)
      expect(h.window.saving.get()).toBe(true)
      expect(h.window.form.get().name).toBe('New draft')
      expect(h.controller.focusedRow.get()?.code).toBe('qw')
      next.finish(false)
      await newSave
      expect(h.window.saving.get()).toBe(false)
      h.window.dispose()
      expect(h.controller.focusedRow.get()).toBeUndefined()
    },
  )

  it('does not release another dialog focus when the already closed window disposes', () => {
    const h = harness()
    h.window.open('qw')
    h.window.close()
    h.controller.focusRow('qw')
    h.window.dispose()
    expect(h.controller.focusedRow.get()?.code).toBe('qw')
  })
})
