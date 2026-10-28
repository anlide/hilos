import type { Expect, Locator, Page } from '@playwright/test'

import { deliveryObject, type DeliveryFrameLog } from './deliveryFrames.js'
import { clickSubmit } from './input.js'
import type { PageErrorFrameLog } from './pageErrorFrames.js'
import { shownByTestId } from './table.js'
import { dismissToasts } from './toasts.js'

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

/** Read country card/list update payloads from the existing delivery observer. */
export function i18nCountryUpdates(
  log: DeliveryFrameLog,
): Record<string, unknown>[] {
  return log.sent.flatMap(({ wire }) => {
    const frame = deliveryObject(wire)
    return frame.type === 'action' &&
      (frame.action === 'hilos_i18n_country_update' ||
        frame.action === 'hilos_i18n_country_row_update')
      ? [deliveryObject(frame.data)]
      : []
  })
}

/** The seven choices carried by every locale window, including an empty Add. */
export const I18N_LOCALE_FIELDS = [
  'date',
  'time',
  'number',
  'phone',
  'address',
  'measurement',
  'collation',
] as const

/** Read all saved or draft choices through the real window controls. */
export async function expectI18nLocaleFormats(
  expect: Expect,
  page: Page,
  formats: Readonly<Record<(typeof I18N_LOCALE_FIELDS)[number], string>>,
): Promise<void> {
  for (const field of I18N_LOCALE_FIELDS)
    await expect(page.getByTestId(`i18n-locale-field-${field}`)).toHaveValue(
      formats[field],
    )
}

/** Select every format through its native binding, including the sole collation. */
export async function selectI18nLocaleFormats(
  page: Page,
  formats: Readonly<Record<(typeof I18N_LOCALE_FIELDS)[number], string>>,
): Promise<void> {
  for (const field of I18N_LOCALE_FIELDS)
    await page
      .getByTestId(`i18n-locale-field-${field}`)
      .selectOption(formats[field])
}

/** Read exact language Overview counters, including zero and countryless state. */
export async function expectI18nLanguageCounts(
  expect: Expect,
  page: Page,
  counts: {
    names: number
    locales: number
    enabled: number
    countryless: string
  },
): Promise<void> {
  for (const [field, value] of [
    ['name-count', counts.names],
    ['locale-count', counts.locales],
    ['enabled-locale-count', counts.enabled],
    ['countryless-state', counts.countryless],
  ] as const)
    await expect(page.getByTestId(`language-overview-${field}`)).toHaveText(
      String(value),
    )
}

/**
 * After UI settlement, prove one real request and its own server acknowledgement.
 * A new document may reuse request IDs, so correlate within its socket and time.
 * Return the sent payload for the scenario's exact data assertions.
 */
export function expectI18nActionReply(
  expect: Expect,
  log: DeliveryFrameLog,
  after: number,
  action: string,
  outcome: { message: string } | { reason: string },
): Record<string, unknown> {
  const requests = log.sent.slice(after).filter(({ wire }) => {
    const request = deliveryObject(wire)
    return request.type === 'action' && request.action === action
  })
  expect(requests).toHaveLength(1)
  const sent = requests[0]!
  const request = deliveryObject(sent.wire)
  expect(typeof request.requestId).toBe('string')
  const replies = log.packets
    .filter((packet) => packet.socket === sent.socket && packet.at >= sent.at)
    .flatMap(({ frames }) => frames.map(({ wire }) => deliveryObject(wire)))
    .filter(
      (wire) =>
        wire.requestId === request.requestId &&
        deliveryObject(wire.data).action === action,
    )
  expect(replies).toHaveLength(1)
  const refusal = 'reason' in outcome
  expect(replies[0]!.type).toBe(refusal ? 'action_error' : 'action_success')
  expect(replies[0]!.outcome).toBe(refusal ? 'fail' : 'success')
  expect(deliveryObject(replies[0]!.data)).toMatchObject(outcome)
  return deliveryObject(request.data)
}

/**
 * Read the button and its own visible reason in one DOM turn. A live table
 * window can replace both nodes between separate locator attribute reads.
 *
 * @param expect The spec's expect.
 * @param page The page carrying the countries table.
 * @param countryCode The country whose deletion is blocked.
 */
export async function expectCountryDeleteDescription(
  expect: Expect,
  page: Page,
  countryCode: string,
): Promise<void> {
  const button = shownByTestId(page, `i18n-countries-delete-${countryCode}`)
  await expect
    .poll(() =>
      button.evaluate((control, code) => {
        const reasons = control.parentElement?.querySelectorAll(
          `[data-id="i18n-countries-delete-reason-${code}"]`,
        )
        const reason = reasons?.length === 1 ? reasons[0] : undefined
        const id = reason?.id
        const targets = [
          ...control.ownerDocument.querySelectorAll('[id]'),
        ].filter((node) => node.id === id)
        return {
          linked: !!id && control.getAttribute('aria-describedby') === id,
          unique: targets.length === 1 && targets[0] === reason,
          visible:
            reason?.checkVisibility({ visibilityProperty: true }) ?? false,
          accessible:
            !!reason && !reason.closest('[aria-hidden="true"], [inert]'),
        }
      }, countryCode),
    )
    .toEqual({ linked: true, unique: true, visible: true, accessible: true })
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

/** Open a base/correction control and await the modal's named input and focus. */
export async function openI18nName(
  expect: Expect,
  page: Page,
  subject: 'language' | 'country',
  control: string,
): Promise<void> {
  await dismissToasts(page)
  await clickSubmit(
    expect,
    shownByTestId(page, `i18n-${subject}-name-${control}`),
  )
  await expect(page.getByTestId(`i18n-${subject}-name-window`)).toBeVisible()
  const input = page.getByTestId(`i18n-${subject}-name-input`)
  await expect(input).toBeFocused()
  await expect(input).toHaveAccessibleName('Name')
  // Vue renders a supplementary note; React and Angular name the hint alone.
  const note = page.getByTestId('i18n-language-name-note')
  const noteCount = subject === 'language' ? await note.count() : 0
  expect(noteCount).toBeLessThanOrEqual(1)
  if (noteCount === 1) await expect(note).toBeVisible()
  await expect(input).toHaveAttribute(
    'aria-describedby',
    subject === 'language'
      ? noteCount === 1
        ? 'i18n-language-name-hint i18n-language-name-note'
        : 'i18n-language-name-hint'
      : 'i18n-country-name-hint',
  )
}

/** Save once, settle the own reply, and return the actual correlated payload. */
export async function saveI18nName(
  expect: Expect,
  page: Page,
  subject: 'language' | 'country',
  log: DeliveryFrameLog,
  operation: 'add' | 'update',
  message: string,
  keyboard = false,
): Promise<Record<string, unknown>> {
  const after = log.sent.length
  const submit = page.getByTestId(`i18n-${subject}-name-submit`)
  if (keyboard) {
    await expect(submit).toBeEnabled()
    await page.getByTestId(`i18n-${subject}-name-input`).press('Enter')
  } else await clickSubmit(expect, submit)
  await expect(page.getByTestId(`i18n-${subject}-name-window`)).toHaveCount(0)
  return expectI18nActionReply(
    expect,
    log,
    after,
    `hilos_i18n_${subject}_name_${operation}`,
    { message },
  )
}

/** Cancel once and wait for either closure or a rendered discard confirmation. */
export async function cancelI18nEditor(
  expect: Expect,
  page: Page,
  windowId: string,
  cancelId: string,
): Promise<void> {
  const editor = page.getByTestId(windowId)
  const discard = page.getByTestId('modal-confirm-discard')
  await clickSubmit(expect, page.getByTestId(cancelId))
  await expect
    .poll(
      async () => (await editor.count()) === 0 || (await discard.isVisible()),
    )
    .toBe(true)
  if (await discard.isVisible()) await clickSubmit(expect, discard)
  await expect(discard).toHaveCount(0)
  await expect(editor).toHaveCount(0)
}

/** Cancel a name draft and settle its optional dirty-discard confirmation. */
export async function cancelI18nName(
  expect: Expect,
  page: Page,
  subject: 'language' | 'country',
): Promise<void> {
  await cancelI18nEditor(
    expect,
    page,
    `i18n-${subject}-name-window`,
    `i18n-${subject}-name-cancel`,
  )
}

/** Expand a writing language once through its native correction chevron. */
export async function expandI18nNameCorrections(
  expect: Expect,
  page: Page,
  subject: 'language' | 'country',
  languageCode: string,
): Promise<void> {
  const expand = i18nNameRow(page, subject, languageCode).getByTestId(
    /^hilos-table-expand-/,
  )
  await clickSubmit(expect, expand)
  await expect(expand).toHaveAttribute('aria-expanded', 'true')
}

/** Confirm one base/correction deletion and prove its own successful reply. */
export async function confirmI18nNameDelete(
  expect: Expect,
  page: Page,
  subject: 'language' | 'country',
  log: DeliveryFrameLog,
  correction: boolean,
  message: string,
): Promise<Record<string, unknown>> {
  const after = log.sent.length
  await clickSubmit(
    expect,
    page.getByTestId(`i18n-${subject}-name-delete-confirm`),
  )
  await expect(
    page.getByTestId(`i18n-${subject}-name-delete-window`),
  ).toHaveCount(0)
  return expectI18nActionReply(
    expect,
    log,
    after,
    `hilos_i18n_${subject}_name_${correction ? 'override_delete' : 'delete'}`,
    { message },
  )
}

/**
 * The section keeps one owner head and three native page links on every tab.
 * Entity cards keep their own heads and never enter this row.
 *
 * @param expect The spec's expect.
 * @param page A page on the section's Overview, Languages or Countries tab.
 * @param section The current page key, application name and row's accessible name.
 */
export async function expectI18nPageHead(
  expect: Expect,
  page: Page,
  section: { currentPage: string; appName: string; tabsLabel?: string },
): Promise<void> {
  await expect(page.getByTestId('hilos-page-head')).toBeVisible()
  await expect(page.getByTestId('hilos-page-head-skeleton')).toHaveCount(0)
  const heading = page.getByRole('heading', { level: 1 })
  await expect(heading).toHaveCount(1)
  await expect(heading).toHaveAttribute('data-id', 'hilos-page-head-title')
  await expect(heading).toHaveText('Internationalization')
  await expect(page.getByTestId('hilos-page-head-block')).toContainText(
    'Languages, countries, locales, and translation screens.',
  )
  await expect(page).toHaveTitle(`Internationalization · ${section.appName}`)

  const crumbs = page.getByTestId('hilos-breadcrumb')
  await expect(crumbs).toHaveAccessibleName('Breadcrumb')
  await expect(crumbs.getByRole('link')).toHaveText(['Hilos'])
  await expect(crumbs.getByTestId('hilos-breadcrumb-hilos')).toHaveAttribute(
    'href',
    '/hilos',
  )
  const currentCrumb = crumbs.locator('[aria-current="page"]')
  await expect(currentCrumb).toHaveCount(1)
  await expect(currentCrumb).toContainText('Internationalization')
  await expect(currentCrumb.locator('a')).toHaveCount(0)

  const tabs = page.getByTestId('hilos-page-tabs')
  await expect(tabs).toHaveAccessibleName(section.tabsLabel ?? 'Page sections')
  await expect(tabs.getByRole('link')).toHaveText([
    'Overview',
    'Languages',
    'Countries',
  ])
  await expect(tabs).toHaveRole('navigation')
  await expect(page.getByRole('tablist')).toHaveCount(0)
  for (const [key, path] of [
    ['hilos_i18n', ''],
    ['hilos_i18n_languages', '/languages'],
    ['hilos_i18n_countries', '/countries'],
  ]) {
    const tab = tabs.getByTestId(`hilos-page-tab-${key}`)
    await expect(tab).toHaveAttribute('href', `/hilos/i18n${path}`)
    if (key === section.currentPage) {
      await expect(tab).toHaveAttribute('aria-current', 'page')
      await expect(tab).toHaveClass(/\bactive\b/)
    } else {
      await expect(tab).not.toHaveAttribute('aria-current')
      await expect(tab).not.toHaveClass(/\bactive\b/)
    }
  }
  await expect(tabs.locator('[aria-current]')).toHaveCount(1)
  await expect(page.getByTestId('hilos-admin-children')).toHaveCount(0)
  await expect(page.getByTestId(/^hilos-admin-child-/)).toHaveCount(0)
}

/**
 * Read the built-in United States card and prove keyboard activation cannot
 * open a deletion window for a row the catalog would restore.
 *
 * @param expect The spec's expect.
 * @param page The country Overview page.
 * @param deletes The number of country delete requests sent by the browser.
 */
export async function expectBuiltInCountryCard(
  expect: Expect,
  page: Page,
  deletes: () => number,
): Promise<void> {
  await expect(page.getByTestId('country-card-code')).toHaveText('us')
  await expect(page.getByTestId('country-card-currency')).toHaveText('$ USD')
  await expect(page.getByTestId('country-card-default-locale')).toHaveText(
    'not chosen',
  )
  await expect(page.getByTestId('country-card-enabled')).toHaveText('Disabled')
  await expect(page.getByTestId('country-card-frozen')).toHaveCount(0)
  await expect(page.getByTestId('country-card-edit')).toBeVisible()
  await expect(page.getByTestId('country-card-switch-on')).toBeVisible()
  const remove = page.getByTestId('country-card-delete')
  const reason =
    'Cannot delete: the built-in catalog knows this country and the reflow would bring it back; switch it off instead'
  await expect(remove).toHaveAccessibleName('Delete country')
  await expect(remove).toHaveAttribute('aria-disabled', 'true')
  await expect(remove).toHaveAttribute(
    'aria-describedby',
    'country-card-delete-reason',
  )
  await expect(remove).toHaveAccessibleDescription(reason)
  await remove.focus()
  await remove.press('Enter')
  await remove.press('Space')
  await expect(page.getByTestId('i18n-country-delete-window')).toHaveCount(0)
  expect(deletes()).toBe(0)
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
 * The adopted language owner's single heading, live breadcrumb and browser title,
 * with code-based addresses and the catalog's ordered Overview, Names, Locales row.
 * @param expect The spec's expect.
 * @param page A page on any of the language's three tabs.
 * @param language The code, permitted native name, active page key and app name.
 */
export async function expectLanguagePageHead(
  expect: Expect,
  page: Page,
  language: {
    code: string
    nativeName: string
    currentPage: string
    appName: string
  },
): Promise<void> {
  await expect(page.getByTestId('hilos-page-head')).toBeVisible()
  await expect(page.getByTestId('hilos-page-head-skeleton')).toHaveCount(0)
  const heading = page.getByRole('heading', { level: 1 })
  await expect(heading).toHaveCount(1)
  await expect(heading).toHaveAttribute('data-id', 'hilos-page-head-title')
  await expect(heading).toHaveText(language.nativeName)
  await expect(page).toHaveTitle(`${language.nativeName} · ${language.appName}`)
  const crumbs = page.getByTestId('hilos-breadcrumb')
  await expect(crumbs).toHaveAccessibleName('Breadcrumb')
  await expect(crumbs.getByRole('link')).toHaveText([
    'Hilos',
    'Internationalization',
    'Languages',
  ])
  for (const [key, href] of [
    ['hilos', '/hilos'],
    ['hilos_i18n', '/hilos/i18n'],
    ['hilos_i18n_languages', '/hilos/i18n/languages'],
  ])
    await expect(crumbs.getByTestId(`hilos-breadcrumb-${key}`)).toHaveAttribute(
      'href',
      href,
    )
  const lastCrumb = crumbs.locator('[aria-current]')
  await expect(lastCrumb).toHaveCount(1)
  await expect(lastCrumb).toHaveAttribute('aria-current', 'page')
  await expect(lastCrumb.locator('a')).toHaveCount(0)
  await expect
    .poll(() =>
      lastCrumb.evaluate((crumb) => {
        const words = crumb.cloneNode(true) as Element
        for (const hidden of words.querySelectorAll('[aria-hidden="true"]'))
          hidden.remove()
        return words.textContent?.trim()
      }),
    )
    .toBe(language.nativeName)
  await expect(lastCrumb.locator('xpath=following-sibling::*')).toHaveCount(0)
  const tabs = page.getByTestId('hilos-page-tabs')
  await expect(tabs).toHaveRole('navigation')
  await expect(tabs).toHaveAccessibleName('Language pages')
  await expect(tabs.getByRole('link')).toHaveText([
    'Overview',
    'Names',
    'Locales',
  ])
  await expect(page.getByRole('tablist')).toHaveCount(0)
  const base = `/hilos/i18n/languages/${language.code}`
  for (const [key, suffix] of [
    ['hilos_i18n_language', ''],
    ['hilos_i18n_language_names', '/names'],
    ['hilos_i18n_language_locales', '/locales'],
  ]) {
    const tab = tabs.getByTestId(`hilos-page-tab-${key}`)
    await expect(tab).toHaveAttribute('href', `${base}${suffix}`)
    if (key === language.currentPage) {
      await expect(tab).toHaveAttribute('aria-current', 'page')
      await expect(tab).toHaveClass(/\bactive\b/)
    } else {
      await expect(tab).not.toHaveAttribute('aria-current')
      await expect(tab).not.toHaveClass(/\bactive\b/)
    }
  }
  await expect(tabs.locator('[aria-current]')).toHaveCount(1)
  await expect(page.getByTestId('hilos-admin-children')).toHaveCount(0)
  await expect(page.getByTestId(/^hilos-admin-child-/)).toHaveCount(0)
  for (const id of [
    'language-card-header',
    'language-card-tabs',
    'language-card-counts',
  ])
    await expect(page.getByTestId(id)).toHaveCount(0)
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
 * The adopted shared head of the default language: its code tile, one shared
 * heading and default mark. Full breadcrumbs, tabs and title are checked by
 * `expectLanguagePageHead`; the former per-page header and its counts are gone.
 * The stand's default language is English from the environment.
 *
 * @param expect The spec's expect.
 * @param page A page on the default language's Overview, Names or Locales tab.
 */
export async function expectDefaultLanguageHeader(
  expect: Expect,
  page: Page,
): Promise<void> {
  await expect(page.getByTestId('language-head-tile')).toHaveText('en')
  const heading = page.getByRole('heading', { level: 1 })
  await expect(heading).toHaveCount(1)
  await expect(heading).toHaveAttribute('data-id', 'hilos-page-head-title')
  await expect(heading).toHaveText('English')
  await expect(page.getByTestId('language-head-default')).toContainText(
    'Default language',
  )
  for (const id of [
    'language-card-header',
    'language-card-tabs',
    'language-card-counts',
  ]) {
    await expect(page.getByTestId(id)).toHaveCount(0)
  }
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
