import type { BrowserContext } from '@playwright/test'
import { expect, test } from '../test.js'

import {
  chooseProfileTimeZone,
  clickSubmit,
  createTestUser,
  deliveryObject,
  expectPageReady,
  expectProfileSessionMoments,
  expectProfileTimeZone,
  expectProfileTimeZoneCatalog,
  expectProfileTimeZoneSearch,
  expectWelcomedSockets,
  gotoPage,
  isSessionCookie,
  login,
  openProfileTimeZone,
  PAGE_READY,
  profileSessionSnapshot,
  timeZoneContextOptions,
  typeInto,
  watchClientDocument,
  watchOpenedSockets,
  watchSocketFrames,
  type SocketFrame,
} from '../../../../../framework/frontend/e2e/index.js'

test.use({ timezoneId: 'Europe/Warsaw', colorScheme: 'light' })

test('fans New York and Automatic choices out to independent sessions, keeps the receiving search and persists each choice', async ({
  browser,
  context,
  page,
}) => {
  const contexts: BrowserContext[] = []
  const frames: SocketFrame[] = []
  const socket = watchSocketFrames(page, (frame) => frames.push(frame))
  const firstSockets = watchOpenedSockets(page)
  try {
    const person = await createTestUser(expect, page)
    await gotoPage(expect, page, '/profile', PAGE_READY)
    await test.step('The real profile reply delivers the catalog and the automatic browser zone', async () => {
      await expectProfileTimeZoneCatalog(expect, frames)
      await expectProfileTimeZone(expect, page, null, 'Europe/Warsaw')
    })

    await test.step('The picker searches the delivered catalog without asking for it again', async () => {
      await openProfileTimeZone(expect, page)
      await expectProfileTimeZoneSearch(expect, page)
      await clickSubmit(expect, page.getByTestId('profile-time-zone-close'))
      await expect(page.getByTestId('profile-time-zone-modal')).toBeHidden()
      await expect(page.getByTestId('profile-time-zone-change')).toBeFocused()
      await expectProfileTimeZoneCatalog(expect, frames)
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

    await openProfileTimeZone(expect, second)
    const search = second.getByTestId('profile-time-zone-search')
    await typeInto(search, 'new york')
    await expect(search).toHaveValue('new york')

    // Every window takes a delivery in place: no document is replaced and no
    // socket is reopened for it.
    const windows = [
      { page, sockets: firstSockets },
      { page: second, sockets: secondSockets },
      { page: stranger, sockets: strangerSockets },
    ]
    const inPlace = async (deliveries: () => Promise<void>) => {
      const socketCounts = windows.map(({ sockets }) => sockets.length)
      const documents = await Promise.all(
        windows.map((opened) => watchClientDocument(opened.page)),
      )
      try {
        await deliveries()
        for (const [index, document] of documents.entries()) {
          await document.unchanged()
          await expectWelcomedSockets(
            expect,
            windows[index]!.sockets,
            socketCounts[index]!,
          )
        }
      } finally {
        await Promise.all(documents.map((document) => document.dispose()))
      }
    }

    await inPlace(() =>
      test.step('A chosen zone reaches the other signed-in window live and leaves another person alone', async () => {
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
      }),
    )

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

    await inPlace(() =>
      test.step('Automatic returns every window to its own browser zone', async () => {
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
      }),
    )

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

test('reformats real session and tab milliseconds while retaining the device browser zone and expanded tabs', async ({
  context,
  page,
}) => {
  await createTestUser(expect, page)
  await gotoPage(expect, page, '/profile', PAGE_READY)
  const sessionsPage = await context.newPage()
  const frames: SocketFrame[] = []
  const socket = watchSocketFrames(sessionsPage, (frame) => frames.push(frame))
  try {
    await gotoPage(expect, sessionsPage, '/profile/sessions', PAGE_READY)
    await expect.poll(() => profileSessionSnapshot(frames)).toBeDefined()
    const snapshot = profileSessionSnapshot(frames)!
    const self = deliveryObject(
      deliveryObject(snapshot.payload.data).selfConnection,
    )
    expect(self.acceptKey).toEqual(expect.any(String))
    await clickSubmit(expect, sessionsPage.getByTestId('profile-session-tabs'))
    await expectProfileSessionMoments(
      expect,
      sessionsPage,
      snapshot,
      'Europe/Warsaw',
      self.acceptKey as string,
      2,
    )
    const document = await watchClientDocument(sessionsPage)
    const socketCount = socket.sockets()
    try {
      await chooseProfileTimeZone(expect, page, 'Asia/Tokyo')
      await expectProfileTimeZone(expect, page, 'Asia/Tokyo', 'Europe/Warsaw')
      await expectProfileSessionMoments(
        expect,
        sessionsPage,
        snapshot,
        'Asia/Tokyo',
        self.acceptKey as string,
        2,
        'Europe/Warsaw',
      )
      await chooseProfileTimeZone(expect, page, null)
      await expectProfileTimeZone(expect, page, null, 'Europe/Warsaw')
      await expectProfileSessionMoments(
        expect,
        sessionsPage,
        snapshot,
        'Europe/Warsaw',
        self.acceptKey as string,
        2,
      )
      await document.unchanged()
      expect(socket.sockets()).toBe(socketCount)
    } finally {
      await document.dispose()
    }
  } finally {
    socket.stop()
    await sessionsPage.close()
  }
})
