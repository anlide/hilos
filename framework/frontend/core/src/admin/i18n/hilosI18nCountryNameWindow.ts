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
  type HilosI18nCountryCard,
  type HilosI18nCountryContext,
} from './hilosI18nCountry.js'
import { type HilosI18nNameRow } from './hilosI18nNames.js'

/** Tracked writes on the ADMIN country names page. */
export const HILOS_I18N_COUNTRY_NAME_ADD_ACTION = 'hilos_i18n_country_name_add'
export const HILOS_I18N_COUNTRY_NAME_UPDATE_ACTION =
  'hilos_i18n_country_name_update'

/** The stored name's character limit; an empty string is a value. */
export const HILOS_I18N_COUNTRY_NAME_MAX = 255

/** Words shared by the base-name and locale-correction windows. */
export const HILOS_I18N_COUNTRY_NAME_COPY = {
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
    subject: 'Country',
    inLanguage: 'Written in',
    locale: 'Locale',
    name: 'Name',
  },
  hints: {
    base: 'Empty text is saved as your own value. Delete the name to remove it.',
    correction:
      'Empty text is saved as your own correction. Delete the correction to inherit the base name.',
    catalog:
      'Your saved name is kept when the built-in catalog refreshes. Delete it to let the catalog supply the name again.',
  },
  conflict: (name: string) => `Name changed elsewhere to "${name}".`,
  add: 'Add',
  cancel: 'Cancel',
  refusalTitle: "Couldn't save the country name",
} as const

interface NameFields {
  name: string
}

/** Coordinates and display names frozen after taking the parent row into focus. */
export interface HilosI18nCountryNameTarget {
  readonly countryId: number
  readonly inLanguageId: number
  readonly localeId: number | null
  readonly nameId: number | null
  readonly baseNameId: number | null
  readonly countryCode: string
  readonly countryName: string
  readonly inLanguageCode: string
  readonly inLanguageNativeName: string
  readonly localeCode: string | null
  readonly mode: 'add' | 'edit'
}

/** One modal's creation form or ordinary row-edit session over a selected name. */
export interface HilosI18nCountryNameWindow extends Omit<
  HilosTrackedRowEdit<NameFields, NameFields>,
  'open'
> {
  readonly target: ReadonlySignal<HilosI18nCountryNameTarget | null>
  readonly mode: ReadonlySignal<'add' | 'edit' | null>
  readonly dirty: ReadonlySignal<boolean>
  /** Creation may submit even an untouched empty draft. */
  readonly canSubmit: ReadonlySignal<boolean>
  readonly noticeKind: ReadonlySignal<RowEditNoticeKind | null>
  /** Open from the fresh parent row; a missing locale or base refuses a correction. */
  open(rowKey: string, localeId?: number | null): boolean
}

/**
 * Hold a parent row live and project just the base name or one locale correction.
 * Creation has no baseline; a concurrent creation remains the server's duplicate
 * refusal. Edits use the shared session, including its choices and late-ack guard.
 *
 * @param controller The names table holding the writing language in focus.
 * @param card The live country card.
 * @param country The country code from the page's address.
 * @param context The tracked action dispatcher.
 */
export function createHilosI18nCountryNameWindow(
  controller: TableViewportController<HilosI18nNameRow>,
  card: ReadonlySignal<HilosI18nCountryCard | null>,
  country: ReadonlySignal<string>,
  context: HilosI18nCountryContext,
): HilosI18nCountryNameWindow {
  const parent = hilosTableRowEditSource(controller, (row) => row.rowKey)
  const target = createSignal<HilosI18nCountryNameTarget | null>(null)
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
      row?.inLanguageId !== held.inLanguageId ||
      card.get()?.countryId !== held.countryId ||
      country.get() !== held.countryCode
    )
      return undefined
    if (held.localeId === null) {
      if (held.mode === 'edit' && row.nameId !== held.nameId) return undefined
      return { name: row.name }
    }
    if (row.nameId !== held.baseNameId) return undefined
    const correction = row.corrections.find(
      (item) => item.localeId === held.localeId,
    )
    if (held.mode === 'edit' && correction?.nameId !== held.nameId)
      return undefined
    return correction
  })
  const source: HilosRowEditSource<NameFields> = {
    open: () => source.live.get() ?? null,
    live: computedSignal(() => {
      const current = selected.get()
      return current === undefined || current.name === null
        ? undefined
        : { name: current.name }
    }),
    close: () => parent.close(),
  }
  const edit = createHilosRowEdit<NameFields, NameFields>(source, {
    formSignal: form,
    fields: (row) => ({ name: row.name }),
    notice: {
      conflict: (state) =>
        HILOS_I18N_COUNTRY_NAME_COPY.conflict(state.fields.name.incoming),
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
  const noticeKind = computedSignal(() => {
    if (!opened.get()) return null
    if (missing.get()) return 'deleted'
    return mode.get() === 'edit'
      ? (edit.state.get().notice?.kind ?? null)
      : null
  })

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
      countryId: held.countryId,
      inLanguageId: held.inLanguageId,
      localeId: held.localeId,
    }
    if (mode.get() === 'edit') {
      if (!edit.canSave.get()) return
      await edit.save((draft) =>
        run(
          context.actions.dispatch(HILOS_I18N_COUNTRY_NAME_UPDATE_ACTION, {
            ...coordinates,
            nameId: held.nameId,
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
        context.actions.dispatch(HILOS_I18N_COUNTRY_NAME_ADD_ACTION, {
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
    canSubmit,
    dirty: computedSignal(() =>
      mode.get() === 'add' ? form.get().name !== '' : edit.state.get().dirty,
    ),
    saveLabel: computedSignal(() =>
      missing.get()
        ? HILOS_ROW_EDIT_COPY.gone
        : mode.get() === 'add'
          ? HILOS_I18N_COUNTRY_NAME_COPY.add
          : edit.saveLabel.get(),
    ),
    noticeKind,
    noticeText: computedSignal(() => {
      if (noticeKind.get() === null) return ''
      return missing.get() ? HILOS_ROW_EDIT_COPY.deleted : edit.noticeText.get()
    }),
    open(rowKey, localeId = null) {
      if (saving.get()) return false
      close()
      const subjectCode = country.get()
      const fresh = parent.open(rowKey)
      const subject = card.get()
      // Country and writing language are different catalogs: equal codes are valid.
      if (fresh === null || subject?.code !== subjectCode) {
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
        countryId: subject.countryId,
        inLanguageId: fresh.inLanguageId,
        countryCode: subjectCode,
        countryName: subject.summary.name ?? subject.code.toUpperCase(),
        inLanguageCode: fresh.code,
        inLanguageNativeName: fresh.nativeName,
        localeId,
        localeCode: correction?.localeCode ?? null,
        nameId,
        baseNameId: localeId === null ? null : fresh.nameId,
        mode: nameId === null ? 'add' : 'edit',
      })
      if (nameId === null) {
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
      stop = subscribeSignal(country, close)
    },
    dispose() {
      stop?.()
      stop = null
      close()
      edit.dispose()
    },
  }
}
