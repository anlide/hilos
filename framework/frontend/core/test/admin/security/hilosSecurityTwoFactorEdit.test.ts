// Covers what is the two-step verification window's own over the row-edit
// session (HIL-1279): the draft trimmed, the value in words about the other
// side, and the send.
import { describe, expect, it, vi } from 'vitest'
import { createHilosTwoFactorSettingEdit } from '../../../src/admin/security/hilosSecurityTwoFactorEdit.js'
import {
  HilosSecondFactorSettingKey,
  type HilosTwoFactorActions,
  type HilosTwoFactorSettingRow,
} from '../../../src/admin/security/hilosSecurityTwoFactor.js'
import { type ActionHandle } from '../../../src/connection/actionLifecycle.js'
import { createSignal } from '../../../src/state/signal.js'
import { type TableViewportController } from '../../../src/table/TableViewportController.js'

function harness(initial: HilosTwoFactorSettingRow) {
  const focused = createSignal<HilosTwoFactorSettingRow | undefined>(undefined)
  const controller = {
    focusRow: (key: string) => {
      if (key !== initial.rowKey) return null
      focused.set(initial)
      return initial
    },
    focusedRow: focused,
    releaseFocus: () => focused.set(undefined),
  } as unknown as TableViewportController<HilosTwoFactorSettingRow>
  const sent: string[][] = []
  const actions: HilosTwoFactorActions = {
    sendSettingSet: (key, value) => (
      sent.push([key, value]),
      {} as ActionHandle
    ),
  }
  const edit = createHilosTwoFactorSettingEdit(controller, actions)
  edit.start()
  const run = vi.fn(() => Promise.resolve(true))

  return { edit, focused, sent, run }
}

const trust = {
  rowKey: HilosSecondFactorSettingKey.trustDays,
  value: '30',
  defaultValue: '30',
} as HilosTwoFactorSettingRow

describe('two-step verification edit window', () => {
  it('trims the draft and sends it trimmed', async () => {
    const { edit, sent, run } = harness(trust)
    edit.open(trust.rowKey)
    edit.patchForm({ text: ' 30 ' })
    expect(edit.state.get().dirty).toBe(false)
    edit.patchForm({ text: ' 7 ' })
    expect(edit.state.get().dirty).toBe(true)
    await edit.save(run)
    expect(sent).toEqual([[trust.rowKey, '7']])
  })

  it("says the other side's value in words", () => {
    const { edit, focused } = harness(trust)
    edit.open(trust.rowKey)
    edit.patchForm({ text: '7' })
    focused.set({ ...trust, value: '0' })
    expect(edit.state.get().conflict).toBe(true)
    expect(edit.noticeText.get()).toBe('Changed elsewhere to "Off".')
  })
})
