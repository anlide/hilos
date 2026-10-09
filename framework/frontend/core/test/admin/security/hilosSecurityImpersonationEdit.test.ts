// Covers what is the impersonation scope window's own over the row-edit
// session (HIL-1279): the scope read out of the row, its own words for a row
// deleted elsewhere, the words about the other side, and the send.
import { describe, expect, it, vi } from 'vitest'
import { createHilosImpersonationScopeEdit } from '../../../src/admin/security/hilosSecurityImpersonationEdit.js'
import {
  HilosImpersonationSettingKey,
  type HilosImpersonationActions,
  type HilosImpersonationSettingRow,
} from '../../../src/admin/security/hilosSecurityImpersonation.js'
import { type ActionHandle } from '../../../src/connection/actionLifecycle.js'
import { HIDDEN_VALUE } from '../../../src/state/hiddenValue.js'
import { createSignal } from '../../../src/state/signal.js'
import { type TableViewportController } from '../../../src/table/TableViewportController.js'

const scopeRow = {
  rowKey: HilosImpersonationSettingKey.scope,
  value: '',
  defaultValue: 'act',
} as HilosImpersonationSettingRow

function harness(initial: HilosImpersonationSettingRow) {
  const focused = createSignal<HilosImpersonationSettingRow | undefined>(
    undefined,
  )
  const controller = {
    focusRow: (key: string) => {
      if (key !== initial.rowKey) return null
      focused.set(initial)
      return initial
    },
    focusedRow: focused,
    releaseFocus: () => focused.set(undefined),
  } as unknown as TableViewportController<HilosImpersonationSettingRow>
  const sent: unknown[] = []
  const actions = {
    sendScopeSet: (scope: unknown) => (sent.push(scope), {} as ActionHandle),
  } as unknown as HilosImpersonationActions
  const edit = createHilosImpersonationScopeEdit(controller, actions)
  edit.start()

  return { edit, focused, sent }
}

describe('impersonation scope window', () => {
  it('reads the scope out of the row, and sends the one chosen', async () => {
    const { edit, sent } = harness(scopeRow)
    edit.open(scopeRow.rowKey)
    expect(edit.form.get()).toEqual({ hidden: false, scope: 'act' })
    edit.patchForm({ scope: 'view' })
    expect(edit.state.get().dirty).toBe(true)
    await edit.save(vi.fn(() => Promise.resolve(true)))
    expect(sent).toEqual(['view'])
  })

  it('says the other side in the scope words, and a deletion in its own', () => {
    const { edit, focused } = harness(scopeRow)
    edit.open(scopeRow.rowKey)
    edit.patchForm({ scope: 'view' })
    focused.set({ ...scopeRow, value: 'act' })
    expect(edit.state.get().conflict).toBe(false)
    focused.set({ ...scopeRow, value: 'view' })
    expect(edit.state.get().dirty).toBe(false)
    edit.patchForm({ scope: 'act' })
    focused.set({ ...scopeRow, value: 'something' })
    expect(edit.state.get().conflict).toBe(false)
    focused.set(undefined)
    expect(edit.noticeText.get()).toBe(
      'Deleted elsewhere — your choice stays on screen.',
    )
  })

  it('opens a hidden scope hidden', () => {
    const { edit } = harness({ ...scopeRow, value: HIDDEN_VALUE })
    edit.open(scopeRow.rowKey)
    expect(edit.form.get()).toEqual({ hidden: true, scope: 'act' })
    expect(edit.state.get().fields.scope.incoming).toBe(HIDDEN_VALUE)
    expect(edit.canSave.get()).toBe(false)
  })
})
