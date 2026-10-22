import { z } from 'zod'
import { type ActionHandle } from '../../connection/actionLifecycle.js'
import {
  createHilosRowEdit,
  hilosTableRowEditSource,
  type HilosActionRun,
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
import {
  hilosI18nLocaleFormatsSchema,
  type HilosI18nLocaleFormats,
  type HilosI18nLocaleRow,
} from './hilosI18nLocales.js'

/** Tracked page actions of the locale window. */
export const HILOS_I18N_LOCALE_ADD_ACTION = 'hilos_i18n_locale_add'
export const HILOS_I18N_LOCALE_UPDATE_ACTION = 'hilos_i18n_locale_update'
export const HILOS_I18N_LOCALE_SWITCH_ON_ACTION = 'hilos_i18n_locale_switch_on'
export const HILOS_I18N_LOCALE_SWITCH_OFF_ACTION =
  'hilos_i18n_locale_switch_off'

/** Page-data key carrying the server's closed format lists. */
export const LOCALE_TEMPLATES_DATA = 'localeTemplates'

export const localeTemplatesSchema = z.strictObject({
  date: z.array(z.string()),
  time: z.array(z.string()),
  number: z.array(z.string()),
  phone: z.array(z.string()),
  address: z.array(z.string()),
  measurement: z.array(z.enum(['metric', 'imperial'])),
  collation: z.array(z.string()),
})

export type HilosI18nLocaleTemplates = z.infer<typeof localeTemplatesSchema>

/** Read the closed lists from the first page response. */
export function createHilosI18nLocaleTemplates(
  context: HilosI18nLanguageContext,
): ReadonlySignal<HilosI18nLocaleTemplates | null> {
  const source = context.scopes.pageDataSignal(LOCALE_TEMPLATES_DATA)
  return computedSignal(() => {
    const parsed = localeTemplatesSchema.safeParse(source.get())
    return parsed.success ? parsed.data : null
  })
}

/** The pair's shared suffix in the form and confirmation headings. */
function localeTitleSuffix(nativeName: string, country: string | null): string {
  return `${nativeName}${country === null ? ' without a country' : ` in ${country}`}`
}

/** The one locale window's words across its add, edit and view cases. */
export const HILOS_I18N_LOCALE_COPY = {
  open: {
    add: 'Add locale',
    edit: 'Edit locale',
    view: 'View locale: nothing to edit',
  },
  title: (
    mode: HilosI18nLocaleWindowMode,
    nativeName: string,
    country: string | null,
  ) =>
    `${mode === 'add' ? 'Add locale' : mode === 'edit' ? 'Edit locale' : 'Locale'} · ${localeTitleSuffix(nativeName, country)}`,
  switchOn: {
    open: 'Switch on',
    title: (nativeName: string, country: string | null) =>
      `Switch on locale · ${localeTitleSuffix(nativeName, country)}`,
    body: 'Switching a locale on freezes its seven formats for everyone: to edit them again, switch it off first.',
    catalogNote:
      'Once it is on, a framework update leaves these formats alone.',
    unsaved:
      'Your changes are not saved, and switching on freezes what is saved: go back and save them first.',
    back: 'Back',
    confirm: 'Switch on',
    refusalTitle: "Couldn't switch on the locale",
  },
  switchOff: {
    open: 'Switch off',
    title: (nativeName: string, country: string | null) =>
      `Switch off locale · ${localeTitleSuffix(nativeName, country)}`,
    body: 'Switching a locale off unlocks its seven formats for editing. Its formats and names stay in place.',
    catalogNote:
      'While it is off, the next framework reflow can replace these formats with the built-in ones.',
    defaultReason:
      'The default language needs its locale without a country: it cannot be switched off.',
    languageWarning:
      'This language is switched on. While its locale without a country is off, formats fall back to the default language.',
    countryWarning:
      "This is the country's default locale. While it is off, the country uses the language's formats; its chosen locale stays in place.",
    back: 'Back',
    confirm: 'Switch off',
    refusalTitle: "Couldn't switch off the locale",
  },
  countryLabel: 'Country:',
  noCountry: '— no country',
  codeLabel: 'Code:',
  lead: 'The table row chose the pair, and the pair makes the code — neither changes here. The formats do.',
  labels: {
    date: 'Date',
    time: 'Time',
    number: 'Number',
    phone: 'Phone',
    address: 'Address',
    measurement: 'Units',
    collation: 'Sorting',
  },
  measurement: { metric: 'Metric', imperial: 'Imperial' },
  choose: 'Choose…',
  plate: {
    addKnown:
      'Filled from the built-in catalog. The locale is added switched off.',
    addOwn:
      'The built-in catalog does not know this pair: choose all seven formats. The locale is added switched off.',
    editKnown:
      "This locale is switched off — edit freely. While it stays off, a framework update brings back the built-in catalog's formats.",
    editOwn: 'This locale is switched off — edit freely.',
    view: 'This locale is switched on, so there is nothing to edit. Switch it off, edit it, switch it on.',
  },
  cancel: 'Cancel',
  add: 'Add',
  save: 'Save',
  close: 'Close',
  refusalTitle: {
    add: "Couldn't add the locale",
    edit: "Couldn't save the locale",
  },
  elsewhere: {
    added: 'Added elsewhere just now.',
    switchedOn: 'Switched on elsewhere just now.',
    switchedOff: 'Switched off elsewhere just now.',
    deleted: 'Deleted elsewhere — your text stays to copy.',
  },
} as const

/** The tracked switch-on operation for a captured locale and parent pair. */
export function createHilosI18nLocaleSwitchOn(
  context: HilosI18nLanguageContext,
): {
  switchOn(
    languageId: number,
    countryId: number | null,
    localeId: number,
  ): ActionHandle
} {
  return {
    switchOn: (languageId, countryId, localeId) =>
      context.actions.dispatch(HILOS_I18N_LOCALE_SWITCH_ON_ACTION, {
        languageId,
        countryId,
        localeId,
      }),
  }
}

/** The tracked switch-off operation bound to the project action lifecycle. */
export function createHilosI18nLocaleSwitchOff(
  context: HilosI18nLanguageContext,
): {
  switchOff(
    languageId: number,
    countryId: number | null,
    localeId: number,
  ): ActionHandle
} {
  return {
    switchOff: (languageId, countryId, localeId) =>
      context.actions.dispatch(HILOS_I18N_LOCALE_SWITCH_OFF_ACTION, {
        languageId,
        countryId,
        localeId,
      }),
  }
}

/**
 * Read the live protection and fallback warning of one locale.
 *
 * @param card The live language card, or null when it disappeared.
 * @param row The focused pair, or no row when the pair disappeared.
 */
export function hilosI18nLocaleSwitchOff(
  card: HilosI18nLanguageCard | null,
  row: HilosI18nLocaleRow | null | undefined,
): { disabled: boolean; reason: string | null; warning: string | null } {
  if (
    card === null ||
    !row ||
    row.localeCode === null ||
    row.formats === null ||
    row.enabled !== true
  ) {
    return { disabled: true, reason: null, warning: null }
  }
  const copy = HILOS_I18N_LOCALE_COPY.switchOff
  if (row.countryCode === null && card.summary.isDefault) {
    return { disabled: true, reason: copy.defaultReason, warning: null }
  }
  return {
    disabled: false,
    reason: null,
    warning:
      row.countryCode === null && card.enabled
        ? copy.languageWarning
        : row.isCountryDefault
          ? copy.countryWarning
          : null,
  }
}

export type HilosI18nLocaleWindowMode = 'add' | 'edit' | 'view'

/** Select the mode once, at opening, from the row's current locale. */
export function hilosI18nLocaleWindowMode(
  row: HilosI18nLocaleRow,
): HilosI18nLocaleWindowMode {
  return row.localeCode === null
    ? 'add'
    : row.enabled === true
      ? 'view'
      : 'edit'
}

/** The country as written in this language, falling back to the capitalized code. */
export function hilosI18nLocaleCountryLabel(
  row: HilosI18nLocaleRow,
): string | null {
  return row.countryCode === null
    ? null
    : (row.countryName ?? row.countryCode.toUpperCase())
}

/** A live row-edit session over the seven stored formats of one switched-off locale. */
export function createHilosI18nLocaleEdit(
  controller: TableViewportController<HilosI18nLocaleRow>,
  context: HilosI18nLanguageContext,
): HilosTrackedRowEdit<HilosI18nLocaleRow, HilosI18nLocaleFormats> {
  const tableSource = hilosTableRowEditSource(controller, (row) => row.rowKey)
  const heldLocaleId = createSignal<number | null>(null)
  const source = {
    open(key?: string) {
      const row = tableSource.open(key)
      if (row?.formats === null || row?.localeId === null) {
        tableSource.close()
        return null
      }
      heldLocaleId.set(row?.localeId ?? null)
      return row
    },
    live: computedSignal(() => {
      const row = tableSource.live.get()
      return row?.formats === null || row?.localeId !== heldLocaleId.get()
        ? undefined
        : row
    }),
    close: () => tableSource.close(),
  }
  const edit = createHilosRowEdit<HilosI18nLocaleRow, HilosI18nLocaleFormats>(
    source,
    {
      initial: {
        date: '',
        time: '',
        number: '',
        phone: '',
        address: '',
        measurement: 'metric',
        collation: '',
      },
      fields: (row) => row.formats!,
      valid: () => source.live.get()?.enabled === false,
      notice: {
        conflict: (state) =>
          (state.notice?.fields ?? [])
            .map(
              (field) =>
                `${HILOS_I18N_LOCALE_COPY.labels[field]} changed elsewhere to "${state.fields[field].incoming}"; your choice stays selected.`,
            )
            .join(' '),
      },
    },
  )

  return {
    ...edit,
    save: (run) =>
      edit.save((draft, row) =>
        run(
          context.actions.dispatch(HILOS_I18N_LOCALE_UPDATE_ACTION, {
            languageId: row.languageId,
            countryId: row.countryId,
            localeId: row.localeId,
            formats: draft,
          }),
        ),
      ),
  }
}

/** The creation form holds strings until all seven choices are made. */
export type HilosI18nLocaleAddForm = Record<
  keyof HilosI18nLocaleFormats,
  string
>

/** Live creation dialog over a pair which has no locale yet. */
export interface HilosI18nLocaleAdd {
  readonly opened: ReadonlySignal<boolean>
  readonly row: ReadonlySignal<HilosI18nLocaleRow | null>
  readonly live: ReadonlySignal<HilosI18nLocaleRow | undefined>
  readonly form: ReadonlySignal<HilosI18nLocaleAddForm>
  readonly saving: ReadonlySignal<boolean>
  readonly elsewhere: ReadonlySignal<boolean>
  readonly gone: ReadonlySignal<boolean>
  /** The form is fit to add, whatever is in flight: the Add button's look. */
  readonly addAvailable: ReadonlySignal<boolean>
  /** The form is fit to add and nothing is in flight: the add's guard. */
  readonly canAdd: ReadonlySignal<boolean>
  open(rowKey: string): boolean
  setField(field: keyof HilosI18nLocaleFormats, value: string): void
  add(run: HilosActionRun): Promise<void>
  close(): void
  dispose(): void
}

/** Creation asks for formats while keeping the pair's row in focus. */
export function createHilosI18nLocaleAdd(
  controller: TableViewportController<HilosI18nLocaleRow>,
  context: HilosI18nLanguageContext,
): HilosI18nLocaleAdd {
  const opened = createSignal(false)
  const row = createSignal<HilosI18nLocaleRow | null>(null)
  const form = createSignal<HilosI18nLocaleAddForm>({
    date: '',
    time: '',
    number: '',
    phone: '',
    address: '',
    measurement: '',
    collation: '',
  })
  const saving = createSignal(false)
  const live = computedSignal(() => {
    const focused = controller.focusedRow.get()
    return opened.get() && focused?.rowKey === row.get()?.rowKey
      ? focused
      : undefined
  })
  const gone = computedSignal(() => opened.get() && live.get() === undefined)
  const elsewhere = computedSignal(
    () =>
      opened.get() &&
      !saving.get() &&
      live.get()?.localeCode !== null &&
      !gone.get(),
  )
  const addAvailable = computedSignal(
    () =>
      opened.get() &&
      !gone.get() &&
      !elsewhere.get() &&
      Object.values(form.get()).every((value) => value !== '') &&
      hilosI18nLocaleFormatsSchema.safeParse(form.get()).success,
  )
  const canAdd = computedSignal(() => addAvailable.get() && !saving.get())

  function close(): void {
    if (saving.get()) return
    opened.set(false)
    controller.releaseFocus()
  }

  return {
    opened,
    row,
    live,
    form,
    saving,
    elsewhere,
    gone,
    addAvailable,
    canAdd,
    open(rowKey) {
      if (saving.get()) return false
      const fresh = controller.focusRow(rowKey)
      if (fresh === null || fresh.localeCode !== null) {
        controller.releaseFocus()
        return false
      }
      row.set(fresh)
      form.set(
        fresh.catalogFormats ?? {
          date: '',
          time: '',
          number: '',
          phone: '',
          address: '',
          measurement: '',
          collation: '',
        },
      )
      opened.set(true)
      return true
    },
    setField(field, value) {
      form.set({ ...form.get(), [field]: value })
    },
    async add(run) {
      if (!canAdd.get()) return
      const formats = hilosI18nLocaleFormatsSchema.parse(form.get())
      const target = row.get()
      if (target === null) return
      saving.set(true)
      try {
        if (
          await run(
            context.actions.dispatch(HILOS_I18N_LOCALE_ADD_ACTION, {
              languageId: target.languageId,
              countryId: target.countryId,
              formats,
            }),
          )
        ) {
          saving.set(false)
          close()
        }
      } finally {
        saving.set(false)
      }
    },
    close,
    dispose: close,
  }
}
