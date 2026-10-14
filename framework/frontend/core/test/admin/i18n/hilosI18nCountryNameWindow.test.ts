import { describe, expect, it, vi } from 'vitest'

import {
  type HilosI18nCountryCard,
  type HilosI18nCountryContext,
} from '../../../src/admin/i18n/hilosI18nCountry.js'
import { createHilosI18nCountryNameWindow } from '../../../src/index.js'
import {
  HILOS_I18N_COUNTRY_NAMES,
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
const originalCard: HilosI18nCountryCard = {
  code: 'qx',
  currencySymbol: '$',
  currencyCode: 'USD',
  enabled: true,
  summary: {
    name: 'Subject country',
    isOwn: true,
    localeCount: 0,
    nameCount: 1,
    defaultLocaleCode: null,
    defaultLocale: 'missing',
    locales: [],
    catalogDefaultLocaleCode: null,
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
    frame: HILOS_I18N_COUNTRY_NAMES.frame,
  })
  controller.ingestWindow(
    [{ rowKey: row.code, slots: { language: row } }],
    1,
    true,
    null,
    null,
    100,
  )
  const card = createSignal<HilosI18nCountryCard | null>(originalCard)
  const country = createSignal('qx')
  const dispatch = vi.fn(() => ({}) as ActionHandle)
  const context = {
    actions: { dispatch } as unknown as ActionLifecycle,
  } as HilosI18nCountryContext
  const window = createHilosI18nCountryNameWindow(
    controller,
    card,
    country,
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
  return { window, controller, sendFocus, card, country, dispatch, update }
}

function pending() {
  let finish: (saved: boolean) => void = () => {}
  const run = () =>
    new Promise<boolean>((resolve) => {
      finish = resolve
    })
  return { run, finish: (saved: boolean) => finish(saved) }
}

describe('country name window', () => {
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
        countryCode: 'qx',
        countryName: 'Subject country',
        inLanguageCode: 'qw',
        inLanguageNativeName: 'Fresh writing name',
        localeCode: null,
      })
      h.card.set({
        ...originalCard,
        summary: { ...originalCard.summary, name: 'Renamed country' },
      })
      h.update({ ...original, name, nativeName: 'Renamed writing language' })
      expect(h.window.target.get()?.countryName).toBe('Subject country')
      expect(h.window.target.get()?.inLanguageNativeName).toBe(
        'Fresh writing name',
      )
      h.window.close()
      expect(h.controller.focusedRow.get()).toBeUndefined()
      expect(h.window.mode.get()).toBeNull()
      expect(h.sendFocus).toHaveBeenLastCalledWith('')
      expect(h.window.noticeKind.get()).toBeNull()
      expect(h.window.noticeText.get()).toBe('')
      h.window.dispose()
    },
  )

  it.each([null, '', 'Country label'])(
    'uses the country card display name %s without inventing a native name',
    (name) => {
      const h = harness()
      h.card.set({
        ...originalCard,
        summary: { ...originalCard.summary, name },
      })
      expect(h.window.open('qw')).toBe(true)
      expect(h.window.target.get()?.countryName).toBe(name ?? 'QX')
      h.window.dispose()
    },
  )

  it.each([null, 'Original'])(
    'allows the country and writing language to share a code (%s)',
    async (name) => {
      const h = harness({ ...original, name })
      h.country.set('qw')
      h.card.set({ ...originalCard, code: 'qw', enabled: false })
      expect(h.window.open('qw')).toBe(true)
      h.window.patchForm({ name: 'Same code, separate catalogs' })
      await h.window.save(() => Promise.resolve(true))
      expect(h.dispatch).toHaveBeenCalledWith(
        name === null
          ? 'hilos_i18n_country_name_add'
          : 'hilos_i18n_country_name_update',
        {
          countryCode: 'qw',
          inLanguageCode: 'qw',
          localeCode: null,
          name: 'Same code, separate catalogs',
          ...(name === null ? {} : { expectedName: name }),
        },
      )
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
        'hilos_i18n_country_name_add',
        { countryCode: 'qx', inLanguageCode: 'qw', localeCode, name: '' },
      )
      h.window.patchForm({ name: '  My value  ' })
      h.update(original)
      expect(h.window.mode.get()).toBe('add')
      expect(h.window.canSubmit.get()).toBe(true)
      expect(h.window.form.get().name).toBe('  My value  ')
      await h.window.save(() => Promise.resolve(false))
      expect(h.window.opened.get()).toBe(true)
      expect(h.dispatch).toHaveBeenLastCalledWith(
        'hilos_i18n_country_name_add',
        {
          countryCode: 'qx',
          inLanguageCode: 'qw',
          localeCode,
          name: '  My value  ',
        },
      )
      await h.window.save(() => Promise.resolve(true))
      expect(h.window.opened.get()).toBe(false)
      expect(h.controller.focusedRow.get()).toBeUndefined()
      expect(h.controller.rows.get()[0]?.row).toEqual(original)
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
        'hilos_i18n_country_name_update',
        {
          countryCode: 'qx',
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
      expect(h.window.noticeKind.get()).toBe('updated')
      h.window.patchForm({ name: 'Mine' })
      await h.window.save(() => Promise.resolve(false))
      expect(h.dispatch).toHaveBeenLastCalledWith(
        'hilos_i18n_country_name_update',
        {
          countryCode: 'qx',
          inLanguageCode: 'qw',
          localeCode,
          name: 'Mine',
          expectedName: 'Server only',
        },
      )
      change('Theirs')
      expect(h.window.noticeKind.get()).toBe('conflict')
      expect(h.window.noticeText.get()).toBe(
        'Name changed elsewhere to "Theirs".',
      )
      await h.window.save(vi.fn())
      expect(h.dispatch).toHaveBeenCalledTimes(1)
      h.window.keepMine()
      expect(h.window.canSave.get()).toBe(true)
      await h.window.save(() => Promise.resolve(false))
      expect(h.dispatch).toHaveBeenLastCalledWith(
        'hilos_i18n_country_name_update',
        {
          countryCode: 'qx',
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
        'hilos_i18n_country_name_update',
        {
          countryCode: 'qx',
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

  it.each(['merge', 'keep', 'take'])(
    'sends an empty expectedName after accounting for it through %s',
    async (choice) => {
      const h = harness()
      h.window.open('qw')
      if (choice !== 'merge') h.window.patchForm({ name: 'Mine' })
      h.update({ ...original, name: '' })
      if (choice === 'keep') h.window.keepMine()
      if (choice === 'take') h.window.takeTheirs()
      if (choice !== 'keep') expect(h.window.form.get().name).toBe('')
      h.window.patchForm({ name: 'Mine' })
      await h.window.save(() => Promise.resolve(false))
      expect(h.dispatch).toHaveBeenCalledWith(
        'hilos_i18n_country_name_update',
        {
          countryCode: 'qx',
          inLanguageCode: 'qw',
          localeCode: null,
          name: 'Mine',
          expectedName: '',
        },
      )
      h.window.dispose()
    },
  )

  it('keeps an equal-text replacement live without a synthetic version', async () => {
    const h = harness()
    h.window.open('qw')
    h.window.patchForm({ name: 'Mine' })
    h.controller.ingestWindow(
      [{ rowKey: 'qw', slots: { language: { ...original } } }],
      1,
      true,
      null,
      null,
      100,
    )
    expect(h.window.state.get().gone).toBe(false)
    expect(h.window.noticeKind.get()).toBeNull()
    await h.window.save(() => Promise.resolve(true))
    expect(h.dispatch).toHaveBeenCalledWith('hilos_i18n_country_name_update', {
      countryCode: 'qx',
      inLanguageCode: 'qw',
      localeCode: null,
      name: 'Mine',
      expectedName: 'Original',
    })
    h.window.dispose()
  })

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
      expect(h.window.noticeKind.get()).toBe('deleted')
      expect(h.window.noticeText.get()).toBe(
        'Deleted elsewhere — your text stays to copy.',
      )
      await h.window.save(vi.fn())
      expect(h.dispatch).not.toHaveBeenCalled()
      h.window.dispose()
    },
  )

  it.each(['base', 'locale', 'row', 'subject'])(
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
      if (lost === 'row')
        h.controller.ingestDelta({
          kind: 'row_removed',
          rowKey: 'qw',
          reason: 'deleted',
        })
      if (lost === 'subject') h.card.set(null)
      expect(h.window.canSubmit.get()).toBe(false)
      expect(h.window.saveLabel.get()).toBe('Deleted')
      expect(h.window.noticeKind.get()).toBe('deleted')
      expect(h.window.noticeText.get()).toBe(
        'Deleted elsewhere — your text stays to copy.',
      )
      expect(h.window.form.get().name).toBe('Copy add')
      await h.window.save(vi.fn())
      expect(h.dispatch).not.toHaveBeenCalled()
      h.window.dispose()
    },
  )

  it.each([null, 'Original'])(
    'keeps a base draft when its country or language disappears (%s)',
    async (name) => {
      const h = harness({ ...original, name })
      h.window.open('qw')
      h.window.patchForm({ name: 'Copy base' })
      h.card.set({ ...originalCard, code: 'qz' })
      expect(h.window.saveLabel.get()).toBe('Deleted')
      await h.window.save(vi.fn())
      h.card.set(originalCard)
      h.controller.ingestDelta({
        kind: 'row_removed',
        rowKey: 'qw',
        reason: 'deleted',
      })
      expect(h.window.saveLabel.get()).toBe('Deleted')
      expect(h.window.canSubmit.get()).toBe(false)
      expect(h.window.canSave.get()).toBe(false)
      expect(h.window.form.get().name).toBe('Copy base')
      expect(h.window.opened.get()).toBe(true)
      await h.window.save(vi.fn())
      expect(h.dispatch).not.toHaveBeenCalled()
      h.window.dispose()
    },
  )

  it('keeps an edited base draft when only the base name is removed', async () => {
    const h = harness()
    h.window.open('qw')
    h.window.patchForm({ name: 'Copy base' })
    h.update({ ...original, name: null, corrections: [] })
    expect(h.window.saveLabel.get()).toBe('Deleted')
    expect(h.window.form.get().name).toBe('Copy base')
    await h.window.save(vi.fn())
    expect(h.dispatch).not.toHaveBeenCalled()
    h.window.dispose()
  })

  it.each([null, 'Original'])(
    'preserves the draft and releases busy after a rejected runner (%s)',
    async (name) => {
      const h = harness({ ...original, name })
      h.window.open('qw')
      h.window.patchForm({ name: 'Retry this' })
      await expect(
        h.window.save(() => Promise.reject(new Error('Lost connection'))),
      ).rejects.toThrow('Lost connection')
      expect(h.window.saving.get()).toBe(false)
      expect(h.window.opened.get()).toBe(true)
      expect(h.window.form.get().name).toBe('Retry this')
      expect(h.controller.focusedRow.get()?.code).toBe('qw')
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
      h.country.set('qz')
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

  it.each([null, 'Original'])(
    'disposes a busy window and ignores its late reply (%s)',
    async (name) => {
      const h = harness({ ...original, name })
      h.window.start()
      h.window.open('qw')
      h.window.patchForm({ name: 'Mine' })
      const p = pending()
      const saved = h.window.save(p.run)
      h.window.dispose()
      expect(h.window.opened.get()).toBe(false)
      expect(h.window.saving.get()).toBe(false)
      expect(h.sendFocus.mock.calls).toEqual([['qw'], ['']])
      h.controller.focusRow('qw')
      h.country.set('qz')
      p.finish(true)
      await saved
      expect(h.window.opened.get()).toBe(false)
      expect(h.controller.focusedRow.get()?.code).toBe('qw')
      h.controller.releaseFocus()
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
