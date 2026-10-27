import { typeInto } from './input.js'
import type { Expect, Locator, Page } from '@playwright/test'

import { deliveryObject } from './deliveryFrames.js'
import type { SocketFrame } from './socket.js'

/** Drive a time-zone control through its actionable states. */
async function clickControl(expect: Expect, control: Locator): Promise<void> {
  await control.scrollIntoViewIfNeeded()
  await expect(control).toBeVisible()
  await expect(control).toBeEnabled()
  await control.focus()
  await control.click()
}

/** Open the profile's live picker and wait for its initial search focus. */
export async function openProfileTimeZone(
  expect: Expect,
  page: Page,
): Promise<void> {
  await clickControl(expect, page.getByTestId('profile-time-zone-change'))
  await expect(page.getByTestId('profile-time-zone-modal')).toBeVisible()
  await expect(page.getByTestId('modal')).toHaveAccessibleName('Time zone')
  await expect(page.getByTestId('profile-time-zone-search')).toBeFocused()
}

/** Choose a real row; a refusal must fail before any caller checks saved state. */
export async function chooseProfileTimeZone(
  expect: Expect,
  page: Page,
  timeZone: string | null,
): Promise<void> {
  await openProfileTimeZone(expect, page)
  await clickControl(
    expect,
    page.getByTestId(
      timeZone === null
        ? 'profile-time-zone-automatic'
        : `profile-time-zone-option-${timeZone}`,
    ),
  )
  const modal = page.getByTestId('profile-time-zone-modal')
  const error = modal.getByTestId('hilos-action-error')
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
    await expect(selected).toHaveClass(/\bactive\b/)
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
  // Every available row carries its own 24-hour clock; an unsupported server
  // identifier stays disabled with its explicit unavailable presentation.
  const clocks = await modal
    .getByTestId(/^profile-time-zone-(?:option-|automatic$)/)
    .evaluateAll((rows) =>
      rows.map((row) => ({
        id: row.getAttribute('data-id'),
        time: row.querySelector('.text-nowrap')?.textContent?.trim(),
        unavailable: row.textContent?.includes('Unavailable in this browser'),
        disabled: row.hasAttribute('disabled'),
        live: row.querySelector('[aria-live]') !== null,
      })),
    )
  expect(clocks.length).toBeGreaterThan(0)
  expect(
    clocks.filter(
      (clock) =>
        clock.live ||
        (clock.unavailable
          ? clock.time !== '—' || !clock.disabled
          : !/^(?:[01]\d|2[0-3]):[0-5]\d$/.test(clock.time ?? '') ||
            clock.disabled),
    ),
  ).toEqual([])
  if (!alreadyOpen) {
    await clickControl(expect, page.getByTestId('profile-time-zone-close'))
    await expect(modal).toBeHidden()
    await expect(page.getByTestId('profile-time-zone-change')).toBeFocused()
  }
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
  await expect(modal.getByText('All time zones', { exact: true })).toBeHidden()
  await expect(modal.getByText('Found', { exact: true })).toBeVisible()
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
  await expect(modal.getByText('All time zones', { exact: true })).toBeVisible()
  await expect(modal.getByText('Found', { exact: true })).toBeHidden()
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
