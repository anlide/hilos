// The OAuth pages' edit windows over the core row-edit session
// (conflict/rowEditSession.ts): the shared return address on the providers page
// (HilosSecurityOauthPage) and one field of one provider on its configuration
// page (HilosSecurityOauthProviderPage), in the three view layers. What is each
// window's own and the same in every view layer lives here: the address kept as
// typed and sent trimmed, the secret forgotten when its window closes, the words
// about the other side, and the sends.
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
  type HilosOAuthFieldRow,
  type HilosOAuthRedirectRow,
  type HilosSecurityOauthActions,
} from './hilosSecurityOauth.js'

/** The one field the return-address window edits. */
export interface HilosOauthRedirectEditFields {
  value: Hideable<string>
}

/** The return-address window's form: the address as typed. */
export interface HilosOauthRedirectEditForm {
  /** True on a row hidden from a viewer of the admin view mode: no input. */
  hidden: boolean
  /** The address as typed; trimmed only when sent. */
  text: string
}

/** The one field a provider field's window edits; the secret reads as empty. */
export interface HilosOauthProviderFieldEditFields {
  value: string
}

/**
 * Create the return-address window over the one-row address table; the view
 * opens it by the key of that row.
 *
 * @param controller The address table whose row the window edits.
 * @param actions The OAuth writes.
 */
export function createHilosOauthRedirectEdit(
  controller: TableViewportController<HilosOAuthRedirectRow>,
  actions: HilosSecurityOauthActions,
): HilosTrackedRowEdit<
  HilosOAuthRedirectRow,
  HilosOauthRedirectEditFields,
  HilosOauthRedirectEditForm
> {
  const edit = createHilosRowEdit<
    HilosOAuthRedirectRow,
    HilosOauthRedirectEditFields,
    HilosOauthRedirectEditForm
  >(
    hilosTableRowEditSource(controller, (row) => row.key),
    {
      initial: { hidden: false, text: '' },
      fields: (row) => ({ value: row.value }),
      form: (row) =>
        isHiddenValue(row.value)
          ? { hidden: true, text: '' }
          : { hidden: false, text: row.value },
      // The draft is the address as typed: a space at its edge is a change the
      // person sees, and the send trims it.
      draft: (form) => ({ value: form.hidden ? HIDDEN_VALUE : form.text }),
      take: (form, taken) =>
        taken.value === undefined || isHiddenValue(taken.value)
          ? form
          : { ...form, text: taken.value },
      notice: {
        conflict: (_state, live) =>
          live === undefined
            ? ''
            : `Changed elsewhere to "${isHiddenValue(live.value) ? hiddenAsWord(live.value) : live.value === '' ? '—' : live.value}".`,
      },
    },
  )

  return {
    ...edit,
    // A hidden draft is never dirty, so it never reaches here; the type alone
    // does not know that.
    save: (run) =>
      edit.save((draft) =>
        isHiddenValue(draft.value)
          ? Promise.resolve(false)
          : run(actions.sendRedirectSet(draft.value.trim())),
      ),
  }
}

/**
 * The effective value of a provider field that is not the secret, as the notice
 * says it: nothing reads as a dash.
 *
 * @param row The field's row.
 */
function providerFieldDisplayValue(row: HilosOAuthFieldRow): string {
  return row.value === null || row.value === '' ? '—' : row.value
}

/**
 * Create a provider field's edit window over the provider fields table. The
 * secret never reads back: its window opens empty and forgets what was typed
 * when it closes.
 *
 * @param controller The provider fields table whose row the window edits.
 * @param actions The OAuth writes.
 */
export function createHilosOauthProviderFieldEdit(
  controller: TableViewportController<HilosOAuthFieldRow>,
  actions: HilosSecurityOauthActions,
): HilosTrackedRowEdit<HilosOAuthFieldRow, HilosOauthProviderFieldEditFields> {
  const edit = createHilosRowEdit<
    HilosOAuthFieldRow,
    HilosOauthProviderFieldEditFields
  >(
    hilosTableRowEditSource(controller, (row) => row.key),
    {
      initial: { value: '' },
      fields: (row) => ({ value: row.value ?? '' }),
      forget: () => ({ value: '' }),
      notice: {
        conflict: (_state, live) =>
          live === undefined
            ? ''
            : `Changed elsewhere to "${providerFieldDisplayValue(live)}".`,
      },
    },
  )

  return {
    ...edit,
    save: (run) =>
      edit.save((draft, row) =>
        run(actions.sendProviderSet(row.providerKey, row.field, draft.value)),
      ),
  }
}
