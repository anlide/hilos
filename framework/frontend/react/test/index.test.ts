// Pins the public surface: everything a React consumer may import from
// '@hilos/react' is exported here. Behavior is covered by the module tests.
import { expect, expectTypeOf, it } from 'vitest'
import { HilosPages } from '@hilos/core'
import type {
  ActionLifecycle,
  HilosConnection,
  ReadonlySignal,
  ScopeManager,
} from '@hilos/core'
import type {
  HilosStatusComponentsContext,
  HilosStatusContext,
  HilosStatusIncidentController,
  HilosStatusSubscriberController,
  HilosStatusSubscribersContext,
  HilosStatusWorkCancel,
  HilosStatusWorkCreate,
  HilosStatusWorkEdit,
  HilosStatusWorksContext,
  HilosStatusWorksState,
} from '@hilos/core/admin/status'
import {
  HilosAuthGateContext,
  HilosAuthSurface,
  HilosDropdown,
  HilosLayout,
  HilosLink,
  HilosMagicLinkPage,
  HilosOAuthCallbackPage,
  HilosI18nPage,
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
  HilosAnalyticsPeoplePage,
  HilosAnalyticsBrowserSessionsPage,
  HilosAnalyticsBrowserSessionPage,
  HilosAnalyticsPersonPage,
  HilosAppearancePage,
  hilosAdminViews,
  HilosPageHead,
  HilosPageHeadingIdContext,
  HilosRouterContext,
  HilosSkeleton,
  HilosStatusComponentsPage,
  HilosStatusIncidentModal,
  HilosStatusPage,
  HilosStatusRemoveSubscriberModal,
  HilosStatusSubscribersPage,
  HilosStatusWorksPage,
  HilosStatusWorkModal,
  HilosStatusCancelWorkModal,
  HilosStatusHistoryPage,
  HilosPublicStatusPage,
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
  HilosI18nPageProps,
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
  HilosAnalyticsPeoplePageProps,
  HilosAnalyticsBrowserSessionsPageProps,
  HilosAnalyticsBrowserSessionPageProps,
  HilosAnalyticsPersonPageProps,
  HilosAppearancePageProps,
  HilosPageHeadProps,
  HilosStatusComponentsPageProps,
  HilosStatusIncidentModalProps,
  HilosStatusPageProps,
  HilosStatusRemoveSubscriberModalProps,
  HilosStatusSubscribersPageProps,
  HilosStatusWorksPageProps,
  HilosStatusWorkModalProps,
  HilosStatusCancelWorkModalProps,
  HilosStatusHistoryPageProps,
  HilosTableTextProps,
} from '../src/index.js'
import * as status from '../src/admin/status/index.js'
import * as publicStatus from '../src/status/index.js'
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
  expect(HilosI18nPage).toBeTypeOf('function')
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

it('exports the props of all eight context-bound i18n pages', () => {
  expectTypeOf<
    Parameters<typeof HilosI18nPage>[0]
  >().toEqualTypeOf<HilosI18nPageProps>()
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

it('exports the five Analytics context pages and leaves them out of the default map', () => {
  expect(HilosAnalyticsPage).toBeTypeOf('function')
  expect(HilosAnalyticsPeoplePage).toBeTypeOf('function')
  expect(HilosAnalyticsBrowserSessionsPage).toBeTypeOf('function')
  expect(HilosAnalyticsBrowserSessionPage).toBeTypeOf('function')
  expect(HilosAnalyticsPersonPage).toBeTypeOf('function')
  expect(hilosAdminViews()).not.toHaveProperty(HilosPages.ANALYTICS)
  expect(hilosAdminViews()).not.toHaveProperty(HilosPages.ANALYTICS_PEOPLE)
  expect(hilosAdminViews()).not.toHaveProperty(
    HilosPages.ANALYTICS_BROWSER_SESSIONS,
  )
  expect(hilosAdminViews()).not.toHaveProperty(
    HilosPages.ANALYTICS_BROWSER_SESSION,
  )
  expect(hilosAdminViews()).not.toHaveProperty(HilosPages.ANALYTICS_PERSON)
  expectTypeOf<
    Parameters<typeof HilosAnalyticsPage>[0]
  >().toEqualTypeOf<HilosAnalyticsPageProps>()
  expectTypeOf<
    Parameters<typeof HilosAnalyticsPeoplePage>[0]
  >().toEqualTypeOf<HilosAnalyticsPeoplePageProps>()
  expectTypeOf<
    Parameters<typeof HilosAnalyticsBrowserSessionsPage>[0]
  >().toEqualTypeOf<HilosAnalyticsBrowserSessionsPageProps>()
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

it('keeps admin Status identity and exports the public page under its own root alias', () => {
  expect(Object.keys(publicStatus)).toEqual(['HilosStatusPage'])
  expect(HilosPublicStatusPage).toBe(publicStatus.HilosStatusPage)
  expect(HilosPublicStatusPage).not.toBe(HilosStatusPage)
  expect(HilosStatusPage).toBe(status.HilosStatusPage)
  expect(base).not.toHaveProperty('HilosPublicStatusPage')
  expect(status).not.toHaveProperty('HilosPublicStatusPage')
  expect(hilosAdminViews()).not.toHaveProperty(HilosPages.PUBLIC_STATUS)
  expectTypeOf<
    Parameters<typeof HilosPublicStatusPage>[0]
  >().toEqualTypeOf<publicStatus.HilosStatusPageProps>()
  expectTypeOf<
    publicStatus.HilosStatusPageProps['connection']
  >().toEqualTypeOf<HilosConnection>()
  expectTypeOf<
    publicStatus.HilosStatusPageProps['actions']
  >().toEqualTypeOf<ActionLifecycle>()
  expectTypeOf<publicStatus.HilosStatusPageProps>().not.toEqualTypeOf<HilosStatusPageProps>()
})

it('exports the Status Overview, Components, Subscribers, Scheduled maintenance, History and their windows once, from admin/status the root re-exports', () => {
  expect(status.HilosStatusPage).toBe(HilosStatusPage)
  expect(status.HilosStatusComponentsPage).toBe(HilosStatusComponentsPage)
  expect(status.HilosStatusIncidentModal).toBe(HilosStatusIncidentModal)
  expect(status.HilosStatusSubscribersPage).toBe(HilosStatusSubscribersPage)
  expect(status.HilosStatusRemoveSubscriberModal).toBe(
    HilosStatusRemoveSubscriberModal,
  )
  expect(status.HilosStatusWorksPage).toBe(HilosStatusWorksPage)
  expect(status.HilosStatusWorkModal).toBe(HilosStatusWorkModal)
  expect(status.HilosStatusCancelWorkModal).toBe(HilosStatusCancelWorkModal)
  expect(status.HilosStatusHistoryPage).toBe(HilosStatusHistoryPage)
  expect(base).not.toHaveProperty('HilosStatusPage')
  expect(base).not.toHaveProperty('HilosStatusComponentsPage')
  expect(base).not.toHaveProperty('HilosStatusIncidentModal')
  expect(base).not.toHaveProperty('HilosStatusSubscribersPage')
  expect(base).not.toHaveProperty('HilosStatusRemoveSubscriberModal')
  expect(base).not.toHaveProperty('HilosStatusWorksPage')
  expect(base).not.toHaveProperty('HilosStatusWorkModal')
  expect(base).not.toHaveProperty('HilosStatusCancelWorkModal')
  expect(base).not.toHaveProperty('HilosStatusHistoryPage')
  expect(hilosAdminViews()).not.toHaveProperty(HilosPages.STATUS)
  expect(hilosAdminViews()).not.toHaveProperty(HilosPages.STATUS_COMPONENTS)
  expect(hilosAdminViews()).not.toHaveProperty(HilosPages.STATUS_SUBSCRIBERS)
  expect(hilosAdminViews()).not.toHaveProperty(HilosPages.STATUS_WORKS)
  expect(hilosAdminViews()).not.toHaveProperty(HilosPages.STATUS_HISTORY)
  expectTypeOf<
    Parameters<typeof HilosStatusPage>[0]
  >().toEqualTypeOf<HilosStatusPageProps>()
  expectTypeOf<
    HilosStatusPageProps['context']
  >().toEqualTypeOf<HilosStatusContext>()
  expectTypeOf<status.HilosStatusPageProps>().toEqualTypeOf<HilosStatusPageProps>()
  expectTypeOf<
    Parameters<typeof HilosStatusComponentsPage>[0]
  >().toEqualTypeOf<HilosStatusComponentsPageProps>()
  expectTypeOf<
    HilosStatusComponentsPageProps['context']
  >().toEqualTypeOf<HilosStatusComponentsContext>()
  expectTypeOf<status.HilosStatusComponentsPageProps>().toEqualTypeOf<HilosStatusComponentsPageProps>()
  expectTypeOf<
    Parameters<typeof HilosStatusIncidentModal>[0]
  >().toEqualTypeOf<HilosStatusIncidentModalProps>()
  expectTypeOf<
    HilosStatusIncidentModalProps['controller']
  >().toEqualTypeOf<HilosStatusIncidentController>()
  expectTypeOf<status.HilosStatusIncidentModalProps>().toEqualTypeOf<HilosStatusIncidentModalProps>()
  expectTypeOf<
    Parameters<typeof HilosStatusSubscribersPage>[0]
  >().toEqualTypeOf<HilosStatusSubscribersPageProps>()
  expectTypeOf<
    HilosStatusSubscribersPageProps['context']
  >().toEqualTypeOf<HilosStatusSubscribersContext>()
  expectTypeOf<status.HilosStatusSubscribersPageProps>().toEqualTypeOf<HilosStatusSubscribersPageProps>()
  expectTypeOf<
    Parameters<typeof HilosStatusRemoveSubscriberModal>[0]
  >().toEqualTypeOf<HilosStatusRemoveSubscriberModalProps>()
  expectTypeOf<
    HilosStatusRemoveSubscriberModalProps['controller']
  >().toEqualTypeOf<HilosStatusSubscriberController>()
  expectTypeOf<status.HilosStatusRemoveSubscriberModalProps>().toEqualTypeOf<HilosStatusRemoveSubscriberModalProps>()
  expectTypeOf<
    Parameters<typeof HilosStatusWorksPage>[0]
  >().toEqualTypeOf<HilosStatusWorksPageProps>()
  expectTypeOf<
    HilosStatusWorksPageProps['context']
  >().toEqualTypeOf<HilosStatusWorksContext>()
  expectTypeOf<status.HilosStatusWorksPageProps>().toEqualTypeOf<HilosStatusWorksPageProps>()
  expectTypeOf<
    Parameters<typeof HilosStatusWorkModal>[0]
  >().toEqualTypeOf<HilosStatusWorkModalProps>()
  expectTypeOf<HilosStatusWorkModalProps['controller']>().toEqualTypeOf<
    HilosStatusWorkCreate | HilosStatusWorkEdit
  >()
  expectTypeOf<
    HilosStatusWorkModalProps['state']
  >().toEqualTypeOf<HilosStatusWorksState>()
  expectTypeOf<status.HilosStatusWorkModalProps>().toEqualTypeOf<HilosStatusWorkModalProps>()
  expectTypeOf<
    Parameters<typeof HilosStatusCancelWorkModal>[0]
  >().toEqualTypeOf<HilosStatusCancelWorkModalProps>()
  expectTypeOf<
    HilosStatusCancelWorkModalProps['controller']
  >().toEqualTypeOf<HilosStatusWorkCancel>()
  expectTypeOf<status.HilosStatusCancelWorkModalProps>().toEqualTypeOf<HilosStatusCancelWorkModalProps>()
  expectTypeOf<
    Parameters<typeof HilosStatusHistoryPage>[0]
  >().toEqualTypeOf<HilosStatusHistoryPageProps>()
  expectTypeOf<
    HilosStatusHistoryPageProps['context']
  >().toEqualTypeOf<HilosStatusContext>()
  expectTypeOf<status.HilosStatusHistoryPageProps>().toEqualTypeOf<HilosStatusHistoryPageProps>()
})

it('exports the shared page head once, from the base entry the root re-exports', () => {
  expect(base.HilosPageHead).toBe(HilosPageHead)
  expectTypeOf<
    Parameters<typeof HilosPageHead>[0]
  >().toEqualTypeOf<HilosPageHeadProps>()
  expectTypeOf<base.HilosPageHeadProps>().toEqualTypeOf<HilosPageHeadProps>()
})

it('exports the date-time picker from the base entry', () => {
  expect(base.HilosDateTimePicker).toBeTypeOf('function')
  expectTypeOf<
    Parameters<typeof base.HilosDateTimePicker>[0]
  >().toEqualTypeOf<base.HilosDateTimePickerProps>()
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
