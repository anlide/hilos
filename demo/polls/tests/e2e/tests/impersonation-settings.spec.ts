// The administrator's impersonation settings (HIL-1170), end to end on polls:
// switching impersonation off takes the takeover off the person's card and
// switching it back on returns it; switching it off also ends a takeover already
// under way (HIL-1308).
//
// The settings are the installation's own, so each test puts back what it moved
// whatever happens.
import type { Page } from '@playwright/test'
import { expect, test as base } from '../test.js'

import {
  cleanupFixtures,
  type TestCleanup,
  watchSocketFrames,
  deliveryObject,
  createTestUser,
  dismissToasts,
  shownByTestId,
  clickSubmit,
  typeInto,
  gotoPage,
  grantAdminToSelf,
} from '../../../../../framework/frontend/e2e/index.js'
import {
  resolveHilosSettingRow,
  type HilosSettingRow,
} from '../../../../../framework/frontend/core/src/admin/settings/hilosSettings.js'
import { HilosPages } from '../../../../../framework/frontend/core/src/routing/hilosPages.js'
import {
  SIGNAL_TYPE_TABLE_WINDOW,
  SIGNAL_TYPE_TABLE_VIEWPORT_DELTA,
} from '../../../../../framework/frontend/core/src/protocol/constants.js'
import { createStandCommandChannel } from '../../../../../framework/frontend/scripts/standCommand.mjs'

/** The settings page this spec drives. */
const SETTINGS_PATH = '/hilos/security/impersonation'

/** The switch that decides whether impersonation exists in the product. */
const ALLOWED_SWITCH = 'hilos-impersonation-switch-auth.impersonation.allowed'

/**
 * Flip the "Impersonation is allowed" switch and wait for the row to move.
 *
 * @param page The administrator's page.
 * @param on The position the switch is to reach.
 */
async function setAllowed(page: Page, on: boolean): Promise<void> {
  await gotoPage(expect, page, SETTINGS_PATH)
  const allowed = shownByTestId(page, ALLOWED_SWITCH)
  if (on) {
    await expect(allowed).not.toBeChecked()
  } else {
    await expect(allowed).toBeChecked()
  }
  await allowed.click()
  if (on) {
    await expect(allowed).toBeChecked()
  } else {
    await expect(allowed).not.toBeChecked()
  }
}

const test = base.extend<{ cleanup: TestCleanup }>({ cleanup: cleanupFixtures })

test('updates takeover availability live and ends the active takeover when disabled', async ({
  page,
  browser,
  cleanup,
}) => {
  const { baseURL, ignoreHTTPSErrors } = test.info().project.use
  const personContext = await browser.newContext({ baseURL, ignoreHTTPSErrors })
  cleanup.defer(() => personContext.close())
  // The administrator ending the takeover needs an independent session.
  const switcherContext = await browser.newContext({
    baseURL,
    ignoreHTTPSErrors,
  })
  cleanup.defer(() => switcherContext.close())
  const switcherPage = await switcherContext.newPage()
  await grantAdminToSelf(expect, page)
  await grantAdminToSelf(expect, switcherPage)
  const person = await createTestUser(expect, await personContext.newPage())
  const settingsPage = await page.context().newPage()
  cleanup.defer(() => settingsPage.close())
  // The reader belongs to the independent admin, so it stays authorized during takeover.
  const settingsReader = await switcherContext.newPage()
  const key = 'auth.impersonation.allowed'
  let setting: HilosSettingRow | undefined
  const decodingFailures: unknown[] = []
  const observer = watchSocketFrames(settingsReader, ({ wire, failure }) => {
    if (failure) decodingFailures.push(failure)
    const envelope = deliveryObject(wire)
    const data = deliveryObject(envelope.data)
    if (data.page !== HilosPages.SETTINGS || data.tableKey !== 'settings')
      return
    const rows =
      envelope.type === SIGNAL_TYPE_TABLE_WINDOW && Array.isArray(data.rows)
        ? data.rows
        : envelope.type === SIGNAL_TYPE_TABLE_VIEWPORT_DELTA
          ? [data.row]
          : []
    for (const raw of rows) {
      const row = deliveryObject(raw)
      if (row.rowKey === key) {
        setting = resolveHilosSettingRow({
          rowKey: key,
          slots: deliveryObject(row.slots),
        })
      }
    }
  })
  cleanup.defer(() => observer.stop())
  await gotoPage(expect, settingsReader, '/hilos/settings')
  await typeInto(settingsReader.getByTestId('hilos-table-search'), key)
  await expect.poll(() => setting).toBeDefined()
  expect(decodingFailures).toEqual([])
  const original = setting!
  const originalOverride = original.overrideValue
  if (originalOverride !== null && typeof originalOverride !== 'string') {
    throw new Error(
      'The administrator must be able to read the setting override',
    )
  }
  const sendCommand = createStandCommandChannel({ timeoutMs: 5000 })
  const restore = async () => {
    if (originalOverride === null) {
      await sendCommand('settings:reset', { key })
    } else {
      await sendCommand('settings:set', { key, value: originalOverride })
    }
    // A fresh window proves both the raw override and its effective source.
    setting = undefined
    await gotoPage(expect, settingsReader, '/hilos/settings')
    await typeInto(settingsReader.getByTestId('hilos-table-search'), key)
    await expect.poll(() => setting).toEqual(original)
    expect(decodingFailures).toEqual([])
  }
  cleanup.defer(restore)
  const card = `/hilos/user/${person.userId}`
  const open = page.getByTestId('hilos-user-impersonate-open')
  let loads = 0
  page.on('load', () => {
    loads += 1
  })

  await test.step('switching impersonation off takes the takeover off the card, and on brings it back', async () => {
    await gotoPage(expect, page, card)
    await expect(open).toBeVisible()
    await expect(open).toBeEnabled()
    const loadsBeforeSettings = loads
    await setAllowed(settingsPage, false)
    await expect(open).toHaveCount(0)
    expect(new URL(page.url()).pathname).toBe(card)
    expect(loads).toBe(loadsBeforeSettings)
    await setAllowed(settingsPage, true)
    await expect(open).toBeVisible()
    await expect(open).toBeEnabled()
    expect(new URL(page.url()).pathname).toBe(card)
    expect(loads).toBe(loadsBeforeSettings)
  })

  await test.step('switching impersonation off returns an administrator from a takeover without a reload', async () => {
    const strip = page.getByTestId('impersonation-banner')
    const gear = page.getByTestId('nav-admin')
    await clickSubmit(expect, open)
    await clickSubmit(
      expect,
      page.getByTestId('hilos-user-impersonate-confirm'),
    )
    await expect(strip).toBeVisible()
    await expect(strip).toContainText(person.name)
    await expect(gear).toHaveCount(0)
    await expect(page.getByTestId('conn-state')).toHaveText('connected')
    await dismissToasts(page)
    const loadsBeforeSwitch = loads
    await setAllowed(switcherPage, false)
    await expect(strip).toBeHidden()
    await expect(page.getByTestId('hilos-toast-info')).toContainText(
      'Impersonation was switched off',
    )
    await expect(gear).toBeVisible()
    expect(loads).toBe(loadsBeforeSwitch)
  })
})
