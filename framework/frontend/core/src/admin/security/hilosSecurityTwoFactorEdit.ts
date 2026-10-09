// The two-step verification page's edit window (HilosSecurity2faPage in the
// three view layers): one setting's value as typed, over the core row-edit
// session (conflict/rowEditSession.ts). What is the window's own and the same
// in every view layer lives here: the draft trimmed, the value in words in the
// line about the other side, and the send.
import {
  createHilosRowEdit,
  hilosTableRowEditSource,
  type HilosTrackedRowEdit,
} from '../../conflict/rowEditSession.js'
import {
  HIDDEN_VALUE,
  isHiddenValue,
  type Hideable,
} from '../../state/hiddenValue.js'
import { type TableViewportController } from '../../table/TableViewportController.js'
import { hiddenAsWord } from '../viewMode.js'
import {
  describeHilosSecondFactorSetting,
  type HilosTwoFactorActions,
  type HilosTwoFactorSettingRow,
} from './hilosSecurityTwoFactor.js'

/** The one field the window edits: the setting's value as text. */
export interface HilosTwoFactorSettingEditFields {
  value: Hideable<string>
}

/** The window's form: the value as typed, trimmed only in the draft. */
export interface HilosTwoFactorSettingEditForm {
  /** True on a row hidden from a viewer of the admin view mode: no input. */
  hidden: boolean
  /** The value as typed. */
  text: string
}

/**
 * Create the two-step verification page's edit window over its table.
 *
 * @param controller The settings table whose row the window edits.
 * @param actions The settings write.
 */
export function createHilosTwoFactorSettingEdit(
  controller: TableViewportController<HilosTwoFactorSettingRow>,
  actions: HilosTwoFactorActions,
): HilosTrackedRowEdit<
  HilosTwoFactorSettingRow,
  HilosTwoFactorSettingEditFields,
  HilosTwoFactorSettingEditForm
> {
  const edit = createHilosRowEdit<
    HilosTwoFactorSettingRow,
    HilosTwoFactorSettingEditFields,
    HilosTwoFactorSettingEditForm
  >(
    hilosTableRowEditSource(controller, (row) => row.rowKey),
    {
      initial: { hidden: false, text: '' },
      fields: (row) => ({ value: row.value }),
      form: (row) =>
        isHiddenValue(row.value)
          ? { hidden: true, text: '' }
          : { hidden: false, text: row.value },
      draft: (form) => ({
        value: form.hidden ? HIDDEN_VALUE : form.text.trim(),
      }),
      take: (form, taken) =>
        taken.value === undefined || isHiddenValue(taken.value)
          ? form
          : { ...form, text: taken.value },
      notice: {
        conflict: (_state, live) =>
          live === undefined
            ? ''
            : `Changed elsewhere to "${isHiddenValue(live.value) ? hiddenAsWord(live.value) : describeHilosSecondFactorSetting(live.rowKey, live.value)}".`,
      },
    },
  )

  return {
    ...edit,
    // A hidden draft is never dirty, so it never reaches here; the type alone
    // does not know that.
    save: (run) =>
      edit.save((draft, row) =>
        isHiddenValue(draft.value)
          ? Promise.resolve(false)
          : run(actions.sendSettingSet(row.rowKey, draft.value)),
      ),
  }
}
