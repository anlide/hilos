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
  readonly languageCode: string
  readonly languageNativeName: string
  readonly inLanguageCode: string
  readonly inLanguageNativeName: string
  readonly localeCode: string | null
  readonly mode: 'add' | 'edit'
}

/** One modal's creation form or ordinary row-edit session over a selected name. */
export interface HilosI18nLanguageNameWindow extends Omit<
  HilosTrackedRowEdit<NameFields, NameFields>,
  'open'
> {
  readonly target: ReadonlySignal<HilosI18nLanguageNameTarget | null>
  readonly mode: ReadonlySignal<'add' | 'edit' | null>
  readonly dirty: ReadonlySignal<boolean>
  readonly canSubmit: ReadonlySignal<boolean>
  readonly notice: ReadonlySignal<{
    kind: RowEditNoticeKind
    text: string
  } | null>
  /** Open from the fresh parent row; a missing locale or base refuses a correction. */
  open(rowKey: string, localeCode?: string | null): boolean
}

/**
 * Hold a parent row live and project just the base name or one locale correction.
 * Creation has no baseline; a concurrent creation remains the server's duplicate
 * refusal. Edits use the shared session, including its choices and late-ack guard.
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
  const parent = hilosTableRowEditSource(controller, (row) => row.code)
  const target = createSignal<HilosI18nLanguageNameTarget | null>(null)
  const form = createSignal<NameFields>({ name: '' })
  const adding = createSignal(false)
  const creating = createSignal(false)
  let round = 0
  let stop: Unsubscribe | null = null
  const selected = computedSignal(() => {
    const held = target.get()
    const row = parent.live.get()
    if (
      held === null ||
      row?.code !== held.inLanguageCode ||
      card.get()?.code !== held.languageCode ||
      language.get() !== held.languageCode
    )
      return undefined
    if (held.localeCode === null) return { name: row.name }
    if (row.name === null) return undefined
    return row.corrections.find((item) => item.localeCode === held.localeCode)
  })
  const source: HilosRowEditSource<NameFields> = {
    open: () => source.live.get() ?? null,
    live: computedSignal(() => {
      const current = selected.get()
      return current?.name == null ? undefined : { name: current.name }
    }),
    close: () => parent.close(),
  }
  const edit = createHilosRowEdit<NameFields, NameFields>(source, {
    formSignal: form,
    fields: (row) => ({ name: row.name }),
    notice: {
      conflict: (state) =>
        HILOS_I18N_LANGUAGE_NAME_COPY.conflict(state.fields.name.incoming),
    },
  })
  const opened = computedSignal(() => adding.get() || edit.opened.get())
  const mode = computedSignal(() => (opened.get() ? target.get()!.mode : null))
  const saving = computedSignal(() => creating.get() || edit.saving.get())
  const missing = computedSignal(() =>
    mode.get() === 'add' ? selected.get() === undefined : edit.state.get().gone,
  )
  const canSubmit = computedSignal(
    () => mode.get() === 'add' && !creating.get() && !missing.get(),
  )

  // Route teardown is allowed during a write. User close goes through the busy
  // guard below. An idle window never releases another dialog's parent focus.
  function close(): void {
    round++
    if (adding.get()) {
      adding.set(false)
      creating.set(false)
      parent.close()
    }
    edit.close()
  }

  async function save(run: HilosActionRun): Promise<void> {
    const held = target.get()
    if (held === null) return
    const coordinates = {
      languageCode: held.languageCode,
      inLanguageCode: held.inLanguageCode,
      localeCode: held.localeCode,
    }
    if (mode.get() === 'edit') {
      if (!edit.canSave.get()) return
      await edit.save((draft) =>
        run(
          context.actions.dispatch(HILOS_I18N_LANGUAGE_NAME_UPDATE_ACTION, {
            ...coordinates,
            name: draft.name,
            expectedName: source.live.get()!.name,
          }),
        ),
      )
      return
    }
    if (!canSubmit.get()) return
    const started = round
    creating.set(true)
    try {
      const saved = await run(
        context.actions.dispatch(HILOS_I18N_LANGUAGE_NAME_ADD_ACTION, {
          ...coordinates,
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
    canSubmit,
    dirty: computedSignal(() =>
      mode.get() === 'add' ? form.get().name !== '' : edit.state.get().dirty,
    ),
    saveLabel: computedSignal(() =>
      missing.get()
        ? HILOS_ROW_EDIT_COPY.gone
        : mode.get() === 'add'
          ? HILOS_I18N_LANGUAGE_NAME_COPY.add
          : edit.saveLabel.get(),
    ),
    notice: computedSignal(() => {
      if (!opened.get()) return null
      if (missing.get())
        return { kind: 'deleted', text: HILOS_ROW_EDIT_COPY.deleted }
      const notice = edit.state.get().notice
      return mode.get() === 'edit' && notice !== null
        ? { kind: notice.kind, text: edit.noticeText.get() }
        : null
    }),
    open(rowKey, localeCode = null) {
      if (saving.get()) return false
      close()
      const subjectCode = language.get()
      const fresh = parent.open(rowKey)
      const subject = card.get()
      if (
        fresh === null ||
        subject?.code !== subjectCode ||
        fresh.code === subjectCode
      ) {
        parent.close()
        return false
      }
      const name =
        localeCode === null
          ? fresh.name
          : fresh.corrections.find((item) => item.localeCode === localeCode)
              ?.name
      if (name === undefined || (localeCode !== null && fresh.name === null)) {
        parent.close()
        return false
      }
      target.set({
        languageCode: subjectCode,
        languageNativeName: subject.nativeName,
        inLanguageCode: fresh.code,
        inLanguageNativeName: fresh.nativeName,
        localeCode,
        mode: name === null ? 'add' : 'edit',
      })
      if (name === null) {
        form.set({ name: '' })
        adding.set(true)
        return true
      }
      return edit.open()
    },
    save,
    close() {
      if (!saving.get()) close()
    },
    start() {
      edit.start()
      stop?.()
      stop = subscribeSignal(language, close)
    },
    dispose() {
      stop?.()
      stop = null
      close()
      edit.dispose()
    },
  }
}
