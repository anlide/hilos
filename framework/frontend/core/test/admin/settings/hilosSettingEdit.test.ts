// Covers what is the settings window's own over the row-edit session
// (HIL-1279): the form of switch and text, the choice of send — reset, update
// of an orphan, add by key — the text left on the value in effect when a reset
// comes from the other side, and the words of a reset elsewhere.
import { describe, expect, it, vi } from 'vitest'
import { createHilosSettingEdit } from '../../../src/admin/settings/hilosSettingEdit.js'
import {
  type HilosSettingRow,
  type HilosSettingsActions,
} from '../../../src/admin/settings/hilosSettings.js'
import { type ActionHandle } from '../../../src/connection/actionLifecycle.js'
import { HIDDEN_VALUE } from '../../../src/state/hiddenValue.js'
import { createSignal } from '../../../src/state/signal.js'
import { type TableViewportController } from '../../../src/table/TableViewportController.js'

function row(partial: Partial<HilosSettingRow>): HilosSettingRow {
  return {
    key: 'app.name',
    type: 'string',
    value: 'Hilos',
    overrideValue: null,
    defaultValue: 'Hilos',
    defaultReferenceKey: null,
    valueSource: 'default',
    ...partial,
  } as HilosSettingRow
}

function harness(rows: HilosSettingRow[]) {
  const focused = createSignal<HilosSettingRow | undefined>(undefined)
  const controller = {
    focusRow: (key: string) => {
      const found = rows.find((candidate) => candidate.key === key) ?? null
      if (found !== null) focused.set(found)
      return found
    },
    focusedRow: focused,
    releaseFocus: () => focused.set(undefined),
  } as unknown as TableViewportController<HilosSettingRow>
  const sent: string[] = []
  const handle = {} as ActionHandle
  const actions: HilosSettingsActions = {
    sendSettingAdd: (key, value) => (sent.push(`add ${key}=${value}`), handle),
    sendSettingUpdate: (key, value) => (
      sent.push(`update ${key}=${value}`),
      handle
    ),
    sendSettingDelete: (key) => (sent.push(`delete ${key}`), handle),
    sendSettingReset: (key) => (sent.push(`reset ${key}`), handle),
  }
  const edit = createHilosSettingEdit(controller, actions)
  edit.start()
  const run = vi.fn(() => Promise.resolve(true))

  return { edit, focused, sent, run }
}

describe('settings edit window', () => {
  it('opens a cataloged key on its default with the switch off and the effective value as text', () => {
    const { edit } = harness([row({})])
    edit.open('app.name')
    expect(edit.form.get()).toEqual({
      hidden: false,
      useCustom: false,
      text: 'Hilos',
    })
    expect(edit.state.get().dirty).toBe(false)
  })

  it('adds by key when the switch goes on with a value, and resets when it goes off', async () => {
    const { edit, sent, run } = harness([
      row({ overrideValue: 'Mine', valueSource: 'override' }),
    ])
    edit.open('app.name')
    expect(edit.form.get()).toEqual({
      hidden: false,
      useCustom: true,
      text: 'Mine',
    })
    edit.patchForm({ useCustom: false })
    expect(edit.state.get().dirty).toBe(true)
    await edit.save(run)
    expect(sent).toEqual(['reset app.name'])
    expect(edit.opened.get()).toBe(false)

    edit.open('app.name')
    edit.patchForm({ text: 'Other' })
    await edit.save(run)
    expect(sent).toEqual(['reset app.name', 'add app.name=Other'])
  })

  it('updates an orphan in place', async () => {
    const { edit, sent, run } = harness([
      row({
        key: 'old.key',
        overrideValue: 'x',
        defaultValue: null,
        valueSource: 'orphan',
      }),
    ])
    edit.open('old.key')
    edit.patchForm({ text: 'y' })
    await edit.save(run)
    expect(sent).toEqual(['update old.key=y'])
  })

  it('says a reset elsewhere in its own words, and leaves the text on the value in effect when taken', () => {
    const { edit, focused } = harness([
      row({ overrideValue: 'Mine', valueSource: 'override', value: 'Mine' }),
    ])
    edit.open('app.name')
    edit.patchForm({ text: 'Typed' })
    focused.set(row({ overrideValue: null, value: 'Hilos' }))
    expect(edit.state.get().conflict).toBe(true)
    expect(edit.noticeText.get()).toBe(
      'Reset elsewhere to the catalog default.',
    )
    edit.takeTheirs()
    expect(edit.form.get()).toEqual({
      hidden: false,
      useCustom: false,
      text: 'Hilos',
    })
    focused.set(row({ overrideValue: 'Theirs', value: 'Theirs' }))
    expect(edit.noticeText.get()).toBe('Updated just now')
    expect(edit.form.get()).toEqual({
      hidden: false,
      useCustom: true,
      text: 'Theirs',
    })
  })

  it('opens a hidden override hidden: an empty form that can never save', async () => {
    const { edit, sent, run } = harness([
      row({ overrideValue: HIDDEN_VALUE, value: HIDDEN_VALUE }),
    ])
    edit.open('app.name')
    expect(edit.form.get()).toEqual({
      hidden: true,
      useCustom: false,
      text: '',
    })
    expect(edit.canSave.get()).toBe(false)
    await edit.save(run)
    expect(sent).toEqual([])
    expect(edit.opened.get()).toBe(false)
  })
})
