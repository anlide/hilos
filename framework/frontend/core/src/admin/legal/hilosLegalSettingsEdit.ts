import {
  createHilosRowEdit,
  hilosTableRowEditSource,
  type HilosTrackedRowEdit,
} from '../../conflict/rowEditSession.js'
import { isHiddenValue, type Hideable } from '../../state/hiddenValue.js'
import { type TableViewportController } from '../../table/TableViewportController.js'
import { hiddenAsWord } from '../viewMode.js'
import {
  HILOS_LEGAL_VALUE_COPY,
  type createHilosLegalSettingsActions,
  type HilosLegalSettingRow,
} from './hilosLegal.js'

/** The one field the legal setting window edits: the value, hidden from a viewer of the admin view mode. */
export interface HilosLegalSettingEditFields {
  value: Hideable<string>
}

/**
 * The legal setting window, shared by the three view adapters: the core
 * row-edit session with the setting's own words and its send. A value hidden
 * from a viewer of the admin view mode opens as the one hidden value, so the
 * draft is never dirty and the modal shows the mark in place of the choice.
 *
 * @param controller The legal settings table whose row the window edits.
 * @param actions The legal settings writes.
 */
export function createHilosLegalSettingEdit(
  controller: TableViewportController<HilosLegalSettingRow>,
  actions: ReturnType<typeof createHilosLegalSettingsActions>,
): HilosTrackedRowEdit<HilosLegalSettingRow, HilosLegalSettingEditFields> {
  const edit = createHilosRowEdit<
    HilosLegalSettingRow,
    HilosLegalSettingEditFields
  >(
    hilosTableRowEditSource(controller, (row) => row.rowKey),
    {
      initial: { value: '' },
      fields: (row) => ({ value: row.value }),
      notice: {
        deleted: 'Deleted elsewhere — your choice stays visible.',
        conflict: (state) => {
          const incoming = hiddenAsWord(state.fields.value.incoming)

          return `Changed elsewhere to "${HILOS_LEGAL_VALUE_COPY[incoming] ?? incoming}".`
        },
      },
    },
  )

  return {
    ...edit,
    save: (run) =>
      edit.save((draft, row) =>
        // A hidden draft is never dirty, so it never reaches here; the type
        // alone does not know that.
        isHiddenValue(draft.value)
          ? Promise.resolve(false)
          : run(actions.sendSettingSet(row.rowKey, draft.value)),
      ),
  }
}
