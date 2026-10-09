// Covers what is the person card's name window's own over the row-edit
// session (HIL-1279): the bounds of a name, the success by the live name
// reaching the one sent, the refusal, a late landing after the window closed,
// and the refusal forgotten on open and close.
import { describe, expect, it } from 'vitest'
import {
  createHilosUserRenameEdit,
  HILOS_USER_NAME_MAX,
  HILOS_USER_NAME_MIN,
} from '../../../src/admin/users/hilosUserRenameEdit.js'
import {
  type HilosUserDetailRow,
  type HilosUserRename,
} from '../../../src/admin/users/hilosUsers.js'
import { HIDDEN_VALUE } from '../../../src/state/hiddenValue.js'
import { createSignal } from '../../../src/state/signal.js'

function harness(name: HilosUserDetailRow['name'] = 'Ann') {
  const detail = createSignal<HilosUserDetailRow | undefined>({
    id: 7,
    name,
  } as HilosUserDetailRow)
  const renameError = createSignal<string | null>('old refusal')
  const sent: [number, string][] = []
  let leaves = true
  const rename: HilosUserRename = {
    renameError,
    clearRenameError: () => renameError.set(null),
    submitRename: (id, next) => {
      sent.push([id, next])
      return leaves
    },
  }
  const edit = createHilosUserRenameEdit(detail, rename)
  edit.start()
  const renameTo = (next: string) =>
    detail.set({ id: 7, name: next } as HilosUserDetailRow)

  return {
    edit,
    detail,
    renameError,
    sent,
    renameTo,
    stay: () => (leaves = false),
  }
}

describe('person card name window', () => {
  it('holds Save outside the bounds and clears the old refusal on open', () => {
    const { edit, renameError, sent } = harness()
    expect(edit.open()).toBe(true)
    expect(renameError.get()).toBeNull()
    edit.setForm({ name: ' A ' })
    expect(edit.canSave.get()).toBe(false)
    edit.save()
    edit.setForm({ name: 'x'.repeat(HILOS_USER_NAME_MAX + 1) })
    expect(edit.canSave.get()).toBe(false)
    edit.save()
    edit.setForm({ name: 'x'.repeat(HILOS_USER_NAME_MIN) })
    expect(edit.canSave.get()).toBe(true)
    expect(sent).toEqual([])
  })

  it('sends the trimmed name and closes once the live name reaches it, not another one', () => {
    const { edit, sent, renameTo } = harness()
    edit.open()
    edit.setForm({ name: '  Bob  ' })
    edit.save()
    expect(sent).toEqual([[7, 'Bob']])
    expect(edit.saving.get()).toBe(true)
    renameTo('Carl')
    expect(edit.opened.get()).toBe(true)
    expect(edit.state.get().conflict).toBe(true)
    expect(edit.noticeText.get()).toBe('Changed elsewhere to "Carl".')
    edit.takeTheirs()
    expect(edit.form.get().name).toBe('Carl')
    renameTo('Bob')
    expect(edit.opened.get()).toBe(false)
    expect(edit.saving.get()).toBe(false)
  })

  it('releases the button on a refusal and keeps the window open', () => {
    const { edit, renameError } = harness()
    edit.open()
    edit.setForm({ name: 'Bob' })
    edit.save()
    renameError.set('That name is taken')
    expect(edit.saving.get()).toBe(false)
    expect(edit.opened.get()).toBe(true)
    expect(edit.form.get().name).toBe('Bob')
  })

  it('waits for nothing when the rename did not leave', () => {
    const { edit, sent, stay } = harness()
    stay()
    edit.open()
    edit.setForm({ name: 'Bob' })
    edit.save()
    expect(sent).toEqual([[7, 'Bob']])
    expect(edit.saving.get()).toBe(false)
  })

  it('moves nothing on a landing after the window closed, and forgets the refusal on close', () => {
    const { edit, renameError, renameTo } = harness()
    edit.open()
    edit.setForm({ name: 'Bob' })
    edit.save()
    renameError.set('late')
    edit.close()
    expect(renameError.get()).toBeNull()
    edit.open()
    edit.setForm({ name: 'Carl' })
    renameTo('Bob')
    expect(edit.opened.get()).toBe(true)
    expect(edit.form.get().name).toBe('Carl')
  })

  it('opens a hidden name hidden and never saves it', () => {
    const { edit, sent } = harness(HIDDEN_VALUE)
    edit.open()
    expect(edit.form.get().name).toBe(HIDDEN_VALUE)
    expect(edit.canSave.get()).toBe(false)
    edit.save()
    expect(sent).toEqual([])
    expect(edit.opened.get()).toBe(true)
  })
})
