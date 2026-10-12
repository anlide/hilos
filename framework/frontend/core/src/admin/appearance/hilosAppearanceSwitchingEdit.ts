// The Theme switching window over the shared row-edit session: the boolean
// choice, the words about changes elsewhere, and the tracked write.
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
  type HilosAppearanceActions,
  type HilosAppearanceSettingRow,
} from './hilosAppearance.js'

/** The one field the window edits. */
export interface HilosAppearanceSwitchingEditFields {
  enabled: Hideable<boolean>
}

/** The window's choice until Save. */
export interface HilosAppearanceSwitchingEditForm {
  /** A hidden value offers no input and never leaves in a write. */
  hidden: boolean
  /** Whether people may choose a theme. */
  enabled: boolean
}

/**
 * Create the Theme switching window over its table's focused row.
 *
 * @param controller The Appearance table whose row the window edits.
 * @param actions The Appearance writes.
 */
export function createHilosAppearanceSwitchingEdit(
  controller: TableViewportController<HilosAppearanceSettingRow>,
  actions: HilosAppearanceActions,
): HilosTrackedRowEdit<
  HilosAppearanceSettingRow,
  HilosAppearanceSwitchingEditFields,
  HilosAppearanceSwitchingEditForm
> {
  const edit = createHilosRowEdit<
    HilosAppearanceSettingRow,
    HilosAppearanceSwitchingEditFields,
    HilosAppearanceSwitchingEditForm
  >(
    hilosTableRowEditSource(controller, (row) => row.rowKey),
    {
      initial: { hidden: false, enabled: false },
      fields: (row) => ({
        enabled: isHiddenValue(row.value) ? HIDDEN_VALUE : row.value === true,
      }),
      form: (row) => ({
        hidden: isHiddenValue(row.value),
        enabled: row.value === true,
      }),
      draft: (form) => ({ enabled: form.hidden ? HIDDEN_VALUE : form.enabled }),
      take: (form, taken) =>
        taken.enabled === undefined || isHiddenValue(taken.enabled)
          ? form
          : { ...form, enabled: taken.enabled },
      notice: {
        deleted: 'Deleted elsewhere — your choice stays on screen.',
        conflict: (_state, live) =>
          live === undefined
            ? ''
            : `Changed elsewhere to "${isHiddenValue(live.value) ? hiddenAsWord(live.value) : live.value === true ? 'On' : 'Off'}".`,
      },
    },
  )

  return {
    ...edit,
    save: (run) =>
      edit.save((draft) =>
        isHiddenValue(draft.enabled)
          ? Promise.resolve(false)
          : run(actions.sendSwitchingSet(draft.enabled)),
      ),
  }
}
