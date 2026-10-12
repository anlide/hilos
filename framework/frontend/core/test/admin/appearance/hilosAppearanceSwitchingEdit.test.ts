import { describe, expect, it, vi } from 'vitest'
import {
  createHilosAppearanceActions,
  HilosAppearanceSettingKey,
  type HilosAppearanceSettingRow,
} from '../../../src/admin/appearance/hilosAppearance.js'
import { createHilosAppearanceSwitchingEdit } from '../../../src/admin/appearance/hilosAppearanceSwitchingEdit.js'
import {
  type ActionHandle,
  type ActionLifecycle,
} from '../../../src/connection/actionLifecycle.js'
import { HIDDEN_VALUE } from '../../../src/state/hiddenValue.js'
import { createSignal } from '../../../src/state/signal.js'
import { type TableViewportController } from '../../../src/table/TableViewportController.js'

const ROW_KEY = HilosAppearanceSettingKey.switchingEnabled

function row(
  value: HilosAppearanceSettingRow['value'],
): HilosAppearanceSettingRow {
  return { rowKey: ROW_KEY, value, defaultValue: true }
}

function harness(initial = row(true)) {
  const focused = createSignal<HilosAppearanceSettingRow | undefined>(undefined)
  const releaseFocus = vi.fn(() => focused.set(undefined))
  const controller = {
    focusRow: (key: string) => {
      if (key !== initial.rowKey) return null
      focused.set(initial)
      return initial
    },
    focusedRow: focused,
    releaseFocus,
  } as unknown as TableViewportController<HilosAppearanceSettingRow>
  const handle = {} as ActionHandle
  const dispatch = vi.fn(() => handle)
  const actions = createHilosAppearanceActions({
    actions: { dispatch } as unknown as ActionLifecycle,
  })
  const edit = createHilosAppearanceSwitchingEdit(controller, actions)
  edit.start()

  return { edit, focused, dispatch, handle, releaseFocus }
}

describe('Appearance switching actions', () => {
  it('dispatches the approved name and boolean payload and returns the handle', () => {
    const handle = {} as ActionHandle
    const dispatch = vi.fn(() => handle)
    const actions = createHilosAppearanceActions({
      actions: { dispatch } as unknown as ActionLifecycle,
    })

    expect(actions.sendSwitchingSet(false)).toBe(handle)
    expect(actions.sendSwitchingSet(true)).toBe(handle)
    expect(dispatch.mock.calls).toEqual([
      ['appearance_theme_switching_set', { enabled: false }],
      ['appearance_theme_switching_set', { enabled: true }],
    ])
  })
})

describe('Appearance switching window', () => {
  it.each([true, false])(
    'opens with the row value %s and no dirty draft',
    (enabled) => {
      const { edit } = harness(row(enabled))
      expect(edit.open(ROW_KEY)).toBe(true)
      expect(edit.form.get()).toEqual({ hidden: false, enabled })
      expect(edit.state.get().dirty).toBe(false)
      expect(edit.canSave.get()).toBe(false)
      edit.dispose()
    },
  )

  it('sends the changed boolean and closes only when the tracked runner succeeds', async () => {
    const { edit, dispatch, handle, releaseFocus } = harness()
    edit.open(ROW_KEY)
    edit.patchForm({ enabled: false })
    expect(edit.canSave.get()).toBe(true)
    let answer!: (saved: boolean) => void
    const run = vi.fn(
      () =>
        new Promise<boolean>((resolve) => {
          answer = resolve
        }),
    )
    const saving = edit.save(run)

    expect(dispatch).toHaveBeenCalledWith('appearance_theme_switching_set', {
      enabled: false,
    })
    expect(run).toHaveBeenCalledWith(handle)
    expect(edit.opened.get()).toBe(true)
    expect(edit.saving.get()).toBe(true)
    expect(edit.canSave.get()).toBe(false)
    answer(true)
    await saving
    expect(edit.opened.get()).toBe(false)
    expect(releaseFocus).toHaveBeenCalledOnce()
    edit.dispose()
  })

  it('keeps a refused draft open and available to save again', async () => {
    const { edit } = harness()
    edit.open(ROW_KEY)
    edit.patchForm({ enabled: false })
    await edit.save(() => Promise.resolve(false))
    expect(edit.opened.get()).toBe(true)
    expect(edit.form.get().enabled).toBe(false)
    expect(edit.canSave.get()).toBe(true)
    edit.dispose()
  })

  it('closes an unchanged draft without dispatching', async () => {
    const { edit, dispatch } = harness()
    edit.open(ROW_KEY)
    await edit.save(vi.fn())
    expect(dispatch).not.toHaveBeenCalled()
    expect(edit.opened.get()).toBe(false)
    edit.dispose()
  })

  it('takes a pristine incoming value and settles a matching boolean draft', () => {
    const { edit, focused } = harness()
    edit.open(ROW_KEY)
    focused.set(row(false))
    expect(edit.form.get().enabled).toBe(false)
    expect(edit.noticeText.get()).toBe('Updated just now')
    expect(edit.canSave.get()).toBe(false)
    edit.patchForm({ enabled: true })
    focused.set(row(true))
    expect(edit.state.get().conflict).toBe(false)
    expect(edit.state.get().dirty).toBe(false)
    edit.dispose()
  })

  it.each([true, false])(
    'uses On/Off words for an incoming conflict of %s',
    (incoming) => {
      // Two boolean changes converge; Hideable's third state lets this boundary
      // exercise the conflict copy without inventing another wire value.
      const { edit, focused } = harness(row(HIDDEN_VALUE))
      edit.open(ROW_KEY)
      edit.setForm({ hidden: false, enabled: !incoming })
      focused.set(row(incoming))
      expect(edit.state.get().conflict).toBe(true)
      expect(edit.noticeText.get()).toBe(
        `Changed elsewhere to "${incoming ? 'On' : 'Off'}".`,
      )
      expect(edit.canSave.get()).toBe(false)
      edit.takeTheirs()
      expect(edit.form.get().enabled).toBe(incoming)
      expect(edit.state.get().conflict).toBe(false)
      edit.dispose()
    },
  )

  it('keeps a deleted row draft visible and refuses its write', async () => {
    const { edit, focused, dispatch } = harness()
    edit.open(ROW_KEY)
    edit.patchForm({ enabled: false })
    focused.set(undefined)
    expect(edit.noticeText.get()).toBe(
      'Deleted elsewhere — your choice stays on screen.',
    )
    expect(edit.saveLabel.get()).toBe('Deleted')
    expect(edit.canSave.get()).toBe(false)
    await edit.save(vi.fn())
    expect(dispatch).not.toHaveBeenCalled()
    expect(edit.opened.get()).toBe(true)
    expect(edit.form.get().enabled).toBe(false)
    edit.dispose()
  })

  it('opens a hidden row without a dirty draft or a write', async () => {
    const { edit, dispatch } = harness(row(HIDDEN_VALUE))
    edit.open(ROW_KEY)
    expect(edit.form.get()).toEqual({ hidden: true, enabled: false })
    edit.patchForm({ enabled: true })
    expect(edit.state.get().dirty).toBe(false)
    await edit.save(vi.fn())
    expect(dispatch).not.toHaveBeenCalled()
    edit.dispose()
  })

  it('never dispatches a hidden draft even if it differs from the saved value', async () => {
    const { edit, dispatch } = harness()
    edit.open(ROW_KEY)
    edit.patchForm({ hidden: true })
    await edit.save(vi.fn())
    expect(dispatch).not.toHaveBeenCalled()
    expect(edit.opened.get()).toBe(true)
    edit.dispose()
  })
})
