// The settings page's edit window (HilosSettingsPage in the three view layers):
// one row's custom value, or a reset back to the catalog default, over the core
// row-edit session (conflict/rowEditSession.ts). What is the window's own and
// the same in every view layer lives here: the form of a switch and a text
// folding into one `overrideValue`, the words about the other side, and the
// choice of send — reset, update of an orphan, add by key.
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
  hasCustomValue,
  isOrphanSetting,
  type HilosSettingRow,
  type HilosSettingsActions,
} from './hilosSettings.js'

/** The one field the window edits: the row's own value, null for the catalog default. */
export interface HilosSettingEditFields {
  overrideValue: Hideable<string | null>
}

/**
 * The window's form: the switch between a custom value and the catalog default,
 * and the text of the custom value — richer than the one field they fold into.
 */
export interface HilosSettingEditForm {
  /** True on a row hidden from a viewer of the admin view mode: no switch, no text. */
  hidden: boolean
  /** Whether the row keeps a value of its own rather than the catalog default. */
  useCustom: boolean
  /** The custom value as typed; a number input writes it as text too. */
  text: string
}

/**
 * The effective value of a row as text, for the text the form shows when the
 * other side took the override away: the live row's where it is known, the
 * opened row's otherwise, and nothing for a hidden one.
 *
 * @param row The row the window opened on.
 * @param live The live row, or undefined when it is gone.
 */
function effectiveText(
  row: HilosSettingRow,
  live: HilosSettingRow | undefined,
): string {
  if (live !== undefined && !isHiddenValue(live.value)) {
    return live.value ?? ''
  }

  return isHiddenValue(row.value) ? '' : (row.value ?? '')
}

/**
 * Create the settings page's edit window over its table.
 *
 * @param controller The settings table whose row the window edits.
 * @param actions The settings writes.
 */
export function createHilosSettingEdit(
  controller: TableViewportController<HilosSettingRow>,
  actions: HilosSettingsActions,
): HilosTrackedRowEdit<
  HilosSettingRow,
  HilosSettingEditFields,
  HilosSettingEditForm
> {
  const edit = createHilosRowEdit<
    HilosSettingRow,
    HilosSettingEditFields,
    HilosSettingEditForm
  >(
    hilosTableRowEditSource(controller, (row) => row.key),
    {
      initial: { hidden: false, useCustom: false, text: '' },
      fields: (row) => ({ overrideValue: row.overrideValue }),
      // An orphan has no catalog default behind it and no switch in the dialog,
      // so its value is always its own; a cataloged key opens with the switch on
      // only when it carries a value of its own.
      form: (row) =>
        isHiddenValue(row.overrideValue)
          ? { hidden: true, useCustom: false, text: '' }
          : {
              hidden: false,
              useCustom: isOrphanSetting(row) || hasCustomValue(row),
              text: row.overrideValue ?? effectiveText(row, undefined),
            },
      // The switch off means the catalog default: nothing of the row's own.
      draft: (form) => ({
        overrideValue: form.hidden
          ? HIDDEN_VALUE
          : form.useCustom
            ? form.text
            : null,
      }),
      // A value taken from the other side lands in the switch and the text the
      // way the window opened with it; a reset taken leaves the text on the value
      // now in effect — the live row's, not the override the other side removed.
      take: (form, taken, row, live) => {
        const next = taken.overrideValue
        if (next === undefined || isHiddenValue(next)) {
          return form
        }

        return {
          ...form,
          useCustom: next !== null || isOrphanSetting(row),
          text: next ?? effectiveText(row, live),
        }
      },
      notice: {
        conflict: (state) =>
          state.fields.overrideValue.incoming === null
            ? 'Reset elsewhere to the catalog default.'
            : `Changed elsewhere to "${hiddenAsWord(state.fields.overrideValue.incoming)}".`,
      },
    },
  )

  return {
    ...edit,
    // The switch turned off resets the key by dropping its row. With a value,
    // an orphan updates in place and a cataloged key adds by key (the add is
    // idempotent, so the row need not exist yet). A hidden draft is never
    // dirty, so it never reaches here; the type alone does not know that.
    save: (run) =>
      edit.save((draft, row) => {
        const next = draft.overrideValue
        if (isHiddenValue(next)) {
          return Promise.resolve(false)
        }
        if (next === null) {
          return run(actions.sendSettingReset(row.key))
        }

        return run(
          isOrphanSetting(row)
            ? actions.sendSettingUpdate(row.key, next)
            : actions.sendSettingAdd(row.key, next),
        )
      }),
  }
}
