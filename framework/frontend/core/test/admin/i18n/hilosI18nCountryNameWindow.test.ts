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
  inLanguageId: 47,
  nameId: 301,
  rowKey: '47',
  code: 'qw',
  nativeName: 'Writing',
  name: 'Original',
  corrections: [
    {
      localeId: 501,
      nameId: 401,
      countryId: 101,
      localeCode: 'qw-GB',
      countryCode: 'gb',
      countryName: 'UK',
      name: 'Correction',
    },
  ],
}
const originalCard: HilosI18nCountryCard = {
  countryId: 141,
  code: 'qx',
  currencySymbol: '$',
  currencyCode: 'USD',
  enabled: true,
  summary: {
    name: 'Subject country',
    isOwn: true,
    localeCount: 0,
    nameCount: 1,
    defaultLocaleId: null,
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
    [{ rowKey: row.rowKey, slots: { language: row } }],
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
      rowKey: next.rowKey,
      row: { rowKey: next.rowKey, slots: { language: next } },
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

/** A known empty base or correction, with independently delivered occupancy. */
function emptyPlace(correction: boolean) {
  const empty: HilosI18nNameRow = correction
    ? {
        ...original,
        corrections: [
          { ...original.corrections[0]!, nameId: null, name: null },
        ],
      }
    : { ...original, nameId: null, name: null }
  const h = harness(empty)
  const localeId = correction ? 501 : null
  const storedNameId = correction ? 801 : 701
  return {
    ...h,
    localeId,
    storedNameId,
    receive(name: string | null, nameId = name === null ? null : storedNameId) {
      h.update(
        correction
          ? {
              ...original,
              corrections: [{ ...original.corrections[0]!, nameId, name }],
            }
          : { ...original, nameId, name },
      )
    },
  }
}

describe('country name window', () => {
  it.each(['base', 'correction', 'base-for-add', 'locale', 'subject'])(
    'keeps the draft and refuses a replacement of %s with the same labels',
    async (changed) => {
      const creating = changed === 'base-for-add'
      const initial = creating
        ? {
            ...original,
            corrections: [
              { ...original.corrections[0]!, nameId: null, name: null },
            ],
          }
        : original
      const h = harness(initial)
      h.window.open(
        '47',
        changed === 'base' || changed === 'subject' ? null : 501,
      )
      h.window.patchForm({ name: 'Keep my draft' })
      if (changed === 'subject') {
        h.card.set({ ...originalCard, countryId: originalCard.countryId + 1 })
      } else if (changed === 'base' || changed === 'base-for-add') {
        h.update({ ...initial, nameId: 999 })
      } else {
        h.update({
          ...initial,
          corrections: [
            {
              ...initial.corrections[0]!,
              ...(changed === 'locale' ? { localeId: 999 } : { nameId: 999 }),
            },
          ],
        })
      }
      expect(h.window.saveLabel.get()).toBe('Deleted')
      expect(h.window.form.get().name).toBe('Keep my draft')
      expect(h.window.target.get()?.countryId).toBe(originalCard.countryId)
      await h.window.save(() => Promise.resolve(true))
      expect(h.dispatch).not.toHaveBeenCalled()
      h.window.dispose()
    },
  )

  it.each([null, '', 'Stored'])(
    'takes a fresh base name %s and fixes its coordinates and mode',
    (name) => {
      const h = harness()
      h.update({
        ...original,
        nameId: name === null ? null : 301,
        name,
        nativeName: 'Fresh writing name',
      })
      expect(h.window.open('missing')).toBe(false)
      expect(h.window.open('47')).toBe(true)
      expect(h.sendFocus).toHaveBeenLastCalledWith('47')
      expect(h.window.mode.get()).toBe(name === null ? 'add' : 'edit')
      expect(h.window.form.get()).toEqual({ name: name ?? '' })
      expect(h.window.target.get()).toMatchObject({
        inLanguageId: 47,
        nameId: name === null ? null : 301,
        countryId: 141,
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
      h.update({
        ...original,
        nameId: name === null ? null : 301,
        name,
        nativeName: 'Renamed writing language',
      })
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
      expect(h.window.open('47')).toBe(true)
      expect(h.window.target.get()?.countryName).toBe(name ?? 'QX')
      h.window.dispose()
    },
  )

  it.each([null, 'Original'])(
    'allows the country and writing language to share a code (%s)',
    async (name) => {
      const h = harness({
        ...original,
        nameId: name === null ? null : 301,
        name,
      })
      h.country.set('qw')
      h.card.set({ ...originalCard, code: 'qw', enabled: false })
      expect(h.window.open('47')).toBe(true)
      h.window.patchForm({ name: 'Same code, separate catalogs' })
      await h.window.save(() => Promise.resolve(true))
      expect(h.dispatch).toHaveBeenCalledWith(
        name === null
          ? 'hilos_i18n_country_name_add'
          : 'hilos_i18n_country_name_update',
        {
          countryId: 141,
          inLanguageId: 47,
          ...(name === null ? { baseNameId: null } : { nameId: 301 }),
          localeId: null,
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
        corrections: [
          {
            ...original.corrections[0]!,
            nameId: name === null ? null : 401,
            name,
          },
        ],
      })
      expect(h.window.open('47', 501)).toBe(true)
      expect(h.window.mode.get()).toBe(name === null ? 'add' : 'edit')
      expect(h.window.form.get().name).toBe(name ?? '')
      expect(h.window.target.get()?.localeCode).toBe('qw-GB')
      h.window.dispose()
    },
  )

  it('refuses missing coordinates without leaving focus held', () => {
    const h = harness({ ...original, nameId: null, name: null })
    expect(h.window.open('47', 501)).toBe(false)
    h.update(original)
    for (const locale of [0, -1, 502])
      expect(h.window.open('47', locale)).toBe(false)
    h.card.set(null)
    expect(h.window.open('47')).toBe(false)
    h.card.set({ ...originalCard, code: 'other' })
    expect(h.window.open('47')).toBe(false)
    expect(h.controller.focusedRow.get()).toBeUndefined()
    h.window.dispose()
  })

  it.each([false, true])(
    'creates untouched empty text with a clean baseline (correction: %s)',
    async (correction) => {
      const h = emptyPlace(correction)
      h.window.open('47', h.localeId)
      expect(h.window.canSave.get()).toBe(true)
      expect(h.window.saveAvailable.get()).toBe(true)
      expect(h.window.canSubmit.get()).toBe(true)
      expect(h.window.submitAvailable.get()).toBe(true)
      expect(h.window.dirty.get()).toBe(false)
      expect(h.window.state.get().gone).toBe(false)
      expect(h.window.noticeKind.get()).toBeNull()
      expect(h.window.noticeText.get()).toBe('')
      await h.window.save(() => Promise.resolve(false))
      expect(h.dispatch).toHaveBeenLastCalledWith(
        'hilos_i18n_country_name_add',
        {
          countryId: 141,
          inLanguageId: 47,
          baseNameId: correction ? 301 : null,
          localeId: h.localeId,
          name: '',
        },
      )
      expect(h.window.opened.get()).toBe(true)
      expect(h.controller.focusedRow.get()?.rowKey).toBe('47')
      await h.window.save(() => Promise.resolve(true))
      expect(h.dispatch).toHaveBeenCalledTimes(2)
      expect(h.window.opened.get()).toBe(false)
      expect(h.controller.focusedRow.get()).toBeUndefined()
      h.window.dispose()
    },
  )

  it.each([false, true])(
    'merges occupation and advances Keep mine before an update (correction: %s)',
    async (correction) => {
      const h = emptyPlace(correction)
      h.window.open('47', h.localeId)
      const target = h.window.target.get()
      h.window.patchForm({ name: '  My value  ' })
      h.receive('First incoming')
      expect(h.window.mode.get()).toBe('edit')
      expect(h.window.target.get()).toBe(target)
      expect(target).toMatchObject({ mode: 'add', nameId: null })
      expect(h.window.saveLabel.get()).toBe('Save')
      expect(h.window.form.get().name).toBe('  My value  ')
      expect(h.window.dirty.get()).toBe(true)
      expect(h.window.noticeKind.get()).toBe('conflict')
      expect(h.window.noticeText.get()).toBe(
        'Name changed elsewhere to "First incoming".',
      )
      expect(h.window.canSave.get()).toBe(false)
      expect(h.window.saveAvailable.get()).toBe(false)
      expect(h.window.canSubmit.get()).toBe(false)
      expect(h.window.submitAvailable.get()).toBe(false)
      await h.window.save(vi.fn())
      h.window.keepMine()
      expect(h.dispatch).not.toHaveBeenCalled()
      expect(h.window.canSave.get()).toBe(true)
      h.receive('Second incoming')
      expect(h.window.state.get().conflict).toBe(true)
      expect(h.window.canSave.get()).toBe(false)
      h.window.keepMine()
      expect(h.window.canSave.get()).toBe(true)
      await h.window.save(() => Promise.resolve(true))
      expect(h.dispatch).toHaveBeenCalledExactlyOnceWith(
        'hilos_i18n_country_name_update',
        {
          countryId: 141,
          inLanguageId: 47,
          nameId: h.storedNameId,
          localeId: h.localeId,
          name: '  My value  ',
          expectedName: 'Second incoming',
        },
      )
      expect(h.window.opened.get()).toBe(false)
      expect(h.controller.focusedRow.get()).toBeUndefined()
      h.window.dispose()
    },
  )

  it.each([false, true])(
    'advances Take theirs and allows a later literal edit (correction: %s)',
    async (correction) => {
      const h = emptyPlace(correction)
      h.window.open('47', h.localeId)
      h.window.patchForm({ name: 'Mine' })
      h.receive('Theirs')
      h.window.takeTheirs()
      expect(h.dispatch).not.toHaveBeenCalled()
      expect(h.window.form.get().name).toBe('Theirs')
      expect(h.window.dirty.get()).toBe(false)
      expect(h.window.canSave.get()).toBe(false)
      expect(h.window.noticeKind.get()).toBe('updated')
      await h.window.save(vi.fn())
      expect(h.window.opened.get()).toBe(true)
      h.receive('Another')
      expect(h.window.form.get().name).toBe('Another')
      expect(h.window.state.get().conflict).toBe(false)
      h.window.patchForm({ name: '   ' })
      expect(h.window.noticeKind.get()).toBeNull()
      expect(h.window.canSave.get()).toBe(true)
      await h.window.save(() => Promise.resolve(false))
      expect(h.dispatch).toHaveBeenCalledExactlyOnceWith(
        'hilos_i18n_country_name_update',
        {
          countryId: 141,
          inLanguageId: 47,
          nameId: h.storedNameId,
          localeId: h.localeId,
          name: '   ',
          expectedName: 'Another',
        },
      )
      h.window.dispose()
    },
  )

  describe.each([false, true])(
    'known-place acceptance (correction: %s)',
    (correction) => {
      it.each(['matching', 'untouched', 'entered-empty', 'stored-empty'])(
        'accepts %s without a conflict or another write',
        async (draft) => {
          const h = emptyPlace(correction)
          h.window.open('47', h.localeId)
          const target = h.window.target.get()
          if (draft === 'matching') h.window.patchForm({ name: 'Incoming' })
          if (draft === 'entered-empty') {
            h.window.patchForm({ name: 'Erased' })
            h.window.patchForm({ name: '' })
          }
          const incoming = draft === 'stored-empty' ? '' : 'Incoming'
          h.receive(incoming)
          expect(h.window.target.get()).toBe(target)
          expect(h.window.mode.get()).toBe('edit')
          expect(h.window.saveLabel.get()).toBe('Save')
          expect(h.window.form.get().name).toBe(incoming)
          expect(h.window.state.get().gone).toBe(false)
          expect(h.window.state.get().conflict).toBe(false)
          expect(h.window.noticeKind.get()).toBe('updated')
          expect(h.window.noticeText.get()).toBe('Updated just now')
          expect(h.window.dirty.get()).toBe(false)
          expect(h.window.canSave.get()).toBe(false)
          expect(h.window.saveAvailable.get()).toBe(false)
          await h.window.save(vi.fn())
          expect(h.dispatch).not.toHaveBeenCalled()
          expect(h.window.opened.get()).toBe(true)
          h.receive('Later')
          expect(h.window.form.get().name).toBe('Later')
          expect(h.window.state.get().conflict).toBe(false)
          h.window.patchForm({ name: 'Mine now' })
          expect(h.window.noticeKind.get()).toBeNull()
          expect(h.window.canSave.get()).toBe(true)
          h.window.dispose()
        },
      )

      it('keeps an existing draft when the first stored value is empty', async () => {
        const h = emptyPlace(correction)
        h.window.open('47', h.localeId)
        h.window.patchForm({ name: 'Mine' })
        h.receive('')
        expect(h.window.mode.get()).toBe('edit')
        expect(h.window.state.get().gone).toBe(false)
        expect(h.window.state.get().conflict).toBe(false)
        expect(h.window.canSave.get()).toBe(true)
        await h.window.save(() => Promise.resolve(false))
        expect(h.dispatch).toHaveBeenCalledExactlyOnceWith(
          'hilos_i18n_country_name_update',
          {
            countryId: 141,
            inLanguageId: 47,
            localeId: h.localeId,
            nameId: h.storedNameId,
            name: 'Mine',
            expectedName: '',
          },
        )
        h.window.dispose()
      })

      it.each(['removed', 'replaced'])(
        'keeps Deleted when its first stored id is %s',
        async (lost) => {
          const h = emptyPlace(correction)
          h.window.open('47', h.localeId)
          h.window.patchForm({ name: 'Copy this' })
          h.receive('Same text')
          h.window.keepMine()
          h.receive(
            lost === 'removed' ? null : 'Same text',
            lost === 'removed' ? null : 999,
          )
          expect(h.window.mode.get()).toBe('edit')
          expect(h.window.saveLabel.get()).toBe('Deleted')
          expect(h.window.noticeKind.get()).toBe('deleted')
          expect(h.window.form.get().name).toBe('Copy this')
          expect(h.window.canSave.get()).toBe(false)
          expect(h.window.canSubmit.get()).toBe(false)
          await h.window.save(vi.fn())
          expect(h.dispatch).not.toHaveBeenCalled()
          h.receive('Same text', 1000)
          expect(h.window.saveLabel.get()).toBe('Deleted')
          expect(h.window.form.get().name).toBe('Copy this')
          h.window.dispose()
        },
      )

      it.each([
        { order: 'refusal-first', incoming: 'Theirs' },
        { order: 'live-first', incoming: 'Theirs' },
        { order: 'refusal-first', incoming: 'Mine' },
        { order: 'live-first', incoming: 'Mine' },
      ])(
        'merges a duplicate in $order order with $incoming without retry',
        async ({ order, incoming }) => {
          const h = emptyPlace(correction)
          h.window.open('47', h.localeId)
          const target = h.window.target.get()
          h.window.patchForm({ name: 'Mine' })
          const p = pending()
          const run = vi.fn(p.run)
          const saved = h.window.save(run)
          if (order === 'refusal-first') {
            p.finish(false)
            await saved
            h.receive(incoming)
          } else {
            h.receive(incoming)
            expect(h.window.saving.get()).toBe(true)
            expect(h.window.canSave.get()).toBe(false)
            p.finish(false)
            await saved
          }
          expect(h.window.saving.get()).toBe(false)
          expect(h.window.opened.get()).toBe(true)
          expect(h.window.target.get()).toBe(target)
          expect(h.controller.focusedRow.get()?.rowKey).toBe('47')
          expect(h.window.mode.get()).toBe('edit')
          expect(h.window.form.get().name).toBe('Mine')
          expect(h.window.noticeKind.get()).toBe(
            incoming === 'Mine' ? 'updated' : 'conflict',
          )
          expect(h.window.noticeText.get()).toBe(
            incoming === 'Mine'
              ? 'Updated just now'
              : 'Name changed elsewhere to "Theirs".',
          )
          expect(h.dispatch).toHaveBeenCalledOnce()
          expect(run).toHaveBeenCalledOnce()
          h.window.keepMine()
          expect(h.window.canSave.get()).toBe(incoming !== 'Mine')
          expect(h.dispatch).toHaveBeenCalledOnce()
          h.window.dispose()
        },
      )

      it.each(['reply', 'route', 'dispose'])(
        'holds a pending add through occupation until %s',
        async (end) => {
          const h = emptyPlace(correction)
          h.window.open('47', h.localeId)
          const target = h.window.target.get()
          h.window.patchForm({ name: 'Mine' })
          const p = pending()
          const run = vi.fn(p.run)
          const saved = h.window.save(run)
          h.receive('Theirs')
          h.window.keepMine()
          expect(h.window.mode.get()).toBe('edit')
          expect(h.window.target.get()).toBe(target)
          expect(h.window.saving.get()).toBe(true)
          expect(h.window.saveAvailable.get()).toBe(true)
          expect(h.window.canSave.get()).toBe(false)
          h.window.close()
          expect(h.window.opened.get()).toBe(true)
          expect(h.window.open('47', h.localeId)).toBe(false)
          await h.window.save(run)
          expect(run).toHaveBeenCalledOnce()
          expect(h.dispatch).toHaveBeenCalledOnce()
          if (end === 'reply') {
            p.finish(true)
            await saved
            expect(h.window.opened.get()).toBe(false)
            expect(h.controller.focusedRow.get()).toBeUndefined()
          } else {
            if (end === 'route') h.country.set('qz')
            else h.window.dispose()
            expect(h.window.opened.get()).toBe(false)
            expect(h.controller.focusedRow.get()).toBeUndefined()
            h.card.set({ ...originalCard, code: 'qz' })
            h.country.set('qz')
            h.window.start()
            h.window.open('47', h.localeId)
            h.window.patchForm({ name: 'New opening' })
            const next = pending()
            const newSave = h.window.save(next.run)
            p.finish(true)
            await saved
            expect(h.window.opened.get()).toBe(true)
            expect(h.window.saving.get()).toBe(true)
            expect(h.window.form.get().name).toBe('New opening')
            expect(h.controller.focusedRow.get()?.rowKey).toBe('47')
            next.finish(false)
            await newSave
            expect(h.window.saving.get()).toBe(false)
          }
          h.window.dispose()
        },
      )
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
      h.window.open('47', localeCode === null ? null : 501)
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
          countryId: 141,
          inLanguageId: 47,
          nameId: localeCode === null ? 301 : 401,
          localeId: localeCode === null ? null : 501,
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
                corrections: [
                  {
                    ...original.corrections[0]!,
                    nameId: name === null ? null : 401,
                    name,
                  },
                ],
              },
        )
      h.window.open('47', localeCode === null ? null : 501)
      change('Server only')
      expect(h.window.form.get().name).toBe('Server only')
      expect(h.window.noticeKind.get()).toBe('updated')
      h.window.patchForm({ name: 'Mine' })
      await h.window.save(() => Promise.resolve(false))
      expect(h.dispatch).toHaveBeenLastCalledWith(
        'hilos_i18n_country_name_update',
        {
          countryId: 141,
          inLanguageId: 47,
          nameId: localeCode === null ? 301 : 401,
          localeId: localeCode === null ? null : 501,
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
          countryId: 141,
          inLanguageId: 47,
          nameId: localeCode === null ? 301 : 401,
          localeId: localeCode === null ? null : 501,
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
          countryId: 141,
          inLanguageId: 47,
          nameId: localeCode === null ? 301 : 401,
          localeId: localeCode === null ? null : 501,
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
      h.window.open('47')
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
          countryId: 141,
          inLanguageId: 47,
          nameId: 301,
          localeId: null,
          name: 'Mine',
          expectedName: '',
        },
      )
      h.window.dispose()
    },
  )

  it('keeps a repeated snapshot of the same ID live', async () => {
    const h = harness()
    h.window.open('47')
    h.window.patchForm({ name: 'Mine' })
    h.controller.ingestWindow(
      [{ rowKey: '47', slots: { language: { ...original } } }],
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
      countryId: 141,
      inLanguageId: 47,
      nameId: 301,
      localeId: null,
      name: 'Mine',
      expectedName: 'Original',
    })
    h.window.dispose()
  })

  it.each(['name', 'base', 'locale', 'row', 'subject'])(
    'keeps the draft with Deleted after losing %s',
    async (lost) => {
      const h = harness()
      h.window.open('47', 501)
      h.window.patchForm({ name: 'Copy this' })
      if (lost === 'name')
        h.update({
          ...original,
          corrections: [
            { ...original.corrections[0]!, nameId: null, name: null },
          ],
        })
      if (lost === 'base') h.update({ ...original, nameId: null, name: null })
      if (lost === 'locale') h.update({ ...original, corrections: [] })
      if (lost === 'row')
        h.controller.ingestDelta({
          kind: 'row_removed',
          rowKey: '47',
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
        corrections: [
          { ...original.corrections[0]!, nameId: null, name: null },
        ],
      })
      h.window.open('47', 501)
      h.window.patchForm({ name: 'Copy add' })
      if (lost === 'base') h.update({ ...original, nameId: null, name: null })
      if (lost === 'locale') h.update({ ...original, corrections: [] })
      if (lost === 'row')
        h.controller.ingestDelta({
          kind: 'row_removed',
          rowKey: '47',
          reason: 'deleted',
        })
      if (lost === 'subject') h.card.set(null)
      expect(h.window.canSubmit.get()).toBe(false)
      expect(h.window.submitAvailable.get()).toBe(false)
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
      const h = harness({
        ...original,
        nameId: name === null ? null : 301,
        name,
      })
      h.window.open('47')
      h.window.patchForm({ name: 'Copy base' })
      h.card.set({ ...originalCard, countryId: 150, code: 'qz' })
      expect(h.window.saveLabel.get()).toBe('Deleted')
      await h.window.save(vi.fn())
      h.card.set(originalCard)
      h.controller.ingestDelta({
        kind: 'row_removed',
        rowKey: '47',
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
    h.window.open('47')
    h.window.patchForm({ name: 'Copy base' })
    h.update({ ...original, nameId: null, name: null, corrections: [] })
    expect(h.window.saveLabel.get()).toBe('Deleted')
    expect(h.window.form.get().name).toBe('Copy base')
    await h.window.save(vi.fn())
    expect(h.dispatch).not.toHaveBeenCalled()
    h.window.dispose()
  })

  it.each([null, 'Original'])(
    'preserves the draft and releases busy after a rejected runner (%s)',
    async (name) => {
      const h = harness({
        ...original,
        nameId: name === null ? null : 301,
        name,
      })
      h.window.open('47')
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
      const h = harness({
        ...original,
        nameId: name === null ? null : 301,
        name,
      })
      h.window.open('47')
      h.window.patchForm({ name: 'Mine' })
      const p = pending()
      const saved = h.window.save(p.run)
      expect(h.window.saving.get()).toBe(true)
      h.window.close()
      expect(h.window.opened.get()).toBe(true)
      expect(h.window.open('47')).toBe(false)
      await h.window.save(vi.fn())
      expect(h.dispatch).toHaveBeenCalledOnce()
      h.country.set('qz')
      expect(h.window.opened.get()).toBe(false)
      expect(h.controller.focusedRow.get()).toBeUndefined()
      h.card.set({ ...originalCard, countryId: 150, code: 'qz' })
      h.window.open('47')
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
    'stays fit to send through its own send, until the name is gone (%s)',
    async (name) => {
      const h = harness({
        ...original,
        nameId: name === null ? null : 301,
        name,
      })
      h.window.open('47')
      h.window.patchForm({ name: 'Mine' })
      const available =
        name === null ? h.window.submitAvailable : h.window.saveAvailable
      const allowed = name === null ? h.window.canSubmit : h.window.canSave
      expect(available.get()).toBe(true)
      expect(allowed.get()).toBe(true)
      const p = pending()
      const saved = h.window.save(p.run)
      expect(h.window.saving.get()).toBe(true)
      expect(allowed.get()).toBe(false)
      expect(available.get()).toBe(true)
      await h.window.save(vi.fn())
      expect(h.dispatch).toHaveBeenCalledOnce()
      h.controller.ingestDelta({
        kind: 'row_removed',
        rowKey: '47',
        reason: 'deleted',
      })
      expect(available.get()).toBe(false)
      p.finish(false)
      await saved
      expect(h.window.saving.get()).toBe(false)
      expect(allowed.get()).toBe(false)
      h.window.dispose()
    },
  )

  it.each([null, 'Original'])(
    'disposes a busy window and ignores its late reply (%s)',
    async (name) => {
      const h = harness({
        ...original,
        nameId: name === null ? null : 301,
        name,
      })
      h.window.start()
      h.window.open('47')
      h.window.patchForm({ name: 'Mine' })
      const p = pending()
      const saved = h.window.save(p.run)
      h.window.dispose()
      expect(h.window.opened.get()).toBe(false)
      expect(h.window.saving.get()).toBe(false)
      expect(h.sendFocus.mock.calls).toEqual([['47'], ['']])
      h.controller.focusRow('47')
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
    h.window.open('47')
    h.window.close()
    h.controller.focusRow('47')
    h.window.dispose()
    expect(h.controller.focusedRow.get()?.code).toBe('qw')
  })
})
