import { expect, test } from '@playwright/test'

import {
  expectHilosUserOverview,
  watchClientDocument,
  watchPageReplies,
} from '../../../../../framework/frontend/e2e/index.js'
import { signUpAdmin } from '../helpers/adminGrant.js'
import { expectPageReady, gotoPage } from '../helpers/page.js'

// spec-owner: demo — the Overview's project slot opens the real chat participant card.

test('cold user Overview and F5 retain the shared head and open the same chat participant', async ({
  page,
}) => {
  const userId = await signUpAdmin(page)
  const name = await page.getByTestId('self-user').innerText()
  // A fresh document has never subscribed to the users list or either card.
  // Only its session cookie is shared with the administrator's first window.
  const card = await page.context().newPage()
  const replies = watchPageReplies(card)
  try {
    await gotoPage(card, `/hilos/user/${userId}`)
    await expectHilosUserOverview(expect, card, userId, name, 'Hilos Chat')
    const participant = card.getByTestId('chat-participant-open')
    await expect(participant).toHaveRole('link')
    await expect(participant).toHaveAccessibleName('Open participant card')
    await expect(participant).toHaveAttribute('href', `/user/${userId}`)
    expect(replies.replies.map((reply) => reply.page)).toEqual(['hilos_user'])

    await card.reload()
    await expectPageReady(card)
    await expectHilosUserOverview(expect, card, userId, name, 'Hilos Chat')
    await expect(participant).toHaveAttribute('href', `/user/${userId}`)
    // Rendering the project link needs only the delivered user id. No public
    // participant subscription is made until the link is actually followed.
    expect(replies.replies.map((reply) => reply.page)).toEqual([
      'hilos_user',
      'hilos_user',
    ])

    const document = await watchClientDocument(card)
    try {
      await participant.focus()
      await expect(participant).toBeFocused()
      await card.keyboard.press('Enter')
      await expect(card).toHaveURL(`/user/${userId}`)
      await expectPageReady(card)
      await expect(card.getByTestId('user-detail')).toBeVisible()
      await expect(card.getByTestId('user-name')).toHaveText(name)
      await expect(card.getByRole('heading', { level: 1 })).toHaveText(name)
      await document.unchanged()
    } finally {
      await document.dispose()
    }
  } finally {
    await card.close()
  }
})
