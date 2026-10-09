// Covers the row-edit session (HIL-1279): opening and the refusal on a missing
// row, the key check of the table source, the step landing in the form with a
// hidden value left out, Keep mine / Take theirs, the one verdict "can save",
// the one door of the send — unchanged closes, blocked sends nothing, success
// closes, failure stays, a late outcome moves nothing — the close that lets the
// row go and forgets, and the save that waits for the live row.
import { describe, expect, it, vi } from 'vitest'
import {
  createHilosRowEdit,
  hilosRowEditIdle,
  hilosSignalRowEditSource,
  hilosTableRowEditSource,
  HILOS_ROW_EDIT_COPY,
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
    live.set({ id: 1, name: 'Carl', note: null })
    expect(edit.opened.get()).toBe(true)
    expect(edit.state.get().conflict).toBe(true)
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
