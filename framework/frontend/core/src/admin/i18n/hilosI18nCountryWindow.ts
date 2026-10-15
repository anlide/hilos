// Both country doors share one edit session; the view layers only render it.
import { type ActionHandle } from '../../connection/actionLifecycle.js'
import {
  createHilosRowEdit,
  hilosTableRowEditSource,
  type HilosActionRun,
  type HilosRowEditSource,
} from '../../conflict/rowEditSession.js'
import {
  computedSignal,
  createSignal,
  subscribeSignal,
  type ReadonlySignal,
} from '../../state/signal.js'
import { type TableViewportController } from '../../table/TableViewportController.js'
import {
  type HilosI18nCountryCard,
  type HilosI18nCountryContext,
} from './hilosI18nCountry.js'
import {
  HILOS_I18N_COUNTRY_ADD_COPY,
  hilosI18nCurrencyCode,
} from './hilosI18nCountryAdd.js'
import {
  type HilosI18nCountryLocaleChoice,
  type HilosI18nCountryRow,
} from './hilosI18nCountries.js'

/** Each tracked update belongs to exactly one page. */
export const HILOS_I18N_COUNTRY_UPDATE_ACTION = 'hilos_i18n_country_update'
export const HILOS_I18N_COUNTRY_ROW_UPDATE_ACTION =
  'hilos_i18n_country_row_update'

export type HilosI18nCountryWindowMode = 'edit' | 'view'

/** The country values shared by the card and the focused table row. */
export interface HilosI18nCountryEditable {
  readonly countryId: number
  readonly code: string
  readonly name: string | null
  readonly currencySymbol: string
  readonly currencyCode: string
  readonly defaultLocaleId: number | null
  readonly defaultLocaleCode: string | null
  readonly enabled: boolean
  readonly isOwn: boolean
  readonly locales: readonly HilosI18nCountryLocaleChoice[]
  readonly catalogDefaultLocaleCode: string | null
}

export interface HilosI18nCountryEditFields {
  readonly currencySymbol: string
  readonly currencyCode: string
  readonly defaultLocaleId: number | null
}

export interface HilosI18nCountryLocaleOption {
  readonly value: string
  readonly label: string
}

/** Words of the country window; field labels and currency hints belong to Add. */
export const HILOS_I18N_COUNTRY_WINDOW_COPY = {
  open: { edit: 'Edit country', view: 'View country: nothing to edit' },
  title: (mode: HilosI18nCountryWindowMode, label: string) =>
    `${mode === 'edit' ? 'Edit country' : 'Country'} · ${label}`,
  codeHint:
    'The country code never changes: its URL and the external catalog use it.',
  hints: {
    none: "This country has no locales yet: add one on a language's Locales page.",
    suggested: 'Suggested by the built-in catalog — save to keep it.',
    plain:
      "Only this country's locales. Not chosen — formats come from the language.",
  },
  off: ' — switched off',
  gone: ' — no longer exists',
  notChosen: 'Not chosen',
  plates: {
    editKnown: {
      lead: 'This country is switched off — edit freely.',
      rest: "While it stays off, a framework update brings back the built-in catalog's currency; the default locale stays yours.",
    },
    editOwn: {
      lead: 'This country is switched off — edit freely.',
      rest: 'It is your own country: a framework update never touches it.',
    },
    view: {
      lead: 'This country is switched on, so there is nothing to edit.',
      rest: 'A live row is changed by nobody — neither you nor a framework update. To change it: switch it off, edit it, switch it on.',
    },
  },
  refusalTitle: "Couldn't save the country",
  elsewhere: {
    switchedOn: 'Switched on elsewhere just now.',
    switchedOff: 'Switched off elsewhere just now.',
    deleted: 'Deleted elsewhere',
  },
  cancel: 'Cancel',
  save: 'Save',
  close: 'Close',
} as const

/** Project the typed table row onto the window's country. */
export function hilosI18nCountryEditableFromRow(
  row: HilosI18nCountryRow,
): HilosI18nCountryEditable {
  return {
    countryId: row.countryId,
    code: row.code,
    name: row.name,
    currencySymbol: row.currencySymbol,
    currencyCode: row.currencyCode,
    defaultLocaleId: row.defaultLocaleId,
    defaultLocaleCode: row.defaultLocaleCode,
    enabled: row.enabled,
    isOwn: row.isOwn,
    locales: row.locales,
    catalogDefaultLocaleCode: row.catalogDefaultLocaleCode,
  }
}

/** Project the card and its summary onto the same window's country. */
export function hilosI18nCountryEditableFromCard(
  card: HilosI18nCountryCard,
): HilosI18nCountryEditable {
  return {
    countryId: card.countryId,
    code: card.code,
    name: card.summary.name,
    currencySymbol: card.currencySymbol,
    currencyCode: card.currencyCode,
    defaultLocaleId: card.summary.defaultLocaleId,
    defaultLocaleCode: card.summary.defaultLocaleCode,
    enabled: card.enabled,
    isOwn: card.summary.isOwn,
    locales: card.summary.locales,
    catalogDefaultLocaleCode: card.summary.catalogDefaultLocaleCode,
  }
}

/** Freeze the mode at opening from the fresh country's switch. */
export function hilosI18nCountryWindowMode(
  row: HilosI18nCountryEditable,
): HilosI18nCountryWindowMode {
  return row.enabled ? 'view' : 'edit'
}

/** Use the localized name, or the capitalized code when no name exists. */
export function hilosI18nCountryWindowLabel(
  row: HilosI18nCountryEditable,
): string {
  return row.name ?? row.code.toUpperCase()
}

/** Suggest only an existing locale when no default is already chosen. */
export function hilosI18nCountrySuggestedLocale(
  row: HilosI18nCountryEditable,
): number | null {
  return row.defaultLocaleId === null
    ? (row.locales.find(
        (locale) => locale.code === row.catalogDefaultLocaleCode,
      )?.localeId ?? null)
    : null
}

/** Keep a vanished selection visible, with Not chosen always last. */
export function hilosI18nCountryLocaleOptions(
  row: HilosI18nCountryEditable,
  chosen: number | null,
  known: ReadonlyMap<number, string> = new Map(),
): readonly HilosI18nCountryLocaleOption[] {
  const copy = HILOS_I18N_COUNTRY_WINDOW_COPY
  const options = [...row.locales]
    .sort((a, b) => (a.code < b.code ? -1 : a.code > b.code ? 1 : 0))
    .map((locale) => ({
      value: String(locale.localeId),
      label: locale.code + (locale.enabled ? '' : copy.off),
    }))
  if (
    chosen !== null &&
    !row.locales.some((locale) => locale.localeId === chosen)
  ) {
    options.push({
      value: String(chosen),
      label: (known.get(chosen) ?? String(chosen)) + copy.gone,
    })
  }
  options.push({ value: '', label: copy.notChosen })
  return options
}

function fields(row: HilosI18nCountryEditable): HilosI18nCountryEditFields {
  return {
    currencySymbol: row.currencySymbol,
    currencyCode: row.currencyCode,
    defaultLocaleId: row.defaultLocaleId,
  }
}

function same(
  a: HilosI18nCountryEditFields,
  b: HilosI18nCountryEditFields,
): boolean {
  return (
    a.currencySymbol === b.currencySymbol &&
    a.currencyCode === b.currencyCode &&
    a.defaultLocaleId === b.defaultLocaleId
  )
}

/** One core-owned edit/view window, independent of either opening page. */
export function createHilosI18nCountryWindow(
  source: HilosRowEditSource<HilosI18nCountryEditable>,
  send: (
    draft: HilosI18nCountryEditFields,
    row: HilosI18nCountryEditable,
  ) => ActionHandle,
) {
  const copy = HILOS_I18N_COUNTRY_WINDOW_COPY
  const mode = createSignal<HilosI18nCountryWindowMode>('view')
  const initial: HilosI18nCountryEditFields = {
    currencySymbol: '',
    currencyCode: '',
    defaultLocaleId: null,
  }
  const opening = createSignal(initial)
  // Only the person's input counts toward discarding: catalog prefill and
  // fields merged from the server are not changes made by the person.
  const personal = createSignal(initial)
  const suggestion = createSignal<number | null>(null)
  const knownLocaleLabels = createSignal<ReadonlyMap<number, string>>(new Map())
  let stopLocales: (() => void) | undefined
  const edit = createHilosRowEdit<
    HilosI18nCountryEditable,
    HilosI18nCountryEditFields
  >(
    {
      ...source,
      open(key) {
        const row = source.open(key)
        if (row !== null) mode.set(hilosI18nCountryWindowMode(row))
        return row
      },
    },
    {
      initial,
      fields,
      form: (row) => ({
        ...fields(row),
        defaultLocaleId:
          row.defaultLocaleId ??
          (mode.get() === 'edit' ? hilosI18nCountrySuggestedLocale(row) : null),
      }),
      draft: (form) => ({
        ...form,
        currencySymbol: form.currencySymbol.trim(),
      }),
      valid: (form) =>
        mode.get() === 'edit' &&
        source.live.get()?.enabled === false &&
        [...form.currencySymbol.trim()].length > 0 &&
        [...form.currencySymbol.trim()].length <= 8 &&
        /^[A-Z]{3}$/.test(form.currencyCode),
      notice: {
        conflict: (state) =>
          (state.notice?.fields ?? [])
            .map((field) => {
              const labels = {
                currencySymbol: HILOS_I18N_COUNTRY_ADD_COPY.currencySymbolLabel,
                currencyCode: HILOS_I18N_COUNTRY_ADD_COPY.currencyCodeLabel,
                defaultLocaleId: HILOS_I18N_COUNTRY_ADD_COPY.defaultLocaleLabel,
              }
              const incoming = state.fields[field].incoming
              const label =
                field === 'defaultLocaleId' && incoming !== null
                  ? (source.live
                      .get()
                      ?.locales.find((locale) => locale.localeId === incoming)
                      ?.code ??
                    knownLocaleLabels.get().get(Number(incoming)) ??
                    String(incoming))
                  : (incoming ?? copy.notChosen)
              return `${labels[field]} changed elsewhere to "${label}"; your choice stays selected.`
            })
            .join(' '),
      },
    },
  )
  const live = computedSignal(() =>
    edit.opened.get() ? source.live.get() : undefined,
  )
  const shown = computedSignal(() => live.get() ?? edit.row.get())
  const form = computedSignal(() =>
    mode.get() === 'view' && shown.get() !== null
      ? fields(shown.get()!)
      : edit.form.get(),
  )
  const lockedOn = computedSignal(
    () => mode.get() === 'edit' && live.get()?.enabled === true,
  )
  const switchedOff = computedSignal(
    () => mode.get() === 'view' && live.get()?.enabled === false,
  )
  const noticeKind = computedSignal(() => {
    if (!edit.opened.get() || edit.saving.get()) return null
    if (edit.state.get().gone) return 'deleted'
    if (lockedOn.get() || switchedOff.get()) return 'updated'
    return mode.get() === 'edit'
      ? (edit.state.get().notice?.kind ?? null)
      : null
  })
  const noticeText = computedSignal(() => {
    if (noticeKind.get() === null) return ''
    if (edit.state.get().gone)
      return mode.get() === 'view'
        ? copy.elsewhere.deleted
        : edit.noticeText.get()
    if (lockedOn.get()) return copy.elsewhere.switchedOn
    if (switchedOff.get()) return copy.elsewhere.switchedOff
    return edit.noticeText.get()
  })

  function patch(patch: Partial<HilosI18nCountryEditFields>): void {
    if (mode.get() !== 'edit' || edit.saving.get()) return
    edit.patchForm(patch)
    personal.set({ ...personal.get(), ...patch })
  }

  return {
    mode,
    title: computedSignal(() =>
      copy.title(
        mode.get(),
        edit.row.get() ? hilosI18nCountryWindowLabel(edit.row.get()!) : '',
      ),
    ),
    opened: edit.opened,
    live,
    row: edit.row,
    form,
    state: edit.state,
    noticeKind,
    noticeText,
    plate: computedSignal(() => ({
      ...(mode.get() === 'view' || lockedOn.get()
        ? copy.plates.view
        : edit.row.get()?.isOwn
          ? copy.plates.editOwn
          : copy.plates.editKnown),
      tone:
        mode.get() === 'view' || lockedOn.get()
          ? 'alert-warning'
          : 'alert-info',
      icon: mode.get() === 'view' || lockedOn.get() ? 'bi-lock' : 'bi-pencil',
    })),
    canSave: edit.canSave,
    saveLabel: edit.saveLabel,
    saving: edit.saving,
    options: computedSignal(() =>
      shown.get()
        ? hilosI18nCountryLocaleOptions(
            shown.get()!,
            form.get().defaultLocaleId,
            knownLocaleLabels.get(),
          )
        : [],
    ),
    localeHint: computedSignal(() => {
      const row = shown.get()
      if (!row || row.locales.length === 0) return copy.hints.none
      return row.defaultLocaleId === null &&
        suggestion.get() !== null &&
        form.get().defaultLocaleId === suggestion.get()
        ? copy.hints.suggested
        : copy.hints.plain
    }),
    touched: computedSignal(
      () => mode.get() === 'edit' && !same(opening.get(), personal.get()),
    ),
    open(requestedMode: HilosI18nCountryWindowMode, key?: string): boolean {
      if (edit.saving.get()) return false
      mode.set(requestedMode)
      if (!edit.open(key)) return false
      const opened = edit.row.get()!
      const labels = new Map(
        opened.locales.map((locale) => [locale.localeId, locale.code]),
      )
      if (
        opened.defaultLocaleId !== null &&
        opened.defaultLocaleCode !== null
      ) {
        labels.set(opened.defaultLocaleId, opened.defaultLocaleCode)
      }
      knownLocaleLabels.set(labels)
      opening.set(edit.form.get())
      personal.set(edit.form.get())
      suggestion.set(
        mode.get() === 'edit'
          ? hilosI18nCountrySuggestedLocale(edit.row.get()!)
          : null,
      )
      return true
    },
    setSymbol: (value: string) => patch({ currencySymbol: value }),
    setCurrencyCode: (value: string) =>
      patch({ currencyCode: hilosI18nCurrencyCode(value) }),
    setDefaultLocale(value: string | null) {
      if (mode.get() !== 'edit' || edit.saving.get()) return
      const id = value === '' || value === null ? null : Number(value)
      if (
        id !== null &&
        (!Number.isSafeInteger(id) || id <= 0 || String(id) !== value)
      )
        return
      suggestion.set(null)
      patch({ defaultLocaleId: id })
    },
    keepMine: edit.keepMine,
    takeTheirs() {
      edit.takeTheirs()
      personal.set(edit.form.get())
      suggestion.set(null)
    },
    save: (run: HilosActionRun) =>
      edit.save((draft, row) => run(send(draft, row))),
    close() {
      if (!edit.saving.get()) edit.close()
    },
    start() {
      edit.start()
      stopLocales?.()
      stopLocales = subscribeSignal(source.live, (row) => {
        if (!edit.opened.get() || row === undefined) return
        const labels = new Map(knownLocaleLabels.get())
        for (const locale of row.locales)
          labels.set(locale.localeId, locale.code)
        if (row.defaultLocaleId !== null && row.defaultLocaleCode !== null) {
          labels.set(row.defaultLocaleId, row.defaultLocaleCode)
        }
        knownLocaleLabels.set(labels)
      })
    },
    dispose() {
      stopLocales?.()
      stopLocales = undefined
      edit.dispose()
    },
  }
}

export type HilosI18nCountryWindow = ReturnType<
  typeof createHilosI18nCountryWindow
>

/** The table door holds the selected row in focus for the window's lifetime. */
export function createHilosI18nCountryRowWindow(
  controller: TableViewportController<HilosI18nCountryRow>,
  context: HilosI18nCountryContext,
): HilosI18nCountryWindow {
  const table = hilosTableRowEditSource(controller, (row) => row.rowKey)
  return createHilosI18nCountryWindow(
    {
      open(key) {
        const row = table.open(key)
        return row === null ? null : hilosI18nCountryEditableFromRow(row)
      },
      live: computedSignal(() => {
        const row = table.live.get()
        return row === undefined
          ? undefined
          : hilosI18nCountryEditableFromRow(row)
      }),
      close: table.close,
    },
    (draft, row) =>
      context.actions.dispatch(HILOS_I18N_COUNTRY_ROW_UPDATE_ACTION, {
        countryId: row.countryId,
        ...draft,
      }),
  )
}

/** The card door reads the same country from its complete page datum. */
export function createHilosI18nCountryCardWindow(
  card: ReadonlySignal<HilosI18nCountryCard | null>,
  context: HilosI18nCountryContext,
): HilosI18nCountryWindow {
  const heldId = createSignal<number | null>(null)
  const row = computedSignal(() => {
    const current = card.get()
    return current === null
      ? undefined
      : hilosI18nCountryEditableFromCard(current)
  })
  return createHilosI18nCountryWindow(
    {
      open() {
        const current = row.get()
        heldId.set(current?.countryId ?? null)
        return current ?? null
      },
      live: computedSignal(() =>
        row.get()?.countryId === heldId.get() ? row.get() : undefined,
      ),
      close() {
        heldId.set(null)
      },
    },
    (draft, country) =>
      context.actions.dispatch(HILOS_I18N_COUNTRY_UPDATE_ACTION, {
        countryId: country.countryId,
        ...draft,
      }),
  )
}
