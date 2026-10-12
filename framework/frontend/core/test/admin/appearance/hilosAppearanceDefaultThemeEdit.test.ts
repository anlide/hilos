import { describe, expect, it, vi } from 'vitest'
import {
  createHilosAppearanceActions,
  HilosAppearanceSettingKey,
  type HilosAppearanceSettingRow,
} from '../../../src/admin/appearance/hilosAppearance.js'
import { createHilosAppearanceDefaultThemeEdit } from '../../../src/admin/appearance/hilosAppearanceDefaultThemeEdit.js'
import {
  type ActionHandle,
  type ActionLifecycle,
} from '../../../src/connection/actionLifecycle.js'
import { HIDDEN_VALUE } from '../../../src/state/hiddenValue.js'
import { createSignal } from '../../../src/state/signal.js'
import { type TableViewportController } from '../../../src/table/TableViewportController.js'
import { type ThemePosition } from '../../../src/theme/themeChoice.js'

const ROW_KEY = HilosAppearanceSettingKey.defaultTheme

function row(
  value: HilosAppearanceSettingRow['value'],
): HilosAppearanceSettingRow {
  return { rowKey: ROW_KEY, value, defaultValue: 'system' }
}

function harness(initial = row('system')) {
  const focused = createSignal<HilosAppearanceSettingRow | undefined>(undefined)
  const focusRow = vi.fn((key: string) => {
    if (key !== initial.rowKey) return null
    focused.set(initial)
    return initial
  })
  const releaseFocus = vi.fn(() => focused.set(undefined))
  const controller = {
    focusRow,
    focusedRow: focused,
    releaseFocus,
  } as unknown as TableViewportController<HilosAppearanceSettingRow>
  const handle = {} as ActionHandle
  const dispatch = vi.fn(() => handle)
  const actions = createHilosAppearanceActions({
    actions: { dispatch } as unknown as ActionLifecycle,
  })
  const edit = createHilosAppearanceDefaultThemeEdit(controller, actions)
  edit.start()

  return { edit, focused, focusRow, dispatch, handle, releaseFocus }
}

describe('Appearance default theme window', () => {
  it.each<ThemePosition>(['light', 'dark', 'system'])(
    'opens with the saved position %s and no dirty draft',
    (defaultTheme) => {
      const { edit } = harness(row(defaultTheme))
      expect(edit.open(ROW_KEY)).toBe(true)
      expect(edit.form.get()).toEqual({ defaultTheme })
      expect(edit.state.get().dirty).toBe(false)
      expect(edit.canSave.get()).toBe(false)
      edit.dispose()
    },
  )

  it('opens only the default theme row', () => {
    const { edit, focusRow } = harness()
    expect(edit.open(HilosAppearanceSettingKey.switchingEnabled)).toBe(false)
    expect(edit.open('other')).toBe(false)
    expect(edit.open()).toBe(false)
    expect(focusRow).not.toHaveBeenCalled()
    edit.dispose()
  })

  it('sends the chosen position and closes only on the tracked success', async () => {
    const { edit, dispatch, handle, releaseFocus } = harness()
    edit.open(ROW_KEY)
    edit.patchForm({ defaultTheme: 'dark' })
    let answer!: (saved: boolean) => void
    const run = vi.fn(
      () =>
        new Promise<boolean>((resolve) => {
          answer = resolve
        }),
    )
    const saving = edit.save(run)

    expect(dispatch).toHaveBeenCalledExactlyOnceWith(
      'appearance_default_theme_set',
      {
        defaultTheme: 'dark',
      },
    )
    expect(run).toHaveBeenCalledWith(handle)
    expect(edit.opened.get()).toBe(true)
    expect(edit.saving.get()).toBe(true)
    expect(edit.canSave.get()).toBe(false)
    await edit.save(run)
    expect(dispatch).toHaveBeenCalledOnce()
    answer(true)
    await saving
    expect(edit.opened.get()).toBe(false)
    expect(releaseFocus).toHaveBeenCalledOnce()
    edit.dispose()
  })

  it('keeps a refused choice open and available to retry', async () => {
    const { edit } = harness()
    edit.open(ROW_KEY)
    edit.patchForm({ defaultTheme: 'dark' })
    await edit.save(() => Promise.resolve(false))
    expect(edit.opened.get()).toBe(true)
    expect(edit.form.get().defaultTheme).toBe('dark')
    expect(edit.canSave.get()).toBe(true)
    edit.dispose()
  })

  it('closes an unchanged draft without a write', async () => {
    const { edit, dispatch } = harness()
    edit.open(ROW_KEY)
    await edit.save(vi.fn())
    expect(dispatch).not.toHaveBeenCalled()
    expect(edit.opened.get()).toBe(false)
    edit.dispose()
  })

  it('takes a pristine update and converges a matching draft', () => {
    const { edit, focused } = harness()
    edit.open(ROW_KEY)
    focused.set(row('dark'))
    expect(edit.form.get().defaultTheme).toBe('dark')
    expect(edit.noticeText.get()).toBe('Updated just now')
    expect(edit.canSave.get()).toBe(false)
    edit.patchForm({ defaultTheme: 'light' })
    expect(edit.noticeText.get()).toBe('')
    focused.set(row('light'))
    expect(edit.state.get().conflict).toBe(false)
    expect(edit.state.get().dirty).toBe(false)
    edit.dispose()
  })

  it.each([
    ['system', 'dark', 'light', 'Light'],
    ['system', 'light', 'dark', 'Dark'],
    ['light', 'dark', 'system', 'System'],
  ] as const)(
    'names an incoming conflict from %s through %s to %s',
    (baseline, draft, incoming, label) => {
      const { edit, focused } = harness(row(baseline))
      edit.open(ROW_KEY)
      edit.patchForm({ defaultTheme: draft })
      focused.set(row(incoming))
      expect(edit.state.get().conflict).toBe(true)
      expect(edit.noticeText.get()).toBe(`Changed elsewhere to "${label}".`)
      expect(edit.canSave.get()).toBe(false)
      edit.takeTheirs()
      expect(edit.form.get().defaultTheme).toBe(incoming)
      expect(edit.state.get().conflict).toBe(false)
      expect(edit.canSave.get()).toBe(false)
      edit.dispose()
    },
  )

  it('keeps mine, advances the baseline and detects the next conflicting change', async () => {
    const { edit, focused, dispatch } = harness()
    edit.open(ROW_KEY)
    edit.patchForm({ defaultTheme: 'dark' })
    focused.set(row('light'))
    await edit.save(vi.fn())
    expect(dispatch).not.toHaveBeenCalled()
    edit.keepMine()
    expect(edit.form.get().defaultTheme).toBe('dark')
    expect(edit.state.get().conflict).toBe(false)
    expect(edit.canSave.get()).toBe(true)
    focused.set(row('system'))
    expect(edit.state.get().conflict).toBe(true)
    edit.dispose()
  })

  it('keeps a deleted row draft visible and refuses its write', async () => {
    const { edit, focused, dispatch } = harness()
    edit.open(ROW_KEY)
    edit.patchForm({ defaultTheme: 'dark' })
    focused.set(undefined)
    expect(edit.noticeText.get()).toBe(
      'Deleted elsewhere — your choice stays on screen.',
    )
    expect(edit.saveLabel.get()).toBe('Deleted')
    expect(edit.canSave.get()).toBe(false)
    await edit.save(vi.fn())
    expect(dispatch).not.toHaveBeenCalled()
    expect(edit.opened.get()).toBe(true)
    expect(edit.form.get().defaultTheme).toBe('dark')
    edit.dispose()
  })

  it.each([HIDDEN_VALUE, 'sepia', '', true])(
    'preserves and refuses a hidden or unknown row value %s',
    async (value) => {
      const { edit, dispatch } = harness(row(value))
      edit.open(ROW_KEY)
      expect(edit.form.get().defaultTheme).toBe(value)
      expect(edit.state.get().dirty).toBe(false)
      expect(edit.canSave.get()).toBe(false)
      await edit.save(vi.fn())
      edit.patchForm({ defaultTheme: 'dark' })
      expect(edit.canSave.get()).toBe(false)
      await edit.save(vi.fn())
      expect(dispatch).not.toHaveBeenCalled()
      edit.dispose()
    },
  )

  it.each([HIDDEN_VALUE, 'sepia', ''])(
    'refuses a hidden or unknown draft value %s',
    async (value) => {
      const { edit, dispatch } = harness()
      edit.open(ROW_KEY)
      edit.patchForm({ defaultTheme: value as ThemePosition })
      expect(edit.canSave.get()).toBe(false)
      await edit.save(vi.fn())
      expect(dispatch).not.toHaveBeenCalled()
      expect(edit.opened.get()).toBe(true)
      edit.dispose()
    },
  )

  it.each([HIDDEN_VALUE, 'sepia'])(
    'refuses a write when the live row becomes unavailable as %s',
    async (value) => {
      const { edit, focused, dispatch } = harness()
      edit.open(ROW_KEY)
      edit.patchForm({ defaultTheme: 'dark' })
      focused.set(row(value))
      edit.keepMine()
      expect(edit.canSave.get()).toBe(false)
      await edit.save(vi.fn())
      expect(dispatch).not.toHaveBeenCalled()
      edit.dispose()
    },
  )
})
