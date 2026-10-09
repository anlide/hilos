// The person card's name window (HilosUserPage in the three view layers): the
// display name over the core row-edit session (conflict/rowEditSession.ts),
// with the card's own row as the live row — there is no table to take it into
// focus from. What is the window's own and the same in every view layer lives
// here: the bounds of a name and the check of the input, the words about the
// other side, and the send that is confirmed by the live name reaching the one
// sent, not by the action's reply.
import {
  createHilosRowEdit,
  hilosSignalRowEditSource,
  type HilosRowEdit,
} from '../../conflict/rowEditSession.js'
import { isHiddenValue, type Hideable } from '../../state/hiddenValue.js'
import { type ReadonlySignal } from '../../state/signal.js'
import { hiddenAsWord } from '../viewMode.js'
import { type HilosUserDetailRow, type HilosUserRename } from './hilosUsers.js'

/** The shortest display name the rename accepts. */
export const HILOS_USER_NAME_MIN = 2
/** The longest display name the rename accepts. */
export const HILOS_USER_NAME_MAX = 64

/**
 * The one field the window edits: the display name — hidden for a viewer of the
 * admin view mode, and then the window shows the mark in place of the input.
 */
export interface HilosUserRenameEditFields {
  name: Hideable<string>
}

/** The name window: the core session with the rename built in. */
export type HilosUserRenameEdit = Omit<
  HilosRowEdit<HilosUserDetailRow, HilosUserRenameEditFields>,
  'save' | 'saveLanded'
> & {
  /**
   * Send the trimmed name; the window is saving until the live name reaches
   * it, or until the rename is refused.
   */
  save(): void
}

/**
 * Whether a trimmed name fits the bounds of a display name.
 *
 * @param name The name as typed.
 */
function fits(name: string): boolean {
  const trimmed = name.trim()

  return (
    trimmed.length >= HILOS_USER_NAME_MIN &&
    trimmed.length <= HILOS_USER_NAME_MAX
  )
}

/**
 * Create the person card's name window over the card's row.
 *
 * @param detail The card's row as it stands, undefined while the card has none.
 * @param rename The rename the card binds to.
 */
export function createHilosUserRenameEdit(
  detail: ReadonlySignal<HilosUserDetailRow | undefined>,
  rename: HilosUserRename,
): HilosUserRenameEdit {
  const edit = createHilosRowEdit<
    HilosUserDetailRow,
    HilosUserRenameEditFields
  >(hilosSignalRowEditSource(detail), {
    initial: { name: '' },
    fields: (row) => ({ name: row.name }),
    draft: (form) => ({
      name: isHiddenValue(form.name) ? form.name : form.name.trim(),
    }),
    valid: (form) => !isHiddenValue(form.name) && fits(form.name),
    notice: {
      conflict: (state) =>
        `Changed elsewhere to "${hiddenAsWord(state.fields.name.incoming)}".`,
    },
  })

  return {
    ...edit,
    // The refusal of the last rename is forgotten when the window opens and
    // when it closes, so a window never opens on an old one.
    open() {
      rename.clearRenameError()

      return edit.open()
    },
    close() {
      edit.close()
      rename.clearRenameError()
    },
    dispose() {
      edit.dispose()
      rename.clearRenameError()
    },
    // A hidden draft never passes the check, so it never reaches here; the type
    // alone does not know that.
    save() {
      edit.saveLanded(
        (draft, row) =>
          isHiddenValue(draft.name)
            ? false
            : rename.submitRename(row.id, draft.name),
        rename.renameError,
      )
    },
  }
}
