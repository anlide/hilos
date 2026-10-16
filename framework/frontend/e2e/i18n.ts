import type { Expect, Locator, Page } from '@playwright/test'

import { shownByTestId } from './table.js'

// The rows and checks of the languages and countries section that all three kits
// perform alike, because the section's data-id controls match across the SDKs.
// The tests themselves — their order, their steps and their navigation — stay in
// each demo's spec; a check here takes the spec's `expect` first and reads the
// page it is handed, never opening one.

/**
 * Find the visible ID-keyed names row through its stable editing control.
 *
 * @param page The page carrying the names table.
 * @param subject Which names table owns the control.
 * @param languageCode The writing language's displayed code.
 */
export function i18nNameRow(
  page: Page,
  subject: 'language' | 'country',
  languageCode: string,
): Locator {
  return shownByTestId(page, /^hilos-table-(row|card)-[0-9]+$/).filter({
    has: page.getByTestId(
      new RegExp(`^i18n-${subject}-name-(add|edit)-${languageCode}$`),
    ),
  })
}

/**
 * Find the visible parent-ID pair through its stable locale control.
 *
 * @param page The page carrying the locales table.
 * @param localeCode The displayed pair code, including a pair with no locale.
 */
export function i18nLocaleRow(page: Page, localeCode: string): Locator {
  return shownByTestId(
    page,
    /^hilos-table-(row|card)-[0-9]+:(null|[0-9]+)$/,
  ).filter({
    has: page.getByTestId(
      new RegExp(`^i18n-locales-(add|edit|view)-${localeCode}$`),
    ),
  })
}

/**
 * The shared card header of the default language, as its card, its names and its
 * locales page all draw it: the code tile, the native name, the default mark and
 * the counts line. The stand's default language is English from the environment.
 *
 * @param expect The spec's expect.
 * @param page A page on the default language's card, names or locales.
 */
export async function expectDefaultLanguageHeader(
  expect: Expect,
  page: Page,
): Promise<void> {
  await expect(page.getByTestId('language-card-code-tile')).toHaveText('en')
  await expect(page.getByTestId('language-card-native-name')).toHaveText(
    'English',
  )
  await expect(page.getByTestId('language-card-default')).toHaveText(
    'Default language',
  )
  await expect(page.getByTestId('language-card-counts')).toHaveText(
    /Locales: \d+ · Names: \d+/,
  )
}

/**
 * The names table of a language on a stand that knows no other language: a
 * language has no row of its own in its names table, so the table stands empty
 * and says why. The spec opens the page itself; this reads what it shows.
 *
 * @param expect The spec's expect.
 * @param page A page on a language's names table.
 */
export async function expectNoOtherLanguageNames(
  expect: Expect,
  page: Page,
): Promise<void> {
  await expect(
    page.locator('table').getByTestId('hilos-table-empty-title'),
  ).toHaveText('No other languages yet')
}
