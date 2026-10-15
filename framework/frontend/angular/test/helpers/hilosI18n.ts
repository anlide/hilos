// The shared bench of the Angular language and country pages and windows: a
// real action lifecycle, a real scope manager and the SDK's own table binder
// over a connection whose delivery alone is replaced, so a test speaks in the
// frames the server would send — the languages, countries, names or locales
// table by the page. A test mounts a component into TestBed through it, reads
// the DOM by data-id, and drives the window with native events.
import type { Provider, Type } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  ActionLifecycle,
  HilosPages,
  ScopeManager,
  bindAdminAccess,
  createSignal,
  hilosToasts,
  pageIdentity,
} from '@hilos/core'
import type {
  HilosConnection,
  HilosI18nCountryCard,
  HilosI18nCountryRow,
  HilosI18nLanguageCard,
  HilosI18nLanguageContext,
  HilosI18nLanguageRow,
  HilosI18nLocaleFormats,
  HilosI18nLocaleRow,
  HilosI18nLocaleTemplates,
  HilosI18nNameRow,
  HilosRouter,
  PageRouteMatch,
} from '@hilos/core'
import { afterEach, vi } from 'vitest'

import { HILOS_ROUTER } from '../../src/hilosRouterToken.js'

/** A switched-off language from the built-in catalog, free to delete. */
export const language: HilosI18nLanguageRow = {
  code: 'uk',
  nativeName: 'Українська',
  rtl: false,
  enabled: false,
  isDefault: false,
  isOwn: false,
  canDelete: true,
  deleteReason: null,
}

/** The main card of {@link language}. */
export const languageCard: HilosI18nLanguageCard = {
  code: 'uk',
  nativeName: 'Українська',
  rtl: false,
  enabled: false,
  summary: {
    isDefault: false,
    isOwn: false,
    localeCount: 2,
    nameCount: 3,
    countrylessLocale: 'missing',
    canDelete: true,
    deleteReason: null,
  },
}

/** How the names of Українська are written in English: a base with three corrections. */
export const englishName: HilosI18nNameRow = {
  code: 'en',
  nativeName: 'English',
  name: 'Ukrainian',
  corrections: [
    {
      localeCode: 'en-GB',
      countryCode: 'gb',
      countryName: 'United Kingdom',
      name: 'Ukrainian (UK)',
    },
    { localeCode: 'en-US', countryCode: 'us', countryName: null, name: '' },
    {
      localeCode: 'en-CA',
      countryCode: 'ca',
      countryName: 'Canada',
      name: null,
    },
  ],
}

/** A stored empty name in Spanish, with nothing under it. */
export const spanishName: HilosI18nNameRow = {
  code: 'es',
  nativeName: 'Español',
  name: '',
  corrections: [],
}

/** No name in French at all. */
export const frenchName: HilosI18nNameRow = {
  code: 'fr',
  nativeName: 'Français',
  name: null,
  corrections: [],
}

/** The seven formats of the built-in catalog for uk-UA. */
export const catalogFormats: HilosI18nLocaleFormats = {
  date: 'dd.MM.yyyy',
  time: 'HH:mm',
  number: '1 234,5',
  phone: '+380 XX XXX XX XX',
  address: 'street, city, postcode',
  measurement: 'metric',
  collation: 'uk',
}

/** The closed format lists the first page answer carries. */
export const localeTemplates: HilosI18nLocaleTemplates = {
  date: ['dd.MM.yyyy', 'yyyy-MM-dd'],
  time: ['HH:mm', 'h:mm a'],
  number: ['1 234,5', '1,234.5'],
  phone: ['+380 XX XXX XX XX', '+1 XXX XXX XXXX'],
  address: ['street, city, postcode', 'city, street'],
  measurement: ['metric', 'imperial'],
  collation: ['uk', 'en'],
}

/** The language's own pair: a switched-on locale without a country. */
export const countrylessLocale: HilosI18nLocaleRow = {
  rowKey: 'uk',
  countryCode: null,
  countryName: null,
  localeCode: 'uk',
  enabled: true,
  isCountryDefault: false,
  formats: catalogFormats,
  catalogFormats,
}

/** A switched-off locale of a country the catalog knows. */
export const ukraineLocale: HilosI18nLocaleRow = {
  rowKey: 'uk-UA',
  countryCode: 'ua',
  countryName: 'Україна',
  localeCode: 'uk-UA',
  enabled: false,
  isCountryDefault: false,
  formats: catalogFormats,
  catalogFormats,
}

/** A pair without a locale that the catalog does not know. */
export const polandPair: HilosI18nLocaleRow = {
  rowKey: 'uk-PL',
  countryCode: 'pl',
  countryName: null,
  localeCode: null,
  enabled: null,
  isCountryDefault: false,
  formats: null,
  catalogFormats: null,
}

/** A switched-off country from the built-in catalog, kept by its own locale. */
export const country: HilosI18nCountryRow = {
  code: 'us',
  name: 'United States',
  currencySymbol: '$',
  currencyCode: 'USD',
  defaultLocaleCode: null,
  enabled: false,
  isOwn: false,
  locales: [
    { code: 'en-US', enabled: true },
    { code: 'es-US', enabled: false },
  ],
  catalogDefaultLocaleCode: 'en-US',
  canDelete: false,
  deleteReason: 'known',
}

/** The main card of {@link country}. */
export const countryCard: HilosI18nCountryCard = {
  code: 'us',
  currencySymbol: '$',
  currencyCode: 'USD',
  enabled: false,
  summary: {
    name: 'United States',
    isOwn: false,
    defaultLocaleCode: null,
    defaultLocale: 'missing',
    localeCount: 2,
    nameCount: 3,
    locales: [
      { code: 'en-US', enabled: true },
      { code: 'es-US', enabled: false },
    ],
    catalogDefaultLocaleCode: 'en-US',
    canDelete: false,
    deleteReason: 'known',
  },
}

/** How United States is written in English: a base with three corrections. */
export const usInEnglish: HilosI18nNameRow = {
  code: 'en',
  nativeName: 'English',
  name: 'United States',
  corrections: [
    {
      localeCode: 'en-GB',
      countryCode: 'gb',
      countryName: 'United Kingdom',
      name: 'USA',
    },
    { localeCode: 'en-US', countryCode: 'us', countryName: null, name: '' },
    {
      localeCode: 'en-CA',
      countryCode: 'ca',
      countryName: 'Canada',
      name: null,
    },
  ],
}

/** A stored empty name of United States in Spanish, with nothing under it. */
export const usInSpanish: HilosI18nNameRow = {
  code: 'es',
  nativeName: 'Español',
  name: '',
  corrections: [],
}

/** No name of United States in French at all. */
export const usInFrench: HilosI18nNameRow = {
  code: 'fr',
  nativeName: 'Français',
  name: null,
  corrections: [],
}

/** A row of any table a bench page binds. */
type BenchRow =
  | HilosI18nLanguageRow
  | HilosI18nCountryRow
  | HilosI18nNameRow
  | HilosI18nLocaleRow

/** The table a bench page binds: its key and the slot a row rides under. */
const BENCH_TABLES: Record<string, { tableKey: string; slot: string }> = {
  [HilosPages.I18N_LANGUAGE_NAMES]: {
    tableKey: 'hilosI18nLanguageNames',
    slot: 'language',
  },
  [HilosPages.I18N_LANGUAGE_LOCALES]: {
    tableKey: 'hilosI18nLanguageLocales',
    slot: 'locale',
  },
  [HilosPages.I18N_COUNTRIES]: {
    tableKey: 'hilosI18nCountries',
    slot: 'country',
  },
  [HilosPages.I18N_COUNTRY_NAMES]: {
    tableKey: 'hilosI18nCountryNames',
    slot: 'language',
  },
}

/** The pages of the bench that stand on one country. */
const COUNTRY_PAGES: readonly string[] = [
  HilosPages.I18N_COUNTRY,
  HilosPages.I18N_COUNTRY_NAMES,
]

/** The list pages of the bench, whose address names no subject. */
const LIST_PAGES: readonly string[] = [
  HilosPages.I18N_LANGUAGES,
  HilosPages.I18N_COUNTRIES,
]

/** The languages table, for every other page of the bench. */
const LANGUAGES_TABLE = { tableKey: 'hilosI18nLanguages', slot: 'language' }

/**
 * The key a row of a bench table travels under.
 *
 * @param row The row.
 */
function benchRowKey(row: BenchRow): string {
  return 'rowKey' in row ? row.rowKey : row.code
}

const releases: (() => void)[] = []
afterEach(() => {
  for (const release of releases.splice(0)) release()
  hilosToasts.clear()
  document.body.innerHTML = ''
})

/**
 * One bench: the context, the navigator and the frames of one language or
 * country page.
 *
 * @param page The page the bench answers for.
 * @param rows The rows the page's table window carries.
 * @param viewer Whether this node shows the admin section in the view mode.
 */
export function i18nBench(
  page: string = HilosPages.I18N_LANGUAGE,
  rows: readonly BenchRow[] = [language],
  viewer = false,
) {
  const table = BENCH_TABLES[page] ?? LANGUAGES_TABLE
  const listeners = new Map<string, Set<(frame: never) => void>>()
  const calls: { action: string; payload: unknown; requestId: string }[] = []
  const focuses: string[] = []
  const unregister = vi.fn()
  const scopes = new ScopeManager()
  const scope = scopes.openPage(page)
  scopes.session.data.set('adminViewMode', viewer)
  const releaseAccess = bindAdminAccess(scopes)
  const emit = (event: string, frame: unknown): void => {
    for (const listener of listeners.get(event) ?? []) listener(frame as never)
  }
  const pushWindow = (next: readonly BenchRow[] = rows): void => {
    emit('tableWindow', {
      data: {
        page,
        tableKey: table.tableKey,
        rows: next.map((row) => ({
          rowKey: benchRowKey(row),
          slots: { [table.slot]: row },
        })),
        totalCount: next.length,
        totalExact: true,
        firstAnchor: null,
        lastAnchor: null,
        offset: 0,
        limit: 100,
      },
    })
  }
  const connection = {
    on(event: string, listener: (frame: never) => void) {
      const group = listeners.get(event) ?? new Set()
      listeners.set(event, group)
      group.add(listener)
      return () => {
        group.delete(listener)
      }
    },
    registerTableWindow: () => pushWindow(),
    unregisterTableWindow: unregister,
    tableWindowDescriptors: () => ({}),
    sendTableViewport: () => true,
    sendTableRendered: () => true,
    sendTableRowFocus(_page: string, _table: string, key: string) {
      focuses.push(key)
      return true
    },
    sendAction(action: string, payload: unknown, requestId: string) {
      calls.push({ action, payload, requestId })
      return true
    },
  } as unknown as HilosConnection
  const actions = new ActionLifecycle(connection)
  const context: HilosI18nLanguageContext = { connection, scopes, actions }
  const route = createSignal<PageRouteMatch>({
    page,
    params: LIST_PAGES.includes(page)
      ? {}
      : COUNTRY_PAGES.includes(page)
        ? { countryCode: 'us' }
        : { languageCode: 'uk' },
    admin: true,
  })
  const loading = createSignal(true)
  const navigate = vi.fn()
  const paths = {
    resolvePath: (key: string, params?: Record<string, string>) => {
      const card = `/hilos/i18n/languages/${params?.['languageCode']}`
      if (key === HilosPages.I18N_LANGUAGES) return '/hilos/i18n/languages'
      if (key === HilosPages.I18N_LANGUAGE) return card
      if (key === HilosPages.I18N_LANGUAGE_NAMES) return `${card}/names`
      if (key === HilosPages.I18N_LANGUAGE_LOCALES) return `${card}/locales`
      const countryCard = `/hilos/i18n/countries/${params?.['countryCode']}`
      if (key === HilosPages.I18N_COUNTRIES) return '/hilos/i18n/countries'
      if (key === HilosPages.I18N_COUNTRY) return countryCard
      if (key === HilosPages.I18N_COUNTRY_NAMES) return `${countryCard}/names`
      return undefined
    },
  }
  const router = {
    currentRoute: route,
    currentPath: createSignal(
      paths.resolvePath(page, { languageCode: 'uk', countryCode: 'us' }) ?? '',
    ),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: loading,
    pageIdentity: pageIdentity(scopes),
    dashboardSections: createSignal(undefined),
    resolvePath: (key: string, params?: Record<string, string>) =>
      paths.resolvePath(key, params),
    clearPageError: vi.fn(),
    denyCurrentPage: vi.fn(),
    awaitPageAnswer: vi.fn(),
    onLeave: () => () => {},
    navigate,
    replacePath: vi.fn(),
    start: vi.fn(),
    stop: vi.fn(),
  } as unknown as HilosRouter
  releases.push(() => {
    emit('state', 'disconnected')
    releaseAccess()
  })

  return {
    context,
    scope,
    scopes,
    router,
    route,
    loading,
    navigate,
    paths,
    calls,
    focuses,
    unregister,
    emit,
    pushWindow,
    /** How many listeners the connection holds now. */
    listenerCount: (): number =>
      [...listeners.values()].reduce((sum, group) => sum + group.size, 0),
    /** Set the main card the page answer carries; null or junk clears it. */
    card(value: unknown = languageCard): void {
      scope.data.set('pageLabel', 'Language')
      scope.data.set('pageLead', '')
      scope.data.set('pageBreadcrumb', [])
      scope.data.set('pageChildren', [])
      scope.data.set('languageCard', value)
      loading.set(false)
    },
    /** Set the country card the page answer carries; null or junk clears it. */
    countryCard(value: unknown = countryCard): void {
      scope.data.set('pageLabel', 'Country')
      scope.data.set('pageLead', '')
      scope.data.set('pageBreadcrumb', [])
      scope.data.set('pageChildren', [])
      scope.data.set('countryCard', value)
      loading.set(false)
    },
    /** Set the closed format lists the page answer carries. */
    templates(value: unknown = localeTemplates): void {
      scope.data.set('localeTemplates', value)
    },
    /** Send one live change of a row of the page's table. */
    delta(row: BenchRow, kind = 'row_updated'): void {
      const rowKey = benchRowKey(row)
      emit('tableViewportDelta', {
        data: {
          page,
          tableKey: table.tableKey,
          kind,
          rowKey,
          ...(kind === 'row_removed'
            ? { reason: 'deleted' }
            : { row: { rowKey, slots: { [table.slot]: row } } }),
        },
      })
    },
    /** Answer one sent action with `::success`. */
    succeed(index = 0, message = 'Server success'): void {
      const call = calls[index]!
      emit('actionSuccess', {
        action: call.action,
        requestId: call.requestId,
        message,
      })
    },
    /** Answer one sent action with a refusal. */
    refuse(index = 0, reason = 'Server refused the language'): void {
      const call = calls[index]!
      emit('actionError', {
        action: call.action,
        requestId: call.requestId,
        reason,
        errorType: 'ValidationException',
        errorDetail: 'The dependency appeared',
      })
    },
  }
}

/** A bench as {@link i18nBench} returns it. */
export type I18nBench = ReturnType<typeof i18nBench>

/**
 * Mount a component over a bench, with its inputs set before the first render.
 *
 * @param component The component to mount.
 * @param bench The bench whose navigator the component reads.
 * @param inputs The inputs to set, by name.
 * @param providers Further providers of the test module.
 */
export function mountOver<T>(
  component: Type<T>,
  bench: I18nBench,
  inputs: Record<string, unknown>,
  providers: Provider[] = [],
) {
  TestBed.configureTestingModule({
    providers: [
      { provide: HILOS_ROUTER, useValue: bench.router },
      ...providers,
    ],
  })
  const fixture = TestBed.createComponent(component)
  for (const [name, value] of Object.entries(inputs)) {
    fixture.componentRef.setInput(name, value)
  }
  fixture.detectChanges()
  document.body.append(fixture.nativeElement as HTMLElement)

  return driver(fixture)
}

/**
 * The DOM verbs of a mounted fixture; each one renders what it changed.
 *
 * @param fixture The mounted fixture.
 */
export function driver<T>(fixture: ComponentFixture<T>) {
  const detect = (): void => fixture.detectChanges()
  const el = <E extends HTMLElement = HTMLElement>(id: string): E | null =>
    document.querySelector<E>(`[data-id="${id}"]`)

  return {
    fixture,
    detect,
    el,
    /** Every node carrying the data-id. */
    all: (id: string): HTMLElement[] => [
      ...document.querySelectorAll<HTMLElement>(`[data-id="${id}"]`),
    ],
    /** Run a change outside the view, then render it. */
    act(change: () => void): void {
      change()
      detect()
    },
    click(id: string): void {
      el(id)!.click()
      detect()
    },
    type(id: string, value: string): void {
      const input = el<HTMLInputElement>(id)!
      input.value = value
      input.dispatchEvent(new Event('input', { bubbles: true }))
      detect()
    },
    toggle(id: string): void {
      el<HTMLInputElement>(id)!.click()
      detect()
    },
    /** Press Enter in the form: the submit event the browser would fire. */
    submit(
      form: HTMLFormElement | null = document.querySelector('form'),
    ): void {
      form!.dispatchEvent(
        new Event('submit', { bubbles: true, cancelable: true }),
      )
      detect()
    },
    escape(): void {
      el('modal')!.dispatchEvent(
        new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
      )
      detect()
    },
    /** Let the reply of an action land, then render what it changed. */
    async settle(change: () => void = () => {}): Promise<void> {
      change()
      await new Promise((resolve) => setTimeout(resolve, 0))
      detect()
    },
  }
}
