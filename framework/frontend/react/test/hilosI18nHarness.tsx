import { act, cleanup, fireEvent, render } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, vi } from 'vitest'
import {
  ActionLifecycle,
  bindAdminAccess,
  createSignal,
  hilosToasts,
  pageIdentity,
  HilosPages,
  ScopeManager,
  type HilosConnection,
  type HilosI18nCountryCard,
  type HilosI18nCountryRow,
  type HilosI18nLanguageCard,
  type HilosI18nLanguageContext,
  type HilosI18nLanguageRow,
  type HilosI18nNameRow,
  type HilosI18nLocaleRow,
  type HilosRouter,
  type PageRouteMatch,
} from '@hilos/core'
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
    sendViewport = vi.fn(() => true)
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
  function pushWindow(next = rows): void {
    emit('tableWindow', {
      data: {
        page,
        tableKey,
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
    pageIdentity: pageIdentity(scopes),
    resolvePath: (key: string, params?: Record<string, string>) => {
      const countryPath = `/hilos/i18n/countries/${params?.countryCode}`
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
      scope.data.set('pageLabel', 'Language details')
      scope.data.set('pageLead', '')
      scope.data.set('pageBreadcrumb', [])
      scope.data.set('pageChildren', [])
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

/** Find the visible table projection first; modal controls portal to the body. */
export function el<T extends HTMLElement = HTMLElement>(id: string): T {
  return document.querySelector<T>(`[data-id="${id}"]`)!
}
export function click(id: string): void {
  fireEvent.click(el(id))
}
export function change(id: string, value: string): void {
  fireEvent.change(el(id), { target: { value } })
}
export async function settle(run: () => void): Promise<void> {
  await act(async () => {
    run()
  })
}
