import { describe, expect, it } from 'vitest'
import { createHilosLegalSettingEdit } from '../../../src/admin/legal/hilosLegalSettingsEdit.js'
import {
  createHilosLegalSettingsActions,
  createHilosLegalSettingsTable,
} from '../../../src/admin/legal/hilosLegal.js'
import { type ActionHandle } from '../../../src/connection/actionLifecycle.js'
import { type HilosLegalContext } from '../../../src/legal/legalAgreements.js'
import { HIDDEN_VALUE } from '../../../src/state/hiddenValue.js'

function harness(initial: unknown = 'checkbox') {
  const focus: string[] = []
  const dispatched: { name: string; payload: unknown }[] = []
  const context = {
    connection: {
      sendTableRowFocus: (_page: string, _table: string, key: string) =>
        focus.push(key),
    },
    actions: {
      dispatch: (name: string, payload: unknown) => {
        dispatched.push({ name, payload })
        return { done: Promise.resolve({}) } as unknown as ActionHandle
      },
    },
  } as unknown as HilosLegalContext
  const table = createHilosLegalSettingsTable(context)
  const editor = createHilosLegalSettingEdit(
    table.controller,
    createHilosLegalSettingsActions(context),
  )
  const window = (value: unknown) =>
    table.controller.ingestWindow(
      [
        {
          rowKey: 'legal.consent_form',
          slots: { setting: { value, defaultValue: 'checkbox' } },
        },
      ],
      1,
      true,
      null,
      null,
      25,
    )
  window(initial)
  editor.start()
  editor.open('legal.consent_form')
  return { editor, table, window, focus, dispatched }
}

describe('legal setting edit sessions', () => {
  it('opens a value hidden from a viewer as the one hidden value, never dirty (HIL-1260)', () => {
    const h = harness({ _hidden: true })
    expect(h.editor.form.get().value).toBe(HIDDEN_VALUE)
    expect(h.editor.state.get().dirty).toBe(false)

    // The next window sends the mark again: still the same value, nothing to say.
    h.window({ _hidden: true })
    expect(h.editor.state.get().dirty).toBe(false)
    expect(h.editor.state.get().notice).toBeNull()
    expect(h.editor.noticeText.get()).toBe('')
    expect(h.editor.canSave.get()).toBe(false)
    h.editor.dispose()
  })

  it('freezes the baseline, keeps drafts out of the row, and releases focus on close', () => {
    const h = harness()
    expect(h.editor.form.get().value).toBe('checkbox')
    expect(h.editor.state.get().dirty).toBe(false)
    h.editor.setForm({ value: 'line' })
    expect(h.editor.state.get().dirty).toBe(true)
    expect(h.editor.canSave.get()).toBe(true)
    expect(h.table.controller.focusedRow.get()?.value).toBe('checkbox')
    expect(h.focus[0]).toBe('legal.consent_form')
    h.editor.close()
    expect(h.editor.opened.get()).toBe(false)
    expect(h.focus[1]).toBe('')
    h.editor.dispose()
  })

  it('silently takes an incoming value while the draft is untouched', () => {
    const h = harness()
    h.window('line')
    expect(h.editor.form.get().value).toBe('line')
    expect(h.editor.state.get().dirty).toBe(false)
    expect(h.editor.state.get().notice?.kind).toBe('updated')
    expect(h.editor.noticeText.get()).toBe('Updated just now')
    h.editor.setForm({ value: 'checkbox' })
    expect(h.editor.state.get().notice).toBeNull()
    h.editor.dispose()
  })

  it('merges a converged value without inventing a conflict', () => {
    const h = harness()
    h.editor.setForm({ value: 'line' })
    h.window('line')
    expect(h.editor.state.get().conflict).toBe(false)
    expect(h.editor.state.get().dirty).toBe(false)
    h.editor.dispose()
  })

  it("says the other side's value in the setting's words on a conflict", () => {
    const h = harness()
    h.editor.setForm({ value: 'line' })
    h.window('remind')
    expect(h.editor.state.get().conflict).toBe(true)
    expect(h.editor.noticeText.get()).toBe(
      'Changed elsewhere to "Keep reminding".',
    )
    expect(h.editor.canSave.get()).toBe(false)
    h.editor.keepMine()
    expect(h.editor.canSave.get()).toBe(true)
    h.editor.dispose()
  })

  it('keeps a draft visible if the server reports the focused row gone', () => {
    const h = harness()
    h.editor.setForm({ value: 'line' })
    h.table.controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'legal.consent_form',
      reason: 'deleted',
    })
    expect(h.editor.row.get()?.rowKey).toBe('legal.consent_form')
    expect(h.editor.state.get().gone).toBe(true)
    expect(h.editor.state.get().dirty).toBe(false)
    expect(h.editor.form.get().value).toBe('line')
    expect(h.editor.noticeText.get()).toBe(
      'Deleted elsewhere — your choice stays visible.',
    )
    expect(h.editor.saveLabel.get()).toBe('Deleted')
    h.editor.dispose()
  })

  it('sends the chosen value through the runner and closes on its success', async () => {
    const h = harness()
    const runs: ActionHandle[] = []
    await h.editor.save((handle) => {
      runs.push(handle)
      return Promise.resolve(true)
    })
    expect(runs).toEqual([])
    expect(h.editor.opened.get()).toBe(false)

    h.editor.open('legal.consent_form')
    h.editor.setForm({ value: 'line' })
    await h.editor.save((handle) => {
      runs.push(handle)
      return Promise.resolve(true)
    })
    expect(runs).toHaveLength(1)
    expect(h.dispatched).toEqual([
      {
        name: 'legal_setting_set',
        payload: { key: 'legal.consent_form', value: 'line' },
      },
    ])
    expect(h.editor.opened.get()).toBe(false)
    h.editor.dispose()
  })
})
