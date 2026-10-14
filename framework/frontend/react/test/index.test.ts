// Pins the public surface: everything a React consumer may import from
// '@hilos/react' is exported here. Behavior is covered by the module tests.
import { expect, expectTypeOf, it } from 'vitest'
import { HilosPages } from '@hilos/core'
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
  hilosAdminViews,
  HilosPageHeadingIdContext,
  HilosRouterContext,
  HilosSkeleton,
  HilosAvatar,
  HilosSwitch,
  HilosTakeoverViewOnlyContext,
  HilosView,
  useConnectionState,
  useLookOnly,
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
} from '../src/index.js'

it('exports the @hilos/react public surface', () => {
  expect(useConnectionState).toBeTypeOf('function')
  expect(useReconnectDragging).toBeTypeOf('function')
  expect(HilosLink).toBeTypeOf('function')
  expect(HilosView).toBeTypeOf('function')
  expect(HilosSkeleton).toBeTypeOf('function')
  expect(HilosAvatar).toBeTypeOf('function')
  expect(HilosSwitch).toBeTypeOf('function')
  expect(HilosLayout).toBeTypeOf('function')
  expect(HilosDropdown).toBeTypeOf('function')
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
