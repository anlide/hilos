// Pins the public surface: everything an Angular consumer may import from
// '@hilos/angular' is exported here. Behavior is covered by the module tests.
import { HilosPages } from '@hilos/core'
import { expect, it } from 'vitest'
import {
  ConflictActions,
  ConflictHeader,
  ErrorPage,
  HILOS_AUTH_GATE,
  HilosAnalyticsBrowserSessionPage,
  HilosAnalyticsPage,
  HilosAppearancePage,
  HilosAnalyticsPersonPage,
  HilosChangeLogPage,
  HilosChangeLogTablePage,
  HilosChangeLogTablesPage,
  HILOS_PAGE_HEADING_ID,
  HILOS_ROUTER,
  HILOS_TAKEOVER_VIEW_ONLY,
  HilosAdminPage,
  HilosAuthSurface,
  HilosBreadcrumb,
  HilosDashboardPage,
  HilosDropdown,
  HilosFormError,
  HilosSendProgress,
  HilosUploadDrop,
  HilosUploadFiles,
  HilosUploadPicker,
  HilosLayout,
  HilosLink,
  HilosMagicLinkPage,
  HilosMaintenance,
  HilosModal,
  HilosOAuthCallbackPage,
  HilosPageHead,
  HilosProfileTheme,
  HilosSettingsPage,
  HilosSkeleton,
  HilosAvatar,
  HilosHiddenMark,
  HilosHideable,
  HilosI18nCountriesPage,
  HilosI18nCountryNamesPage,
  HilosI18nCountryPage,
  HilosI18nLanguageLocalesPage,
  HilosI18nLanguageNamesPage,
  HilosI18nLanguagePage,
  HilosI18nLanguagesPage,
  HilosSwitch,
  HilosThemeMenu,
  HilosUserPage,
  HilosUsersPage,
  HilosView,
  HilosViewportTable,
  HilosTableText,
  LoadingButton,
  connectionStateSignal,
  createHilosTrackedAction,
  hilosAdminViews,
  injectLookOnly,
} from '../src/index.js'
import * as root from '../src/index.js'
import * as base from '../src/base/index.js'

it('exports the @hilos/angular public surface', () => {
  expect(connectionStateSignal).toBeTypeOf('function')
  expect(HILOS_ROUTER).toBeTypeOf('object')
  expect(HILOS_PAGE_HEADING_ID).toBeTypeOf('object')
  expect(HILOS_TAKEOVER_VIEW_ONLY).toBeTypeOf('object')
  expect(injectLookOnly).toBeTypeOf('function')
  expect(HILOS_AUTH_GATE).toBeTypeOf('object')
  expect(HilosLink).toBeTypeOf('function')
  expect(HilosView).toBeTypeOf('function')
  expect(HilosSkeleton).toBeTypeOf('function')
  expect(HilosAvatar).toBeTypeOf('function')
  expect(HilosHiddenMark).toBeTypeOf('function')
  expect(HilosHideable).toBeTypeOf('function')
  expect(HilosSwitch).toBeTypeOf('function')
  expect(ErrorPage).toBeTypeOf('function')
  expect(HilosLayout).toBeTypeOf('function')
  expect(HilosThemeMenu).toBeTypeOf('function')
  expect(HilosProfileTheme).toBeTypeOf('function')
  expect(HilosMaintenance).toBeTypeOf('function')
  expect(LoadingButton).toBeTypeOf('function')
  expect(HilosBreadcrumb).toBeTypeOf('function')
  expect(HilosPageHead).toBeTypeOf('function')
  expect(ConflictHeader).toBeTypeOf('function')
  expect(ConflictActions).toBeTypeOf('function')
  expect(HilosAdminPage).toBeTypeOf('function')
  expect(HilosAuthSurface).toBeTypeOf('function')
  expect(HilosMagicLinkPage).toBeTypeOf('function')
  expect(HilosOAuthCallbackPage).toBeTypeOf('function')
  expect(HilosDashboardPage).toBeTypeOf('function')
  expect(HilosModal).toBeTypeOf('function')
  expect(HilosFormError).toBeTypeOf('function')
  expect(HilosSendProgress).toBeTypeOf('function')
  expect(HilosUploadDrop).toBeTypeOf('function')
  expect(HilosUploadFiles).toBeTypeOf('function')
  expect(HilosUploadPicker).toBeTypeOf('function')
  expect(HilosViewportTable).toBeTypeOf('function')
  expect(HilosTableText).toBeTypeOf('function')
  expect(HilosDropdown).toBeTypeOf('function')
  expect(HilosSettingsPage).toBeTypeOf('function')
  expect(HilosAppearancePage).toBeTypeOf('function')
  expect(hilosAdminViews()).not.toHaveProperty(HilosPages.APPEARANCE)
  expect(HilosUsersPage).toBeTypeOf('function')
  expect(HilosUserPage).toBeTypeOf('function')
  expect(createHilosTrackedAction).toBeTypeOf('function')
  expect(HilosI18nLanguagesPage).toBeTypeOf('function')
  expect(HilosI18nLanguagePage).toBeTypeOf('function')
  expect(hilosAdminViews()).toHaveProperty(HilosPages.I18N)
  expect(hilosAdminViews()).not.toHaveProperty(HilosPages.I18N_LANGUAGES)
  expect(hilosAdminViews()).not.toHaveProperty(HilosPages.I18N_LANGUAGE)
  expect(HilosI18nLanguageNamesPage).toBeTypeOf('function')
  expect(HilosI18nLanguageLocalesPage).toBeTypeOf('function')
  expect(hilosAdminViews()).not.toHaveProperty(HilosPages.I18N_LANGUAGE_NAMES)
  expect(hilosAdminViews()).not.toHaveProperty(HilosPages.I18N_LANGUAGE_LOCALES)
  expect(HilosI18nCountriesPage).toBeTypeOf('function')
  expect(HilosI18nCountryPage).toBeTypeOf('function')
  expect(HilosI18nCountryNamesPage).toBeTypeOf('function')
  expect(hilosAdminViews()).not.toHaveProperty(HilosPages.I18N_COUNTRIES)
  expect(hilosAdminViews()).not.toHaveProperty(HilosPages.I18N_COUNTRY)
  expect(hilosAdminViews()).not.toHaveProperty(HilosPages.I18N_COUNTRY_NAMES)
})

it('exports the three Change Log pages and leaves them out of the default map', () => {
  expect(HilosChangeLogPage).toBeTypeOf('function')
  expect(HilosChangeLogTablesPage).toBeTypeOf('function')
  expect(HilosChangeLogTablePage).toBeTypeOf('function')
  expect(hilosAdminViews()).not.toHaveProperty(HilosPages.CHANGE_LOG)
  expect(hilosAdminViews()).not.toHaveProperty(HilosPages.CHANGE_LOG_TABLES)
  expect(hilosAdminViews()).not.toHaveProperty(HilosPages.CHANGE_LOG_TABLE)
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
})

it('exports the shared page head once, from the base entry the root re-exports', () => {
  expect(base.HilosPageHead).toBe(HilosPageHead)
})

it('exports table text once without exposing its mount internals', () => {
  expect(base.HilosTableText).toBe(HilosTableText)
  for (const name of [
    'HilosTableValue',
    'HILOS_TABLE_TEXT',
    'createTableTextReading',
    'createTableTextOverflow',
    'createTableViewportGeometry',
  ]) {
    expect(base).not.toHaveProperty(name)
    expect(root).not.toHaveProperty(name)
  }
})
