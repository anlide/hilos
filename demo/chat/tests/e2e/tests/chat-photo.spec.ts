import { expect, test } from '../test.js'
import {
  clickSubmit,
  openAndSelectPhoto,
  typeInto,
  gotoPage,
  preparePhotoSubject,
} from '../../../../../framework/frontend/e2e/index.js'

import {
  dictateModerationVerdict,
  modelKey,
} from '../../../../../framework/frontend/scripts/standModel.mjs'
import { picture } from '../helpers/profilePhoto.js'
import { endSendPause } from '../helpers/sendPause.js'

// spec-owner: demo — the feed, Participants and /user/{id} are chat's own screens.

// The circles of chat's screens (HIL-1300). A message opens a series of one
// author's messages with a circle and the name, and the message that continues
// it wears no visible circle; a roster row and the person's page wear the
// person's circle. A guest's open screens follow the person's photo — set,
// then removed — without a reload.

test.use({ viewport: { width: 1440, height: 1000 }, colorScheme: 'light' })

/** The initials of a `Photo <model key>` name: the first and the last word's first letters. */
const PHOTO_NAME_INITIALS = 'PM'

// FIXME(F-059, 2026-11-01): аватарка события не видна после сохранения фото · чужой · попытка 1 · HIL-1761
test(
  'a guest sees the circles of a series, the roster and the user page follow the photo',
  { tag: '@quarantine:F-059' },
  async ({ browser, page }) => {
    const { baseURL, ignoreHTTPSErrors } = test.info().project.use
    const guestContext = await browser.newContext({
      baseURL,
      ignoreHTTPSErrors,
    })

    try {
      const guestPage = await guestContext.newPage()
      const guestUserPage = await guestContext.newPage()

      // The name carries the key the photo check finds in its prompt; each
      // message carries a key of its own, so the analyzer that reads the feed
      // after a message never spends the photo's verdict.
      const key = modelKey()
      const { name, userId } = await preparePhotoSubject(expect, page, key)
      await gotoPage(expect, page, '/')
      await expect(page.getByTestId('self-user')).toHaveText(name)

      // The rename notice before it makes the first message the head of a series.
      const firstKey = modelKey()
      const first = `the first of a series ${firstKey}`
      await dictateModerationVerdict(firstKey, true, 'ok')
      await typeInto(page.getByTestId('message-input'), first)
      await clickSubmit(expect, page.getByTestId('message-send'))
      await expect(
        page.getByTestId('event-text').filter({ hasText: first }),
      ).toBeVisible()

      // The guest opens the feed and the person's page while the re-send lockout runs.
      await gotoPage(expect, guestPage, '/')
      await gotoPage(expect, guestUserPage, `/user/${userId}`)
      await expect(guestUserPage.getByTestId('user-name')).toHaveText(name)

      const secondKey = modelKey()
      const second = `the second of a series ${secondKey}`
      await dictateModerationVerdict(secondKey, true, 'ok')
      await typeInto(page.getByTestId('message-input'), second)
      // A command ends the first submit's re-send lockout.
      await endSendPause(userId)
      await expect(page.getByTestId('message-send')).toBeEnabled()
      await clickSubmit(expect, page.getByTestId('message-send'))

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
      await gotoPage(expect, page, '/profile')
      await dictateModerationVerdict(key, true, 'ok')
      await openAndSelectPhoto(
        expect,
        page,
        await picture(page, 'image/jpeg'),
        'image/jpeg',
      )
      await clickSubmit(expect, page.getByTestId('profile-photo-save'))
      await expect(page.getByTestId('modal')).toBeHidden()
      for (const photo of photos) {
        await expect(photo).toBeVisible()
        await expect(photo).toHaveAttribute('src', /variant=hilos_avatar/)
      }
      await expect(continuation.getByTestId('hilos-avatar-photo')).toHaveCount(
        0,
      )

      // The person removes it: the initials come back.
      await clickSubmit(expect, page.getByTestId('profile-photo-open'))
      await clickSubmit(expect, page.getByTestId('profile-photo-remove'))
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
  },
)
