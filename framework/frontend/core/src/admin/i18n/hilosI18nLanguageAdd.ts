// Language creation uses the catalog delivered with the page, without a request.
// A custom code asks for confirmation before the same tracked action is sent.
import { type HilosActionRun } from '../../conflict/rowEditSession.js'
import {
  computedSignal,
  createSignal,
  type ReadonlySignal,
} from '../../state/signal.js'
import { type HilosI18nBuiltInLanguage } from './hilosI18nBuiltInCatalog.js'
import { type HilosI18nLanguageContext } from './hilosI18nLanguage.js'

/** Tracked page action for creating a language. */
export const HILOS_I18N_LANGUAGE_ADD_ACTION = 'hilos_i18n_language_add'

/** Words shared by the language creation form and its confirmation. */
export const HILOS_I18N_LANGUAGE_ADD_COPY = {
  open: 'Add language',
  title: 'Add language',
  codeLabel: 'Code',
  codePlaceholder: 'fr',
  codeHint: 'ISO 639-1. Type the code — the catalog fills in the rest.',
  nativeNameLabel: 'Native name',
  nativeNamePlaceholder: 'Cymraeg',
  nativeNameKnownHint:
    'Filled in from the built-in catalog — you can change it.',
  nativeNameOwnHint: 'How the language calls itself, in the language itself.',
  rtlLabel: 'rtl — writes right to left',
  knownLead: 'The system knows this language.',
  knownRest:
    'Its native name and writing direction come from the built-in catalog — change them now if needed. The language is added switched off.',
  ownLead: 'This language is not in the built-in catalog.',
  ownRest:
    'You enter its native name and writing direction, and keeping them up is on you: a framework update never touches your own languages.',
  cancel: 'Cancel',
  submit: 'Add',
  confirmTitle: 'The system does not know this language',
  direction: (rtl: boolean) =>
    rtl ? 'writes right to left' : 'writes left to right',
  catalogLine: (code: string, count: number) =>
    `The code ${code} is not in the built-in catalog of ${count} languages. You can still add it, and the product will work with it like with any other — but three things become yours:`,
  consequences: [
    'nobody will correct its native name and writing direction if you got them wrong;',
    'the built-in catalog has no country names in it — you will type them in yourself;',
    'a framework update adds nothing for it either: the catalog grows only with its own codes.',
  ],
  confirmNote:
    'Changing your mind is easy: while the language has no locale and no name written by a person, it is deleted whole.',
  confirmSubmit: 'Add anyway',
  refusalTitle: "Couldn't add the language",
} as const

/** Normalize a language code as the form accepts it. */
export function hilosI18nLanguageCode(input: string): string {
  return input.trim().toLowerCase()
}

/** The three values held by the creation form. */
export interface HilosI18nLanguageAddForm {
  readonly code: string
  readonly nativeName: string
  readonly rtl: boolean
}

/** The language creation flow used by every view layer. */
export interface HilosI18nLanguageAdd {
  readonly step: ReadonlySignal<'closed' | 'form' | 'confirm'>
  readonly form: ReadonlySignal<HilosI18nLanguageAddForm>
  readonly known: ReadonlySignal<HilosI18nBuiltInLanguage | null>
  readonly complete: ReadonlySignal<boolean>
  readonly canSubmit: ReadonlySignal<boolean>
  readonly saving: ReadonlySignal<boolean>
  open(): void
  setCode(input: string): void
  setNativeName(input: string): void
  setRtl(rtl: boolean): void
  submit(run: HilosActionRun): Promise<void>
  confirm(run: HilosActionRun): Promise<void>
  close(): void
  dispose(): void
}

/**
 * Create the form and its custom-language confirmation over the page catalog.
 *
 * @param context The project's action lifecycle.
 * @param catalog The built-in languages delivered in the page response.
 */
export function createHilosI18nLanguageAdd(
  context: HilosI18nLanguageContext,
  catalog: ReadonlySignal<readonly HilosI18nBuiltInLanguage[] | null>,
): HilosI18nLanguageAdd {
  const step = createSignal<'closed' | 'form' | 'confirm'>('closed')
  const form = createSignal<HilosI18nLanguageAddForm>({
    code: '',
    nativeName: '',
    rtl: false,
  })
  const saving = createSignal(false)
  const complete = computedSignal(() => /^[a-z]{2}$/.test(form.get().code))
  const known = computedSignal(() =>
    complete.get()
      ? (catalog.get()?.find((entry) => entry.code === form.get().code) ?? null)
      : null,
  )
  const canSubmit = computedSignal(
    () =>
      step.get() !== 'closed' &&
      !saving.get() &&
      complete.get() &&
      catalog.get() !== null &&
      form.get().nativeName.trim() !== '',
  )

  function close(): void {
    if (saving.get()) return
    step.set('closed')
    form.set({ code: '', nativeName: '', rtl: false })
  }

  async function dispatch(run: HilosActionRun): Promise<void> {
    if (!canSubmit.get()) return
    const value = form.get()
    saving.set(true)
    try {
      if (
        await run(
          context.actions.dispatch(HILOS_I18N_LANGUAGE_ADD_ACTION, {
            languageCode: value.code,
            nativeName: value.nativeName.trim(),
            rtl: value.rtl,
          }),
        )
      ) {
        saving.set(false)
        close()
      }
    } finally {
      saving.set(false)
    }
  }

  return {
    step,
    form,
    known,
    complete,
    canSubmit,
    saving,
    open() {
      if (saving.get()) return
      form.set({ code: '', nativeName: '', rtl: false })
      step.set('form')
    },
    setCode(input) {
      if (saving.get()) return
      const code = hilosI18nLanguageCode(input)
      if (code === form.get().code) return
      const entry = catalog.get()?.find((entry) => entry.code === code)
      form.set({
        code,
        nativeName: entry?.nativeName ?? '',
        rtl: entry?.rtl ?? false,
      })
    },
    setNativeName(input) {
      if (!saving.get()) form.set({ ...form.get(), nativeName: input })
    },
    setRtl(rtl) {
      if (!saving.get()) form.set({ ...form.get(), rtl })
    },
    async submit(run) {
      if (step.get() !== 'form' || !canSubmit.get()) return
      if (known.get() === null) {
        step.set('confirm')
        return
      }
      await dispatch(run)
    },
    async confirm(run) {
      if (step.get() !== 'confirm' || known.get() !== null) return
      await dispatch(run)
    },
    close,
    dispose: close,
  }
}
