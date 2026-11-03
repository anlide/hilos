import type { BrowserContextOptions, Expect, Page } from '@playwright/test'

import { deliveryObject } from './deliveryFrames.js'
import { clickSubmit, typeInto } from './input.js'
import type { SocketFrame } from './socket.js'

/** The accessible-name text a row that no browser clock can display carries. */
const UNAVAILABLE_ROW_TEXT = 'Unavailable in this browser'

/**
 * Context options for a scenario that opens its own browser context in one
 * explicit time zone, besides the test's own page.
 *
 * Playwright Test fills absent context options from the combined `use` options,
 * including for `browser.newContext` and `browser.newPage`. Explicit keys win,
 * even when their value is undefined. This helper keeps the options the caller
 * hands in from `test.info().project.use` and sets the explicit zone and light
 * theme, the only palette e2e runs in
 * (`docs/agents/frontend/testing-strategy.md`, "Light theme, desktop width").
 * A browser launched outside Playwright Test has no such inherited defaults.
 *
 * The scenario calls `browser.newContext` itself, signs in there and closes
 * the context: two independently signed-in sessions are two people, so no
 * cookies or storage state travel from one context to the other.
 *
 * @param options The project's `use` options to preserve in the explicit options.
 * @param timeZone The IANA zone the browser of that context reports.
 */
export function timeZoneContextOptions(
  options: BrowserContextOptions,
  timeZone: string,
): BrowserContextOptions {
  return { ...options, timezoneId: timeZone, colorScheme: 'light' }
}

/** The two zones an environment check expects, each named by the spec. */
export interface TimeZoneEnvironment {
  /** The zone the browser context was given and must report as its own. */
  readonly browserTimeZone: string
  /** The zone the daemon was started with, as the spec knows it from its stand. */
  readonly projectTimeZone: string
}

/**
 * Prove the two zones a time-zone scenario stands on: the browser reports the
 * one its context was given, and every welcome the daemon sent names the
 * project zone the spec expects. The browser zone differs from the project
 * zone and UTC, comparing canonical identities so aliases do not hide equality.
 *
 * Both expectations come from the spec, never from the evidence itself: the
 * project zone is read back from the original welcome and compared with what the
 * spec knows its stand was started with, not taken from the server's answer and
 * then asserted against it. No welcome is forged or rewritten here; the stand's
 * project zone is set in its environment before the daemon starts. A frame the
 * core decoder failed on is not accepted as evidence either — an undecodable
 * message could have been the welcome, so one failure anywhere in the observed
 * stream fails the check.
 *
 * @param expect The calling spec's Playwright expect.
 * @param page The page whose browser zone is read.
 * @param frames Frames observed with `watchSocketFrames` from before navigation.
 * @param environment The browser and project zones the spec expects.
 */
export async function expectTimeZoneEnvironment(
  expect: Expect,
  page: Page,
  frames: readonly SocketFrame[],
  environment: TimeZoneEnvironment,
): Promise<void> {
  const welcomes = () =>
    frames.filter((frame) => deliveryObject(frame.wire).type === 'handshake')
  await expect.poll(() => welcomes().length).toBeGreaterThan(0)
  expect(frames.filter((frame) => frame.failure !== undefined)).toEqual([])
  for (const frame of welcomes()) {
    expect(
      deliveryObject(deliveryObject(frame.wire).data).projectTimeZone,
    ).toBe(environment.projectTimeZone)
  }
  const browserTimeZone = new Intl.DateTimeFormat('en', {
    timeZone: environment.browserTimeZone,
  }).resolvedOptions().timeZone
  const projectTimeZone = new Intl.DateTimeFormat('en', {
    timeZone: environment.projectTimeZone,
  }).resolvedOptions().timeZone
  expect(browserTimeZone).not.toBe(projectTimeZone)
  expect(browserTimeZone).not.toBe('UTC')
  expect(
    await page.evaluate(() => Intl.DateTimeFormat().resolvedOptions().timeZone),
  ).toBe(environment.browserTimeZone)
}

/** Open the profile's live picker and wait for its initial search focus. */
export async function openProfileTimeZone(
  expect: Expect,
  page: Page,
): Promise<void> {
  await clickSubmit(expect, page.getByTestId('profile-time-zone-change'))
  await expect(page.getByTestId('profile-time-zone-modal')).toBeVisible()
  await expect(page.getByTestId('modal')).toHaveAccessibleName('Time zone')
  await expect(page.getByTestId('profile-time-zone-search')).toBeFocused()
}

/** Close an open picker and wait for focus to return to its opening control. */
async function closeProfileTimeZone(expect: Expect, page: Page): Promise<void> {
  await clickSubmit(expect, page.getByTestId('profile-time-zone-close'))
  await expect(page.getByTestId('profile-time-zone-modal')).toBeHidden()
  await expect(page.getByTestId('profile-time-zone-change')).toBeFocused()
}

/** Choose a real row; a refusal must fail before any caller checks saved state. */
export async function chooseProfileTimeZone(
  expect: Expect,
  page: Page,
  timeZone: string | null,
): Promise<void> {
  await openProfileTimeZone(expect, page)
  await clickSubmit(
    expect,
    page.getByTestId(
      timeZone === null
        ? 'profile-time-zone-automatic'
        : `profile-time-zone-option-${timeZone}`,
    ),
  )
  const modal = page.getByTestId('profile-time-zone-modal')
  const error = modal.getByTestId('hilos-action-error')
  // The write settles either way — the picker closes on success or shows its
  // refusal — before the saved state is read.
  await expect
    .poll(async () => !(await modal.isVisible()) || (await error.isVisible()))
    .toBe(true)
  await expect(error).toBeHidden()
  await expect(modal).toBeHidden()
  await expect(page.getByTestId('profile-time-zone-change')).toBeEnabled()
  await expect(page.getByTestId('profile-time-zone-change')).toBeFocused()
}

/** Check the saved line and live mark without closing or filtering an open picker. */
export async function expectProfileTimeZone(
  expect: Expect,
  page: Page,
  ownTimeZone: string | null,
  browserTimeZone: string,
  selectedVisible = true,
): Promise<void> {
  await expect(page.getByTestId('profile-time-zone')).toHaveText(
    ownTimeZone ?? `${browserTimeZone} · automatic`,
  )
  const modal = page.getByTestId('profile-time-zone-modal')
  const alreadyOpen = await modal.isVisible()
  if (!alreadyOpen) await openProfileTimeZone(expect, page)
  const selected = page.getByTestId(
    ownTimeZone === null
      ? 'profile-time-zone-automatic'
      : `profile-time-zone-option-${ownTimeZone}`,
  )
  await expect(selected).toHaveCount(selectedVisible ? 1 : 0)
  if (selectedVisible) {
    await expect(selected).toHaveAttribute('aria-current', 'true')
  }
  await expect(modal.locator('[aria-current="true"]')).toHaveCount(
    selectedVisible ? 1 : 0,
  )
  const automatic = page.getByTestId('profile-time-zone-automatic')
  if ((await automatic.count()) > 0) {
    await expect(automatic).toContainText(
      `${browserTimeZone} — from this browser`,
    )
    await expect(automatic).toBeEnabled()
  }
  // A zone this browser cannot display stays a disabled row that says so; every
  // other row is a working choice. How the row's clock is formatted is the
  // core's own unit contract, not an e2e outcome. Nothing in a row is a live
  // region: a clock that ticks every minute must not be announced each time.
  const rows = await modal
    .getByTestId(/^profile-time-zone-(?:option-|automatic$)/)
    .evaluateAll(
      (rows, unavailableText) =>
        rows.map((row) => ({
          id: row.getAttribute('data-id'),
          unavailable: row.textContent?.includes(unavailableText) ?? false,
          disabled: row.hasAttribute('disabled'),
          live: row.querySelector('[aria-live]') !== null,
        })),
      UNAVAILABLE_ROW_TEXT,
    )
  expect(rows.length).toBeGreaterThan(0)
  expect(
    rows.filter((row) => row.live || row.unavailable !== row.disabled),
  ).toEqual([])
  if (!alreadyOpen) await closeProfileTimeZone(expect, page)
}

/** Prove city-underscore search, empty results and restoration of the full list. */
export async function expectProfileTimeZoneSearch(
  expect: Expect,
  page: Page,
): Promise<void> {
  const modal = page.getByTestId('profile-time-zone-modal')
  const search = page.getByTestId('profile-time-zone-search')
  await typeInto(search, 'new york')
  await expect(search).toHaveValue('new york')
  await expect(
    page.getByTestId('profile-time-zone-option-America/New_York'),
  ).toBeVisible()
  await expect(modal.getByTestId(/^profile-time-zone-option-/)).toHaveCount(1)
  await expect(page.getByTestId('profile-time-zone-automatic')).toBeHidden()
  await typeInto(search, 'no-such-time-zone')
  await expect(page.getByTestId('profile-time-zone-empty')).toHaveText(
    'No time zones match',
  )
  await expect(modal.getByTestId(/^profile-time-zone-option-/)).toHaveCount(0)
  await expect(page.getByTestId('profile-time-zone-automatic')).toBeHidden()
  await search.fill('')
  await expect(search).toHaveValue('')
  await expect(page.getByTestId('profile-time-zone-empty')).toBeHidden()
  await expect(page.getByTestId('profile-time-zone-automatic')).toBeVisible()
  await expect(page.getByTestId('profile-time-zone-option-UTC')).toBeAttached()
  await expect(
    page.getByTestId('profile-time-zone-option-America/New_York'),
  ).toBeAttached()
}

/** Verify the real profile reply delivers one scalar catalog snapshot. */
export async function expectProfileTimeZoneCatalog(
  expect: Expect,
  frames: readonly SocketFrame[],
): Promise<void> {
  const replies = () =>
    frames.filter((frame) => {
      const wire = deliveryObject(frame.wire)
      return (
        wire.type === 'page_response' &&
        deliveryObject(wire.data).page === 'hilos_profile'
      )
    })
  await expect.poll(() => replies().length).toBe(1)
  const frame = replies()[0]!
  expect(frame.failure).toBeUndefined()
  const data = deliveryObject(deliveryObject(frame.wire).data)
  const payload = deliveryObject(data.payload)
  const catalog = deliveryObject(deliveryObject(payload.lists).profileTimeZones)
  expect(catalog.cleared).toBe(true)
  expect(Array.isArray(catalog.items)).toBe(true)
  const items = catalog.items as unknown[]
  for (const item of items) {
    const row = deliveryObject(item)
    expect(typeof row.itemKey).toBe('string')
    expect(row).toEqual({
      itemKey: row.itemKey,
      slots: { timeZone: row.itemKey },
    })
  }
  for (const timeZone of [
    'UTC',
    'Europe/Warsaw',
    'Asia/Tokyo',
    'America/New_York',
  ]) {
    expect(items).toContainEqual({
      itemKey: timeZone,
      slots: { timeZone },
    })
  }
}
