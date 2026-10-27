import { expect, test, type BrowserContext } from '@playwright/test'

import {
  login,
  chooseProfileTimeZone,
  expectProfileTimeZone,
  expectProfileTimeZoneCatalog,
  expectProfileTimeZoneSearch,
  forwardSessionTimeZone,
  isSessionCookie,
  openProfileTimeZone,
  watchClientDocument,
  watchSocketFrames,
  type SocketFrame,
  clickSubmit,
  expectPageReady,
  gotoPage,
  PAGE_READY,
  typeInto,
} from '../../../../../framework/frontend/e2e/index.js'
import { signUp } from '../helpers/session.js'

test.use({ timezoneId: 'Europe/Warsaw' })

test('displays automatic browser time zone with Change before Theme and receives the server catalog', async ({
  page,
}) => {
  const frames: SocketFrame[] = []
  const socket = watchSocketFrames(page, (frame) => frames.push(frame))
  try {
    await signUp(expect, page)
    await gotoPage(expect, page, '/profile', PAGE_READY)
    await expect(page.getByTestId('profile-name')).toBeVisible()
    await expectProfileTimeZoneCatalog(expect, frames)
    await expect(page.getByTestId('profile-time-zone-row')).toBeVisible()
    await expect(page.getByTestId('profile-time-zone-change')).toHaveText(
      'Change',
    )
    await expectProfileTimeZone(expect, page, null, 'Europe/Warsaw')

    const rowIndices = await page
      .getByTestId('profile-detail')
      .locator('> div')
      .evaluateAll((rows) => {
        const timeZoneIndex = rows.findIndex((element) =>
          element.querySelector('[data-id="profile-time-zone"]'),
        )
        const themeIndex = rows.findIndex((element) =>
          element.querySelector('[data-id="profile-theme"]'),
        )
        return { timeZoneIndex, themeIndex }
      })
    expect(rowIndices.timeZoneIndex).toBeGreaterThan(-1)
    expect(rowIndices.themeIndex).toBe(rowIndices.timeZoneIndex + 1)
  } finally {
    socket.stop()
  }
})

test('renders delivered own time zone and updates reactively on session restate without reload', async ({
  page,
}) => {
  let fullLoads = 0
  page.on('load', () => {
    fullLoads += 1
  })

  const socket = await forwardSessionTimeZone(page, 'Asia/Tokyo')
  try {
    await signUp(expect, page)
    await gotoPage(expect, page, '/profile')
    await expect(page.getByTestId('profile-name')).toBeVisible()

    const timeZone = page.getByTestId('profile-time-zone')
    await expect(timeZone).toHaveText('Asia/Tokyo')

    const sockets = socket.sockets()
    const loadsAfterNavigation = fullLoads

    socket.replay('Europe/Warsaw')
    await expect(timeZone).toHaveText('Europe/Warsaw')

    socket.replay(null)
    await expect(timeZone).toHaveText('Europe/Warsaw · automatic')

    expect(socket.sockets()).toBe(sockets)
    expect(fullLoads).toBe(loadsAfterNavigation)
  } finally {
    socket.dispose()
  }
})

test('real account choices fan out to independent sessions, preserve picker search and reset to each browser', async ({
  browser,
  context,
  page,
  baseURL,
}) => {
  // Two registrations, an independent password login, two writes and reloads
  // exceed the single-client budget even on an idle stand.
  test.slow()
  const contexts: BrowserContext[] = []
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
    await expectProfileTimeZone(expect, second, null, 'Asia/Tokyo')
    await openProfileTimeZone(expect, stranger)
    await expectProfileTimeZone(expect, stranger, null, 'Europe/London')

    await openProfileTimeZone(expect, second)
    await expectProfileTimeZoneSearch(expect, second)
    const search = second.getByTestId('profile-time-zone-search')
    await typeInto(search, 'new york')
    await expect(search).toHaveValue('new york')
    const document = await watchClientDocument(second)
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
      await document.unchanged()
      await expectProfileTimeZone(expect, stranger, null, 'Europe/London')

      // Check persistence after the live delivery was already observed.
      await page.reload()
      await expectPageReady(expect, page)
      await expectProfileTimeZone(
        expect,
        page,
        'America/New_York',
        'Europe/Warsaw',
      )
      await chooseProfileTimeZone(expect, page, null)
      await expectProfileTimeZone(expect, page, null, 'Europe/Warsaw')
      await expectProfileTimeZone(expect, second, null, 'Asia/Tokyo', false)
      await expect(second.getByTestId('profile-time-zone-modal')).toBeVisible()
      await expect(search).toHaveValue('new york')
      await document.unchanged()
      await search.fill('')
      await expectProfileTimeZone(expect, second, null, 'Asia/Tokyo')
      await expectProfileTimeZone(expect, stranger, null, 'Europe/London')
      await clickSubmit(expect, second.getByTestId('profile-time-zone-close'))
      await expect(second.getByTestId('profile-time-zone-modal')).toBeHidden()
      await expect(second.getByTestId('profile-time-zone-change')).toBeFocused()
    } finally {
      await document.dispose()
    }

    await page.reload()
    await expectPageReady(expect, page)
    await expectProfileTimeZone(expect, page, null, 'Europe/Warsaw')
    await second.reload()
    await expectPageReady(expect, second)
    await expectProfileTimeZone(expect, second, null, 'Asia/Tokyo')
    await expectProfileTimeZone(expect, stranger, null, 'Europe/London')
  } finally {
    await Promise.all(contexts.map((context) => context.close()))
  }
})
