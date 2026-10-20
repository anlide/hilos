import type { Expect, Locator, Page } from '@playwright/test'

import type { PageErrorFrameLog } from './pageErrorFrames.js'
import { shownByTestId } from './table.js'

// The country card's page key and the server's 404 catalog. This folder does
// not import the core, so the words are copied. A live deletion adds the line
// on the frame; a later subscribe omits the key. The screen does not draw it.
const COUNTRY_CARD_PAGE = 'hilos_i18n_country'
const COUNTRY_NOT_FOUND_TITLE = 'Page Not Found'
const COUNTRY_NOT_FOUND_MESSAGE = 'The page you are looking for does not exist.'
const COUNTRY_DELETED_LINE = 'It was deleted just now.'

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
 * Read a committed base or correction in place, without Apply or navigation.
 *
 * @param expect The spec's expect.
 * @param page The page carrying the names table.
 * @param subject Which names table owns the control.
 * @param key The writing language or locale code.
 * @param value The committed name.
 */
export async function expectI18nName(
  expect: Expect,
  page: Page,
  subject: 'language' | 'country',
  key: string,
  value: string,
): Promise<void> {
  const correction = key.includes('-')
  const surface = correction
    ? shownByTestId(page, `i18n-names-correction-${key}`)
    : i18nNameRow(page, subject, key)
  await expect(surface).toContainText(value)
  await expect(
    shownByTestId(
      page,
      `i18n-${subject}-name-${correction ? 'locale-' : ''}edit-${key}`,
    ),
  ).toBeVisible()
  await expect(page.getByTestId('hilos-table-apply')).toHaveCount(0)
}

/**
 * Arm after both subscriptions are ready, then check each saved value arrived on
 * the existing documents and sockets. Child-frame navigation is irrelevant.
 *
 * @param expect The spec's expect.
 * @param pages The subscribed pages, before the first action under test.
 */
export async function watchI18nLivePages(
  expect: Expect,
  pages: readonly Page[],
): Promise<{ unchanged(): Promise<void> }> {
  for (const page of pages)
    await expect(page.getByTestId('conn-state')).toHaveText('connected')
  const observations = pages.map((page) => {
    const observation = { page, navigations: 0, sockets: 0 }
    page.on('framenavigated', (frame) => {
      if (frame === page.mainFrame()) observation.navigations += 1
    })
    page.on('websocket', () => {
      observation.sockets += 1
    })
    return observation
  })
  return {
    async unchanged() {
      for (const { page } of observations)
        await expect(page.getByTestId('conn-state')).toHaveText('connected')
      for (const observation of observations) {
        const url = observation.page.url()
        expect(
          observation.navigations,
          `No main-frame navigation on ${url}`,
        ).toBe(0)
        expect(observation.sockets, `No new WebSocket on ${url}`).toBe(0)
      }
    },
  }
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

/** The country-card 404 fields, or undefined when this frame is another refusal. */
function countryNotFound(
  frame: PageErrorFrameLog,
): Record<string, unknown> | undefined {
  const data = frame.data
  if (data === null || typeof data !== 'object' || Array.isArray(data)) {
    return undefined
  }
  const fields = data as Record<string, unknown>
  if (
    frame.type !== 'subscription_page_error' ||
    fields.page !== COUNTRY_CARD_PAGE ||
    fields.httpCode !== 404 ||
    fields.errorCode !== 'not_found' ||
    fields.title !== COUNTRY_NOT_FOUND_TITLE ||
    fields.message !== COUNTRY_NOT_FOUND_MESSAGE
  ) {
    return undefined
  }
  return fields
}

/** The server 404 on the same country address. The deletion line is not drawn. */
async function expectCountryNotFoundScreen(
  expect: Expect,
  page: Page,
  countryCode: string,
): Promise<void> {
  await expect(page).toHaveURL(
    new RegExp(`/hilos/i18n/countries/${countryCode}$`),
  )
  await expect(page.getByTestId('hilos-page-state')).toHaveAttribute(
    'data-state',
    'error',
  )
  const error = page.getByTestId('page-error')
  await expect(error).toBeVisible()
  await expect(error).toHaveAttribute('data-error-code', '404')
  await expect(error).toContainText(COUNTRY_NOT_FOUND_TITLE)
  await expect(error).toContainText(COUNTRY_NOT_FOUND_MESSAGE)
  await expect(page.getByTestId('country-card-unavailable')).toHaveCount(0)
}

/**
 * A second window whose country was just deleted: the address stays, the
 * screen is the server 404, and the live frame carries the deletion line.
 *
 * @param expect The spec's expect.
 * @param page The window that was open on the country card.
 * @param frames Frames observed on that window since before it opened.
 * @param countryCode The deleted country's code, still in the address.
 */
export async function expectDeletedCountryCard(
  expect: Expect,
  page: Page,
  frames: readonly PageErrorFrameLog[],
  countryCode: string,
): Promise<void> {
  await expectCountryNotFoundScreen(expect, page, countryCode)
  await expect
    .poll(() =>
      frames.some((frame) => {
        const fields = countryNotFound(frame)
        return fields?.transitionMessage === COUNTRY_DELETED_LINE
      }),
    )
    .toBe(true)
}

/**
 * The same 404 after a reload. The new frame omits the deletion-line key.
 *
 * @param expect The spec's expect.
 * @param page The window reloaded on the deleted country's address.
 * @param frames Frames observed on that window, including the reload.
 * @param countryCode The deleted country's code, still in the address.
 * @param loggedBeforeReload How many frames the log held before the reload.
 */
export async function expectDeletedCountryCardReloaded(
  expect: Expect,
  page: Page,
  frames: readonly PageErrorFrameLog[],
  countryCode: string,
  loggedBeforeReload: number,
): Promise<void> {
  await expectCountryNotFoundScreen(expect, page, countryCode)
  await expect
    .poll(() =>
      frames.slice(loggedBeforeReload).some((frame) => {
        const fields = countryNotFound(frame)
        return (
          fields !== undefined && !Object.hasOwn(fields, 'transitionMessage')
        )
      }),
    )
    .toBe(true)
}
