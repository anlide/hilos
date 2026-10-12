// The Default theme window over the shared row-edit session: the position,
// the words about changes elsewhere, and the tracked write.
import {
  createHilosRowEdit,
  hilosTableRowEditSource,
  type HilosTrackedRowEdit,
} from '../../conflict/rowEditSession.js'
import { HIDDEN_VALUE, type Hideable } from '../../state/hiddenValue.js'
import { type TableViewportController } from '../../table/TableViewportController.js'
import { THEME_POSITIONS, type ThemePosition } from '../../theme/themeChoice.js'
import { hiddenAsWord } from '../viewMode.js'
import {
  HilosAppearanceSettingKey,
  type HilosAppearanceActions,
  type HilosAppearanceSettingRow,
} from './hilosAppearance.js'

/** The one field and draft the window edits, without a fallback position. */
export interface HilosAppearanceDefaultThemeEditFields {
  defaultTheme: Hideable<ThemePosition>
}

/** Whether a value is one of the shared positions the radio group offers. */
function isThemePosition(value: unknown): value is ThemePosition {
  return THEME_POSITIONS.some((position) => position.value === value)
}

/**
 * Create the Default theme window over its table's focused row.
 *
 * @param controller The Appearance table whose row the window edits.
 * @param actions The Appearance writes.
 */
export function createHilosAppearanceDefaultThemeEdit(
  controller: TableViewportController<HilosAppearanceSettingRow>,
  actions: HilosAppearanceActions,
): HilosTrackedRowEdit<
  HilosAppearanceSettingRow,
  HilosAppearanceDefaultThemeEditFields
> {
  const source = hilosTableRowEditSource(controller, (row) => row.rowKey)
  const edit = createHilosRowEdit<
    HilosAppearanceSettingRow,
    HilosAppearanceDefaultThemeEditFields
  >(source, {
    initial: { defaultTheme: HIDDEN_VALUE },
    // This row's catalog supplies a position. Preserve an unrecognized value
    // from the boundary so it cannot silently become a different theme;
    // both the input check and the send below require a known position.
    fields: (row) => ({ defaultTheme: row.value as Hideable<ThemePosition> }),
    valid: (form) =>
      isThemePosition(form.defaultTheme) &&
      isThemePosition(source.live.get()?.value),
    notice: {
      deleted: 'Deleted elsewhere — your choice stays on screen.',
      conflict: (_state, live) =>
        live === undefined
          ? ''
          : `Changed elsewhere to "${THEME_POSITIONS.find((position) => position.value === live.value)?.label ?? hiddenAsWord(live.value)}".`,
    },
  })

  return {
    ...edit,
    open: (key) =>
      key === HilosAppearanceSettingKey.defaultTheme && edit.open(key),
    save: (run) =>
      edit.save((draft) =>
        isThemePosition(draft.defaultTheme)
          ? run(actions.sendDefaultThemeSet(draft.defaultTheme))
          : Promise.resolve(false),
      ),
  }
}
