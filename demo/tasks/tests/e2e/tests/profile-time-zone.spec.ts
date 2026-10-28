import { expect, test, type BrowserContext } from '@playwright/test'

import {
  chooseProfileTimeZone,
  clickSubmit,
  deliveryObject,
  expectPageReady,
  expectProfileTimeZone,
  expectProfileTimeZoneCatalog,
  expectProfileTimeZoneSearch,
  gotoPage,
  isSessionCookie,
  login,
  openProfileTimeZone,
  PAGE_READY,
  signUp,
  typeInto,
  watchClientDocument,
  watchSocketFrames,
  type SocketFrame,
} from '../../../../../framework/frontend/e2e/index.js'

test.use({ timezoneId: 'Europe/Warsaw', colorScheme: 'light' })

test('shows Automatic before Theme and receives the profile time-zone catalog', async ({
  page,
}) => {
  const frames: SocketFrame[] = []
  const socket = watchSocketFrames(page, (frame) => frames.push(frame))
  try {
    await signUp(expect, page)
    await gotoPage(expect, page, '/profile', PAGE_READY)
    await expectProfileTimeZoneCatalog(expect, frames)
    await expect(page.getByTestId('profile-time-zone-row')).toBeVisible()
    await expect(page.getByTestId('profile-time-zone-change')).toHaveText(
      'Change',
    )
    await expectProfileTimeZone(expect, page, null, 'Europe/Warsaw')

    const positions = await page
      .getByTestId('profile-detail')
      .locator('> div')
      .evaluateAll((rows) => ({
        timeZone: rows.findIndex((row) =>
          row.querySelector('[data-id="profile-time-zone"]'),
        ),
        theme: rows.findIndex((row) =>
          row.querySelector('[data-id="profile-theme"]'),
        ),
      }))
    expect(positions.timeZone).toBeGreaterThan(-1)
    expect(positions.theme).toBe(positions.timeZone + 1)
  } finally {
    socket.stop()
  }
})

test('real Tokyo and Automatic choices reach independent sessions while preserving an open search', async ({
  browser,
  context,
  page,
  baseURL,
}) => {
  // Two registrations, an independent login, two account writes and reloads
  // exceed the ordinary single-client budget even on an idle stand.
  test.slow()
  const contexts: BrowserContext[] = []
  const frames: SocketFrame[] = []
  const socket = watchSocketFrames(page, (frame) => frames.push(frame))
  const sockets = [socket]
  try {
    const person = await signUp(expect, page)
    await gotoPage(expect, page, '/profile', PAGE_READY)
    const secondContext = await browser.newContext({
      baseURL,
      ignoreHTTPSErrors: true,
      colorScheme: 'light',
      timezoneId: 'America/New_York',
    })
    contexts.push(secondContext)
    const second = await secondContext.newPage()
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

    const strangerContext = await browser.newContext({
      baseURL,
      ignoreHTTPSErrors: true,
      colorScheme: 'light',
      timezoneId: 'Europe/London',
    })
    contexts.push(strangerContext)
    const stranger = await strangerContext.newPage()
    const otherPerson = await signUp(expect, stranger)
    expect(otherPerson.userId).not.toBe(person.userId)
    await gotoPage(expect, stranger, '/profile', PAGE_READY)
    await expectProfileTimeZone(expect, page, null, 'Europe/Warsaw')
    await expectProfileTimeZone(expect, second, null, 'America/New_York')
    await openProfileTimeZone(expect, stranger)
    await expectProfileTimeZone(expect, stranger, null, 'Europe/London')

    await openProfileTimeZone(expect, second)
    await expectProfileTimeZoneSearch(expect, second)
    const search = second.getByTestId('profile-time-zone-search')
    await typeInto(search, 'new york')
    await expect(search).toHaveValue('new york')
    const firstDocument = await watchClientDocument(page)
    const secondDocument = await watchClientDocument(second)
    const connections = [socket.sockets(), secondSocket.sockets()]
    try {
      for (const timeZone of ['Asia/Tokyo', null]) {
        await chooseProfileTimeZone(expect, page, timeZone)
        for (const session of [
          { page, frames, browserZone: 'Europe/Warsaw' },
          {
            page: second,
            frames: secondFrames,
            browserZone: 'America/New_York',
          },
        ]) {
          await expect
            .poll(
              () =>
                session.frames
                  .map(({ wire }) => deliveryObject(wire))
                  .filter(({ type }) => type === 'handshake_response')
                  .at(-1)?.data,
            )
            .toMatchObject({
              entities: { currentUser: { id: person.userId, timeZone } },
            })
          await expectProfileTimeZone(
            expect,
            session.page,
            timeZone,
            session.browserZone,
            session.page === page,
          )
          expect(
            session.frames.filter((frame) => frame.failure !== undefined),
          ).toEqual([])
        }
        await expect(
          second.getByTestId('profile-time-zone-modal'),
        ).toBeVisible()
        await expect(search).toHaveValue('new york')
        await firstDocument.unchanged()
        await secondDocument.unchanged()
        expect([socket.sockets(), secondSocket.sockets()]).toEqual(connections)
        await expectProfileTimeZone(expect, stranger, null, 'Europe/London')
      }
      await search.fill('')
      await expectProfileTimeZone(expect, second, null, 'America/New_York')
      await clickSubmit(expect, second.getByTestId('profile-time-zone-close'))
      await expect(second.getByTestId('profile-time-zone-modal')).toBeHidden()
      await expect(second.getByTestId('profile-time-zone-change')).toBeFocused()
    } finally {
      await firstDocument.dispose()
      await secondDocument.dispose()
    }

    await page.reload()
    await expectPageReady(expect, page)
    await expectProfileTimeZone(expect, page, null, 'Europe/Warsaw')
    await second.reload()
    await expectPageReady(expect, second)
    await expectProfileTimeZone(expect, second, null, 'America/New_York')
    await expectProfileTimeZone(expect, stranger, null, 'Europe/London')
  } finally {
    for (const socket of sockets) socket.stop()
    await Promise.all(contexts.map((context) => context.close()))
  }
})
