import { describe, expect, it } from 'vitest'
import { createHilosLegalSettingEdit } from '../../../src/admin/legal/hilosLegalSettingsEdit.js'
import { createHilosLegalSettingsTable } from '../../../src/admin/legal/hilosLegal.js'
import { type HilosLegalContext } from '../../../src/legal/legalAgreements.js'
import { HIDDEN_VALUE } from '../../../src/state/hiddenValue.js'

function harness(initial: unknown = 'checkbox') {
  const focus: string[] = []
  const context = {
    connection: {
      sendTableRowFocus: (_page: string, _table: string, key: string) =>
        focus.push(key),
    },
  } as unknown as HilosLegalContext
  const table = createHilosLegalSettingsTable(context)
  const editor = createHilosLegalSettingEdit(table.controller)
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
  return { editor, table, window, focus }
}

describe('legal setting edit sessions', () => {
  it('opens a value hidden from a viewer as the one hidden value, never dirty (HIL-1260)', () => {
    const h = harness({ _hidden: true })
    expect(h.editor.value.get()).toBe(HIDDEN_VALUE)
    expect(h.editor.state.get().dirty).toBe(false)

    // The next window sends the mark again: still the same value, nothing to say.
    h.window({ _hidden: true })
    expect(h.editor.state.get().dirty).toBe(false)
    expect(h.editor.state.get().notice).toBeNull()
    expect(h.editor.noticeText.get()).toBe('')
    h.editor.dispose()
  })

  it('freezes the baseline, keeps drafts out of the row, and releases focus on close', () => {
    const h = harness()
    expect(h.editor.value.get()).toBe('checkbox')
    expect(h.editor.state.get().dirty).toBe(false)
    h.editor.setValue('line')
    expect(h.editor.state.get().dirty).toBe(true)
    expect(h.table.controller.focusedRow.get()?.value).toBe('checkbox')
    expect(h.focus[0]).toBe('legal.consent_form')
    h.editor.close()
    expect(h.editor.row.get()).toBeNull()
    h.editor.dispose()
  })

  it('silently takes an incoming value while the draft is untouched', () => {
    const h = harness()
    h.window('line')
    expect(h.editor.value.get()).toBe('line')
    expect(h.editor.state.get().dirty).toBe(false)
    expect(h.editor.state.get().notice?.kind).toBe('updated')
    h.editor.setValue('checkbox')
    expect(h.editor.state.get().notice).toBeNull()
    h.editor.dispose()
  })

  it('merges a converged value without inventing a conflict', () => {
    const h = harness()
    h.editor.setValue('line')
    h.window('line')
    expect(h.editor.state.get().conflict).toBe(false)
    expect(h.editor.state.get().dirty).toBe(false)
    h.editor.dispose()
  })

  it('keeps a draft visible if the server reports the focused row gone', () => {
    const h = harness()
    h.editor.setValue('line')
    h.table.controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'legal.consent_form',
      reason: 'deleted',
    })
    expect(h.editor.row.get()?.rowKey).toBe('legal.consent_form')
    expect(h.editor.state.get().gone).toBe(true)
    expect(h.editor.state.get().dirty).toBe(false)
    expect(h.editor.value.get()).toBe('line')
    h.editor.dispose()
  })
})
