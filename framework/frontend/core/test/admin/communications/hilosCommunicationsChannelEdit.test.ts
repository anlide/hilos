// Covers what is the channel field window's own over the row-edit session
// (HIL-1279): the text of a typed value and the typed value read back from it,
// a hidden field, the words about the other side, and the send.
import { describe, expect, it, vi } from 'vitest'
import {
  createHilosChannelFieldEdit,
  hilosChannelDisplayValue,
  hilosChannelEditedValue,
  hilosChannelFormText,
} from '../../../src/admin/communications/hilosCommunicationsChannelEdit.js'
import {
  type HilosChannelFieldRow,
  type HilosCommunicationsActions,
} from '../../../src/admin/communications/hilosCommunications.js'
import { type ActionHandle } from '../../../src/connection/actionLifecycle.js'
import { HIDDEN_VALUE } from '../../../src/state/hiddenValue.js'
import { createSignal } from '../../../src/state/signal.js'
import { type TableViewportController } from '../../../src/table/TableViewportController.js'

function row(partial: Partial<HilosChannelFieldRow>): HilosChannelFieldRow {
  return {
    key: 'smtp.port',
    channel: 'smtp',
    field: 'port',
    label: 'Port',
    type: 'integer',
    value: 25,
    ...partial,
  } as HilosChannelFieldRow
}

function harness(rows: HilosChannelFieldRow[]) {
  const focused = createSignal<HilosChannelFieldRow | undefined>(undefined)
  const controller = {
    focusRow: (key: string) => {
      const found = rows.find((candidate) => candidate.key === key) ?? null
      if (found !== null) focused.set(found)
      return found
    },
    focusedRow: focused,
    releaseFocus: () => focused.set(undefined),
  } as unknown as TableViewportController<HilosChannelFieldRow>
  const sent: unknown[][] = []
  const actions = {
    sendChannelSet: (...args: unknown[]) => (
      sent.push(args),
      {} as ActionHandle
    ),
  } as unknown as HilosCommunicationsActions
  const edit = createHilosChannelFieldEdit(controller, actions)
  edit.start()
  const run = vi.fn(() => Promise.resolve(true))

  return { edit, focused, sent, run }
}

describe('channel field edit window', () => {
  it('reads and writes a typed value as text', () => {
    expect(hilosChannelFormText('boolean', true)).toBe('1')
    expect(hilosChannelFormText('boolean', null)).toBe('0')
    expect(hilosChannelFormText('integer', 25)).toBe('25')
    expect(hilosChannelFormText('string', null)).toBe('')
    expect(hilosChannelEditedValue('boolean', '1')).toBe(true)
    expect(hilosChannelEditedValue('float', '2.5')).toBe(2.5)
    expect(hilosChannelEditedValue('string', 'x')).toBe('x')
    expect(hilosChannelDisplayValue(false)).toBe('Off')
    expect(hilosChannelDisplayValue('')).toBe('—')
    expect(hilosChannelDisplayValue(25)).toBe('25')
  })

  it('opens on the text of the value and sends the typed value', async () => {
    const { edit, sent, run } = harness([row({})])
    edit.open('smtp.port')
    expect(edit.form.get()).toEqual({ hidden: false, text: '25' })
    edit.patchForm({ text: '587' })
    expect(edit.state.get().dirty).toBe(true)
    await edit.save(run)
    expect(sent).toEqual([['smtp', 'port', 587]])
    expect(edit.opened.get()).toBe(false)
  })

  it("says the other side's value as the cell does, and takes it as text", () => {
    const { edit, focused } = harness([row({})])
    edit.open('smtp.port')
    edit.patchForm({ text: '587' })
    focused.set(row({ value: 465 }))
    expect(edit.state.get().conflict).toBe(true)
    expect(edit.noticeText.get()).toBe('Changed elsewhere to "465".')
    edit.takeTheirs()
    expect(edit.form.get().text).toBe('465')
    expect(edit.state.get().conflict).toBe(false)
  })

  it("opens a switch on its text and takes the other side's position silently", () => {
    const tls = { key: 'smtp.tls', field: 'tls', type: 'boolean' }
    const { edit, focused } = harness([row({ ...tls, value: false })])
    edit.open('smtp.tls')
    expect(edit.form.get().text).toBe('0')
    focused.set(row({ ...tls, value: true }))
    expect(edit.form.get().text).toBe('1')
    expect(edit.noticeText.get()).toBe('Updated just now')
  })

  it('opens a hidden value hidden and never saves it', async () => {
    const { edit, sent, run } = harness([row({ value: HIDDEN_VALUE })])
    edit.open('smtp.port')
    expect(edit.form.get()).toEqual({ hidden: true, text: '' })
    expect(edit.canSave.get()).toBe(false)
    await edit.save(run)
    expect(sent).toEqual([])
    expect(edit.opened.get()).toBe(false)
  })
})
