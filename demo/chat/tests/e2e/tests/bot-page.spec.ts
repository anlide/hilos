import { test, expect, type Page } from '@playwright/test'

import {
  shownByTestId,
  clickSubmit,
  typeInto,
  gotoPage,
  PAGE_READY,
  signUpAdmin,
  tableRowKeyByText,
} from '../../../../../framework/frontend/e2e/index.js'

// spec-owner: demo — chat's bot detail page

// Bot detail page e2e: /bot/:id displays a read-only profile of one chat bot
// resolved from the page entity subscription. The profile renders an entity
// head with HilosAvatar, name, presence and active badges, description, and
// optional personality/style/topics fields without a card wrapper or emoji.
// The page's botProfile list keeps that profile current, so an admin rename and
// a cleared description and fields reach the open guest page without a reload.
// The guest's first navigation is /bot itself: the main page's bot list would
// fill the entity store beforehand and hide a missing source on this page.
// The test removes the bot it made: bots.spec.ts counts the seed as exactly
// twenty bots.

/**
 * Delete a bot the admin window shows and wait for its controls to leave.
 *
 * @param page The Playwright page on the bots admin.
 * @param rowKey The bot's row key.
 */
async function deleteBot(page: Page, rowKey: string): Promise<void> {
  await shownByTestId(page, `admin-bots-delete-${rowKey}`).click()
  await clickSubmit(expect, page.getByTestId('admin-bots-delete-confirm'))
  await expect(page.getByTestId('admin-bots-delete-confirm')).toHaveCount(0)
  await expect(page.getByTestId(`admin-bots-delete-${rowKey}`)).toHaveCount(0)
}

test('guest observes bot detail layout, accessible head, and live rename and clear', async ({
  browser,
  page,
}) => {
  // Admin creation, a guest subscription, and two admin edits watched live by
  // the guest outlive the single-client cap.
  test.slow()

  await signUpAdmin(expect, page, { entryId: 'message-signin' })
  await gotoPage(expect, page, '/hilos/app/bots')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()

  const stamp = Date.now()
  const name = `Atlas ${stamp} Helper`
  const description = 'A versatile helper bot whose profile a guest reads live.'
  const personality =
    'Thoughtful, curious, analytical, and remarkably persistent in testing edge cases.'
  const style = 'Direct and articulate with comprehensive explanations.'
  const topics =
    'Testing, automation, user interface styling, responsive design, accessibility.'

  await page.getByTestId('hilos-table-main-action').click()
  await typeInto(page.getByTestId('admin-bots-name'), name)
  await typeInto(page.getByTestId('admin-bots-description'), description)
  await typeInto(page.getByTestId('admin-bots-style'), style)
  await typeInto(page.getByTestId('admin-bots-topics'), topics)
  await typeInto(page.getByTestId('admin-bots-personality'), personality)
  await page.getByTestId('admin-bots-active').uncheck()
  await clickSubmit(expect, page.getByTestId('admin-bots-save'))
  await expect(page.getByTestId('admin-bots-save')).toHaveCount(0)

  await typeInto(page.getByTestId('hilos-table-search'), name)
  const rowKey = await tableRowKeyByText(expect, page, name)

  const { baseURL, ignoreHTTPSErrors } = test.info().project.use
  const guestContext = await browser.newContext({ baseURL, ignoreHTTPSErrors })
  let guestFailure: { error: unknown } | undefined

  try {
    const guestPage = await guestContext.newPage()
    let guestLoads = 0
    guestPage.on('load', () => {
      guestLoads += 1
    })

    await gotoPage(expect, guestPage, `/bot/${rowKey}`, PAGE_READY)
    const loadsAfterCold = guestLoads

    const botHead = guestPage.getByTestId('bot-head')
    const botName = guestPage.getByTestId('bot-name')
    await expect(botName).toHaveText(name)
    await expect(botName).toHaveClass(/\bh4\b/)
    await expect(guestPage.locator('h1')).toHaveCount(1)

    const avatar = botHead.locator('[data-id="hilos-avatar"]')
    await expect(avatar).toHaveText('AH')
    await expect(avatar).toHaveAttribute('aria-hidden', 'true')

    await expect(guestPage.getByTestId('bot-presence')).toHaveText('offline')
    await expect(guestPage.getByTestId('bot-active')).toHaveText('inactive')

    await expect(botHead.getByTestId('bot-description')).toHaveText(description)

    const botFields = guestPage.getByTestId('bot-fields')
    await expect(botFields.getByTestId('bot-personality')).toHaveText(
      personality,
    )
    await expect(botFields.getByTestId('bot-style')).toHaveText(style)
    await expect(botFields.getByTestId('bot-topics')).toHaveText(topics)

    await expect(guestPage.locator('.card')).toHaveCount(0)
    await expect(guestPage.getByTestId('bot-view')).not.toContainText('🤖')

    const renamed = `Beacon ${stamp} Guide`
    await shownByTestId(page, `admin-bots-edit-${rowKey}`).click()
    await typeInto(page.getByTestId('admin-bots-name'), renamed)
    await clickSubmit(expect, page.getByTestId('admin-bots-save'))
    await expect(page.getByTestId('admin-bots-save')).toHaveCount(0)

    await expect(botName).toHaveText(renamed)
    await expect(avatar).toHaveText('BG')
    expect(guestLoads).toBe(loadsAfterCold)

    // The admin search still names the old bot; search the new name so the row
    // stays in the admin window for the second edit.
    await typeInto(page.getByTestId('hilos-table-search'), renamed)
    await shownByTestId(page, `admin-bots-edit-${rowKey}`).click()
    await typeInto(page.getByTestId('admin-bots-description'), '')
    await typeInto(page.getByTestId('admin-bots-personality'), '')
    await typeInto(page.getByTestId('admin-bots-style'), '')
    await typeInto(page.getByTestId('admin-bots-topics'), '')
    await clickSubmit(expect, page.getByTestId('admin-bots-save'))
    await expect(page.getByTestId('admin-bots-save')).toHaveCount(0)

    const botDetail = guestPage.getByTestId('bot-detail')
    await expect(botDetail.getByTestId('bot-description')).toHaveCount(0)
    for (const field of ['bot-personality', 'bot-style', 'bot-topics']) {
      await expect(botDetail.getByTestId(field)).toHaveCount(0)
    }
    await expect(botDetail.getByTestId('bot-fields')).toHaveCount(0)
    await expect(botDetail.locator('dt')).toHaveCount(0)
    await expect(botName).toHaveText(renamed)
    await expect(avatar).toHaveText('BG')
    await expect(guestPage.getByTestId('bot-presence')).toHaveText('offline')
    await expect(guestPage.getByTestId('bot-active')).toHaveText('inactive')
    expect(guestLoads).toBe(loadsAfterCold)
  } catch (error) {
    guestFailure = { error }
  } finally {
    await guestContext.close()
  }

  // The bot goes even when the guest part failed, and a failed cleanup must not
  // hide the assertion that failed first.
  try {
    await deleteBot(page, rowKey)
  } catch (error) {
    guestFailure ??= { error }
  }
  if (guestFailure) {
    throw guestFailure.error
  }
})
