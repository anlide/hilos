import type { Locator, Page } from '@playwright/test'
import { shownByTestId } from './table.js'

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
