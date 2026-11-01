import { expect, test, type BrowserContext } from '@playwright/test'

import {
  chooseProfileTimeZone,
  clickSubmit,
  createTestUser,
  deliveryObject,
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

test.use({ timezoneId: 'Europe/Warsaw', colorScheme: 'light' })

test('fans real Tokyo and Automatic choices out to independent sessions, keeps the receiving search and persists each choice', async ({
  browser,
  context,
  page,
}) => {
  // Two account fixtures, an independent login, two account writes and reloads
  // exceed the ordinary single-client budget even on an idle stand.
  test.slow()
  const contexts: BrowserContext[] = []
  const frames: SocketFrame[] = []
  const socket = watchSocketFrames(page, (frame) => frames.push(frame))
  const sockets = [socket]
  const firstOpened = watchOpenedSockets(page)
  try {
    const person = await createTestUser(expect, page)
    await gotoPage(expect, page, '/profile', PAGE_READY)
    await test.step('The real profile reply delivers the catalog and the automatic browser zone', async () => {
      await expectProfileTimeZoneCatalog(expect, frames)
      await expectProfileTimeZone(expect, page, null, 'Europe/Warsaw')
    })

    const secondContext = await browser.newContext(
      timeZoneContextOptions(test.info().project.use, 'America/New_York'),
    )
    contexts.push(secondContext)
    const second = await secondContext.newPage()
    const secondOpened = watchOpenedSockets(second)
    const secondFrames: SocketFrame[] = []
    const secondSocket = watchSocketFrames(second, (frame) =>
      secondFrames.push(frame),
    )
    sockets.push(secondSocket)
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
    const strangerOpened = watchOpenedSockets(stranger)
    const otherPerson = await createTestUser(expect, stranger)
    expect(otherPerson.userId).not.toBe(person.userId)
    await gotoPage(expect, stranger, '/profile', PAGE_READY)
    await expectProfileTimeZone(expect, page, null, 'Europe/Warsaw')
    await expectProfileTimeZone(expect, second, null, 'America/New_York')
    await openProfileTimeZone(expect, stranger)
    await expectProfileTimeZone(expect, stranger, null, 'Europe/London')

    await test.step('The picker searches the real catalog and keeps a filter open', async () => {
      await openProfileTimeZone(expect, second)
      await expectProfileTimeZoneSearch(expect, second)
    })
    const search = second.getByTestId('profile-time-zone-search')
    await typeInto(search, 'new york')
    await expect(search).toHaveValue('new york')

    // The saved choice comes back on each signed-in session's own socket.
    const echoed = async (
      sessionFrames: readonly SocketFrame[],
      timeZone: string | null,
    ) =>
      expect
        .poll(
          () =>
            sessionFrames
              .map(({ wire }) => deliveryObject(wire))
              .filter(({ type }) => type === 'handshake_response')
              .at(-1)?.data,
        )
        .toMatchObject({
          entities: { currentUser: { id: person.userId, timeZone } },
        })
    // Every window takes a delivery in place: no document is replaced and no
    // socket is reopened for it.
    const windows = [
      { page, sockets: firstOpened },
      { page: second, sockets: secondOpened },
      { page: stranger, sockets: strangerOpened },
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
        await chooseProfileTimeZone(expect, page, 'Asia/Tokyo')
        await echoed(frames, 'Asia/Tokyo')
        await echoed(secondFrames, 'Asia/Tokyo')
        await expectProfileTimeZone(expect, page, 'Asia/Tokyo', 'Europe/Warsaw')
        await expectProfileTimeZone(
          expect,
          second,
          'Asia/Tokyo',
          'America/New_York',
          false,
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
      await expectProfileTimeZone(expect, page, 'Asia/Tokyo', 'Europe/Warsaw')
    })

    await inPlace(() =>
      test.step('Automatic returns every window to its own browser zone', async () => {
        await chooseProfileTimeZone(expect, page, null)
        await echoed(frames, null)
        await echoed(secondFrames, null)
        await expectProfileTimeZone(expect, page, null, 'Europe/Warsaw')
        await expectProfileTimeZone(
          expect,
          second,
          null,
          'America/New_York',
          false,
        )
        await expect(
          second.getByTestId('profile-time-zone-modal'),
        ).toBeVisible()
        await expect(search).toHaveValue('new york')
        await search.fill('')
        await expectProfileTimeZone(expect, second, null, 'America/New_York')
        await expectProfileTimeZone(expect, stranger, null, 'Europe/London')
        await clickSubmit(expect, second.getByTestId('profile-time-zone-close'))
        await expect(second.getByTestId('profile-time-zone-modal')).toBeHidden()
        await expect(
          second.getByTestId('profile-time-zone-change'),
        ).toBeFocused()
      }),
    )
    for (const sessionFrames of [frames, secondFrames]) {
      expect(
        sessionFrames.filter((frame) => frame.failure !== undefined),
      ).toEqual([])
    }

    await test.step('Automatic persists through a reload of each window', async () => {
      await page.reload()
      await expectPageReady(expect, page)
      await expectProfileTimeZone(expect, page, null, 'Europe/Warsaw')
      await second.reload()
      await expectPageReady(expect, second)
      await expectProfileTimeZone(expect, second, null, 'America/New_York')
      await expectProfileTimeZone(expect, stranger, null, 'Europe/London')
    })
  } finally {
    for (const watched of sockets) watched.stop()
    await Promise.all(contexts.map((opened) => opened.close()))
  }
})
