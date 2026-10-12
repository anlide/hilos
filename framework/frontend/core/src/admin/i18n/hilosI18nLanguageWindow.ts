import { type RowEditNoticeKind } from '../../conflict/rowEdit.js'
import {
  createHilosRowEdit,
  hilosSignalRowEditSource,
  hilosTableRowEditSource,
  type HilosRowEditSource,
  type HilosTrackedRowEdit,
} from '../../conflict/rowEditSession.js'
import {
  computedSignal,
  createSignal,
  type ReadonlySignal,
} from '../../state/signal.js'
import { type TableViewportController } from '../../table/TableViewportController.js'
import {
  type HilosI18nLanguageCard,
  type HilosI18nLanguageContext,
} from './hilosI18nLanguage.js'
import { type HilosI18nLanguageRow } from './hilosI18nLanguages.js'

/** Tracked actions of the card and table-row language windows. */
export const HILOS_I18N_LANGUAGE_UPDATE_ACTION = 'hilos_i18n_language_update'
export const HILOS_I18N_LANGUAGE_ROW_UPDATE_ACTION =
  'hilos_i18n_language_row_update'

/** The native name's stored character limit. */
export const HILOS_I18N_LANGUAGE_NATIVE_NAME_MAX = 64

/** The language window's words, shared by every view layer. */
export const HILOS_I18N_LANGUAGE_WINDOW_COPY = {
  open: { edit: 'Edit language', view: 'View language: nothing to edit' },
  title: {
    edit: (name: string) => `Edit language · ${name}`,
    view: (name: string) => `Language · ${name}`,
  },
  labels: {
    code: 'Code',
    nativeName: 'Native name',
    rtl: 'rtl — writes right to left',
  },
  hints: {
    code: 'Never changes — whether the language is on or off.',
    nativeName: {
      edit: 'How the language calls itself, in the language itself.',
      view: 'The language is switched on: its name is live for everyone.',
    },
    rtl: 'The writing direction turns the layout of the whole product around.',
  },
  plate: {
    known: {
      lead: 'The language is switched off — edit freely.',
      text: 'While it is off, nobody uses it, and the built-in catalog keeps this row: a framework update that changes the catalog brings its values back. Switch the language on, and your edit stays for good.',
    },
    own: {
      lead: 'The language is switched off — edit freely.',
      text: 'While it is off, nobody uses it. It is your own language: nothing refreshes it, so your edit stays as you leave it.',
    },
    on: {
      lead: 'The language is switched on, so there is nothing to edit here.',
      text: 'A switched-on language is live for everyone, and nobody changes it — neither you nor the reflow. To change it, switch it off, edit it and switch it back on.',
    },
    code: 'The code stays grey here too: the env and everything built on languages refer to a language by its code, so it never changes.',
  },
  conflict: {
    nativeName: (value: string) =>
      `Native name changed elsewhere to "${value}".`,
    rtl: (value: boolean) =>
      `Writing direction changed elsewhere to ${value ? 'right to left' : 'left to right'}.`,
  },
  elsewhere: {
    switchedOn: 'Switched on elsewhere just now.',
    switchedOff: 'Switched off elsewhere just now.',
  },
  cancel: 'Cancel',
  save: 'Save',
  close: 'Close',
  refusalTitle: "Couldn't save the language",
} as const

/** The shared projection of a language card and a languages-table row. */
export interface HilosI18nLanguageEditable {
  code: string
  nativeName: string
  rtl: boolean
  enabled: boolean
  isOwn: boolean
}

/** The two stored fields the window edits. */
export interface HilosI18nLanguageEditFields {
  nativeName: string
  rtl: boolean
}

export type HilosI18nLanguageWindowMode = 'edit' | 'view'

/** One live language window with its mode fixed at opening. */
export interface HilosI18nLanguageWindow extends HilosTrackedRowEdit<
  HilosI18nLanguageEditable,
  HilosI18nLanguageEditFields
> {
  readonly mode: ReadonlySignal<HilosI18nLanguageWindowMode | null>
  readonly live: ReadonlySignal<HilosI18nLanguageEditable | undefined>
  readonly frozen: ReadonlySignal<boolean>
  readonly plate: ReadonlySignal<'on' | 'known' | 'own'>
  readonly notice: ReadonlySignal<{
    kind: RowEditNoticeKind
    text: string
  } | null>
}

/**
 * Open the language window over a table's focused row.
 *
 * @param controller The languages table holding the row live.
 * @param context The project's tracked action dispatcher.
 */
export function createHilosI18nLanguageRowWindow(
  controller: TableViewportController<HilosI18nLanguageRow>,
  context: HilosI18nLanguageContext,
): HilosI18nLanguageWindow {
  return createHilosI18nLanguageWindow(
    hilosTableRowEditSource(controller, (row) => row.code),
    context,
    HILOS_I18N_LANGUAGE_ROW_UPDATE_ACTION,
  )
}

/**
 * Open the same window over the main card's page-scoped datum.
 *
 * @param card The live language card, cleared when the language is gone.
 * @param context The project's tracked action dispatcher.
 */
export function createHilosI18nLanguageCardWindow(
  card: ReadonlySignal<HilosI18nLanguageCard | null>,
  context: HilosI18nLanguageContext,
): HilosI18nLanguageWindow {
  const row = computedSignal(() => {
    const current = card.get()
    return current === null
      ? undefined
      : {
          code: current.code,
          nativeName: current.nativeName,
          rtl: current.rtl,
          enabled: current.enabled,
          isOwn: current.summary.isOwn,
        }
  })
  return createHilosI18nLanguageWindow(
    hilosSignalRowEditSource(row),
    context,
    HILOS_I18N_LANGUAGE_UPDATE_ACTION,
  )
}

/**
 * Keep the two entry points on the same session and live verdicts.
 *
 * @param source The live row source of this entry point.
 * @param context The project's tracked action dispatcher.
 * @param action The action belonging to this entry point's page.
 */
function createHilosI18nLanguageWindow(
  source: HilosRowEditSource<HilosI18nLanguageEditable>,
  context: HilosI18nLanguageContext,
  action: string,
): HilosI18nLanguageWindow {
  const selectedMode = createSignal<HilosI18nLanguageWindowMode>('edit')
  const copy = HILOS_I18N_LANGUAGE_WINDOW_COPY
  const edit = createHilosRowEdit<
    HilosI18nLanguageEditable,
    HilosI18nLanguageEditFields
  >(source, {
    initial: { nativeName: '', rtl: false },
    fields: (row) => ({ nativeName: row.nativeName, rtl: row.rtl }),
    draft: (form) => ({ nativeName: form.nativeName.trim(), rtl: form.rtl }),
    valid: (form) =>
      source.live.get()?.enabled === false &&
      form.nativeName.trim().length >= 1 &&
      form.nativeName.trim().length <= HILOS_I18N_LANGUAGE_NATIVE_NAME_MAX,
    notice: {
      conflict: (state) =>
        (state.notice?.fields ?? [])
          .map((field) =>
            field === 'nativeName'
              ? copy.conflict.nativeName(state.fields.nativeName.incoming)
              : copy.conflict.rtl(state.fields.rtl.incoming),
          )
          .join(' '),
    },
  })
  const mode = computedSignal(() =>
    edit.opened.get() ? selectedMode.get() : null,
  )
  const frozen = computedSignal(
    () =>
      mode.get() === 'view' ||
      (mode.get() === 'edit' &&
        source.live.get()?.enabled === true &&
        !edit.saving.get()),
  )
  const plate = computedSignal(() =>
    frozen.get() ? 'on' : edit.row.get()?.isOwn ? 'own' : 'known',
  )
  const notice = computedSignal<{
    kind: RowEditNoticeKind
    text: string
  } | null>(() => {
    if (!edit.opened.get()) return null
    const state = edit.state.get()
    if (state.gone) return { kind: 'deleted', text: edit.noticeText.get() }
    if (mode.get() === 'edit' && frozen.get())
      return { kind: 'updated', text: copy.elsewhere.switchedOn }
    if (mode.get() === 'view' && source.live.get()?.enabled === false)
      return { kind: 'updated', text: copy.elsewhere.switchedOff }
    return state.notice === null
      ? null
      : { kind: state.notice.kind, text: edit.noticeText.get() }
  })

  return {
    ...edit,
    mode,
    live: source.live,
    frozen,
    plate,
    notice,
    open(key) {
      if (!edit.open(key)) return false
      selectedMode.set(edit.row.get()?.enabled ? 'view' : 'edit')
      return true
    },
    save: (run) =>
      edit.save((draft, row) =>
        run(
          context.actions.dispatch(action, {
            languageCode: row.code,
            nativeName: draft.nativeName,
            rtl: draft.rtl,
          }),
        ),
      ),
  }
}
