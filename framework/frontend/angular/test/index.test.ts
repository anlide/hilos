// Pins the public surface: everything an Angular consumer may import from
// '@hilos/angular' is exported here. Behavior is covered by the module tests.
import { HilosPages } from '@hilos/core/base'
import { expect, it } from 'vitest'
import {
  ConflictActions,
  ConflictHeader,
  ErrorPage,
  HILOS_AUTH_GATE,
  HILOS_PAGE_HEADING_ID,
  HILOS_ROUTER,
  HILOS_TAKEOVER_VIEW_ONLY,
  HilosAdminPage,
  HilosAuthSurface,
  HilosBreadcrumb,
  HilosDropdown,
  HilosFormError,
  HilosSendProgress,
  HilosUploadDrop,
  HilosUploadFiles,
  HilosUploadPicker,
  HilosLayout,
  HilosPageTemplate,
  HilosLink,
  HilosMagicLinkPage,
  HilosMaintenance,
  HilosModal,
  HilosOAuthCallbackPage,
  HilosPageHead,
  HilosProfileHead,
  HilosProfileTheme,
  HilosSkeleton,
  HilosAvatar,
  HilosHiddenMark,
  HilosHideable,
  HilosSwitch,
  HilosThemeMenu,
  HilosView,
  HilosViewportTable,
  HilosTableEmptyState,
  HilosTableText,
  LoadingButton,
  connectionStateSignal,
  createHilosTrackedAction,
  injectLookOnly,
} from '@hilos/angular/base'
import {
  HilosAnalyticsBrowserSessionPage,
  HilosAnalyticsBrowserSessionsPage,
  HilosAnalyticsPage,
  HilosAnalyticsPeoplePage,
  HilosAnalyticsPersonPage,
} from '@hilos/angular/admin/analytics'
import { HilosAppearancePage } from '@hilos/angular/admin/appearance'
import {
  HilosChangeLogPage,
  HilosChangeLogTablePage,
  HilosChangeLogTablesPage,
} from '@hilos/angular/admin/change-log'
import { HilosDashboardPage } from '@hilos/angular/admin/dashboard'
import {
  HilosLegalDocumentSetPage,
  HilosLegalDocumentDeviationsPage,
  HilosLegalDocumentRevisionsPage,
  HilosLegalRevisionTextPage,
} from '@hilos/angular/admin/legal'
import { HilosModerationPage } from '@hilos/angular/admin/moderation'
import { HilosSettingsPage } from '@hilos/angular/admin/settings'
import {
  HilosI18nCountriesPage,
  HilosI18nCountryNamesPage,
  HilosI18nCountryPage,
  HilosI18nLanguageLocalesPage,
  HilosI18nLanguageNamesPage,
  HilosI18nLanguagePage,
  HilosI18nLanguagesPage,
  HilosI18nPage,
} from '@hilos/angular/admin/i18n'
import { HilosUserPage, HilosUsersPage } from '@hilos/angular/admin/users'
import { hilosAdminViews } from '@hilos/angular/admin-views'
import * as base from '../src/base/index.js'
import * as legal from '@hilos/angular/admin/legal'
import * as moderation from '@hilos/angular/admin/moderation'

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
  expect(HilosPageTemplate).toBeTypeOf('function')
  expect(HilosPageTemplate).toBe(base.HilosPageTemplate)
  expect(HilosThemeMenu).toBeTypeOf('function')
  expect(HilosProfileHead).toBeTypeOf('function')
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
  expect(HilosTableEmptyState).toBeTypeOf('function')
  expect(HilosTableEmptyState).toBe(base.HilosTableEmptyState)
  expect(HilosModerationPage).toBeTypeOf('function')
  expect(HilosModerationPage).toBe(moderation.HilosModerationPage)
  expect(moderation.HilosModerationKindRulesPage).toBeTypeOf('function')
  expect(base).not.toHaveProperty('HilosModerationPage')
  expect(base).not.toHaveProperty('HilosModerationKindRulesPage')
  for (const name of [
    'HilosModerationRuleAdd',
    'HilosModerationRuleEdit',
    'HilosModerationRuleDelete',
  ]) {
    expect(moderation).not.toHaveProperty(name)
    expect(base).not.toHaveProperty(name)
  }
  expect(HilosTableText).toBeTypeOf('function')
  expect(HilosDropdown).toBeTypeOf('function')
  expect(HilosSettingsPage).toBeTypeOf('function')
  expect(HilosLegalDocumentSetPage).toBeTypeOf('function')
  expect(HilosLegalDocumentSetPage).toBe(legal.HilosLegalDocumentSetPage)
  expect(HilosLegalDocumentDeviationsPage).toBeTypeOf('function')
  expect(HilosLegalDocumentDeviationsPage).toBe(
    legal.HilosLegalDocumentDeviationsPage,
  )
  expect(HilosLegalDocumentRevisionsPage).toBeTypeOf('function')
  expect(HilosLegalDocumentRevisionsPage).toBe(
    legal.HilosLegalDocumentRevisionsPage,
  )
  expect(HilosLegalRevisionTextPage).toBeTypeOf('function')
  expect(HilosLegalRevisionTextPage).toBe(legal.HilosLegalRevisionTextPage)
  expect(legal).not.toHaveProperty('HilosLegalRevisionPageShell')
  expect(legal).not.toHaveProperty('HilosLegalDocumentPageShell')
  expect(HilosAppearancePage).toBeTypeOf('function')
  expect(hilosAdminViews()).not.toHaveProperty(HilosPages.APPEARANCE)
  expect(HilosUsersPage).toBeTypeOf('function')
  expect(HilosUserPage).toBeTypeOf('function')
  expect(createHilosTrackedAction).toBeTypeOf('function')
  expect(HilosI18nLanguagesPage).toBeTypeOf('function')
  expect(HilosI18nLanguagePage).toBeTypeOf('function')
  expect(HilosI18nPage).toBeTypeOf('function')
  expect(hilosAdminViews()).not.toHaveProperty(HilosPages.I18N)
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

it('exports the five Analytics context pages and leaves them out of the default map', () => {
  expect(HilosAnalyticsPage).toBeTypeOf('function')
  expect(HilosAnalyticsBrowserSessionPage).toBeTypeOf('function')
  expect(HilosAnalyticsBrowserSessionsPage).toBeTypeOf('function')
  expect(HilosAnalyticsPeoplePage).toBeTypeOf('function')
  expect(HilosAnalyticsPersonPage).toBeTypeOf('function')
  expect(hilosAdminViews()).not.toHaveProperty(HilosPages.ANALYTICS)
  expect(hilosAdminViews()).not.toHaveProperty(
    HilosPages.ANALYTICS_BROWSER_SESSION,
  )
  expect(hilosAdminViews()).not.toHaveProperty(
    HilosPages.ANALYTICS_BROWSER_SESSIONS,
  )
  expect(hilosAdminViews()).not.toHaveProperty(HilosPages.ANALYTICS_PEOPLE)
  expect(hilosAdminViews()).not.toHaveProperty(HilosPages.ANALYTICS_PERSON)
})

it('exports the shared page head once, from the base entry the root re-exports', () => {
  expect(base.HilosPageHead).toBe(HilosPageHead)
})

it('exports the date-time picker field from the base entry', () => {
  expect(base.HilosDateTimePicker).toBeTypeOf('function')
})

it('exports the shared profile head once, from the base entry the root re-exports', () => {
  expect(base.HilosProfileHead).toBe(HilosProfileHead)
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
  }
})

it('exports the Status owners through their section while keeping context pages out of defaults', async () => {
  const status = await import('@hilos/angular/admin/status')
  const owners = {
    ...(await import('../src/admin/status/HilosStatusPage.js')),
    ...(await import('../src/admin/status/HilosStatusComponentsPage.js')),
    ...(await import('../src/admin/status/HilosStatusHistoryPage.js')),
    ...(await import('../src/admin/status/HilosStatusIncidentModal.js')),
    ...(await import('../src/admin/status/HilosStatusSubscribersPage.js')),
    ...(await import('../src/admin/status/HilosStatusRemoveSubscriberModal.js')),
    ...(await import('../src/admin/status/HilosStatusWorksPage.js')),
    ...(await import('../src/admin/status/HilosStatusWorkModal.js')),
    ...(await import('../src/admin/status/HilosStatusCancelWorkModal.js')),
  }
  expect(Object.keys(status).sort()).toEqual([
    'HilosStatusCancelWorkModal',
    'HilosStatusComponentsPage',
    'HilosStatusHistoryPage',
    'HilosStatusIncidentModal',
    'HilosStatusPage',
    'HilosStatusRemoveSubscriberModal',
    'HilosStatusSubscribersPage',
    'HilosStatusWorkModal',
    'HilosStatusWorksPage',
  ])
  for (const name of [
    'HilosStatusPage',
    'HilosStatusComponentsPage',
    'HilosStatusHistoryPage',
    'HilosStatusIncidentModal',
    'HilosStatusSubscribersPage',
    'HilosStatusRemoveSubscriberModal',
    'HilosStatusWorksPage',
    'HilosStatusWorkModal',
    'HilosStatusCancelWorkModal',
  ] as const) {
    expect(status[name]).toBeTypeOf('function')
    expect(status[name]).toBe(owners[name])
    expect(base).not.toHaveProperty(name)
  }
  for (const page of [
    HilosPages.STATUS,
    HilosPages.STATUS_COMPONENTS,
    HilosPages.STATUS_SUBSCRIBERS,
    HilosPages.STATUS_WORKS,
    HilosPages.STATUS_HISTORY,
  ])
    expect(hilosAdminViews()).not.toHaveProperty(page)
})

it('exports the public Status alias from its own section without changing the admin name', async () => {
  const status = await import('@hilos/angular/status')
  const admin = await import('@hilos/angular/admin/status')
  expect(Object.keys(status)).toEqual(['HilosStatusPage'])
  const owner = await import('../src/public/HilosStatusPage.js')
  expect(status.HilosStatusPage).toBe(owner.HilosStatusPage)
  expect(status.HilosStatusPage).not.toBe(admin.HilosStatusPage)
  expect(base).not.toHaveProperty('HilosPublicStatusPage')
  expect(hilosAdminViews()).not.toHaveProperty(HilosPages.PUBLIC_STATUS)
})
