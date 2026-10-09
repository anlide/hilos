// The channel page's edit window (HilosCommunicationsChannelPage in the three
// view layers): one field's override, typed by the field, over the core
// row-edit session (conflict/rowEditSession.ts). What is the window's own and
// the same in every view layer lives here: the text the form shows for a typed
// value and the typed value read back from it, the value as the cell says it,
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
  type HilosChannelFieldRow,
  type HilosCommunicationsActions,
} from './hilosCommunications.js'

/** A channel field's typed value; null for a secret, which is never sent to the browser. */
export type HilosChannelFieldValue = boolean | number | string | null

/** The one field the window edits: the field's typed value. */
export interface HilosChannelFieldEditFields {
  value: Hideable<HilosChannelFieldValue>
}

/** The window's form: the value as text, the way an input holds it. */
export interface HilosChannelFieldEditForm {
  /** True on a row hidden from a viewer of the admin view mode: no input. */
  hidden: boolean
  /** The value as typed; a switch reads '1' / '0'. */
  text: string
}

/**
 * The effective value of a field that is not a secret, as the cell and the
 * notice say it: a switch reads On / Off, nothing reads as a dash.
 *
 * @param value The field's typed value.
 */
export function hilosChannelDisplayValue(
  value: HilosChannelFieldValue,
): string {
  if (typeof value === 'boolean') {
    return value ? 'On' : 'Off'
  }

  return value === null || value === '' ? '—' : String(value)
}

/**
 * The text the window's input shows for a typed value: a switch reads '1' /
 * '0', an empty value reads as nothing, anything else as itself.
 *
 * @param type The field's type: `string`, `integer`, `float` or `boolean`.
 * @param value The field's typed value.
 */
export function hilosChannelFormText(
  type: string,
  value: HilosChannelFieldValue,
): string {
  if (type === 'boolean') {
    return value === true ? '1' : '0'
  }

  return value === null ? '' : String(value)
}

/**
 * The typed value the text of the input stands for, as the set action sends it.
 *
 * @param type The field's type: `string`, `integer`, `float` or `boolean`.
 * @param text The text as typed.
 */
export function hilosChannelEditedValue(
  type: string,
  text: string,
): boolean | number | string {
  if (type === 'boolean') {
    return text === '1'
  }
  if (type === 'integer' || type === 'float') {
    return Number(text)
  }

  return text
}

/**
 * Create the channel page's edit window over its fields table.
 *
 * @param controller The channel fields table whose row the window edits.
 * @param actions The channel writes.
 */
export function createHilosChannelFieldEdit(
  controller: TableViewportController<HilosChannelFieldRow>,
  actions: HilosCommunicationsActions,
): HilosTrackedRowEdit<
  HilosChannelFieldRow,
  HilosChannelFieldEditFields,
  HilosChannelFieldEditForm
> {
  const edit = createHilosRowEdit<
    HilosChannelFieldRow,
    HilosChannelFieldEditFields,
    HilosChannelFieldEditForm
  >(
    hilosTableRowEditSource(controller, (row) => row.key),
    {
      initial: { hidden: false, text: '' },
      fields: (row) => ({ value: row.value }),
      form: (row) =>
        isHiddenValue(row.value)
          ? { hidden: true, text: '' }
          : { hidden: false, text: hilosChannelFormText(row.type, row.value) },
      draft: (form, row) => ({
        value: form.hidden
          ? HIDDEN_VALUE
          : hilosChannelEditedValue(row.type, form.text),
      }),
      take: (form, taken, row) =>
        taken.value === undefined || isHiddenValue(taken.value)
          ? form
          : { ...form, text: hilosChannelFormText(row.type, taken.value) },
      notice: {
        conflict: (_state, live) =>
          live === undefined
            ? ''
            : `Changed elsewhere to "${isHiddenValue(live.value) ? hiddenAsWord(live.value) : hilosChannelDisplayValue(live.value)}".`,
      },
    },
  )

  return {
    ...edit,
    // A hidden draft is never dirty, and a typed value read from the text is
    // never null, so neither reaches here; the type alone does not know that.
    save: (run) =>
      edit.save((draft, row) =>
        isHiddenValue(draft.value) || draft.value === null
          ? Promise.resolve(false)
          : run(actions.sendChannelSet(row.channel, row.field, draft.value)),
      ),
  }
}
