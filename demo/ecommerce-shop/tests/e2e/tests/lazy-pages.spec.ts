import { test, expect, type Page } from '@playwright/test'
import {
  armSocketDrop,
  bundleFiles,
  chooseHeaderTheme,
  dropSocket,
  expectBundleRequests,
  expectLazyPageFailure,
  expectLazyAdminPage,
  expectLazyAdminPending,
  expectLazyDeparture,
  settleClientBundles,
  expectPublicBundle,
  expectServerRefusal,
  expectSettingsPageHead,
  expectThemeMode,
  holdClientBundle,
  holdPageReply,
  readClientBundleReport,
  selectLazyBundle,
  siblingExclusiveFiles,
  watchClientBundles,
  watchClientDocument,
  waitClientBundle,
  watchHandshakeFrames,
  watchPagePresentation,
  watchPageReplies,
  watchTop,
} from '../../../../../framework/frontend/e2e/index.js'
import { grantAdminToSelf } from '../helpers/adminGrant.js'
import { expectPageReady, gotoPage, PAGE_REFUSED } from '../helpers/page.js'
import { login, logout, signUp } from '../helpers/session.js'

// The production artifact supplies the graph and the real server supplies every
// access verdict. No product global, fake backend or guessed chunk hash is used.
const SETTINGS_MODULE = '/views/Hilos/Settings/Settings.tsx'
const APPEARANCE_MODULE = '/views/Hilos/Appearance/Appearance.tsx'
const SETTINGS_KEY = 'hilos_settings'
const SETTINGS_PATH = '/hilos/settings'

/**
 * Give the shop's registered password account the existing command-channel grant.
 *
 * @param page The fresh page whose account will open the lazy admin section.
 */
async function signUpAdmin(page: Page): Promise<void> {
  await signUp(page)
  await grantAdminToSelf(page)
}

let bundles: ReturnType<typeof watchClientBundles>
let replies: ReturnType<typeof watchPageReplies>

test.beforeEach(async ({ page }) => {
  bundles = watchClientBundles(page)
  replies = watchPageReplies(page)
})

test.afterEach(async ({ page }, info) => {
  await info.attach('lazy-network-and-page-replies', {
    body: JSON.stringify(
      { requests: bundles.requests, replies: replies.replies },
      null,
      2,
    ),
    contentType: 'application/json',
  })
  if (!page.isClosed()) {
    await info.attach('lazy-final-screen', {
      body: await page.screenshot(),
      contentType: 'image/png',
    })
  }
  bundles.dispose()
})

test('cold public footer, home and history stay eager', async ({ page }) => {
  const report = await readClientBundleReport(page)
  expectPublicBundle(expect, report)
  expect(
    report.initial.modules.filter((id) => id.includes('/views/Hilos/')),
  ).toEqual([])
  await test.info().attach('production-bundle-report', {
    body: JSON.stringify(report),
    contentType: 'application/json',
  })
  await gotoPage(page, '/')
  await expectThemeMode(expect, page, 'light')
  await expect(page.getByTestId('nav-brand')).toHaveAccessibleName(/\S/)
  await expect(page.getByTestId('skip-to-content')).toHaveAttribute(
    'href',
    '#hilos-main-content',
  )
  const document = await watchClientDocument(page)
  try {
    await page.getByTestId('footer-link-hilos_about').click()
    await expectPageReady(page)
    await page.getByTestId('nav-brand').click()
    await expectPageReady(page)
    await page.goBack()
    await expectPageReady(page)
    await expect(page).toHaveURL(/\/about$/)
    await page.goForward()
    await expectPageReady(page)
    await expect(page).toHaveURL(/\/$/)
    for (const footer of ['hilos_terms', 'hilos_privacy', 'hilos_license']) {
      await page.getByTestId(`footer-link-${footer}`).click()
      await expectPageReady(page)
      await page.getByTestId('nav-brand').click()
      await expectPageReady(page)
    }
    await expectThemeMode(expect, page, 'light')
    // The menu remains keyboard accessible after the footer and history trip.
    await chooseHeaderTheme(expect, page, 'light')
    await expectThemeMode(expect, page, 'light')
    await document.unchanged()
    expectBundleRequests(expect, bundles.requests, bundleFiles(report.initial))
  } finally {
    await document.dispose()
  }
})

test('holds selected project code after the server answer, then reuses it through history', async ({
  page,
}) => {
  await signUpAdmin(page)
  // A new document discards registration's module map before selecting any admin page.
  await gotoPage(page, '/')
  const report = await readClientBundleReport(page)
  const selected = selectLazyBundle(report, SETTINGS_MODULE)
  const sibling = selectLazyBundle(report, APPEARANCE_MODULE)
  const dashboard = selectLazyBundle(
    report,
    '/admin/dashboard/HilosDashboardPage.tsx',
  )
  const exclusive = siblingExclusiveFiles(report, selected, sibling)
  expect(exclusive).toContain(sibling.entry)
  await page.getByTestId('nav-admin').click()
  await expectPageReady(page)
  const held = await holdClientBundle(page, selected.entry)
  const presentation = await watchPagePresentation(page)
  const header = await watchTop(page.getByTestId('nav-theme'))
  try {
    await page.getByTestId('dashboard-card-hilos_settings').click()
    await held.requested
    await expect
      .poll(
        () =>
          replies.replies.filter(
            (reply) =>
              reply.page === SETTINGS_KEY && reply.type === 'page_response',
          ).length,
      )
      .toBe(1)
    await expectLazyAdminPending(expect, page)
    await expect(page.getByTestId('hilos-page-skeleton')).toBeVisible()
    await expect(page.getByTestId('hilos-page-departure')).toBeEmpty()
    const waiting = await presentation.read()
    expectLazyDeparture(expect, waiting)
    await test.info().attach('held-code-presentation', {
      body: JSON.stringify(waiting),
      contentType: 'application/json',
    })
    const finished = waitClientBundle(page, selected.entry, 'success')
    held.release()
    await finished
    await expectPageReady(page)
    await expectLazyAdminPage(expect, page, 'Settings')
    await header.unchanged()
    expectBundleRequests(expect, bundles.requests, [
      ...bundleFiles(report.initial),
      ...bundleFiles(dashboard),
      ...bundleFiles(selected),
    ])
    expect(
      bundles.requests.filter((request) =>
        exclusive.includes(request.fileName),
      ),
    ).toEqual([])
    const downloaded = bundles.requests.length
    await page.getByTestId('nav-brand').click()
    await expectPageReady(page)
    await page.goBack()
    await expectPageReady(page)
    await expectSettingsPageHead(expect, page)
    await page.goForward()
    await expectPageReady(page)
    await page.goBack()
    await expectPageReady(page)
    await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
    expect(bundles.requests.length).toBe(downloaded)
    await test.info().attach('selected-and-sibling-closures', {
      body: JSON.stringify({ selected, sibling, exclusive }),
      contentType: 'application/json',
    })
  } finally {
    await held.dispose()
    await presentation.dispose()
  }
})

for (const outcome of ['success', 'failure'] as const) {
  test(`late ${outcome} of an abandoned module keeps home current`, async ({
    page,
  }) => {
    await signUpAdmin(page)
    await gotoPage(page, '/')
    const report = await readClientBundleReport(page)
    const selected = selectLazyBundle(report, APPEARANCE_MODULE)
    await page.getByTestId('nav-admin').click()
    await expectPageReady(page)
    const held = await holdClientBundle(page, selected.entry)
    try {
      await page.getByTestId('dashboard-card-hilos_appearance').click()
      await held.requested
      await page.getByTestId('nav-brand').click()
      await expectPageReady(page)
      const settled = waitClientBundle(
        page,
        selected.entry,
        outcome === 'success' ? 'success' : 'failure',
      )
      held.release(outcome)
      await settled
      await settleClientBundles(expect, page, bundles.requests, selected)
      await expectPageReady(page)
      await expect(page).toHaveURL(/\/$/)
      await expect(page.getByTestId('hilos-admin-title')).toHaveCount(0)
      await expect(page.getByTestId('hilos-page-head-title')).toHaveCount(0)
      await expect(page.getByTestId('page-error')).toHaveCount(0)
    } finally {
      await held.dispose()
    }
  })
}

test('a real chunk failure gives a safe local 503 and SPA home recovery', async ({
  page,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, '/')
  const report = await readClientBundleReport(page)
  const selected = selectLazyBundle(report, SETTINGS_MODULE)
  await page.getByTestId('nav-admin').click()
  await expectPageReady(page)
  const document = await watchClientDocument(page)
  const held = await holdClientBundle(page, selected.entry)
  try {
    await page.getByTestId('dashboard-card-hilos_settings').click()
    await held.requested
    const failed = waitClientBundle(page, selected.entry, 'failure')
    held.release('failure')
    await failed
    await expectLazyPageFailure(expect, page, selected.entry)
    await page.getByTestId('page-error-home').click()
    await expectPageReady(page)
    await expect(page).toHaveURL(/\/$/)
    await document.unchanged()
    // Remove the network fault before revisiting. Chromium may retain failed ESM
    // in its native module map; observe that policy without demanding a refetch.
    await held.dispose()
    const beforeRevisit = bundles.requests.length
    await page.getByTestId('nav-admin').click()
    await expectPageReady(page)
    await page.getByTestId('dashboard-card-hilos_settings').click()
    await expect(page.getByTestId('hilos-page-state')).toHaveAttribute(
      'data-state',
      /^(ready|error)$/,
    )
    const state = await page
      .getByTestId('hilos-page-state')
      .getAttribute('data-state')
    if (state === 'error')
      await expectLazyPageFailure(expect, page, selected.entry)
    else await expectLazyAdminPage(expect, page, 'Settings')
    await document.unchanged()
    await test.info().attach('failed-esm-revisit-observation', {
      body: JSON.stringify({
        state,
        requests: bundles.requests.slice(beforeRevisit),
      }),
      contentType: 'application/json',
    })
  } finally {
    await held.dispose()
    await document.dispose()
  }
})

test('cold allowed admin deep link waits for the real permitting response', async ({
  page,
}) => {
  await signUpAdmin(page)
  const report = await readClientBundleReport(page)
  const selected = selectLazyBundle(report, SETTINGS_MODULE)
  const answer = await holdPageReply(page, SETTINGS_KEY)
  // Start the normal settled-navigation helper without awaiting it until both gates open.
  const navigation = gotoPage(page, SETTINGS_PATH)
  const completed = navigation.catch((error: unknown) => error)
  try {
    await answer.received
    expect(
      answer.replies.some(
        (reply) =>
          reply.page === SETTINGS_KEY && reply.type === 'page_response',
      ),
    ).toBe(true)
    await expect
      .poll(() =>
        bundles.requests.some(
          (request) =>
            request.fileName === selected.entry &&
            request.outcome === 'finished',
        ),
      )
      .toBe(true)
    await expectLazyAdminPending(expect, page)
    answer.release()
    await navigation
    await expectLazyAdminPage(expect, page, 'Settings')
  } finally {
    answer.dispose()
    await completed
    await test.info().attach('held-real-server-reply', {
      body: JSON.stringify(answer.replies),
      contentType: 'application/json',
    })
  }
})

for (const visitor of ['guest', 'non-admin'] as const) {
  for (const outcome of ['success', 'failure', 'failure-first'] as const) {
    test(`cold ${visitor} refusal wins over module ${outcome}`, async ({
      page,
    }) => {
      if (visitor === 'non-admin') await signUp(page)
      const report = await readClientBundleReport(page)
      const selected = selectLazyBundle(report, SETTINGS_MODULE)
      const held = await holdClientBundle(page, selected.entry)
      const answer =
        outcome === 'failure-first'
          ? await holdPageReply(page, SETTINGS_KEY)
          : undefined
      const navigation = gotoPage(page, SETTINGS_PATH, PAGE_REFUSED)
      const completed = navigation.catch((error: unknown) => error)
      const status = visitor === 'guest' ? 401 : 403
      try {
        await held.requested
        if (answer) {
          await answer.received
          held.release('failure')
          await expectLazyPageFailure(expect, page, selected.entry)
          answer.release()
        }
        await navigation
        await expect
          .poll(() =>
            (answer?.replies ?? replies.replies).some(
              (reply) =>
                reply.page === SETTINGS_KEY &&
                reply.type === 'subscription_page_error' &&
                reply.httpCode === status,
            ),
          )
          .toBe(true)
        await expectServerRefusal(expect, page, status)
        if (!answer) {
          const settled = waitClientBundle(
            page,
            selected.entry,
            outcome === 'success' ? 'success' : 'failure',
          )
          held.release(outcome === 'success' ? 'success' : 'failure')
          await settled
        }
        await settleClientBundles(expect, page, bundles.requests, selected)
        await expectServerRefusal(expect, page, status)
      } finally {
        answer?.dispose()
        await held.dispose()
        await completed
        if (answer)
          await test.info().attach('refusal-after-module-failure', {
            body: JSON.stringify(answer.replies),
            contentType: 'application/json',
          })
      }
    })
  }
}

test('signing in after a guest refusal uses the loaded descriptor without another download', async ({
  page,
}) => {
  const email = await signUp(page)
  await grantAdminToSelf(page)
  await expect(page.getByTestId('nav-admin')).toHaveAttribute(
    'data-access',
    'full',
  )
  await logout(page)
  await gotoPage(page, '/')
  const report = await readClientBundleReport(page)
  const selected = selectLazyBundle(report, SETTINGS_MODULE)
  await gotoPage(page, SETTINGS_PATH, PAGE_REFUSED)
  await expectServerRefusal(expect, page, 401)
  await expect
    .poll(
      () =>
        bundles.requests.filter(
          (request) =>
            request.fileName === selected.entry &&
            request.outcome === 'finished',
        ).length,
    )
    .toBe(1)
  const downloaded = bundles.requests.length
  const document = await watchClientDocument(page)
  try {
    await login(page, email)
    await expectPageReady(page)
    await expectLazyAdminPage(expect, page, 'Settings')
    expect(bundles.requests.length).toBe(downloaded)
    await document.unchanged()
  } finally {
    await document.dispose()
  }
})

test('reconnect retains the mounted admin page and uses its loaded section', async ({
  page,
}) => {
  await armSocketDrop(page)
  const handshakes = watchHandshakeFrames(page)
  await signUpAdmin(page)
  await gotoPage(page, SETTINGS_PATH)
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
  const mounted = await page.getByTestId('hilos-viewport-table').elementHandle()
  const document = await watchClientDocument(page)
  const presentation = await watchPagePresentation(page)
  const downloads = bundles.requests.length
  const sockets = replies.sockets()
  const welcomes = handshakes.length
  try {
    await dropSocket(page)
    await expect.poll(() => replies.sockets()).toBeGreaterThan(sockets)
    await expect.poll(() => handshakes.length).toBeGreaterThan(welcomes)
    await expect
      .poll(() =>
        replies.replies.some(
          (reply) =>
            reply.socket > sockets &&
            reply.page === SETTINGS_KEY &&
            reply.type === 'page_response',
        ),
      )
      .toBe(true)
    await expect(page.getByTestId('conn-state')).toHaveText('connected')
    await expectPageReady(page)
    await expectLazyAdminPage(expect, page, 'Settings')
    expect(await mounted?.evaluate((node) => node.isConnected)).toBe(true)
    expect(
      (await presentation.read()).every(
        (state) => state.state === 'ready' && !state.skeleton,
      ),
    ).toBe(true)
    expect(bundles.requests.length).toBe(downloads)
    await document.unchanged()
  } finally {
    await presentation.dispose()
    await document.dispose()
    await mounted?.dispose()
  }
})
