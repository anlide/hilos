// The impersonation page's scope window (HilosSecurityImpersonationPage in the
// three view layers): what may be done inside someone else's account, over the
// core row-edit session (conflict/rowEditSession.ts). What is the window's own
// and the same in every view layer lives here: the scope read out of the row,
// the words about the other side, and the send.
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
  HILOS_IMPERSONATION_SCOPE_COPY,
  hilosImpersonationScopeOf,
  type HilosImpersonationActions,
  type HilosImpersonationScope,
  type HilosImpersonationSettingRow,
} from './hilosSecurityImpersonation.js'

/** The one field the window edits: the scope in force. */
export interface HilosImpersonationScopeEditFields {
  scope: Hideable<HilosImpersonationScope>
}

/** The window's form: the choice as made until Save. */
export interface HilosImpersonationScopeEditForm {
  /** True on a row hidden from a viewer of the admin view mode: no choice. */
  hidden: boolean
  /** The scope chosen. */
  scope: HilosImpersonationScope
}

/**
 * Create the impersonation page's scope window over its table.
 *
 * @param controller The impersonation settings table whose row the window edits.
 * @param actions The impersonation writes.
 */
export function createHilosImpersonationScopeEdit(
  controller: TableViewportController<HilosImpersonationSettingRow>,
  actions: HilosImpersonationActions,
): HilosTrackedRowEdit<
  HilosImpersonationSettingRow,
  HilosImpersonationScopeEditFields,
  HilosImpersonationScopeEditForm
> {
  const edit = createHilosRowEdit<
    HilosImpersonationSettingRow,
    HilosImpersonationScopeEditFields,
    HilosImpersonationScopeEditForm
  >(
    hilosTableRowEditSource(controller, (row) => row.rowKey),
    {
      initial: { hidden: false, scope: 'act' },
      fields: (row) => ({
        scope: isHiddenValue(row.value)
          ? HIDDEN_VALUE
          : hilosImpersonationScopeOf(row),
      }),
      form: (row) =>
        isHiddenValue(row.value)
          ? { hidden: true, scope: 'act' }
          : { hidden: false, scope: hilosImpersonationScopeOf(row) },
      draft: (form) => ({ scope: form.hidden ? HIDDEN_VALUE : form.scope }),
      take: (form, taken) =>
        taken.scope === undefined || isHiddenValue(taken.scope)
          ? form
          : { ...form, scope: taken.scope },
      notice: {
        deleted: 'Deleted elsewhere — your choice stays on screen.',
        conflict: (_state, live) =>
          live === undefined
            ? ''
            : `Changed elsewhere to "${isHiddenValue(live.value) ? hiddenAsWord(live.value) : HILOS_IMPERSONATION_SCOPE_COPY[hilosImpersonationScopeOf(live)]}".`,
      },
    },
  )

  return {
    ...edit,
    // A hidden draft is never dirty, so it never reaches here; the type alone
    // does not know that.
    save: (run) =>
      edit.save((draft) =>
        isHiddenValue(draft.scope)
          ? Promise.resolve(false)
          : run(actions.sendScopeSet(draft.scope)),
      ),
  }
}
