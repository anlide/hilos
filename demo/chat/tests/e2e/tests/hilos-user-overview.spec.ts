import { expect, test } from '@playwright/test'

import {
  deliveryObject,
  expectHilosUserOverview,
  expectReaderMillisecondMoment,
  expectUserActivityDisplay,
  expectUserActivityMoment,
  expectUserTimeZone,
  userTableRows,
  watchClientDocument,
  watchPageReplies,
  watchSocketFrames,
  type SocketFrame,
  expectPageReady,
  gotoPage,
  signUpAdmin,
} from '../../../../../framework/frontend/e2e/index.js'
import { signUp } from '../helpers/session.js'

// spec-owner: demo — the Overview's project slot opens the real chat participant card.

test.use({ locale: 'en-GB', timezoneId: 'Asia/Tokyo' })

test('cold user Overview and F5 retain the shared head and open the same chat participant', async ({
  page,
}) => {
  const userId = await signUpAdmin(expect, page, { entryId: 'message-signin' })
  const name = await page.getByTestId('self-user').innerText()
  // A fresh document has never subscribed to the users list or either card.
  // Only its session cookie is shared with the administrator's first window.
  const card = await page.context().newPage()
  const replies = watchPageReplies(card)
  const frames: SocketFrame[] = []
  const wire = watchSocketFrames(card, (frame) => frames.push(frame))
  try {
    await gotoPage(expect, card, `/hilos/user/${userId}`)
    await expectHilosUserOverview(expect, card, userId, name, 'Hilos Chat')
    await expectUserTimeZone(expect, card, null)
    expect(
      userTableRows(frames).filter(
        (row) => row.tableKey === 'userDetail' && row.rowKey === String(userId),
      )[0]?.user.timeZone,
    ).toBeNull()
    await expectUserActivityDisplay(
      expect,
      card,
      userId,
      expectUserActivityMoment(expect, frames, 'userDetail', userId),
      'Asia/Tokyo',
      'card',
    )
    const participant = card.getByTestId('chat-participant-open')
    await expect(participant).toHaveRole('link')
    await expect(participant).toHaveAccessibleName('Open participant card')
    await expect(participant).toHaveAttribute('href', `/user/${userId}`)
    expect(replies.replies.map((reply) => reply.page)).toEqual(['hilos_user'])

    await card.reload()
    await expectPageReady(expect, card)
    await expectHilosUserOverview(expect, card, userId, name, 'Hilos Chat')
    await expectUserTimeZone(expect, card, null)
    await expectUserActivityDisplay(
      expect,
      card,
      userId,
      expectUserActivityMoment(expect, frames, 'userDetail', userId),
      'Asia/Tokyo',
      'card',
    )
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
      await expectPageReady(expect, card)
      await expect(card.getByTestId('user-detail')).toBeVisible()
      await expect(card.getByTestId('user-name')).toHaveText(name)
      await expect(card.getByRole('heading', { level: 1 })).toHaveText(name)
      await document.unchanged()
    } finally {
      await document.dispose()
    }
  } finally {
    wire.stop()
    await test.info().attach('overview-activity-wire', {
      body: JSON.stringify(frames),
      contentType: 'application/json',
    })
    await card.close()
  }
})

test('a guest sees the public participant activity in the non-UTC browser zone from its real page reply', async ({
  browser,
  page,
}) => {
  const user = await signUp(expect, page)
  const { baseURL, ignoreHTTPSErrors } = test.info().project.use
  const guestContext = await browser.newContext({
    baseURL,
    ignoreHTTPSErrors,
    locale: 'en-GB',
    timezoneId: 'Asia/Tokyo',
  })
  const guest = await guestContext.newPage()
  const frames: SocketFrame[] = []
  const wire = watchSocketFrames(guest, (frame) => frames.push(frame))
  try {
    await gotoPage(expect, guest, `/user/${user.userId}`)
    await expect(guest.getByTestId('conn-state')).toHaveText('connected')
    await expect(guest.getByTestId('nav-profile')).toHaveCount(0)
    await expect(guest.getByTestId('self-user')).toHaveCount(0)
    await expect(guest.getByTestId('user-name')).toHaveText(user.name)
    const reply = frames.find(({ wire }) => {
      const value = deliveryObject(wire)
      return (
        value.type === 'page_response' &&
        deliveryObject(value.data).page === 'user'
      )
    })
    expect(reply).toBeDefined()
    expect(reply!.failure).toBeUndefined()
    expect(reply!.raw).toEqual(expect.any(String))
    const data = deliveryObject(deliveryObject(reply!.wire).data)
    const participant = deliveryObject(
      deliveryObject(deliveryObject(data.payload).entities).user,
    )
    expect(participant.id).toBe(user.userId)
    await expectReaderMillisecondMoment(
      expect,
      guest.getByTestId('user-last-activity'),
      participant.lastActivity,
      'Asia/Tokyo',
    )
    // Fresh registered accounts distinguish epoch milliseconds from seconds.
    expect(participant.lastActivity).toBeGreaterThan(1_000_000_000_000)
    await test.info().attach('guest-public-activity-wire', {
      body: JSON.stringify(frames),
      contentType: 'application/json',
    })
  } finally {
    wire.stop()
    await guestContext.close()
  }
})
