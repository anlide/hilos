// Covers the row-edit session (HIL-1279): opening and the refusal on a missing
// row, the key check of the table source, the step landing in the form with a
// hidden value left out, Keep mine / Take theirs, the verdict "fit to save" a
// save in flight leaves standing and the verdict "can save" it takes down
// (HIL-1766), the one door of the send — unchanged closes, blocked sends
// nothing, success closes, failure stays, a late outcome moves nothing — the
// close that lets the row go and forgets, and the save that waits for the live
// row. Creation in a known place (HIL-1643) may send unchanged shown values;
// its first occupation marks matching visible fields updated once, and keeps
// the same save guards, merge steps and opening-scoped outcomes.
import { describe, expect, expectTypeOf, it, vi } from 'vitest'
import {
  createHilosRowEdit,
  hilosRowEditIdle,
  hilosSignalRowEditSource,
  hilosTableRowEditSource,
  HILOS_ROW_EDIT_COPY,
  type HilosRowEditCheckpoint,
} from '../../src/conflict/rowEditSession.js'
import { createHilosLegalSettingsTable } from '../../src/admin/legal/hilosLegal.js'
import { type HilosLegalContext } from '../../src/legal/legalAgreements.js'
import {
  HIDDEN_VALUE,
  isHiddenValue,
  type Hideable,
} from '../../src/state/hiddenValue.js'
import { createSignal } from '../../src/state/signal.js'

interface Row {
  readonly id: number
  readonly name: Hideable<string>
  readonly note: string | null
}

interface Fields {
  name: Hideable<string>
  note: string | null
}

interface Form {
  hidden: boolean
  name: string
  note: string
}

/** A window over a signal row, with a form richer than its fields. */
function signalHarness(
  initial: Row | null = { id: 1, name: 'Ann', note: null },
) {
  const live = createSignal<Row | undefined>(initial ?? undefined)
  const edit = createHilosRowEdit<Row, Fields, Form>(
    hilosSignalRowEditSource(live),
    {
      initial: { hidden: false, name: '', note: '' },
      fields: (row) => ({ name: row.name, note: row.note }),
      form: (row) =>
        isHiddenValue(row.name)
          ? { hidden: true, name: '', note: '' }
          : { hidden: false, name: row.name, note: row.note ?? '' },
      draft: (form) => ({
        name: form.hidden ? HIDDEN_VALUE : form.name.trim(),
        note: form.note.trim() || null,
      }),
      take: (form, taken) => ({
        ...form,
        ...(taken.name !== undefined && !isHiddenValue(taken.name)
          ? { name: taken.name }
          : {}),
        ...(taken.note !== undefined ? { note: taken.note ?? '' } : {}),
      }),
      valid: (form) => form.hidden || form.name.trim().length >= 2,
      forget: (form) => ({ ...form, note: '' }),
      notice: {
        conflict: (state) =>
          `Changed to "${String(state.fields.name.incoming)}".`,
      },
    },
  )
  edit.start()

  return { edit, live }
}

interface PlaceRow extends Row {
  readonly recordId: number | null
}

/** A known live place, whose record may not exist yet. */
function creationHarness(
  initial: PlaceRow = { id: 1, recordId: null, name: 'Ann', note: null },
) {
  const live = createSignal<PlaceRow | undefined>(initial)
  const edit = createHilosRowEdit<PlaceRow, Fields>(
    hilosSignalRowEditSource(live),
    {
      initial: { name: '', note: null },
      fields: (row) => ({ name: row.name, note: row.note }),
      creating: (live) => live.recordId === null,
      valid: (form) => isHiddenValue(form.name) || form.name.length >= 2,
      notice: { conflict: () => 'conflict' },
    },
  )
  edit.start()

  return { edit, live }
}

/** A window over a real table, as the legal settings page has it. */
function tableHarness() {
  const focus: string[] = []
  const context = {
    connection: {
      sendTableRowFocus: (_page: string, _table: string, key: string) =>
        focus.push(key),
    },
  } as unknown as HilosLegalContext
  const table = createHilosLegalSettingsTable(context)
  const window = (value: unknown) =>
    table.controller.ingestWindow(
      [
        {
          rowKey: 'legal.consent_form',
          slots: { setting: { value, defaultValue: 'checkbox' } },
        },
        {
          rowKey: 'legal.on_lapse',
          slots: { setting: { value: 'freeze', defaultValue: 'freeze' } },
        },
      ],
      2,
      true,
      null,
      null,
      25,
    )
  window('checkbox')
  const edit = createHilosRowEdit<
    { rowKey: string; value: Hideable<string> },
    { value: Hideable<string> }
  >(
    hilosTableRowEditSource(table.controller, (row) => row.rowKey),
    {
      initial: { value: '' },
      fields: (row) => ({ value: row.value }),
      notice: { conflict: () => 'conflict' },
    },
  )
  edit.start()

  return { edit, table, window, focus }
}

describe('row-edit session: opening', () => {
  it('opens on the fresh row with the form and the snapshot taken from it', () => {
    const { edit } = signalHarness()
    expect(edit.opened.get()).toBe(false)
    expect(edit.row.get()).toBeNull()
    expect(edit.state.get()).toEqual(hilosRowEditIdle({}))
    expect(edit.open()).toBe(true)
    expect(edit.opened.get()).toBe(true)
    expect(edit.row.get()?.id).toBe(1)
    expect(edit.form.get()).toEqual({ hidden: false, name: 'Ann', note: '' })
    expect(edit.state.get().dirty).toBe(false)
    expect(edit.canSave.get()).toBe(false)
    expect(edit.saveLabel.get()).toBe(HILOS_ROW_EDIT_COPY.save)
  })

  it('refuses to open while there is no row', () => {
    const { edit } = signalHarness(null)
    expect(edit.open()).toBe(false)
    expect(edit.opened.get()).toBe(false)
  })

  it('refuses a table row that is not there, and takes a present one into focus', () => {
    const { edit, focus } = tableHarness()
    expect(edit.open('legal.nowhere')).toBe(false)
    expect(edit.open()).toBe(false)
    expect(focus).toEqual([])
    expect(edit.open('legal.consent_form')).toBe(true)
    expect(focus).toEqual(['legal.consent_form'])
    expect(edit.form.get()).toEqual({ value: 'checkbox' })
  })

  it('opens a hidden value hidden: the form is empty and the draft never dirty', () => {
    const { edit, live } = signalHarness({
      id: 1,
      name: HIDDEN_VALUE,
      note: null,
    })
    edit.open()
    expect(edit.form.get()).toEqual({ hidden: true, name: '', note: '' })
    expect(edit.state.get().dirty).toBe(false)
    live.set({ id: 1, name: HIDDEN_VALUE, note: null })
    expect(edit.state.get().notice).toBeNull()
    expect(edit.canSave.get()).toBe(false)
  })
})

describe('row-edit session: the live row', () => {
  it('reads the row gone when another dialog takes the focus to another key', () => {
    const { edit, table } = tableHarness()
    edit.open('legal.consent_form')
    expect(edit.state.get().gone).toBe(false)
    table.controller.focusRow('legal.on_lapse')
    expect(edit.state.get().gone).toBe(true)
    expect(edit.saveLabel.get()).toBe(HILOS_ROW_EDIT_COPY.gone)
    expect(edit.noticeText.get()).toBe(HILOS_ROW_EDIT_COPY.deleted)
    expect(edit.canSave.get()).toBe(false)
  })

  it('keeps the draft on screen when the row is removed under the window', () => {
    const { edit, table } = tableHarness()
    edit.open('legal.consent_form')
    edit.setForm({ value: 'line' })
    table.controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'legal.consent_form',
      reason: 'deleted',
    })
    expect(edit.state.get().gone).toBe(true)
    expect(edit.form.get()).toEqual({ value: 'line' })
    expect(edit.row.get()?.rowKey).toBe('legal.consent_form')
  })

  it('lands a field only the other side changed in the form and says so, until typed over', () => {
    const { edit, live } = signalHarness()
    edit.open()
    live.set({ id: 1, name: 'Anna', note: null })
    expect(edit.form.get()).toEqual({ hidden: false, name: 'Anna', note: '' })
    expect(edit.state.get().dirty).toBe(false)
    expect(edit.noticeText.get()).toBe(HILOS_ROW_EDIT_COPY.updated)
    edit.patchForm({ name: 'Annie' })
    expect(edit.noticeText.get()).toBe('')
    expect(edit.state.get().dirty).toBe(true)
  })

  it('moves the snapshot on a hidden value taken, and leaves the form alone', () => {
    const { edit, live } = signalHarness()
    edit.open()
    live.set({ id: 1, name: HIDDEN_VALUE, note: 'hi' })
    expect(edit.form.get()).toEqual({ hidden: false, name: 'Ann', note: 'hi' })
    expect(edit.state.get().fields.note.refreshed).toBe(true)
  })

  it('answers a conflict with Keep mine or Take theirs', () => {
    const { edit, live } = signalHarness()
    edit.open()
    edit.patchForm({ name: 'Bob' })
    live.set({ id: 1, name: 'Carl', note: null })
    expect(edit.state.get().conflict).toBe(true)
    expect(edit.canSave.get()).toBe(false)
    expect(edit.noticeText.get()).toBe('Changed to "Carl".')
    edit.keepMine()
    expect(edit.state.get().conflict).toBe(false)
    expect(edit.form.get().name).toBe('Bob')
    expect(edit.canSave.get()).toBe(true)

    live.set({ id: 1, name: 'Dan', note: null })
    expect(edit.state.get().conflict).toBe(true)
    edit.takeTheirs()
    expect(edit.form.get().name).toBe('Dan')
    expect(edit.state.get().conflict).toBe(false)
    expect(edit.noticeText.get()).toBe(HILOS_ROW_EDIT_COPY.updated)
    expect(edit.canSave.get()).toBe(false)
  })
})

describe('row-edit session: can save and the one door of the send', () => {
  it('can save only when open, present, settled, idle, valid and dirty', async () => {
    const { edit, live } = signalHarness()
    expect(edit.canSave.get()).toBe(false)
    edit.open()
    expect(edit.canSave.get()).toBe(false)
    edit.patchForm({ name: 'Bob' })
    expect(edit.canSave.get()).toBe(true)
    edit.patchForm({ name: 'B' })
    expect(edit.canSave.get()).toBe(false)
    edit.patchForm({ name: 'Bob' })
    live.set({ id: 1, name: 'Carl', note: null })
    expect(edit.canSave.get()).toBe(false)
    edit.keepMine()
    expect(edit.canSave.get()).toBe(true)
    live.set(undefined)
    expect(edit.canSave.get()).toBe(false)
    live.set({ id: 1, name: 'Carl', note: null })
    let release: (saved: boolean) => void = () => undefined
    const pending = edit.save(
      () => new Promise<boolean>((resolve) => (release = resolve)),
    )
    expect(edit.saving.get()).toBe(true)
    expect(edit.canSave.get()).toBe(false)
    release(false)
    await pending
    expect(edit.saving.get()).toBe(false)
    expect(edit.canSave.get()).toBe(true)
  })

  it('is fit to save only when open, present, settled, valid and dirty', () => {
    const { edit, live } = signalHarness()
    expect(edit.saveAvailable.get()).toBe(false)
    edit.open()
    expect(edit.saveAvailable.get()).toBe(false)
    edit.patchForm({ name: 'Bob' })
    expect(edit.saveAvailable.get()).toBe(true)
    edit.patchForm({ name: 'B' })
    expect(edit.saveAvailable.get()).toBe(false)
    edit.patchForm({ name: 'Bob' })
    live.set({ id: 1, name: 'Carl', note: null })
    expect(edit.saveAvailable.get()).toBe(false)
    edit.keepMine()
    expect(edit.saveAvailable.get()).toBe(true)
    live.set(undefined)
    expect(edit.saveAvailable.get()).toBe(false)
    live.set({ id: 1, name: 'Carl', note: null })
    expect(edit.saveAvailable.get()).toBe(true)
    edit.close()
    expect(edit.saveAvailable.get()).toBe(false)
  })

  it('stays fit to save through its own save in flight, which it does not send again', async () => {
    const { edit, live } = signalHarness()
    edit.open()
    edit.patchForm({ name: 'Bob' })
    const send = vi.fn(() => Promise.resolve(true))
    let release: (saved: boolean) => void = () => undefined
    const pending = edit.save(
      () => new Promise<boolean>((resolve) => (release = resolve)),
    )
    expect(edit.saving.get()).toBe(true)
    expect(edit.canSave.get()).toBe(false)
    expect(edit.saveAvailable.get()).toBe(true)
    await edit.save(send)
    expect(send).not.toHaveBeenCalled()

    live.set({ id: 1, name: 'Carl', note: null })
    expect(edit.saveAvailable.get()).toBe(false)
    edit.keepMine()
    expect(edit.saveAvailable.get()).toBe(true)
    expect(edit.canSave.get()).toBe(false)
    live.set(undefined)
    expect(edit.saveAvailable.get()).toBe(false)
    expect(edit.canSave.get()).toBe(false)

    release(false)
    await pending
    expect(edit.saving.get()).toBe(false)
    expect(edit.saveAvailable.get()).toBe(false)
    expect(edit.canSave.get()).toBe(false)
    live.set({ id: 1, name: 'Carl', note: null })
    expect(edit.saveAvailable.get()).toBe(true)
    expect(edit.canSave.get()).toBe(true)
  })

  it('closes an unchanged draft without a send', async () => {
    const { edit } = signalHarness()
    edit.open()
    const send = vi.fn(() => Promise.resolve(true))
    await edit.save(send)
    expect(send).not.toHaveBeenCalled()
    expect(edit.opened.get()).toBe(false)
  })

  it('sends nothing while closed, in conflict, invalid, gone or in flight', async () => {
    const { edit, live } = signalHarness()
    const send = vi.fn(() => Promise.resolve(true))
    await edit.save(send)
    edit.open()
    edit.patchForm({ name: 'B' })
    await edit.save(send)
    edit.patchForm({ name: 'Bob' })
    live.set({ id: 1, name: 'Carl', note: null })
    await edit.save(send)
    edit.keepMine()
    live.set(undefined)
    await edit.save(send)
    expect(send).not.toHaveBeenCalled()
    expect(edit.opened.get()).toBe(true)

    live.set({ id: 1, name: 'Carl', note: null })
    let release: (saved: boolean) => void = () => undefined
    const first = edit.save(
      () => new Promise<boolean>((resolve) => (release = resolve)),
    )
    await edit.save(send)
    expect(send).not.toHaveBeenCalled()
    release(true)
    await first
    expect(edit.opened.get()).toBe(false)
  })

  it('sends the projected draft with the row, closes on true and stays open on false', async () => {
    const { edit } = signalHarness()
    edit.open()
    edit.setForm({ hidden: false, name: '  Bob ', note: '  ' })
    const send = vi.fn<(draft: Fields, row: Row) => Promise<boolean>>(() =>
      Promise.resolve(false),
    )
    await edit.save(send)
    expect(send).toHaveBeenCalledWith(
      { name: 'Bob', note: null },
      { id: 1, name: 'Ann', note: null },
    )
    expect(edit.opened.get()).toBe(true)
    expect(edit.form.get().name).toBe('  Bob ')
    await edit.save(() => Promise.resolve(true))
    expect(edit.opened.get()).toBe(false)
  })

  it('lets a send that throws release the window', async () => {
    const { edit } = signalHarness()
    edit.open()
    edit.patchForm({ name: 'Bob' })
    await expect(
      edit.save(() => Promise.reject(new Error('lost'))),
    ).rejects.toThrow('lost')
    expect(edit.saving.get()).toBe(false)
    expect(edit.opened.get()).toBe(true)
  })

  it('ignores the outcome of a save whose window closed and opened again', async () => {
    const { edit } = signalHarness()
    edit.open()
    edit.patchForm({ name: 'Bob' })
    let release: (saved: boolean) => void = () => undefined
    const pending = edit.save(
      () => new Promise<boolean>((resolve) => (release = resolve)),
    )
    edit.close()
    expect(edit.saving.get()).toBe(false)
    expect(edit.open()).toBe(true)
    edit.patchForm({ name: 'Carl' })
    release(true)
    await pending
    expect(edit.opened.get()).toBe(true)
    expect(edit.form.get().name).toBe('Carl')
  })

  it('refuses to open while a save is in flight', () => {
    const { edit } = signalHarness()
    edit.open()
    edit.patchForm({ name: 'Bob' })
    void edit.save(() => new Promise<boolean>(() => undefined))
    edit.close()
    expect(edit.open()).toBe(true)
  })
})

describe('row-edit session: creation in a known place', () => {
  it.each([true, false])(
    'sends unchanged prefill and waits for the action outcome %s',
    async (saved) => {
      const { edit, live } = creationHarness()
      edit.open()
      expect(edit.state.get().dirty).toBe(false)
      expect(edit.saveAvailable.get()).toBe(true)
      expect(edit.canSave.get()).toBe(true)
      let release: (saved: boolean) => void = () => undefined
      const send = vi.fn(
        () => new Promise<boolean>((resolve) => (release = resolve)),
      )
      const pending = edit.save(send)
      expect(send).toHaveBeenCalledExactlyOnceWith(
        { name: 'Ann', note: null },
        { id: 1, recordId: null, name: 'Ann', note: null },
      )
      expect(edit.opened.get()).toBe(true)
      expect(edit.saving.get()).toBe(true)
      expect(edit.saveAvailable.get()).toBe(true)
      expect(edit.canSave.get()).toBe(false)
      await edit.save(send)
      expect(send).toHaveBeenCalledTimes(1)

      live.set({ id: 1, recordId: 7, name: 'Ann', note: null })
      expect(edit.opened.get()).toBe(true)
      expect(edit.saving.get()).toBe(true)
      expect(edit.saveAvailable.get()).toBe(false)
      expect(edit.canSave.get()).toBe(false)
      release(saved)
      await pending
      expect(edit.saving.get()).toBe(false)
      expect(edit.opened.get()).toBe(!saved)
    },
  )

  it.each(['save', 'saveLanded'] as const)(
    'sends nothing through %s after an equal record occupies the place',
    async (method) => {
      const { edit, live } = creationHarness()
      edit.open()
      live.set({ id: 1, recordId: 7, name: 'Ann', note: null })
      expect(edit.row.get()?.recordId).toBeNull()
      expect(edit.saveAvailable.get()).toBe(false)
      expect(edit.canSave.get()).toBe(false)
      const send = vi.fn(() => true)
      if (method === 'save') {
        await edit.save(() => Promise.resolve(send()))
      } else {
        edit.saveLanded(send, createSignal<string | null>(null))
      }
      expect(send).not.toHaveBeenCalled()
      expect(edit.opened.get()).toBe(false)
    },
  )

  it('keeps closed, invalid, conflict and gone guards while the place is empty', async () => {
    const { edit, live } = creationHarness()
    const send = vi.fn(() => Promise.resolve(false))
    expect(edit.saveAvailable.get()).toBe(false)
    expect(edit.canSave.get()).toBe(false)
    await edit.save(send)
    edit.open()
    edit.patchForm({ name: 'A' })
    expect(edit.saveAvailable.get()).toBe(false)
    expect(edit.canSave.get()).toBe(false)
    await edit.save(send)
    edit.patchForm({ name: 'Bob' })
    live.set({ id: 1, recordId: null, name: 'Carl', note: null })
    expect(edit.state.get().conflict).toBe(true)
    expect(edit.saveAvailable.get()).toBe(false)
    expect(edit.canSave.get()).toBe(false)
    await edit.save(send)
    edit.keepMine()
    expect(edit.canSave.get()).toBe(true)
    live.set(undefined)
    expect(edit.state.get().gone).toBe(true)
    expect(edit.saveAvailable.get()).toBe(false)
    expect(edit.canSave.get()).toBe(false)
    await edit.save(send)
    expect(send).not.toHaveBeenCalled()
    expect(edit.opened.get()).toBe(true)
  })

  it.each(['Ann', 'Bob'])(
    'marks the first matching name %s updated beside a field changed only by the person',
    (name) => {
      const { edit, live } = creationHarness()
      edit.open()
      edit.patchForm({ name, note: 'mine' })
      live.set({ id: 1, recordId: 7, name, note: null })
      expect(edit.form.get()).toEqual({ name, note: 'mine' })
      expect(edit.state.get().fields.name.refreshed).toBe(true)
      expect(edit.state.get().fields.note.refreshed).toBe(false)
      expect(edit.state.get().notice).toEqual({
        kind: 'updated',
        fields: ['name'],
      })
      expect(edit.noticeText.get()).toBe(HILOS_ROW_EDIT_COPY.updated)
      expect(edit.state.get().dirty).toBe(true)
      expect(edit.state.get().settle).toBeNull()

      edit.patchForm({ name: 'Annie' })
      expect(edit.state.get().fields.name.refreshed).toBe(false)
      expect(edit.noticeText.get()).toBe('')
      expect(edit.state.get().settle).toBeNull()
      edit.patchForm({ name })
      live.set({ id: 1, recordId: 7, name: 'Carl', note: null })
      expect(edit.state.get().conflict).toBe(false)
      expect(edit.form.get()).toEqual({ name: 'Carl', note: 'mine' })
      expect(edit.state.get().fields.name.refreshed).toBe(true)
      expect(edit.state.get().settle).toBeNull()
    },
  )

  it('keeps the ordinary incoming step beside the first acceptance mark', () => {
    const { edit, live } = creationHarness()
    edit.open()
    edit.patchForm({ name: 'Bob' })
    live.set({ id: 1, recordId: 7, name: 'Bob', note: 'theirs' })
    expect(edit.form.get()).toEqual({ name: 'Bob', note: 'theirs' })
    expect(edit.state.get().notice).toEqual({
      kind: 'updated',
      fields: ['name', 'note'],
    })
    expect(edit.state.get().dirty).toBe(false)
    expect(edit.state.get().settle).toBeNull()
    edit.patchForm({ name: 'Dan', note: 'mine' })
    expect(edit.noticeText.get()).toBe('')
    live.set({ id: 1, recordId: 7, name: 'Ann', note: null })
    expect(edit.state.get().fields.name.conflict).toBe(true)
    expect(edit.state.get().fields.note.conflict).toBe(true)
  })

  it('does not mark conflicting or hidden fields updated on occupation', () => {
    const { edit, live } = creationHarness()
    edit.open()
    edit.patchForm({ name: 'Bob' })
    live.set({ id: 1, recordId: 7, name: 'Carl', note: null })
    expect(edit.state.get().conflict).toBe(true)
    expect(edit.state.get().fields.name.refreshed).toBe(false)
    expect(edit.state.get().fields.note.refreshed).toBe(true)
    expect(edit.state.get().settle).toBeNull()
    edit.keepMine()
    edit.patchForm({ note: 'mine' })
    live.set({ id: 1, recordId: 7, name: 'Bob', note: null })
    expect(edit.noticeText.get()).toBe('')
    expect(edit.state.get().fields.name.refreshed).toBe(false)
    expect(edit.state.get().fields.note.refreshed).toBe(false)

    const hidden = creationHarness({
      id: 1,
      recordId: null,
      name: HIDDEN_VALUE,
      note: null,
    })
    hidden.edit.open()
    hidden.edit.patchForm({ note: 'mine' })
    hidden.live.set({ id: 1, recordId: 7, name: HIDDEN_VALUE, note: null })
    expect(hidden.edit.state.get().fields.name.refreshed).toBe(false)
    expect(hidden.edit.noticeText.get()).toBe('')
    expect(hidden.edit.state.get().settle).toBeNull()
  })

  it('does not mark ordinary converged fields updated without creating', () => {
    const { edit, live } = signalHarness()
    edit.open()
    edit.patchForm({ name: 'Bob', note: 'mine' })
    live.set({ id: 1, name: 'Bob', note: null })
    expect(edit.state.get().fields.name.refreshed).toBe(false)
    expect(edit.noticeText.get()).toBe('')
    expect(edit.state.get().settle).toBeNull()
  })

  it('resets the occupation mark on close and open, including dispose and start', () => {
    const { edit, live } = creationHarness()
    edit.open()
    edit.close()
    live.set({ id: 1, recordId: 7, name: 'Ann', note: null })
    edit.open()
    expect(edit.noticeText.get()).toBe('')
    expect(edit.state.get().settle).toBeNull()
    edit.dispose()
    live.set({ id: 1, recordId: null, name: 'Ann', note: null })
    edit.start()
    edit.open()
    live.set({ id: 1, recordId: 8, name: 'Ann', note: null })
    expect(edit.noticeText.get()).toBe(HILOS_ROW_EDIT_COPY.updated)
    expect(edit.state.get().settle).toBeNull()
  })

  it('waits for a live landing when an unchanged creation is sent through saveLanded', () => {
    const { edit, live } = creationHarness()
    const refusal = createSignal<string | null>(null)
    const send = vi.fn(() => true)
    edit.open()
    edit.saveLanded(send, refusal)
    expect(send).toHaveBeenCalledExactlyOnceWith(
      { name: 'Ann', note: null },
      { id: 1, recordId: null, name: 'Ann', note: null },
    )
    expect(edit.opened.get()).toBe(true)
    expect(edit.saving.get()).toBe(true)
    expect(edit.saveAvailable.get()).toBe(true)
    expect(edit.canSave.get()).toBe(false)
    edit.saveLanded(send, refusal)
    expect(send).toHaveBeenCalledTimes(1)
    live.set({ id: 1, recordId: 7, name: 'Ann', note: null })
    expect(edit.opened.get()).toBe(false)
    expect(edit.saving.get()).toBe(false)
  })

  it.each([true, false])(
    'ignores a late creation outcome %s while a new opening saves',
    async (saved) => {
      const { edit } = creationHarness()
      edit.open()
      let releaseOld: (saved: boolean) => void = () => undefined
      const oldSave = edit.save(
        () => new Promise<boolean>((resolve) => (releaseOld = resolve)),
      )
      edit.close()
      edit.open()
      edit.patchForm({ note: 'new' })
      let releaseNew: (saved: boolean) => void = () => undefined
      const newSave = edit.save(
        () => new Promise<boolean>((resolve) => (releaseNew = resolve)),
      )
      releaseOld(saved)
      await oldSave
      expect(edit.opened.get()).toBe(true)
      expect(edit.saving.get()).toBe(true)
      expect(edit.form.get()).toEqual({ name: 'Ann', note: 'new' })
      expect(edit.saveAvailable.get()).toBe(true)
      expect(edit.canSave.get()).toBe(false)
      releaseNew(false)
      await newSave
      expect(edit.saving.get()).toBe(false)
      expect(edit.canSave.get()).toBe(true)
    },
  )
})

describe('row-edit session: closing', () => {
  it('lets the focus go and forgets what the form must, keeping the rest', () => {
    const { edit, focus } = tableHarness()
    edit.open('legal.consent_form')
    edit.setForm({ value: 'line' })
    edit.close()
    expect(focus).toEqual(['legal.consent_form', ''])
    expect(edit.opened.get()).toBe(false)
    expect(edit.row.get()?.rowKey).toBe('legal.consent_form')
    expect(edit.form.get()).toEqual({ value: 'line' })
    expect(edit.state.get().gone).toBe(false)
    expect(edit.state.get().dirty).toBe(false)
  })

  it('applies forget on close and nothing on a second close', () => {
    const { edit } = signalHarness()
    edit.open()
    edit.setForm({ hidden: false, name: 'Bob', note: 'secret' })
    edit.close()
    expect(edit.form.get()).toEqual({ hidden: false, name: 'Bob', note: '' })
    edit.setForm({ hidden: false, name: 'Bob', note: 'again' })
    edit.close()
    expect(edit.form.get().note).toBe('again')
  })

  it('stops the steps on dispose and listens again on start', () => {
    const { edit, live } = signalHarness()
    edit.open()
    edit.dispose()
    expect(edit.opened.get()).toBe(false)
    edit.start()
    edit.open()
    live.set({ id: 1, name: 'Anna', note: null })
    expect(edit.form.get().name).toBe('Anna')
  })
})

describe('row-edit session: a save that waits for the live row', () => {
  function landing() {
    const h = signalHarness()
    const refusal = createSignal<string | null>(null)
    const sent: Fields[] = []
    h.edit.open()
    h.edit.patchForm({ name: 'Bob' })
    const send = (draft: Fields): boolean => {
      sent.push(draft)
      return true
    }

    return { ...h, refusal, sent, send }
  }

  it('closes the moment the live fields read as the draft sent, not another one', () => {
    const { edit, live, refusal, sent, send } = landing()
    edit.saveLanded(send, refusal)
    expect(sent).toEqual([{ name: 'Bob', note: null }])
    expect(edit.saving.get()).toBe(true)
    expect(edit.canSave.get()).toBe(false)
    expect(edit.saveAvailable.get()).toBe(true)
    edit.saveLanded(send, refusal)
    expect(sent).toHaveLength(1)
    live.set({ id: 1, name: 'Carl', note: null })
    expect(edit.opened.get()).toBe(true)
    expect(edit.state.get().conflict).toBe(true)
    expect(edit.saveAvailable.get()).toBe(false)
    edit.takeTheirs()
    expect(edit.form.get().name).toBe('Carl')
    expect(edit.saving.get()).toBe(true)
    live.set({ id: 1, name: 'Bob', note: null })
    expect(edit.opened.get()).toBe(false)
    expect(edit.saving.get()).toBe(false)
  })

  it('releases the window on the refusal and keeps it open', () => {
    const { edit, refusal, send } = landing()
    edit.saveLanded(send, refusal)
    refusal.set('No')
    expect(edit.saving.get()).toBe(false)
    expect(edit.opened.get()).toBe(true)
    expect(edit.form.get().name).toBe('Bob')
  })

  it('waits for nothing when the send did not leave', () => {
    const { edit, refusal } = landing()
    edit.saveLanded(() => false, refusal)
    expect(edit.saving.get()).toBe(false)
    expect(edit.opened.get()).toBe(true)
  })

  it('moves nothing on a landing after the window closed and opened again', () => {
    const { edit, live, refusal, send } = landing()
    edit.saveLanded(send, refusal)
    edit.close()
    expect(edit.saving.get()).toBe(false)
    edit.open()
    edit.patchForm({ name: 'Carl' })
    live.set({ id: 1, name: 'Bob', note: null })
    expect(edit.opened.get()).toBe(true)
    expect(edit.form.get().name).toBe('Carl')
  })
})

describe('row-edit session: a form held outside', () => {
  it('writes into the signal the window hands it', () => {
    const live = createSignal<Row | undefined>({
      id: 1,
      name: 'Ann',
      note: null,
    })
    const formSignal = createSignal<Fields>({ name: 'typed', note: null })
    const edit = createHilosRowEdit<Row, Fields>(
      hilosSignalRowEditSource(live),
      {
        formSignal,
        fields: (row) => ({ name: row.name, note: row.note }),
        notice: { conflict: () => '' },
      },
    )
    expect(edit.form.get()).toEqual({ name: 'typed', note: null })
    edit.open()
    expect(formSignal.get()).toEqual({ name: 'Ann', note: null })
    formSignal.set({ name: 'Bob', note: null })
    expect(edit.state.get().dirty).toBe(true)
    expect(edit.canSave.get()).toBe(true)
  })
})

describe('row-edit session: checkpoints', () => {
  it('restores detached baseline and rich form into a fresh opening without sending', async () => {
    const first = signalHarness()
    first.edit.open()
    first.edit.setForm({ hidden: false, name: '  Bob ', note: ' memo ' })
    const checkpoint = first.edit.checkpoint()!
    expectTypeOf(checkpoint).toEqualTypeOf<
      HilosRowEditCheckpoint<Fields, Form>
    >()
    expect(Object.keys(checkpoint).sort()).toEqual([
      'awaitingOccupation',
      'baseline',
      'form',
    ])
    expect(checkpoint.baseline.values).toEqual({ name: 'Ann', note: null })
    first.edit.dispose()
    const { edit } = signalHarness()
    expect(edit.restore(checkpoint)).toBe(false)
    expect(edit.opened.get()).toBe(false)
    edit.open()
    expect(edit.restore(checkpoint)).toBe(true)
    expect(edit.form.get()).toEqual({
      hidden: false,
      name: '  Bob ',
      note: ' memo ',
    })
    expect(edit.state.get().dirty).toBe(true)
    expect(edit.state.get().conflict).toBe(false)
    expect(edit.saving.get()).toBe(false)
    expect(edit.canSave.get()).toBe(true)
    const send = vi.fn(() => Promise.resolve(false))
    expect(send).not.toHaveBeenCalled()
    await edit.save(send)
    expect(send).toHaveBeenCalledExactlyOnceWith(
      { name: 'Bob', note: 'memo' },
      { id: 1, name: 'Ann', note: null },
    )
  })

  it('keeps the real baseline during a conflict instead of snapshotting incoming', () => {
    const first = signalHarness()
    first.edit.open()
    first.edit.patchForm({ name: 'Bob' })
    first.live.set({ id: 1, name: 'Carl', note: null })
    const checkpoint = first.edit.checkpoint()!
    expect(checkpoint.baseline.values.name).toBe('Ann')
    const { edit } = signalHarness({ id: 1, name: 'Dan', note: 'live' })
    edit.open()
    const fresh = edit.row.get()
    expect(edit.restore(checkpoint)).toBe(true)
    expect(edit.row.get()).toBe(fresh)
    expect(edit.form.get()).toEqual({
      hidden: false,
      name: 'Bob',
      note: 'live',
    })
    expect(edit.state.get().conflict).toBe(true)
    expect(edit.state.get().fields.note.refreshed).toBe(true)
    expect(edit.noticeText.get()).toBe('Changed to "Dan".')
    edit.keepMine()
    expect(edit.state.get().conflict).toBe(false)
    expect(edit.canSave.get()).toBe(true)
    expect(edit.noticeText.get()).toBe(HILOS_ROW_EDIT_COPY.updated)
    expect(edit.checkpoint()!.baseline.values).toEqual({
      name: 'Dan',
      note: 'live',
    })
  })

  it('silently takes changed incoming and keeps its refreshed mark across another restore', () => {
    const first = signalHarness()
    first.edit.open()
    first.edit.patchForm({ note: 'mine' })
    const second = signalHarness({ id: 1, name: 'Carl', note: null })
    second.edit.open()
    second.edit.restore(first.edit.checkpoint()!)
    expect(second.edit.form.get()).toEqual({
      hidden: false,
      name: 'Carl',
      note: 'mine',
    })
    expect(second.edit.state.get().conflict).toBe(false)
    expect(second.edit.noticeText.get()).toBe(HILOS_ROW_EDIT_COPY.updated)
    const third = signalHarness({ id: 1, name: 'Carl', note: null })
    third.edit.open()
    third.edit.restore(second.edit.checkpoint()!)
    expect(third.edit.state.get().fields.name.refreshed).toBe(true)
    expect(third.edit.noticeText.get()).toBe(HILOS_ROW_EDIT_COPY.updated)
    third.edit.patchForm({ name: 'Dan' })
    expect(third.edit.noticeText.get()).toBe('')
  })

  it('takes theirs after restore and compares later incoming against that choice', () => {
    const first = signalHarness()
    first.edit.open()
    first.edit.patchForm({ name: 'Bob' })
    const { edit, live } = signalHarness({ id: 1, name: 'Carl', note: null })
    edit.open()
    edit.restore(first.edit.checkpoint()!)
    expect(edit.canSave.get()).toBe(false)
    edit.takeTheirs()
    expect(edit.form.get().name).toBe('Carl')
    expect(edit.canSave.get()).toBe(false)
    expect(edit.noticeText.get()).toBe(HILOS_ROW_EDIT_COPY.updated)
    live.set({ id: 1, name: 'Dan', note: null })
    expect(edit.state.get().conflict).toBe(false)
    expect(edit.form.get().name).toBe('Dan')
  })

  it('detaches snapshot containers both when taking and when restoring them', () => {
    const first = signalHarness()
    first.edit.open()
    first.live.set({ id: 1, name: 'Carl', note: null })
    const checkpoint = first.edit.checkpoint()!
    checkpoint.baseline.values.name = 'changed outside'
    ;(checkpoint.baseline.refreshed as string[]).length = 0
    checkpoint.form.name = 'changed form'
    expect(first.edit.form.get().name).toBe('Carl')
    expect(first.edit.state.get().fields.name.refreshed).toBe(true)
    expect(first.edit.checkpoint()!.baseline.values.name).toBe('Carl')
    const restored = first.edit.checkpoint()!
    const second = signalHarness({ id: 1, name: 'Carl', note: null })
    second.edit.open()
    second.edit.restore(restored)
    restored.form.name = 'changed later'
    restored.baseline.values.name = 'changed baseline later'
    ;(restored.baseline.refreshed as string[]).length = 0
    expect(second.edit.form.get().name).toBe('Carl')
    expect(second.edit.state.get().dirty).toBe(false)
    expect(second.edit.state.get().fields.name.refreshed).toBe(true)
  })

  it('uses the current source key and cannot restore a missing or stolen table focus', () => {
    const first = tableHarness()
    first.edit.open('legal.consent_form')
    first.edit.patchForm({ value: 'line' })
    const checkpoint = first.edit.checkpoint()!
    first.edit.dispose()
    const { edit, table, focus } = tableHarness()
    expect(edit.open('legal.nowhere')).toBe(false)
    expect(edit.restore(checkpoint)).toBe(false)
    expect(focus).toEqual([])
    edit.open('legal.consent_form')
    expect(edit.restore(checkpoint)).toBe(true)
    expect(focus).toEqual(['legal.consent_form'])
    table.controller.focusRow('legal.on_lapse')
    expect(edit.restore(checkpoint)).toBe(false)
    expect(edit.state.get().gone).toBe(true)
    expect(edit.form.get()).toEqual({ value: 'line' })
    edit.close()
    edit.open('legal.on_lapse')
    expect(edit.restore(checkpoint)).toBe(true)
    expect(edit.row.get()?.rowKey).toBe('legal.on_lapse')
    expect(edit.state.get().conflict).toBe(true)
  })

  it('does not resurrect a missing signal row or a deleted row under an opening', () => {
    const { edit, live } = signalHarness()
    expect(edit.checkpoint()).toBeNull()
    edit.open()
    edit.patchForm({ name: 'Bob' })
    const checkpoint = edit.checkpoint()!
    live.set(undefined)
    expect(edit.restore(checkpoint)).toBe(false)
    expect(edit.state.get().gone).toBe(true)
    expect(edit.form.get().name).toBe('Bob')
    edit.close()
    expect(edit.checkpoint()).toBeNull()
    expect(edit.open()).toBe(false)
    expect(edit.restore(checkpoint)).toBe(false)
  })

  it('refuses inapplicable typed field sets and uncloneable data without replacing the edit', () => {
    const { edit } = signalHarness()
    edit.open()
    edit.patchForm({ name: 'Bob' })
    const before = edit.checkpoint()!
    const otherFields = {
      ...before,
      baseline: { values: { other: 'x' }, refreshed: [] },
    } as unknown as HilosRowEditCheckpoint<Fields, Form>
    expect(edit.restore(otherFields)).toBe(false)
    const callback = { ...before, form: { ...before.form, callback: vi.fn() } }
    expect(edit.restore(callback)).toBe(false)
    expect(callback.form.callback).not.toHaveBeenCalled()
    expect(edit.checkpoint()).toEqual(before)
    expect(edit.canSave.get()).toBe(true)
  })

  it('keeps canonical hidden identity with the default form and draft projections', () => {
    const first = creationHarness({
      id: 1,
      recordId: 7,
      name: HIDDEN_VALUE,
      note: null,
    })
    first.edit.open()
    first.edit.patchForm({ note: 'mine' })
    const second = creationHarness({
      id: 1,
      recordId: 7,
      name: HIDDEN_VALUE,
      note: null,
    })
    second.edit.open()
    expect(second.edit.restore(first.edit.checkpoint()!)).toBe(true)
    expect(second.edit.form.get().name).toBe(HIDDEN_VALUE)
    expect(second.edit.state.get().fields.name.status).toBe('unchanged')
    expect(second.edit.state.get().fields.name.refreshed).toBe(false)
    expect(second.edit.state.get().dirty).toBe(true)
    expect(second.edit.noticeText.get()).toBe('')
  })

  it('restores the first-occupation verdict without making an occupied place a creation', () => {
    const first = creationHarness()
    first.edit.open()
    const checkpoint = first.edit.checkpoint()!
    const empty = creationHarness()
    empty.edit.open()
    empty.edit.restore(checkpoint)
    expect(empty.edit.canSave.get()).toBe(true)
    const occupied = creationHarness({
      id: 1,
      recordId: 7,
      name: 'Ann',
      note: null,
    })
    occupied.edit.open()
    occupied.edit.restore(checkpoint)
    expect(occupied.edit.canSave.get()).toBe(false)
    expect(occupied.edit.noticeText.get()).toBe(HILOS_ROW_EDIT_COPY.updated)
    expect(occupied.edit.checkpoint()!.awaitingOccupation).toBe(false)
  })

  it('restores into an external rich form signal and retains draft/take/valid/forget', () => {
    const live = createSignal({ id: 1, name: 'Ann', note: null })
    const formSignal = createSignal({ input: '', details: { text: '' } })
    const edit = createHilosRowEdit(hilosSignalRowEditSource(live), {
      formSignal,
      fields: (row) => ({ name: row.name }),
      form: (row) => ({ input: row.name, details: { text: 'local' } }),
      draft: (form) => ({ name: form.input.trim() }),
      take: (form, taken) => ({ ...form, input: taken.name ?? form.input }),
      valid: (form) => form.input.trim().length >= 2,
      forget: (form) => ({ ...form, details: { text: '' } }),
      notice: { conflict: () => 'conflict' },
    })
    edit.start()
    edit.open()
    const checkpoint = edit.checkpoint()!
    edit.patchForm({ input: 'Bob' })
    live.set({ id: 1, name: 'Carl', note: null })
    expect(edit.restore(checkpoint)).toBe(true)
    expect(formSignal.get()).toEqual({
      input: 'Carl',
      details: { text: 'local' },
    })
    checkpoint.form.details.text = 'changed outside'
    expect(formSignal.get().details.text).toBe('local')
    edit.patchForm({ input: 'B' })
    const invalid = edit.checkpoint()!
    edit.patchForm({ input: 'Bob' })
    edit.restore(invalid)
    expect(formSignal.get().input).toBe('B')
    expect(edit.canSave.get()).toBe(false)
    edit.close()
    expect(formSignal.get().details.text).toBe('')
  })

  it.each(['save', 'saveLanded'] as const)(
    'does not carry %s pending state into a restored session or replace its in-flight edit',
    async (method) => {
      const first = signalHarness()
      first.edit.open()
      first.edit.patchForm({ name: 'Bob' })
      const before = first.edit.checkpoint()!
      let release: (saved: boolean) => void = () => undefined
      let pending: Promise<void> | undefined
      if (method === 'save') {
        pending = first.edit.save(
          () => new Promise<boolean>((resolve) => (release = resolve)),
        )
      } else {
        first.edit.saveLanded(() => true, createSignal<string | null>(null))
      }
      expect(first.edit.restore(before)).toBe(false)
      expect(first.edit.saving.get()).toBe(true)
      const whileSaving = first.edit.checkpoint()!
      const second = signalHarness()
      second.edit.open()
      expect(second.edit.restore(whileSaving)).toBe(true)
      expect(second.edit.saving.get()).toBe(false)
      expect(second.edit.opened.get()).toBe(true)
      expect(second.edit.canSave.get()).toBe(true)
      first.edit.dispose()
      release(true)
      await pending
      expect(second.edit.opened.get()).toBe(true)
    },
  )
})

describe('row-edit session: own-save rebase', () => {
  it('restores a draft equal to its old baseline against confirmed own saved fields before merging', () => {
    const first = signalHarness()
    first.edit.open()
    first.edit.patchForm({ name: 'Bob' })
    first.edit.patchForm({ name: 'Ann' })
    const checkpoint = first.edit.checkpoint()!
    const { edit } = signalHarness({ id: 1, name: 'Bob', note: null })
    edit.open()
    expect(edit.restore(checkpoint, { name: 'Bob', note: null })).toBe(true)
    expect(edit.form.get().name).toBe('Ann')
    expect(edit.checkpoint()!.baseline.values.name).toBe('Bob')
    expect(edit.state.get().dirty).toBe(true)
    expect(edit.state.get().conflict).toBe(false)
    expect(edit.noticeText.get()).toBe('')
    expect(edit.canSave.get()).toBe(true)
  })

  it('does not publish through a stale checkpoint or overwrite an opening changed by delivery', () => {
    const { edit } = signalHarness()
    edit.open()
    edit.patchForm({ name: 'Bob' })
    const sent = edit.checkpoint()!
    edit.close()
    const publish = vi.fn()
    expect(
      edit.rebaseAfterSave({ name: 'Bob', note: null }, sent, publish),
    ).toBe(false)
    expect(publish).not.toHaveBeenCalled()
    edit.open()
    edit.patchForm({ name: 'Carl' })
    const current = edit.checkpoint()!
    expect(
      edit.rebaseAfterSave({ name: 'Carl', note: null }, current, () => {
        edit.close()
        edit.open()
        edit.patchForm({ name: 'Dan' })
      }),
    ).toBe(false)
    expect(edit.form.get().name).toBe('Dan')
    expect(edit.checkpoint()!.baseline.values.name).toBe('Ann')
  })

  it('preserves a newer draft reverted to the old baseline while delivering its own echo', () => {
    const { edit, live } = signalHarness()
    edit.open()
    edit.patchForm({ name: 'Bob' })
    const sent = edit.checkpoint()!
    edit.patchForm({ name: 'Ann' })
    expect(
      edit.rebaseAfterSave({ name: 'Bob', note: null }, sent, () => {
        live.set({ id: 1, name: 'Bob', note: null })
      }),
    ).toBe(true)
    expect(edit.form.get().name).toBe('Ann')
    expect(edit.checkpoint()!.baseline.values.name).toBe('Bob')
    expect(edit.state.get().dirty).toBe(true)
    expect(edit.state.get().conflict).toBe(false)
    expect(edit.noticeText.get()).toBe('')
    live.set({ id: 1, name: 'Ann', note: null })
    expect(edit.state.get().dirty).toBe(false)
    expect(edit.noticeText.get()).toBe('')
  })

  it('accepts its own echo, preserves a newer draft and merges the next foreign change normally', () => {
    const { edit, live } = signalHarness()
    edit.open()
    edit.patchForm({ name: 'Bob' })
    const sent = edit.checkpoint()!
    edit.patchForm({ name: 'Carl' })
    live.set({ id: 1, name: 'Bob', note: null })
    expect(edit.state.get().conflict).toBe(true)
    expect(edit.rebaseAfterSave({ name: 'Bob', note: null }, sent)).toBe(true)
    expect(edit.form.get().name).toBe('Carl')
    expect(edit.state.get().dirty).toBe(true)
    expect(edit.state.get().conflict).toBe(false)
    expect(edit.state.get().fields.name.refreshed).toBe(false)
    expect(edit.noticeText.get()).toBe('')
    expect(edit.canSave.get()).toBe(true)
    expect(edit.opened.get()).toBe(true)
    expect(edit.checkpoint()!.baseline.values.name).toBe('Bob')
    live.set({ id: 1, name: 'Dan', note: 'theirs' })
    expect(edit.state.get().conflict).toBe(true)
    expect(edit.form.get()).toEqual({
      hidden: false,
      name: 'Carl',
      note: 'theirs',
    })
    edit.takeTheirs()
    expect(edit.form.get().name).toBe('Dan')
    expect(edit.noticeText.get()).toBe(HILOS_ROW_EDIT_COPY.updated)
  })

  it('keeps the shown form of an unchanged submission without closing', () => {
    const { edit, live } = signalHarness()
    edit.open()
    edit.patchForm({ name: '  Bob  ' })
    const sent = edit.checkpoint()!
    live.set({ id: 1, name: 'Bob', note: null })
    expect(edit.rebaseAfterSave({ name: 'Bob', note: null }, sent)).toBe(true)
    expect(edit.form.get().name).toBe('  Bob  ')
    expect(edit.state.get().dirty).toBe(false)
    expect(edit.state.get().notice).toBeNull()
    expect(edit.canSave.get()).toBe(false)
    expect(edit.opened.get()).toBe(true)
    live.set({ id: 1, name: 'Carl', note: null })
    expect(edit.form.get().name).toBe('Carl')
    expect(edit.noticeText.get()).toBe(HILOS_ROW_EDIT_COPY.updated)
  })

  it('preserves the form across accepted fields different from the submitted ones', () => {
    const { edit, live } = signalHarness()
    edit.open()
    edit.patchForm({ name: 'Bob', note: 'sent' })
    const sent = edit.checkpoint()!
    edit.patchForm({ note: 'newer' })
    live.set({ id: 1, name: 'BOB', note: 'sent' })
    expect(edit.rebaseAfterSave({ name: 'BOB', note: 'sent' }, sent)).toBe(true)
    expect(edit.form.get()).toEqual({
      hidden: false,
      name: 'Bob',
      note: 'newer',
    })
    expect(edit.noticeText.get()).toBe('')
    expect(edit.state.get().dirty).toBe(true)
    expect(edit.state.get().conflict).toBe(false)
  })

  it.each([true, false])(
    'retains a later accepted foreign value and its choice or refreshed mark, with local draft %s',
    (changed) => {
      const { edit, live } = signalHarness()
      edit.open()
      edit.patchForm({ name: 'Bob' })
      const sent = edit.checkpoint()!
      live.set({ id: 1, name: 'Bob', note: null })
      if (changed) edit.patchForm({ name: 'Carl' })
      live.set({ id: 1, name: 'Dan', note: null })
      if (changed) edit.keepMine()
      const notice = edit.noticeText.get()
      expect(edit.rebaseAfterSave({ name: 'Bob', note: null }, sent)).toBe(true)
      expect(edit.checkpoint()!.baseline.values.name).toBe('Dan')
      expect(edit.form.get().name).toBe(changed ? 'Carl' : 'Dan')
      expect(edit.noticeText.get()).toBe(notice)
      expect(edit.state.get().fields.name.refreshed).toBe(!changed)
      expect(edit.state.get().conflict).toBe(false)
    },
  )

  it('does not hide a foreign incoming that already followed the own commit', () => {
    const { edit, live } = signalHarness()
    edit.open()
    edit.patchForm({ name: 'Bob' })
    const sent = edit.checkpoint()!
    edit.patchForm({ name: 'Carl' })
    live.set({ id: 1, name: 'Dan', note: null })
    edit.rebaseAfterSave({ name: 'Bob', note: null }, sent)
    expect(edit.state.get().conflict).toBe(true)
    expect(edit.form.get().name).toBe('Carl')
    expect(edit.noticeText.get()).toBe('Changed to "Dan".')
  })

  it.each(['close', 'dispose', 'open', 'restore'] as const)(
    'ignores a checkpoint from before %s and never resurrects its opening',
    (move) => {
      const { edit, live } = signalHarness()
      edit.open()
      edit.patchForm({ name: 'Bob' })
      const old = edit.checkpoint()!
      if (move === 'close') edit.close()
      if (move === 'dispose') edit.dispose()
      if (move === 'open') edit.open()
      if (move === 'restore') edit.restore(old)
      const before = edit.form.get()
      expect(edit.rebaseAfterSave({ name: 'Bob', note: null }, old)).toBe(false)
      expect(edit.form.get()).toBe(before)
      expect(edit.opened.get()).toBe(move === 'open' || move === 'restore')
      if (move === 'close' || move === 'dispose') {
        live.set({ id: 1, name: 'Bob', note: null })
        edit.open()
        edit.patchForm({ name: 'Carl' })
        expect(edit.rebaseAfterSave({ name: 'Bob', note: null }, old)).toBe(
          false,
        )
        expect(edit.form.get().name).toBe('Carl')
      }
    },
  )

  it('requires a local current checkpoint after data restore and accepts it only once', () => {
    const first = signalHarness()
    first.edit.open()
    first.edit.patchForm({ name: 'Bob' })
    const serialized = JSON.parse(
      JSON.stringify(first.edit.checkpoint()),
    ) as HilosRowEditCheckpoint<Fields, Form>
    const { edit, live } = signalHarness()
    edit.open()
    edit.restore(serialized)
    const current = edit.checkpoint()!
    live.set({ id: 1, name: 'Bob', note: null })
    expect(edit.rebaseAfterSave({ name: 'Bob', note: null }, serialized)).toBe(
      false,
    )
    expect(
      edit.rebaseAfterSave(
        { name: 'Bob', note: null },
        first.edit.checkpoint()!,
      ),
    ).toBe(false)
    expect(edit.rebaseAfterSave({ name: 'Bob', note: null }, current)).toBe(
      true,
    )
    edit.patchForm({ name: 'Carl' })
    expect(edit.rebaseAfterSave({ name: 'Bob', note: null }, current)).toBe(
      false,
    )
  })

  it('keeps a newer restored draft when accepting the earlier save in a fresh opening', () => {
    const first = signalHarness()
    first.edit.open()
    first.edit.patchForm({ name: 'Carl' })
    const checkpoint = first.edit.checkpoint()!
    const { edit } = signalHarness({ id: 1, name: 'Bob', note: null })
    edit.open()
    edit.restore(checkpoint)
    const current = edit.checkpoint()!
    expect(edit.rebaseAfterSave({ name: 'Bob', note: null }, current)).toBe(
      true,
    )
    expect(edit.form.get().name).toBe('Carl')
    expect(edit.checkpoint()!.baseline.values.name).toBe('Bob')
    expect(edit.state.get().conflict).toBe(false)
    expect(edit.canSave.get()).toBe(true)
    expect(edit.noticeText.get()).toBe('')
  })

  it('does not unlock either existing in-flight save lifecycle', async () => {
    const { edit, live } = signalHarness()
    edit.open()
    edit.patchForm({ name: 'Bob' })
    const sent = edit.checkpoint()!
    let release: (saved: boolean) => void = () => undefined
    const pending = edit.save(
      () => new Promise<boolean>((resolve) => (release = resolve)),
    )
    live.set({ id: 1, name: 'Bob', note: null })
    expect(edit.rebaseAfterSave({ name: 'Bob', note: null }, sent)).toBe(false)
    expect(edit.saving.get()).toBe(true)
    expect(edit.canSave.get()).toBe(false)
    release(true)
    await pending
    expect(edit.opened.get()).toBe(false)
    edit.open()
    edit.patchForm({ name: 'Carl' })
    const next = edit.checkpoint()!
    edit.saveLanded(() => true, createSignal<string | null>(null))
    expect(edit.rebaseAfterSave({ name: 'Carl', note: null }, next)).toBe(false)
    expect(edit.saving.get()).toBe(true)
    edit.dispose()
  })
})
