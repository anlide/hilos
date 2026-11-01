import { expect, test, type BrowserContext } from '@playwright/test'

import {
  chooseProfileTimeZone,
  clickSubmit,
  createTestUser,
  expectPageReady,
  expectProfileTimeZone,
  expectProfileTimeZoneCatalog,
  expectProfileTimeZoneSearch,
  expectWelcomedSockets,
  gotoPage,
  isSessionCookie,
  login,
  openProfileTimeZone,
  PAGE_READY,
  timeZoneContextOptions,
  typeInto,
  watchClientDocument,
  watchOpenedSockets,
  watchSocketFrames,
  type SocketFrame,
} from '../../../../../framework/frontend/e2e/index.js'

test.use({ timezoneId: 'Europe/Warsaw' })

test('fans real account choices out to independent sessions, keeps the receiving search and resets each browser', async ({
  browser,
  context,
  page,
}) => {
  // Two registrations, an independent password login, two writes and reloads
  // exceed the single-client budget even on an idle stand.
  test.slow()
  const contexts: BrowserContext[] = []
  const frames: SocketFrame[] = []
  const socket = watchSocketFrames(page, (frame) => frames.push(frame))
  try {
    const person = await createTestUser(expect, page)
    await gotoPage(expect, page, '/profile', PAGE_READY)
    await test.step('The real profile reply delivers the catalog and the automatic browser zone', async () => {
      await expectProfileTimeZoneCatalog(expect, frames)
      await expectProfileTimeZone(expect, page, null, 'Europe/Warsaw')
    })

    const secondContext = await browser.newContext(
      timeZoneContextOptions(test.info().project.use, 'Asia/Tokyo'),
    )
    contexts.push(secondContext)
    const second = await secondContext.newPage()
    const secondSockets = watchOpenedSockets(second)
    await gotoPage(expect, second, '/profile')
    await login(expect, second, person.email)
    await expectPageReady(expect, second)
    await expect(second.getByTestId('profile-name')).toHaveText(person.name)
    const firstSession = (await context.cookies()).find((cookie) =>
      isSessionCookie(cookie.name),
    )
    const secondSession = (await secondContext.cookies()).find((cookie) =>
      isSessionCookie(cookie.name),
    )
    expect(firstSession).toBeDefined()
    expect(secondSession).toBeDefined()
    expect(secondSession?.value).not.toBe(firstSession?.value)

    const strangerContext = await browser.newContext(
      timeZoneContextOptions(test.info().project.use, 'Europe/London'),
    )
    contexts.push(strangerContext)
    const stranger = await strangerContext.newPage()
    const strangerSockets = watchOpenedSockets(stranger)
    const otherPerson = await createTestUser(expect, stranger)
    expect(otherPerson.userId).not.toBe(person.userId)
    await gotoPage(expect, stranger, '/profile', PAGE_READY)
    await expectProfileTimeZone(expect, page, null, 'Europe/Warsaw')
    await expectProfileTimeZone(expect, second, null, 'Asia/Tokyo')
    await openProfileTimeZone(expect, stranger)
    await expectProfileTimeZone(expect, stranger, null, 'Europe/London')

    await test.step('The picker searches the real catalog and keeps a filter open', async () => {
      await openProfileTimeZone(expect, second)
      await expectProfileTimeZoneSearch(expect, second)
    })
    const search = second.getByTestId('profile-time-zone-search')
    await typeInto(search, 'new york')
    await expect(search).toHaveValue('new york')
    const receivers = [second, stranger]
    const receiverSockets = [secondSockets, strangerSockets]
    const socketCounts = receiverSockets.map((opened) => opened.length)
    const documents = await Promise.all(receivers.map(watchClientDocument))
    try {
      await test.step('A chosen zone reaches the other signed-in window live and leaves another person alone', async () => {
        await chooseProfileTimeZone(expect, page, 'America/New_York')
        await expectProfileTimeZone(
          expect,
          page,
          'America/New_York',
          'Europe/Warsaw',
        )
        await expectProfileTimeZone(
          expect,
          second,
          'America/New_York',
          'Asia/Tokyo',
        )
        await expect(
          second.getByTestId('profile-time-zone-modal'),
        ).toBeVisible()
        await expect(search).toHaveValue('new york')
        await expectProfileTimeZone(expect, stranger, null, 'Europe/London')
      })

      await test.step('A fresh load renders the saved zone as its own', async () => {
        // Persistence is read only after the live delivery was already observed.
        await page.reload()
        await expectPageReady(expect, page)
        await expectProfileTimeZone(
          expect,
          page,
          'America/New_York',
          'Europe/Warsaw',
        )
      })

      await test.step('Automatic returns every window to its own browser zone', async () => {
        await chooseProfileTimeZone(expect, page, null)
        await expectProfileTimeZone(expect, page, null, 'Europe/Warsaw')
        await expectProfileTimeZone(expect, second, null, 'Asia/Tokyo', false)
        await expect(
          second.getByTestId('profile-time-zone-modal'),
        ).toBeVisible()
        await expect(search).toHaveValue('new york')
        await search.fill('')
        await expectProfileTimeZone(expect, second, null, 'Asia/Tokyo')
        await expectProfileTimeZone(expect, stranger, null, 'Europe/London')
        await clickSubmit(expect, second.getByTestId('profile-time-zone-close'))
        await expect(second.getByTestId('profile-time-zone-modal')).toBeHidden()
        await expect(
          second.getByTestId('profile-time-zone-change'),
        ).toBeFocused()
      })

      // The receiving windows were updated in place: no document was replaced
      // and no socket was reopened for the deliveries they received.
      for (const [index, document] of documents.entries()) {
        await document.unchanged()
        await expectWelcomedSockets(
          expect,
          receiverSockets[index]!,
          socketCounts[index]!,
        )
      }
    } finally {
      await Promise.all(documents.map((document) => document.dispose()))
    }

    await test.step('Automatic persists through a reload of each window', async () => {
      await page.reload()
      await expectPageReady(expect, page)
      await expectProfileTimeZone(expect, page, null, 'Europe/Warsaw')
      await second.reload()
      await expectPageReady(expect, second)
      await expectProfileTimeZone(expect, second, null, 'Asia/Tokyo')
      await expectProfileTimeZone(expect, stranger, null, 'Europe/London')
    })
  } finally {
    socket.stop()
    await Promise.all(contexts.map((opened) => opened.close()))
  }
})
