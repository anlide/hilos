import { expect, test, type BrowserContext } from '@playwright/test'

import {
  chooseProfileTimeZone,
  clickSubmit,
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
  signUp,
  typeInto,
  watchClientDocument,
  watchOpenedSockets,
  watchSocketFrames,
  type SocketFrame,
} from '../../../../../framework/frontend/e2e/index.js'

test.use({ timezoneId: 'Europe/Warsaw', colorScheme: 'light' })

test('shows the automatic browser zone before Theme and searches the real profile catalog', async ({
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

    const rowIndices = await page
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
    expect(rowIndices.timeZone).toBeGreaterThan(-1)
    expect(rowIndices.theme).toBe(rowIndices.timeZone + 1)

    await openProfileTimeZone(expect, page)
    await expectProfileTimeZoneSearch(expect, page)
    await clickSubmit(expect, page.getByTestId('profile-time-zone-close'))
    await expect(page.getByTestId('profile-time-zone-modal')).toBeHidden()
    await expect(page.getByTestId('profile-time-zone-change')).toBeFocused()
    await expectProfileTimeZoneCatalog(expect, frames)
    await chooseProfileTimeZone(expect, page, 'Asia/Tokyo')
    await expectProfileTimeZone(expect, page, 'Asia/Tokyo', 'Europe/Warsaw')
    await chooseProfileTimeZone(expect, page, null)
    await expectProfileTimeZone(expect, page, null, 'Europe/Warsaw')
  } finally {
    socket.stop()
  }
})

test('fans choices out to independent browsers, keeps the receiving search and isolates another person', async ({
  browser,
  context,
  page,
  baseURL,
}) => {
  const contexts: BrowserContext[] = []
  const firstSockets = watchOpenedSockets(page)
  try {
    const person = await signUp(expect, page)
    await gotoPage(expect, page, '/profile', PAGE_READY)
    const secondContext = await browser.newContext({
      baseURL,
      ignoreHTTPSErrors: true,
      colorScheme: 'light',
      timezoneId: 'Asia/Tokyo',
    })
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

    const strangerContext = await browser.newContext({
      baseURL,
      ignoreHTTPSErrors: true,
      colorScheme: 'light',
      timezoneId: 'Europe/London',
    })
    contexts.push(strangerContext)
    const stranger = await strangerContext.newPage()
    const strangerSockets = watchOpenedSockets(stranger)
    const otherPerson = await signUp(expect, stranger)
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
    const pages = [page, second, stranger]
    const sockets = [firstSockets, secondSockets, strangerSockets]
    const socketCounts = sockets.map((opened) => opened.length)
    const documents = await Promise.all(pages.map(watchClientDocument))
    try {
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
      await expect(second.getByTestId('profile-time-zone-modal')).toBeVisible()
      await expect(search).toHaveValue('new york')
      await expectProfileTimeZone(expect, stranger, null, 'Europe/London')

      await chooseProfileTimeZone(expect, page, null)
      await expectProfileTimeZone(expect, page, null, 'Europe/Warsaw')
      await expectProfileTimeZone(expect, second, null, 'Asia/Tokyo', false)
      await expect(second.getByTestId('profile-time-zone-modal')).toBeVisible()
      await expect(search).toHaveValue('new york')
      await search.fill('')
      await expectProfileTimeZone(expect, second, null, 'Asia/Tokyo')
      await expectProfileTimeZone(expect, stranger, null, 'Europe/London')
      await clickSubmit(expect, second.getByTestId('profile-time-zone-close'))
      await expect(second.getByTestId('profile-time-zone-modal')).toBeHidden()
      await expect(second.getByTestId('profile-time-zone-change')).toBeFocused()
      for (const [index, document] of documents.entries()) {
        await document.unchanged()
        await expectWelcomedSockets(
          expect,
          sockets[index]!,
          socketCounts[index]!,
        )
      }
    } finally {
      await Promise.all(documents.map((document) => document.dispose()))
    }
  } finally {
    await Promise.all(contexts.map((opened) => opened.close()))
  }
})

test('reformats real session and tab milliseconds while retaining the device browser zone and expanded tabs', async ({
  context,
  page,
}) => {
  await signUp(expect, page)
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
