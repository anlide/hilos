import { type RowEditNoticeKind } from '../../conflict/rowEdit.js'
import {
  createHilosRowEdit,
  HILOS_ROW_EDIT_COPY,
  hilosTableRowEditSource,
  type HilosActionRun,
  type HilosRowEditSource,
  type HilosTrackedRowEdit,
} from '../../conflict/rowEditSession.js'
import {
  computedSignal,
  createSignal,
  subscribeSignal,
  type ReadonlySignal,
  type Unsubscribe,
} from '../../state/signal.js'
import { type TableViewportController } from '../../table/TableViewportController.js'
import {
  type HilosI18nLanguageCard,
  type HilosI18nLanguageContext,
} from './hilosI18nLanguage.js'
import { type HilosI18nNameRow } from './hilosI18nNames.js'

/** Tracked writes on the ADMIN language names page. */
export const HILOS_I18N_LANGUAGE_NAME_ADD_ACTION =
  'hilos_i18n_language_name_add'
export const HILOS_I18N_LANGUAGE_NAME_UPDATE_ACTION =
  'hilos_i18n_language_name_update'

/** The stored name's character limit; an empty string is a value. */
export const HILOS_I18N_LANGUAGE_NAME_MAX = 255

/** Words shared by the base-name and locale-correction windows. */
export const HILOS_I18N_LANGUAGE_NAME_COPY = {
  title: {
    add: 'Add name',
    edit: 'Edit name',
    addCorrection: 'Add locale correction',
    editCorrection: 'Edit locale correction',
  },
  open: (
    adding: boolean,
    subject: string,
    language: string,
    locale: string | null = null,
  ) =>
    `${adding ? 'Add' : 'Edit'} ${locale === null ? 'name' : 'locale correction'} · ${subject} in ${language}${locale === null ? '' : ` · ${locale}`}`,
  labels: {
    subject: 'Language',
    inLanguage: 'Written in',
    locale: 'Locale',
    name: 'Name',
  },
  notes: {
    base: "The base name is shared across the language's locales until a specific locale sets its own correction.",
    correction:
      'An optional correction handles small differences between variants of the same language.',
  },
  hints: {
    base: 'Empty text is saved as your own value. Delete the name to remove it.',
    correction:
      'Empty text is saved as your own correction. Delete the correction to inherit the base name.',
  },
  conflict: (name: string) => `Name changed elsewhere to "${name}".`,
  add: 'Add',
  cancel: 'Cancel',
  refusalTitle: "Couldn't save the name",
} as const

interface NameFields {
  name: string
}

/** Coordinates and display names frozen after taking the parent row into focus. */
export interface HilosI18nLanguageNameTarget {
  readonly languageId: number
  readonly inLanguageId: number
  readonly localeId: number | null
  readonly nameId: number | null
  readonly baseNameId: number | null
  readonly languageCode: string
  readonly languageNativeName: string
  readonly inLanguageCode: string
  readonly inLanguageNativeName: string
  readonly localeCode: string | null
  readonly mode: 'add' | 'edit'
}

/** One modal's known-place row-edit session over a selected name. */
export interface HilosI18nLanguageNameWindow extends Omit<
  HilosTrackedRowEdit<NameFields, NameFields>,
  'open'
> {
  readonly target: ReadonlySignal<HilosI18nLanguageNameTarget | null>
  /** Live occupancy chooses add or edit; target.mode keeps the opening title. */
  readonly mode: ReadonlySignal<'add' | 'edit' | null>
  readonly dirty: ReadonlySignal<boolean>
  /**
   * Creation is fit to submit, whatever is in flight: the Add button's look.
   * Creation may submit even an untouched empty draft.
   */
  readonly submitAvailable: ReadonlySignal<boolean>
  /** Creation is fit to submit and nothing is in flight: the submit's guard. */
  readonly canSubmit: ReadonlySignal<boolean>
  readonly notice: ReadonlySignal<{
    kind: RowEditNoticeKind
    text: string
  } | null>
  /** Open from the fresh parent row; a missing locale or base refuses a correction. */
  open(rowKey: string, localeId?: number | null): boolean
}

/**
 * Hold a parent row live and project just the base name or one locale correction.
 * Both creation and editing merge against the shown name, initially '' at an
 * empty place. Live occupancy chooses mode; target keeps the opening title and
 * coordinates. The first stored id stays bound through choices and late replies.
 *
 * @param controller The names table holding the writing language in focus.
 * @param card The live subject card.
 * @param language The subject code from the page's address.
 * @param context The tracked action dispatcher.
 */
export function createHilosI18nLanguageNameWindow(
  controller: TableViewportController<HilosI18nNameRow>,
  card: ReadonlySignal<HilosI18nLanguageCard | null>,
  language: ReadonlySignal<string>,
  context: HilosI18nLanguageContext,
): HilosI18nLanguageNameWindow {
  const parent = hilosTableRowEditSource(controller, (row) => row.rowKey)
  const target = createSignal<HilosI18nLanguageNameTarget | null>(null)
  const form = createSignal<NameFields>({ name: '' })
  const boundNameId = createSignal<number | null>(null)
  const creating = createSignal(false)
  let round = 0
  const stops: Unsubscribe[] = []
  const selected = computedSignal(() => {
    const held = target.get()
    const row = parent.live.get()
    if (
      held === null ||
      row?.inLanguageId !== held.inLanguageId ||
      card.get()?.languageId !== held.languageId ||
      language.get() !== held.languageCode
    )
      return undefined
    if (held.localeId === null) {
      return {
        nameId: row.nameId,
        name: row.nameId === null ? '' : (row.name ?? ''),
      }
    }
    if (row.nameId !== held.baseNameId) return undefined
    const correction = row.corrections.find(
      (item) => item.localeId === held.localeId,
    )
    return correction === undefined
      ? undefined
      : {
          nameId: correction.nameId,
          name: correction.nameId === null ? '' : (correction.name ?? ''),
        }
  })
  const source: HilosRowEditSource<NameFields> = {
    open: () => source.live.get() ?? null,
    live: computedSignal(() => {
      const current = selected.get()
      const bound = boundNameId.get()
      return current === undefined ||
        (bound !== null && current.nameId !== bound)
        ? undefined
        : { name: current.name }
    }),
    close: () => parent.close(),
  }
  const edit = createHilosRowEdit<NameFields, NameFields>(source, {
    formSignal: form,
    fields: (row) => ({ name: row.name }),
    creating: () => selected.get()?.nameId === null,
    notice: {
      conflict: (state) =>
        HILOS_I18N_LANGUAGE_NAME_COPY.conflict(state.fields.name.incoming),
    },
  })
  const opened = edit.opened
  const mode = computedSignal(() =>
    opened.get() ? (boundNameId.get() === null ? 'add' : 'edit') : null,
  )
  const saving = computedSignal(() => creating.get() || edit.saving.get())
  const saveAvailable = edit.saveAvailable
  const canSave = computedSignal(() => saveAvailable.get() && !saving.get())
  const submitAvailable = computedSignal(
    () => mode.get() === 'add' && saveAvailable.get(),
  )
  const canSubmit = computedSignal(() => mode.get() === 'add' && canSave.get())

  // Route teardown is allowed during a write. User close goes through the busy
  // guard below. An idle window never releases another dialog's parent focus.
  function close(): void {
    round++
    creating.set(false)
    edit.close()
  }

  async function save(run: HilosActionRun): Promise<void> {
    const held = target.get()
    if (held === null || !canSave.get()) return
    const coordinates = {
      languageId: held.languageId,
      inLanguageId: held.inLanguageId,
      localeId: held.localeId,
    }
    if (mode.get() === 'edit') {
      await edit.save((draft) =>
        run(
          context.actions.dispatch(HILOS_I18N_LANGUAGE_NAME_UPDATE_ACTION, {
            ...coordinates,
            nameId: boundNameId.get(),
            name: draft.name,
            expectedName: source.live.get()!.name,
          }),
        ),
      )
      return
    }
    const started = round
    creating.set(true)
    try {
      const saved = await run(
        context.actions.dispatch(HILOS_I18N_LANGUAGE_NAME_ADD_ACTION, {
          ...coordinates,
          baseNameId: held.baseNameId,
          name: form.get().name,
        }),
      )
      if (saved && started === round) close()
    } finally {
      if (started === round) creating.set(false)
    }
  }

  return {
    ...edit,
    target,
    opened,
    mode,
    saving,
    saveAvailable,
    canSave,
    submitAvailable,
    canSubmit,
    dirty: computedSignal(() => edit.state.get().dirty),
    saveLabel: computedSignal(() =>
      edit.state.get().gone
        ? HILOS_ROW_EDIT_COPY.gone
        : mode.get() === 'add'
          ? HILOS_I18N_LANGUAGE_NAME_COPY.add
          : edit.saveLabel.get(),
    ),
    notice: computedSignal(() => {
      if (!opened.get()) return null
      const notice = edit.state.get().notice
      return notice !== null
        ? { kind: notice.kind, text: edit.noticeText.get() }
        : null
    }),
    open(rowKey, localeId = null) {
      if (saving.get()) return false
      close()
      const subjectCode = language.get()
      const fresh = parent.open(rowKey)
      const subject = card.get()
      if (
        fresh === null ||
        subject?.code !== subjectCode ||
        fresh.inLanguageId === subject.languageId
      ) {
        parent.close()
        return false
      }
      const correction =
        localeId === null
          ? null
          : fresh.corrections.find((item) => item.localeId === localeId)
      const nameId = localeId === null ? fresh.nameId : correction?.nameId
      if (
        nameId === undefined ||
        (localeId !== null && fresh.nameId === null)
      ) {
        parent.close()
        return false
      }
      target.set({
        languageId: subject.languageId,
        inLanguageId: fresh.inLanguageId,
        languageCode: subjectCode,
        languageNativeName: subject.nativeName,
        inLanguageCode: fresh.code,
        inLanguageNativeName: fresh.nativeName,
        localeId,
        localeCode: correction?.localeCode ?? null,
        nameId,
        baseNameId: localeId === null ? null : fresh.nameId,
        mode: nameId === null ? 'add' : 'edit',
      })
      boundNameId.set(nameId)
      return edit.open()
    },
    save,
    close() {
      if (!saving.get()) close()
    },
    start() {
      for (const stop of stops.splice(0)) stop()
      stops.push(
        subscribeSignal(selected, (current) => {
          if (
            opened.get() &&
            boundNameId.get() === null &&
            current !== undefined
          )
            boundNameId.set(current.nameId)
        }),
        subscribeSignal(language, close),
      )
      edit.start()
    },
    dispose() {
      for (const stop of stops.splice(0)) stop()
      close()
      edit.dispose()
    },
  }
}
