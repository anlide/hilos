import { expect, test, type Page } from '@playwright/test'

import { modelKey } from '../../../../../framework/frontend/scripts/standModel.mjs'
import { signUpAdmin } from '../helpers/adminGrant.js'
import { openBotEdit, watchBotSearch } from '../helpers/bots.js'
import { dictateModerationVerdict } from '../helpers/moderation.js'
import { gotoPage } from '../helpers/page.js'
import { nameForPhoto, picture, selectPhoto } from '../helpers/profilePhoto.js'
import { clickSubmit, typeInto } from '../helpers/session.js'

// spec-owner: demo — the feed, Participants and /user/{id} are chat's own screens.

// The circles of chat's screens (HIL-1300). A message opens a series of one
// author's messages with a circle and the name, and the message that continues
// it wears no visible circle; a roster row and the person's page wear the
// person's circle, and a bot row the circle of the bot's initials. A guest's
// open screens follow the person's photo — set, then removed — without a reload.

test.use({ viewport: { width: 1440, height: 1000 }, colorScheme: 'light' })

/** The initials of a `Photo <model key>` name: the first and the last word's first letters. */
const PHOTO_NAME_INITIALS = 'PM'

/**
 * A seeded bot that stays quiet while active: it speaks only on a topic of its
 * own (food), and no test of this demo dictates that topic to the analyzer.
 */
const QUIET_BOT = 'Quinn'

/** The initials of {@link QUIET_BOT}. */
const QUIET_BOT_INITIALS = 'Q'

/**
 * Switch a seeded bot on or off in the bots admin table.
 *
 * @param page An administrator's page.
 * @param name The seeded bot's name.
 * @param active Whether the bot is to be active.
 * @param windows The observer installed before the first document.
 */
async function setBotActive(
  page: Page,
  name: string,
  active: boolean,
  windows: Awaited<ReturnType<typeof watchBotSearch>>,
): Promise<void> {
  await openBotEdit(page, name, windows)
  await page.getByTestId('admin-bots-active').setChecked(active)
  await clickSubmit(page.getByTestId('admin-bots-save'))
  await expect(page.getByTestId('admin-bots-save')).toHaveCount(0)
}

test('a guest sees the circles of a series, the roster and the user page follow the photo', async ({
  browser,
  page,
}) => {
  const { baseURL, ignoreHTTPSErrors } = test.info().project.use
  const guestContext = await browser.newContext({ baseURL, ignoreHTTPSErrors })

  try {
    const guestPage = await guestContext.newPage()
    const guestUserPage = await guestContext.newPage()

    // The name carries the key the photo check finds in its prompt; each
    // message carries a key of its own, so the analyzer that reads the feed
    // after a message never spends the photo's verdict.
    const key = modelKey()
    const name = await nameForPhoto(page, key)
    await gotoPage(page, '/')
    await expect(page.getByTestId('self-user')).toHaveText(name)
    const userId = Number(await page.getByTestId('self-user-id').textContent())

    // The rename notice before it makes the first message the head of a series.
    const firstKey = modelKey()
    const first = `the first of a series ${firstKey}`
    await dictateModerationVerdict(firstKey, true, 'ok')
    await typeInto(page.getByTestId('message-input'), first)
    await clickSubmit(page.getByTestId('message-send'))
    await expect(
      page.getByTestId('event-text').filter({ hasText: first }),
    ).toBeVisible()

    // The guest opens the feed and the person's page while the re-send lockout runs.
    await gotoPage(guestPage, '/')
    await gotoPage(guestUserPage, `/user/${userId}`)
    await expect(guestUserPage.getByTestId('user-name')).toHaveText(name)

    const secondKey = modelKey()
    const second = `the second of a series ${secondKey}`
    await dictateModerationVerdict(secondKey, true, 'ok')
    await typeInto(page.getByTestId('message-input'), second)
    // The first submit's re-send lockout has to run out first.
    await expect(page.getByTestId('message-send')).toBeEnabled({
      timeout: 20_000,
    })
    await clickSubmit(page.getByTestId('message-send'))

    const head = guestPage.getByTestId('event').filter({
      has: guestPage.getByTestId('event-text').filter({ hasText: first }),
    })
    const continuation = guestPage.getByTestId('event').filter({
      has: guestPage.getByTestId('event-text').filter({ hasText: second }),
    })
    await expect(continuation).toBeVisible()
    await expect(head.getByTestId('hilos-avatar')).toBeVisible()
    await expect(head.getByTestId('hilos-avatar')).toHaveText(
      PHOTO_NAME_INITIALS,
    )
    await expect(head.getByTestId('event-author')).toBeVisible()
    await expect(continuation.getByTestId('hilos-avatar')).toBeHidden()
    // The continuation's name stays for screen readers only; Playwright counts
    // the one-pixel clip of visually-hidden as visible, so the class says it.
    await expect(continuation.getByTestId('event-author')).toHaveClass(
      /\bvisually-hidden\b/,
    )
    await expect(continuation.getByTestId('event-author')).toHaveText(name)

    const participant = guestPage
      .getByTestId('participant')
      .filter({ hasText: name })
    await expect(participant.getByTestId('hilos-avatar')).toHaveText(
      PHOTO_NAME_INITIALS,
    )
    const userCircle = guestUserPage
      .getByTestId('user-detail')
      .getByTestId('hilos-avatar')
    await expect(userCircle).toHaveText(PHOTO_NAME_INITIALS)

    const photos = [
      head.getByTestId('hilos-avatar-photo'),
      participant.getByTestId('hilos-avatar-photo'),
      userCircle.getByTestId('hilos-avatar-photo'),
    ]
    for (const photo of photos) {
      await expect(photo).toHaveCount(0)
    }

    // The person sets a photo: the guest's open screens show it without a reload.
    await gotoPage(page, '/profile')
    await dictateModerationVerdict(key, true, 'ok')
    await selectPhoto(page, await picture(page, 'image/jpeg'), 'image/jpeg')
    await clickSubmit(page.getByTestId('profile-photo-save'))
    await expect(page.getByTestId('modal')).toBeHidden()
    for (const photo of photos) {
      await expect(photo).toBeVisible()
      await expect(photo).toHaveAttribute('src', /variant=hilos_avatar/)
    }
    await expect(continuation.getByTestId('hilos-avatar-photo')).toHaveCount(0)

    // The person removes it: the initials come back.
    await clickSubmit(page.getByTestId('profile-photo-open'))
    await clickSubmit(page.getByTestId('profile-photo-remove'))
    await expect(page.getByTestId('modal')).toBeHidden()
    for (const photo of photos) {
      await expect(photo).toHaveCount(0)
    }
    await expect(head.getByTestId('hilos-avatar')).toHaveText(
      PHOTO_NAME_INITIALS,
    )
    await expect(participant.getByTestId('hilos-avatar')).toHaveText(
      PHOTO_NAME_INITIALS,
    )
    await expect(userCircle).toHaveText(PHOTO_NAME_INITIALS)
  } finally {
    await guestContext.close()
  }
})

// Every seeded bot is inactive and the main page lists only active ones, so the
// test switches a quiet one on to have a bot row at all, and off again after.
test('a bot row wears the circle of its initials', async ({ page }) => {
  const windows = await watchBotSearch(page)
  await signUpAdmin(page)

  try {
    await setBotActive(page, QUIET_BOT, true, windows)
    await gotoPage(page, '/')
    const bot = page.getByTestId('bot').filter({
      has: page.getByTestId('bot-name').filter({ hasText: QUIET_BOT }),
    })
    await expect(bot.getByTestId('hilos-avatar')).toHaveText(QUIET_BOT_INITIALS)
  } finally {
    await setBotActive(page, QUIET_BOT, false, windows)
  }
})
