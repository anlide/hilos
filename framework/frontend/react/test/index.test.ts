// Pins the public surface: everything a React consumer may import from
// '@hilos/react' is exported here. Behavior is covered by the module tests.
import { expect, expectTypeOf, it } from 'vitest'
import { HilosPages } from '@hilos/core'
import type { ReadonlySignal, ScopeManager } from '@hilos/core'
import {
  HilosAuthGateContext,
  HilosAuthSurface,
  HilosDropdown,
  HilosLayout,
  HilosLink,
  HilosMagicLinkPage,
  HilosOAuthCallbackPage,
  HilosI18nLanguagesPage,
  HilosI18nLanguagePage,
  HilosI18nLanguageNamesPage,
  HilosI18nLanguageLocalesPage,
  HilosI18nCountriesPage,
  HilosI18nCountryPage,
  HilosI18nCountryNamesPage,
  HilosChangeLogPage,
  HilosChangeLogTablesPage,
  HilosChangeLogTablePage,
  HilosAnalyticsPage,
  HilosAnalyticsBrowserSessionPage,
  HilosAnalyticsPersonPage,
  HilosAppearancePage,
  hilosAdminViews,
  HilosPageHead,
  HilosPageHeadingIdContext,
  HilosRouterContext,
  HilosSkeleton,
  HilosAvatar,
  HilosSwitch,
  HilosTableText,
  HilosTakeoverViewOnlyContext,
  HilosThemeMenu,
  HilosView,
  useConnectionState,
  useLookOnly,
  usePageInstanceName,
  useReconnectDragging,
} from '../src/index.js'

import type {
  HilosI18nLanguagesPageProps,
  HilosI18nLanguagePageProps,
  HilosI18nLanguageNamesPageProps,
  HilosI18nLanguageLocalesPageProps,
  HilosI18nCountriesPageProps,
  HilosI18nCountryPageProps,
  HilosI18nCountryNamesPageProps,
  HilosChangeLogPageProps,
  HilosChangeLogTablesPageProps,
  HilosChangeLogTablePageProps,
  HilosAnalyticsPageProps,
  HilosAnalyticsBrowserSessionPageProps,
  HilosAnalyticsPersonPageProps,
  HilosAppearancePageProps,
  HilosPageHeadProps,
  HilosTableTextProps,
} from '../src/index.js'
import * as base from '../src/base/index.js'

it('exports the @hilos/react public surface', () => {
  expect(useConnectionState).toBeTypeOf('function')
  expect(useReconnectDragging).toBeTypeOf('function')
  expect(HilosLink).toBeTypeOf('function')
  expect(HilosView).toBeTypeOf('function')
  expect(HilosSkeleton).toBeTypeOf('function')
  expect(HilosAvatar).toBeTypeOf('function')
  expect(HilosSwitch).toBeTypeOf('function')
  expect(HilosLayout).toBeTypeOf('function')
  expect(HilosThemeMenu).toBeTypeOf('function')
  expect(HilosDropdown).toBeTypeOf('function')
  expect(HilosPageHead).toBeTypeOf('function')
  expect(usePageInstanceName).toBeTypeOf('function')
  expect(HilosRouterContext).toBeTypeOf('object')
  expect(HilosPageHeadingIdContext).toBeTypeOf('object')
  expect(HilosTakeoverViewOnlyContext).toBeTypeOf('object')
  expect(useLookOnly).toBeTypeOf('function')
  expect(HilosAuthGateContext).toBeTypeOf('object')
  expect(HilosAuthSurface).toBeTypeOf('function')
  expect(HilosMagicLinkPage).toBeTypeOf('function')
  expect(HilosOAuthCallbackPage).toBeTypeOf('function')
  expect(HilosI18nLanguagesPage).toBeTypeOf('function')
  expect(HilosI18nLanguagePage).toBeTypeOf('function')
  expect(HilosI18nLanguageNamesPage).toBeTypeOf('function')
  expect(HilosI18nLanguageLocalesPage).toBeTypeOf('function')
  expect(HilosI18nCountriesPage).toBeTypeOf('function')
  expect(HilosI18nCountryPage).toBeTypeOf('function')
  expect(HilosI18nCountryNamesPage).toBeTypeOf('function')
  expect(hilosAdminViews()).not.toHaveProperty(HilosPages.I18N_LANGUAGE_NAMES)
  expect(hilosAdminViews()).not.toHaveProperty(HilosPages.I18N_LANGUAGE_LOCALES)
})

it('exports the props of all seven context-bound i18n pages', () => {
  expectTypeOf<
    Parameters<typeof HilosI18nLanguagesPage>[0]
  >().toEqualTypeOf<HilosI18nLanguagesPageProps>()
  expectTypeOf<
    Parameters<typeof HilosI18nLanguagePage>[0]
  >().toEqualTypeOf<HilosI18nLanguagePageProps>()
  expectTypeOf<
    Parameters<typeof HilosI18nLanguageNamesPage>[0]
  >().toEqualTypeOf<HilosI18nLanguageNamesPageProps>()
  expectTypeOf<
    Parameters<typeof HilosI18nLanguageLocalesPage>[0]
  >().toEqualTypeOf<HilosI18nLanguageLocalesPageProps>()
  expectTypeOf<
    Parameters<typeof HilosI18nCountriesPage>[0]
  >().toEqualTypeOf<HilosI18nCountriesPageProps>()
  expectTypeOf<
    Parameters<typeof HilosI18nCountryPage>[0]
  >().toEqualTypeOf<HilosI18nCountryPageProps>()
  expectTypeOf<
    Parameters<typeof HilosI18nCountryNamesPage>[0]
  >().toEqualTypeOf<HilosI18nCountryNamesPageProps>()
})

it('exports the three Change Log pages and leaves them out of the default map', () => {
  expect(HilosChangeLogPage).toBeTypeOf('function')
  expect(HilosChangeLogTablesPage).toBeTypeOf('function')
  expect(HilosChangeLogTablePage).toBeTypeOf('function')
  expect(hilosAdminViews()).not.toHaveProperty(HilosPages.CHANGE_LOG)
  expect(hilosAdminViews()).not.toHaveProperty(HilosPages.CHANGE_LOG_TABLES)
  expect(hilosAdminViews()).not.toHaveProperty(HilosPages.CHANGE_LOG_TABLE)
  expectTypeOf<
    Parameters<typeof HilosChangeLogPage>[0]
  >().toEqualTypeOf<HilosChangeLogPageProps>()
  expectTypeOf<
    Parameters<typeof HilosChangeLogTablesPage>[0]
  >().toEqualTypeOf<HilosChangeLogTablesPageProps>()
  expectTypeOf<
    Parameters<typeof HilosChangeLogTablePage>[0]
  >().toEqualTypeOf<HilosChangeLogTablePageProps>()
})

it('exports the three Analytics context pages and leaves them out of the default map', () => {
  expect(HilosAnalyticsPage).toBeTypeOf('function')
  expect(HilosAnalyticsBrowserSessionPage).toBeTypeOf('function')
  expect(HilosAnalyticsPersonPage).toBeTypeOf('function')
  expect(hilosAdminViews()).not.toHaveProperty(HilosPages.ANALYTICS)
  expect(hilosAdminViews()).not.toHaveProperty(
    HilosPages.ANALYTICS_BROWSER_SESSION,
  )
  expect(hilosAdminViews()).not.toHaveProperty(HilosPages.ANALYTICS_PERSON)
  expectTypeOf<
    Parameters<typeof HilosAnalyticsPage>[0]
  >().toEqualTypeOf<HilosAnalyticsPageProps>()
  expectTypeOf<
    Parameters<typeof HilosAnalyticsBrowserSessionPage>[0]
  >().toEqualTypeOf<HilosAnalyticsBrowserSessionPageProps>()
  expectTypeOf<
    Parameters<typeof HilosAnalyticsPersonPage>[0]
  >().toEqualTypeOf<HilosAnalyticsPersonPageProps>()
})

it('exports the Appearance context page and leaves it out of the default map', () => {
  expect(HilosAppearancePage).toBeTypeOf('function')
  expect(hilosAdminViews()).not.toHaveProperty(HilosPages.APPEARANCE)
  expectTypeOf<
    Parameters<typeof HilosAppearancePage>[0]
  >().toEqualTypeOf<HilosAppearancePageProps>()
})

it('exports the shared page head once, from the base entry the root re-exports', () => {
  expect(base.HilosPageHead).toBe(HilosPageHead)
  expectTypeOf<
    Parameters<typeof HilosPageHead>[0]
  >().toEqualTypeOf<HilosPageHeadProps>()
  expectTypeOf<base.HilosPageHeadProps>().toEqualTypeOf<HilosPageHeadProps>()
})

it('exports only the public parts text component with one root/base identity', () => {
  expect(base.HilosTableText).toBe(HilosTableText)
  expectTypeOf<
    Parameters<typeof HilosTableText>[0]
  >().toEqualTypeOf<HilosTableTextProps>()
  expectTypeOf<base.HilosTableTextProps>().toEqualTypeOf<HilosTableTextProps>()
  expectTypeOf<keyof HilosTableTextProps>().toEqualTypeOf<'value'>()
  expect(base).not.toHaveProperty('HilosTableValue')
  expect(base).not.toHaveProperty('HilosTableTextContext')
  expect(base).not.toHaveProperty('useTableTextReading')
})

it('exports the live instance name hook once, from the base entry the root re-exports', () => {
  expect(base.usePageInstanceName).toBe(usePageInstanceName)
  expectTypeOf(usePageInstanceName).toEqualTypeOf<
    (
      scopes: ScopeManager,
      page: string,
      name: ReadonlySignal<string | undefined>,
    ) => void
  >()
})
