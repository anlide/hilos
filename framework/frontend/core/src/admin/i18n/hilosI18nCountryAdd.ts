// Country creation uses the built-in country codes delivered with the page.
// A custom code asks for confirmation before the same tracked action is sent.
import { type HilosActionRun } from '../../conflict/rowEditSession.js'
import {
  computedSignal,
  createSignal,
  type ReadonlySignal,
} from '../../state/signal.js'
import { type HilosI18nCountryContext } from './hilosI18nCountry.js'

/** Tracked page action for creating a country. */
export const HILOS_I18N_COUNTRY_ADD_ACTION = 'hilos_i18n_country_add'

/** Words shared by the country creation form and its confirmation. */
export const HILOS_I18N_COUNTRY_ADD_COPY = {
  open: 'Add country',
  title: 'Add country',
  codeLabel: 'Code',
  codePlaceholder: 'gg',
  codeHint: 'ISO 3166-1 alpha-2.',
  currencySymbolLabel: 'Currency symbol',
  currencySymbolPlaceholder: '£',
  currencySymbolHint: 'What a person sees.',
  currencyCodeLabel: 'Currency code',
  currencyCodePlaceholder: 'GBP',
  currencyCodeHint: 'ISO 4217 — used for calculations.',
  defaultLocaleLabel: 'Default locale',
  defaultLocaleChoice: 'Not chosen',
  defaultLocaleHint:
    'A new country has no locales yet, so there is nothing to choose. Pick one later, while the country is switched off.',
  knownLead: 'The system knows this country.',
  knownRest:
    'It came with the built-in catalog and is already in the list, so adding it again is refused.',
  ownLead: 'This country is not in the built-in catalog.',
  ownRest:
    'You enter its currency, and keeping it up is on you: a framework update never touches your own countries.',
  cancel: 'Cancel',
  submit: 'Add',
  confirmTitle: 'The system does not know this country',
  entered: 'currency entered by you',
  catalogLine: (code: string, count: number) =>
    `The code ${code} is not in the built-in catalog of ${count} countries. You can still add it, but three things become yours:`,
  consequences: [
    'nobody will correct its currency symbol and code if they change;',
    'the built-in catalog has no names for it — you will type each one in yourself;',
    'its locales get no formats from the catalog — you will choose all seven for each.',
  ],
  confirmNote:
    'Check the code: people most often land here because of a typo in the two letters, not because the country is truly missing from the list.',
  confirmSubmit: 'Add anyway',
  refusalTitle: "Couldn't add the country",
} as const

/** Normalize a country code as the form accepts it. */
export function hilosI18nCountryCode(input: string): string {
  return input.trim().toLowerCase()
}

/** Normalize a currency code as the form accepts it. */
export function hilosI18nCurrencyCode(input: string): string {
  return input.trim().toUpperCase()
}

/** The three values held by the creation form. */
export interface HilosI18nCountryAddForm {
  readonly code: string
  readonly currencySymbol: string
  readonly currencyCode: string
}

/** The country creation flow used by every view layer. */
export interface HilosI18nCountryAdd {
  readonly step: ReadonlySignal<'closed' | 'form' | 'confirm'>
  readonly form: ReadonlySignal<HilosI18nCountryAddForm>
  readonly kind: ReadonlySignal<'known' | 'own' | null>
  readonly catalogSize: ReadonlySignal<number | null>
  readonly canSubmit: ReadonlySignal<boolean>
  readonly saving: ReadonlySignal<boolean>
  open(): void
  setCode(input: string): void
  setCurrencySymbol(input: string): void
  setCurrencyCode(input: string): void
  submit(run: HilosActionRun): Promise<void>
  confirm(run: HilosActionRun): Promise<void>
  close(): void
  dispose(): void
}

/**
 * Create the form and its custom-country confirmation over the page catalog.
 *
 * @param context The project's action lifecycle.
 * @param catalog The built-in country codes delivered in the page response.
 */
export function createHilosI18nCountryAdd(
  context: HilosI18nCountryContext,
  catalog: ReadonlySignal<readonly string[] | null>,
): HilosI18nCountryAdd {
  const step = createSignal<'closed' | 'form' | 'confirm'>('closed')
  const form = createSignal<HilosI18nCountryAddForm>({
    code: '',
    currencySymbol: '',
    currencyCode: '',
  })
  const saving = createSignal(false)
  const catalogSize = computedSignal(() => catalog.get()?.length ?? null)
  const kind = computedSignal<'known' | 'own' | null>(() => {
    const codes = catalog.get()
    const code = form.get().code
    if (codes === null || !/^[a-z]{2}$/.test(code)) return null
    return codes.includes(code) ? 'known' : 'own'
  })
  const canSubmit = computedSignal(
    () =>
      step.get() !== 'closed' &&
      !saving.get() &&
      kind.get() !== null &&
      form.get().currencySymbol.trim() !== '' &&
      [...form.get().currencySymbol.trim()].length <= 8 &&
      /^[A-Z]{3}$/.test(form.get().currencyCode),
  )

  async function dispatch(run: HilosActionRun): Promise<void> {
    if (!canSubmit.get()) return
    const value = form.get()
    saving.set(true)
    try {
      if (
        await run(
          context.actions.dispatch(HILOS_I18N_COUNTRY_ADD_ACTION, {
            countryCode: value.code,
            currencySymbol: value.currencySymbol.trim(),
            currencyCode: value.currencyCode,
          }),
        )
      ) {
        step.set('closed')
      }
    } finally {
      saving.set(false)
    }
  }

  function close(): void {
    if (!saving.get()) step.set('closed')
  }

  return {
    step,
    form,
    kind,
    catalogSize,
    canSubmit,
    saving,
    open() {
      if (saving.get()) return
      form.set({ code: '', currencySymbol: '', currencyCode: '' })
      step.set('form')
    },
    setCode(input) {
      form.set({ ...form.get(), code: hilosI18nCountryCode(input) })
    },
    setCurrencySymbol(input) {
      form.set({ ...form.get(), currencySymbol: input })
    },
    setCurrencyCode(input) {
      form.set({ ...form.get(), currencyCode: hilosI18nCurrencyCode(input) })
    },
    async submit(run) {
      if (step.get() !== 'form' || !canSubmit.get()) return
      if (kind.get() === 'own') {
        step.set('confirm')
        return
      }
      await dispatch(run)
    },
    async confirm(run) {
      if (step.get() !== 'confirm' || kind.get() !== 'own') return
      await dispatch(run)
    },
    close,
    dispose: close,
  }
}
