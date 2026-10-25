import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { StrictMode, useEffect } from 'react'
import type { ReactNode } from 'react'
import { afterEach, expect, vi } from 'vitest'
import {
  ActionLifecycle,
  bindAdminAccess,
  COUNTRY_CARD_DATA,
  createAppPageRouter,
  createHilosRouter,
  createSignal,
  hilosToasts,
  pageIdentity,
  HilosPages,
  PageSubscription,
  ScopeManager,
  type HilosConnection,
  type HilosI18nCountryCard,
  type HilosI18nCountryContext,
  type HilosI18nCountryRow,
  type HilosI18nLanguageCard,
  type HilosI18nLanguageContext,
  type HilosI18nLanguageRow,
  type HilosI18nNameRow,
  type HilosI18nLocaleRow,
  type HilosRouter,
  type PageRouteMatch,
} from '@hilos/core'
import { HilosView } from '../src/HilosView.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

export const language: HilosI18nLanguageRow = {
  languageId: 19,
  rowKey: '19',
  code: 'uk',
  nativeName: 'Українська',
  rtl: false,
  enabled: false,
  isDefault: false,
  isOwn: false,
  canDelete: true,
  deleteReason: null,
}
export const languageCard: HilosI18nLanguageCard = {
  languageId: 19,
  code: 'uk',
  nativeName: 'Українська',
  rtl: false,
  enabled: false,
  summary: {
    isDefault: false,
    isOwn: false,
    localeCount: 2,
    enabledLocaleCount: 0,
    nameCount: 3,
    countrylessLocale: 'missing',
    canDelete: true,
    deleteReason: null,
  },
}
export const country: HilosI18nCountryRow = {
  countryId: 143,
  rowKey: '143',
  code: 'gg',
  name: 'Guernsey',
  currencySymbol: '£',
  currencyCode: 'GBP',
  defaultLocaleId: null,
  defaultLocaleCode: null,
  enabled: false,
  isOwn: true,
  locales: [],
  catalogDefaultLocaleCode: null,
  canDelete: true,
  deleteReason: null,
}
export const countryCard: HilosI18nCountryCard = {
  countryId: 143,
  code: 'gg',
  currencySymbol: '£',
  currencyCode: 'GBP',
  enabled: false,
  summary: {
    name: 'Guernsey',
    isOwn: true,
    defaultLocaleId: null,
    defaultLocaleCode: null,
    defaultLocale: 'missing',
    localeCount: 2,
    nameCount: 3,
    locales: [],
    catalogDefaultLocaleCode: null,
    canDelete: true,
    deleteReason: null,
  },
}
/** The country's catalog lead: the owner's, and the main tab's own. */
const COUNTRY_OWNER_LEAD =
  'A single country: currency, default locale, and state.'

/**
 * The shell either country tab answers with beside its card: the tab's own
 * catalog label and lead, the country as the owner (the catalog gives it no
 * icon), the chain through Countries ending at the country (not at Names), the
 * Overview · Names row and no child cards. Other pages keep the legacy identity.
 *
 * @param page The subscribing page key.
 */
function countryTabShell(page: string): Record<string, unknown> | null {
  const tab =
    page === HilosPages.I18N_COUNTRY
      ? { label: 'Country', lead: COUNTRY_OWNER_LEAD }
      : page === HilosPages.I18N_COUNTRY_NAMES
        ? {
            label: 'Country names',
            lead: 'How this country is named in each language.',
          }
        : null
  return tab === null
    ? null
    : {
        pageLabel: tab.label,
        pageLead: tab.lead,
        pageHeadOwner: {
          page: HilosPages.I18N_COUNTRY,
          label: 'Country',
          lead: COUNTRY_OWNER_LEAD,
        },
        pageBreadcrumb: [
          { page: HilosPages.DASHBOARD, label: 'Hilos' },
          { page: HilosPages.I18N, label: 'Internationalization' },
          { page: HilosPages.I18N_COUNTRIES, label: 'Countries' },
          { page: HilosPages.I18N_COUNTRY, label: 'Country' },
        ],
        pageTabs: [
          { page: HilosPages.I18N_COUNTRY, label: 'Overview' },
          { page: HilosPages.I18N_COUNTRY_NAMES, label: 'Names' },
        ],
        pageChildren: [],
      }
}

/** The language's three tabs answer the shared owner, trail, and ordered row. */
function languageTabShell(page: string): Record<string, unknown> | null {
  const tab =
    page === HilosPages.I18N_LANGUAGE
      ? { label: 'Language', lead: 'One language and its state.' }
      : page === HilosPages.I18N_LANGUAGE_NAMES
        ? {
            label: 'Language names',
            lead: 'How this language is named in each language.',
          }
        : page === HilosPages.I18N_LANGUAGE_LOCALES
          ? {
              label: 'Language locales',
              lead: 'The locale formats this language uses.',
            }
          : null
  return tab === null
    ? null
    : {
        pageLabel: tab.label,
        pageLead: tab.lead,
        pageHeadOwner: {
          page: HilosPages.I18N_LANGUAGE,
          label: 'Language',
          lead: 'One language and its state.',
        },
        pageBreadcrumb: [
          { page: HilosPages.DASHBOARD, label: 'Hilos' },
          { page: HilosPages.I18N, label: 'Internationalization' },
          { page: HilosPages.I18N_LANGUAGES, label: 'Languages' },
          { page: HilosPages.I18N_LANGUAGE, label: 'Language' },
        ],
        pageTabs: [
          { page: HilosPages.I18N_LANGUAGE, label: 'Overview' },
          { page: HilosPages.I18N_LANGUAGE_NAMES, label: 'Names' },
          { page: HilosPages.I18N_LANGUAGE_LOCALES, label: 'Locales' },
        ],
        pageChildren: [],
      }
}
const releases: (() => void)[] = []
afterEach(async () => {
  cleanup()
  await act(async () => {
    for (const release of releases.splice(0)) release()
  })
  hilosToasts.clear()
})

/** A real action lifecycle and scope; only connection delivery is replaced. */
export function harness(
  page: string = HilosPages.I18N_LANGUAGE,
  rows: readonly (
    | HilosI18nLanguageRow
    | HilosI18nCountryRow
    | HilosI18nNameRow
    | HilosI18nLocaleRow
  )[] = [language],
  viewer = false,
) {
  const listeners = new Map<string, Set<(frame: never) => void>>()
  const calls: { action: string; payload: unknown; requestId: string }[] = []
  const focuses: (string | null)[] = []
  const unregister = vi.fn(),
    sendViewport = vi.fn<HilosConnection['sendTableViewport']>(
      (_page, _table, descriptor) => {
        // Answer subject-filter changes as well as the initial registration.
        pushWindow(rows, descriptor.requestId)
        return true
      },
    )
  const scopes = new ScopeManager(),
    scope = scopes.openPage(page)
  scopes.session.data.set('adminViewMode', viewer)
  const releaseAccess = bindAdminAccess(scopes)
  const emit = (event: string, frame: unknown): void => {
    for (const listener of listeners.get(event) ?? []) listener(frame as never)
  }
  const tableKey =
    page === HilosPages.I18N_LANGUAGE_NAMES
      ? 'hilosI18nLanguageNames'
      : page === HilosPages.I18N_COUNTRY_NAMES
        ? 'hilosI18nCountryNames'
        : page === HilosPages.I18N_LANGUAGE_LOCALES
          ? 'hilosI18nLanguageLocales'
          : page === HilosPages.I18N_COUNTRIES
            ? 'hilosI18nCountries'
            : 'hilosI18nLanguages'
  const slot =
    page === HilosPages.I18N_LANGUAGE_LOCALES
      ? 'locale'
      : page === HilosPages.I18N_COUNTRIES
        ? 'country'
        : 'language'
  const isCountry = [
    HilosPages.I18N_COUNTRIES,
    HilosPages.I18N_COUNTRY,
    HilosPages.I18N_COUNTRY_NAMES,
  ].some((key) => key === page)
  function rowKey(row: (typeof rows)[number]): string {
    return row.rowKey
  }
  function pushWindow(next = rows, requestId?: string): void {
    emit('tableWindow', {
      data: {
        page,
        tableKey,
        requestId,
        rows: next.map((row) => ({
          rowKey: rowKey(row),
          slots: { [slot]: row },
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
      const set = listeners.get(event) ?? new Set()
      listeners.set(event, set)
      set.add(listener)
      return () => {
        set.delete(listener)
      }
    },
    registerTableWindow: () => pushWindow(),
    unregisterTableWindow: unregister,
    tableWindowDescriptors: () => ({}),
    sendTableViewport: sendViewport,
    sendTableRendered: () => true,
    sendTableRowFocus(_page: string, _table: string, key: string | null) {
      focuses.push(key)
      return true
    },
    sendAction(action: string, payload: unknown, requestId: string) {
      calls.push({ action, payload, requestId })
      return true
    },
  } as unknown as HilosConnection
  const actions = new ActionLifecycle(connection)
  const baseListenerCounts = new Map(
    [...listeners].map(([event, handlers]) => [event, handlers.size]),
  )
  const context: HilosI18nLanguageContext = { connection, scopes, actions }
  const route = createSignal<PageRouteMatch>({
    page,
    params: isCountry ? { countryCode: 'gg' } : { languageCode: 'uk' },
    admin: true,
  })
  const loading = createSignal(true)
  const navigation = {
    currentRoute: route,
    pageLoading: loading,
    pageError: createSignal(null),
    pageIdentity: pageIdentity(scopes),
    resolvePath: (key: string, params?: Record<string, string>) => {
      const countryPath = `/hilos/i18n/countries/${params?.countryCode}`
      if (key === HilosPages.DASHBOARD) return '/hilos'
      if (key === HilosPages.I18N) return '/hilos/i18n'
      if (key === HilosPages.I18N_COUNTRIES) return '/hilos/i18n/countries'
      if (key === HilosPages.I18N_COUNTRY) return countryPath
      if (key === HilosPages.I18N_COUNTRY_NAMES) return `${countryPath}/names`
      const path = `/hilos/i18n/languages/${params?.languageCode}`
      if (key === HilosPages.I18N_LANGUAGES) return '/hilos/i18n/languages'
      if (key === HilosPages.I18N_LANGUAGE) return path
      if (key === HilosPages.I18N_LANGUAGE_NAMES) return `${path}/names`
      if (key === HilosPages.I18N_LANGUAGE_LOCALES) return `${path}/locales`
      return undefined
    },
    navigate: vi.fn(),
  } as unknown as HilosRouter
  releases.push(() => {
    emit('state', 'disconnected')
    releaseAccess()
  })
  return {
    context,
    scope,
    scopes,
    navigation,
    route,
    calls,
    focuses,
    unregister,
    sendViewport,
    listeners,
    baseListenerCounts,
    emit,
    tableKey,
    pushWindow,
    mount(children: ReactNode) {
      return render(
        <HilosRouterContext.Provider value={navigation}>
          {children}
        </HilosRouterContext.Provider>,
      )
    },
    card(value: unknown = isCountry ? countryCard : languageCard) {
      const shell = countryTabShell(page) ??
        languageTabShell(page) ?? {
          pageLabel: 'Language details',
          pageLead: '',
          pageBreadcrumb: [],
          pageChildren: [],
        }
      for (const [key, field] of Object.entries(shell))
        scope.data.set(key, field)
      scope.data.set(isCountry ? 'countryCard' : 'languageCard', value)
      loading.set(false)
    },
    viewer(value: boolean) {
      scopes.session.data.set('adminViewMode', value)
    },
    delta(row: (typeof rows)[number], kind = 'row_updated') {
      emit('tableViewportDelta', {
        data: {
          page,
          tableKey,
          kind,
          rowKey: rowKey(row),
          ...(kind === 'row_removed'
            ? { reason: 'deleted' }
            : { row: { rowKey: rowKey(row), slots: { [slot]: row } } }),
        },
      })
    },
    succeed(index = 0, message = 'Server success') {
      const call = calls[index]!
      emit('actionSuccess', {
        action: call.action,
        requestId: call.requestId,
        message,
      })
    },
    refuse(index = 0, reason = 'Server refused the language') {
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

/**
 * A country tab as an app mounts it: the routed outlet under StrictMode over the
 * real navigator and page subscription, with harness()'s connection and action
 * lifecycle underneath. A refusal then reaches the tab the way the server's does
 * — the error is set and the page scope emptied under it (HIL-1600) — so a
 * window loses its source because the scope went, not because a test cleared it.
 *
 * @param page The country tab's page key.
 * @param rows The rows its table window serves.
 * @param tab The tab itself, drawn over the harness's context.
 */
export function liveCountryTab(
  page: string,
  rows: Parameters<typeof harness>[1],
  tab: (context: HilosI18nCountryContext) => ReactNode,
) {
  const h = harness(page, rows)
  const subscription = new PageSubscription(
    {
      state: 'connected',
      reconnectDragging: false,
      send: () => true,
      on: () => () => {},
      forgetPageFrames: () => {},
      tableWindowDescriptors: () => ({}),
    },
    h.scopes,
  )
  let path =
    page === HilosPages.I18N_COUNTRY_NAMES
      ? '/hilos/i18n/countries/gg/names'
      : '/hilos/i18n/countries/gg'
  const navigation = createHilosRouter(createAppPageRouter({}), subscription, {
    pathname: () => path,
    pushState: (next) => {
      path = next
    },
    replaceState: (next) => {
      path = next
    },
    onPopState: () => () => {},
  })
  navigation.start()
  releases.push(navigation.stop)
  const mounted = vi.fn()
  function Tab() {
    useEffect(() => {
      mounted()
    }, [])
    return tab(h.context)
  }
  const view = render(
    <StrictMode>
      <HilosRouterContext.Provider value={navigation}>
        <HilosView pages={{ [page]: Tab }} />
      </HilosRouterContext.Provider>
    </StrictMode>,
  )
  return {
    ...h,
    view,
    subscription,
    navigation,
    mounted,
    path: () => path,
    /**
     * Answer the subscription the way the tab does, its shell with its card.
     *
     * @param card The card the answer carries.
     */
    async answer(card: HilosI18nCountryCard = countryCard) {
      await settle(() => {
        subscription.ingestPageResponse(page, {
          data: { ...countryTabShell(page), [COUNTRY_CARD_DATA]: card },
        })
      })
    },
  }
}

/** Find the visible table projection first; modal controls portal to the body. */
export function el<T extends HTMLElement = HTMLElement>(id: string): T {
  return document.querySelector<T>(`[data-id="${id}"]`)!
}

/** Assert the language owner head, its current catalog tab, and retired nodes. */
export function expectLanguagePageHead(
  currentPage: string,
  nativeName = languageCard.nativeName,
): void {
  const title = el('hilos-page-head-title')
  expect(title.tagName).toBe('H1')
  expect(title.textContent).toBe(nativeName)
  expect(document.querySelectorAll('h1')).toHaveLength(1)
  expect(lastCrumb()).toBe(nativeName)
  const tabs = el('hilos-page-tabs')
  expect(tabs.getAttribute('aria-label')).toBe('Language pages')
  expect([...tabs.querySelectorAll('a')].map((tab) => tab.textContent)).toEqual(
    ['Overview', 'Names', 'Locales'],
  )
  const activeTab = el(`hilos-page-tab-${currentPage}`)
  expect(activeTab.getAttribute('aria-current')).toBe('page')
  expect(activeTab.classList.contains('active')).toBe(true)
  for (const id of [
    'language-card-header',
    'language-card-tabs',
    'language-card-counts',
    'language-card-native-name',
  ])
    expect(el(id)).toBeNull()
}

export function click(id: string): void {
  fireEvent.click(el(id))
}
export function change(id: string, value: string): void {
  fireEvent.change(el(id), { target: { value } })
}

/** The last breadcrumb entry's own text, without its silent separator. */
export function lastCrumb(): string | null | undefined {
  const current = document.querySelector(
    '[data-id="hilos-breadcrumb"] [aria-current="page"]',
  )
  if (current === null) return undefined
  const text = current.cloneNode(true) as Element
  for (const hidden of text.querySelectorAll('[aria-hidden="true"]'))
    hidden.remove()
  return text.textContent
}

/** The data-ids the manual country header carried; none of them stays. */
const RETIRED_COUNTRY_HEADER = [
  'country-card-header',
  'country-card-title',
  'country-card-tabs',
  'country-card-code-badge',
  'country-card-own',
  'country-card-line',
]

/** Whether anything of the manual country header is left, its tabs included. */
export function retiredCountryHeaderLeft(): boolean {
  return (
    RETIRED_COUNTRY_HEADER.some((id) => el(id) !== null) ||
    document.querySelector('[data-id^="country-card-tab-"]') !== null
  )
}
export async function settle(run: () => void): Promise<void> {
  await act(async () => {
    run()
  })
}
