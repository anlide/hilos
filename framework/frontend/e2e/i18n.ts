import type { Expect, Locator, Page } from '@playwright/test'

import type { PageErrorFrameLog } from './pageErrorFrames.js'
import { shownByTestId } from './table.js'

// The countries section's page key and address, the country tabs' page keys
// and the server's 404 catalog. This folder does not import the core, so the
// words are copied. A live deletion adds the line on the frame and the screen
// draws it under the 404; a later subscribe omits the key, and the screen
// draws no line.
const COUNTRIES_PAGE = 'hilos_i18n_countries'
const COUNTRIES_PATH = '/hilos/i18n/countries'
const COUNTRY_CARD_PAGE = 'hilos_i18n_country'
const COUNTRY_NAMES_PAGE = 'hilos_i18n_country_names'
const COUNTRY_NOT_FOUND_TITLE = 'Page Not Found'
const COUNTRY_NOT_FOUND_MESSAGE = 'The page you are looking for does not exist.'
const COUNTRY_DELETED_LINE = 'It was deleted just now.'

/**
 * Each country tab as its 404 names it: the page key on the frame, the address
 * after the country's code, and the tab's own words for a body it cannot draw.
 */
const COUNTRY_TABS = {
  overview: {
    page: COUNTRY_CARD_PAGE,
    path: '',
    unavailable: 'country-card-unavailable',
  },
  names: {
    page: COUNTRY_NAMES_PAGE,
    path: '/names',
    unavailable: 'country-names-unavailable',
  },
} as const

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
 * The shared head of a country on either of its tabs: the one h1, the last
 * crumb and the browser tab carry the shown name; the trail is the named
 * Breadcrumb, its Countries link keeps the section's address and its last
 * crumb is text rather than a link; the Country pages row reads Overview and
 * Names, the two links keep the code-based addresses whatever the name, only
 * the tab on screen is current; and no child cards stand under the head. The
 * country's own block stays in the spec, with the fixture it describes.
 *
 * @param expect The spec's expect.
 * @param page A page on the country's Overview or Names tab.
 * @param country The country's code, the name the head shows, the application
 *   name the browser tab composes it with, and the page key of the tab on screen.
 */
export async function expectCountryPageHead(
  expect: Expect,
  page: Page,
  country: { code: string; name: string; appName: string; currentPage: string },
): Promise<void> {
  await expect(page.getByTestId('hilos-page-head')).toBeVisible()
  await expect(page.getByTestId('hilos-page-head-skeleton')).toHaveCount(0)
  const heading = page.getByRole('heading', { level: 1 })
  await expect(heading).toHaveCount(1)
  await expect(heading).toHaveAttribute('data-id', 'hilos-page-head-title')
  await expect(heading).toHaveText(country.name)
  await expect(page).toHaveTitle(`${country.name} · ${country.appName}`)

  const crumbs = page.getByTestId('hilos-breadcrumb')
  await expect(crumbs).toHaveAccessibleName('Breadcrumb')
  await expect(
    page.getByTestId(`hilos-breadcrumb-${COUNTRIES_PAGE}`),
  ).toHaveAttribute('href', COUNTRIES_PATH)
  const lastCrumb = crumbs.locator('[aria-current]')
  await expect(lastCrumb).toHaveCount(1)
  await expect(lastCrumb).toHaveAttribute('aria-current', 'page')
  // The page itself is the last crumb: text, not a link.
  await expect(lastCrumb.locator('a')).toHaveCount(0)
  // The crumb's own words: a kit may draw its divider as a decoration hidden
  // from assistive technology inside the crumb, and that is not the name.
  await expect
    .poll(() =>
      lastCrumb.evaluate((crumb) => {
        const words = crumb.cloneNode(true) as Element
        for (const hidden of words.querySelectorAll('[aria-hidden="true"]'))
          hidden.remove()
        return words.textContent?.trim()
      }),
    )
    .toBe(country.name)
  await expect(lastCrumb.locator('xpath=following-sibling::*')).toHaveCount(0)

  const tabs = page.getByTestId('hilos-page-tabs')
  await expect(tabs).toHaveAccessibleName('Country pages')
  await expect(tabs.getByRole('link')).toHaveText(['Overview', 'Names'])
  const overviewPath = `${COUNTRIES_PATH}/${country.code}`
  for (const [key, href] of [
    [COUNTRY_CARD_PAGE, overviewPath],
    [COUNTRY_NAMES_PAGE, `${overviewPath}/names`],
  ] as const) {
    const tab = tabs.getByTestId(`hilos-page-tab-${key}`)
    await expect(tab).toHaveAttribute('href', href)
    if (key === country.currentPage) {
      await expect(tab).toHaveAttribute('aria-current', 'page')
    } else {
      await expect(tab).not.toHaveAttribute('aria-current')
    }
  }
  await expect(tabs.locator('[aria-current]')).toHaveCount(1)

  await expect(page.getByTestId('hilos-admin-children')).toHaveCount(0)
  await expect(page.getByTestId(/^hilos-admin-child-/)).toHaveCount(0)
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

/**
 * The country 404 fields of one tab, or undefined when this frame is another
 * refusal or the refusal of the other tab.
 *
 * @param frame One refusal frame from the log.
 * @param tab The country tab the frame must name.
 */
function countryNotFound(
  frame: PageErrorFrameLog,
  tab: keyof typeof COUNTRY_TABS,
): Record<string, unknown> | undefined {
  const data = frame.data
  if (data === null || typeof data !== 'object' || Array.isArray(data)) {
    return undefined
  }
  const fields = data as Record<string, unknown>
  if (
    frame.type !== 'subscription_page_error' ||
    fields.page !== COUNTRY_TABS[tab].page ||
    fields.httpCode !== 404 ||
    fields.errorCode !== 'not_found' ||
    fields.title !== COUNTRY_NOT_FOUND_TITLE ||
    fields.message !== COUNTRY_NOT_FOUND_MESSAGE
  ) {
    return undefined
  }
  return fields
}

/**
 * The server 404 on the same address of the country's tab, in place of the
 * whole page: no head, crumbs or tabs, and none of the tab's own body. The line
 * under the 404 is drawn only by a live deletion.
 *
 * @param expect The spec's expect.
 * @param page The window on the deleted country's tab.
 * @param countryCode The deleted country's code, still in the address.
 * @param tab The tab the window stands on.
 * @param line Whether the deletion line stands under the 404.
 */
async function expectCountryNotFoundScreen(
  expect: Expect,
  page: Page,
  countryCode: string,
  tab: keyof typeof COUNTRY_TABS,
  line: boolean,
): Promise<void> {
  await expect(page).toHaveURL(
    new RegExp(
      `/hilos/i18n/countries/${countryCode}${COUNTRY_TABS[tab].path}$`,
    ),
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
  const deletion = error.getByTestId('page-error-transition-message')
  if (line) {
    await expect(deletion).toBeVisible()
    await expect(deletion).toHaveText(COUNTRY_DELETED_LINE)
  } else {
    await expect(deletion).toHaveCount(0)
  }
  for (const gone of [
    'hilos-page-head',
    'hilos-breadcrumb',
    'hilos-page-tabs',
    COUNTRY_TABS[tab].unavailable,
  ]) {
    await expect(page.getByTestId(gone)).toHaveCount(0)
  }
}

/**
 * A second window whose country was just deleted: the address of its tab
 * stays, the screen is the server 404 with the deletion line under it, and the
 * live frame of that tab carries the line.
 *
 * @param expect The spec's expect.
 * @param page The window that was open on the country's tab.
 * @param frames Frames observed on that window since before it opened.
 * @param countryCode The deleted country's code, still in the address.
 * @param tab The tab the window was open on; Overview unless named.
 */
export async function expectDeletedCountryCard(
  expect: Expect,
  page: Page,
  frames: readonly PageErrorFrameLog[],
  countryCode: string,
  tab: keyof typeof COUNTRY_TABS = 'overview',
): Promise<void> {
  await expectCountryNotFoundScreen(expect, page, countryCode, tab, true)
  await expect
    .poll(() =>
      frames.some((frame) => {
        const fields = countryNotFound(frame, tab)
        return fields?.transitionMessage === COUNTRY_DELETED_LINE
      }),
    )
    .toBe(true)
}

/**
 * The same 404 after a reload, with no line: the new frame omits its key.
 *
 * @param expect The spec's expect.
 * @param page The window reloaded on the deleted country's tab.
 * @param frames Frames observed on that window, including the reload.
 * @param countryCode The deleted country's code, still in the address.
 * @param loggedBeforeReload How many frames the log held before the reload.
 * @param tab The tab the window was reloaded on; Overview unless named.
 */
export async function expectDeletedCountryCardReloaded(
  expect: Expect,
  page: Page,
  frames: readonly PageErrorFrameLog[],
  countryCode: string,
  loggedBeforeReload: number,
  tab: keyof typeof COUNTRY_TABS = 'overview',
): Promise<void> {
  await expectCountryNotFoundScreen(expect, page, countryCode, tab, false)
  await expect
    .poll(() =>
      frames.slice(loggedBeforeReload).some((frame) => {
        const fields = countryNotFound(frame, tab)
        return (
          fields !== undefined && !Object.hasOwn(fields, 'transitionMessage')
        )
      }),
    )
    .toBe(true)
}
