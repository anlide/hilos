import type { Browser, Page } from '@playwright/test'
import { test, expect } from '../test.js'
import {
  cleanupFixtures,
  createTestUser,
  dismissToasts,
  expectDocumentUnchanged,
  expectHilosUserBlockSettled,
  leaveSecondAdmin,
  clickSubmit,
  typeInto,
  gotoPage,
  markDocument,
  PAGE_REFUSED,
  grantAdminToSelf,
  uniqueEmail,
  type SignedInUser,
  type TestCleanup,
} from '../../../../../framework/frontend/e2e/index.js'
import {
  HilosPages,
  resolveHilosPath,
} from '../../../../../framework/frontend/core/src/base/index.js'
import { mergeAccounts } from '../../../../../framework/frontend/scripts/accountMerge.mjs'
import { setAdmin } from '../../../../../framework/frontend/scripts/adminGrant.mjs'
import { seedTestUser } from '../../../../../framework/frontend/scripts/testUser.mjs'

const usersTest = test.extend<{
  usersCleanup: TestCleanup
  ownedUser: SignedInUser
}>({
  usersCleanup: cleanupFixtures,
  ownedUser: async (
    { browser, baseURL, ignoreHTTPSErrors, locale, timezoneId, usersCleanup },
    use,
  ) => {
    const context = await browser.newContext({
      baseURL,
      ignoreHTTPSErrors,
      locale,
      timezoneId,
    })
    usersCleanup.defer(() => context.close())
    const user = await createTestUser(expect, await context.newPage(), {
      email: `hil2128-fgm-${uniqueEmail()}`,
    })
    await use(user)
  },
})

// Hilos users admin e2e: /hilos/users renders the framework users table over the
// live socket, the client's own granted row is present, search filters the client
// viewport, a row links to the user detail page, and a modal rename round-trips
// through the backend and re-renders with no document reload.

/**
 * Open a second visitor in a context of its own, so the users table holds a row that is
 * not the admin's own.
 *
 * Identity here is the session cookie, so two pages of one context are one browser and one
 * account. A context made off the browser fixture inherits none of the project's `use`
 * options - including the tolerance for the self-signed certificate the test nginx serves -
 * so they are passed on by hand.
 *
 * @param browser The browser the test runs in.
 * @returns A page belonging to a fresh visitor.
 */
async function openSecondVisitor(browser: Browser): Promise<Page> {
  const { baseURL, ignoreHTTPSErrors } = test.info().project.use
  const context = await browser.newContext({ baseURL, ignoreHTTPSErrors })

  return context.newPage()
}

/** Open the users admin and wait for the live table. */
async function openUsers(page: Page): Promise<void> {
  await gotoPage(expect, page, '/hilos/users')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
}

test('refuses the users admin to a visitor without the grant', async ({
  page,
}) => {
  // The visitor here is anonymous — since HIL-610 the handshake leaves the session
  // without a user at all — and so holds no grant either. The access gate refuses
  // the subscription outright rather than rendering an empty table, and the shell
  // offers no way in: no gear to click.
  await gotoPage(expect, page, '/hilos/users', PAGE_REFUSED)
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('nav-admin')).toHaveCount(0)
  await expect(page.getByTestId('hilos-viewport-table')).toHaveCount(0)
})

usersTest(
  'searches and opens its own user, then commits a live rename',
  async ({ page, ownedUser }) => {
    await grantAdminToSelf(expect, page)

    let fullLoads = 0
    page.on('load', () => {
      fullLoads += 1
    })
    await openUsers(page)
    const ownOpen = page
      .getByTestId(`hilos-users-open-${ownedUser.userId}`)
      .filter({ visible: true })

    await usersTest.step(
      'filters the users table from the search box',
      async () => {
        await expect(
          page
            .locator('[data-id^="hilos-users-open-"]')
            .filter({ visible: true }),
        ).not.toHaveCount(0)

        // A query no name matches empties the viewport; clearing it restores rows.
        const search = page.getByTestId('hilos-table-search')
        await typeInto(search, 'zzz-no-such-user-zzz')
        await expect(
          page.locator('[data-id^="hilos-users-open-"]'),
        ).toHaveCount(0)
        await search.fill('')
        await expect(
          page
            .locator('[data-id^="hilos-users-open-"]')
            .filter({ visible: true }),
        ).not.toHaveCount(0)

        await typeInto(search, ownedUser.name)
        await expect(
          page
            .locator('[data-id^="hilos-users-open-"]')
            .filter({ visible: true }),
        ).toHaveCount(1)
        await expect(ownOpen).toBeVisible()
      },
    )

    await usersTest.step(
      'lists users in the framework table and opens a detail page',
      async () => {
        await expect(page.getByTestId('conn-state')).toHaveText('connected')
        await expect(page.getByRole('heading', { level: 1 })).toHaveText(
          'Users',
        )
        await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
        await expect(ownOpen).toBeVisible()
        const loadsAfterColdLoad = fullLoads

        // Open this scenario's person over the live socket, without a document reload.
        await ownOpen.click()
        await expect(page).toHaveURL(`/hilos/user/${ownedUser.userId}`)
        await expect(page.getByTestId('hilos-user-detail')).toBeVisible()
        await expect(page.getByTestId('hilos-user-name')).toBeVisible()
        await expect(page.getByTestId('hilos-user-name')).toHaveText(
          ownedUser.name,
        )
        expect(fullLoads).toBe(loadsAfterColdLoad)
      },
    )

    await usersTest.step(
      'renames a user from the detail page and re-renders live',
      async () => {
        const newName = `${ownedUser.name} Renamed`
        await clickSubmit(expect, page.getByTestId('hilos-user-edit'))
        await typeInto(page.getByTestId('hilos-user-name-input'), newName)
        await clickSubmit(expect, page.getByTestId('hilos-user-save'))

        // The committed name returns over the live table and the edit form closes.
        await expect(page.getByTestId('hilos-user-name-input')).toHaveCount(0)
        await expect(page.getByTestId('hilos-user-name')).toHaveText(newName)
      },
    )
  },
)

// FLAKY under the full multi-demo e2e battery: the daemon degrades under
// concurrent load and the detail table's presence fields under-populate (the
// live user renders offline with 0 sessions), same family as the bots "No
// suitable regular worker" flake. Passes in isolation; disabled pending
// HIL-376 (daemon degradation).
// Quarantine: F-001; original reason and parking date above.
test(
  'shows the connected user as online with a live session',
  { tag: '@quarantine:F-001' },
  async ({ page }) => {
    // Give this browser an account and take its id from the grant reply (HIL-610:
    // a visitor has no user row, and the page publishes no id for one), then open
    // its detail directly. Robust against how many users the shared DB has
    // accumulated: the self row need not be on the first viewport page of
    // /hilos/users (which shows only the first 10 by ascending id).
    const userId = await grantAdminToSelf(expect, page)

    await gotoPage(expect, page, `/hilos/user/${userId}`)
    await expect(page.getByTestId('conn-state')).toHaveText('connected')
    await expect(page.getByTestId('hilos-user-detail')).toBeVisible()

    // Regression: a connected user must render online with at least one live
    // session. The detail table's computed presence fields were never populated
    // (the project browser context returned null), so the live user rendered as
    // offline with 0 sessions. The presence badge stands over an invisible twin
    // holding the room of its widest word (HIL-1566); the visible one is read.
    await expect(page.getByTestId('hilos-user-sessions')).toHaveText(/[1-9]/)
    await expect(
      page
        .locator('[data-id="hilos-user-detail"] .badge')
        .filter({ visible: true }),
    ).toHaveText('online')
  },
)

usersTest(
  'a rename in one tab lands at once in another, raising no Apply',
  async ({ page, ownedUser, usersCleanup }) => {
    await grantAdminToSelf(expect, page)

    const newName = `${ownedUser.name} Pending Rename`

    // Both tabs watch the users list; tab A then renames a user from its detail page.
    const tabB = await page.context().newPage()
    usersCleanup.defer(() => tabB.close())
    await openUsers(page)
    await openUsers(tabB)
    const controlId = `hilos-users-open-${ownedUser.userId}`
    for (const tab of [page, tabB]) {
      await typeInto(tab.getByTestId('hilos-table-search'), ownedUser.name)
      // Settle the filter before observing a control that was already visible.
      await expect(
        tab.locator('[data-id^="hilos-users-open-"]').filter({ visible: true }),
      ).toHaveCount(1)
      await expect(
        tab.getByTestId(controlId).filter({ visible: true }),
      ).toBeVisible()
    }

    // Tab A opens its own person and renames it; its detail applies at once.
    await page.getByTestId(controlId).filter({ visible: true }).click()
    await expect(page.getByTestId('hilos-user-detail')).toBeVisible()
    await clickSubmit(expect, page.getByTestId('hilos-user-edit'))
    await typeInto(page.getByTestId('hilos-user-name-input'), newName)
    await clickSubmit(expect, page.getByTestId('hilos-user-save'))
    await expect(page.getByTestId('hilos-user-name-input')).toHaveCount(0)
    await expect(page.getByTestId('hilos-user-name')).toHaveText(newName)

    // Tab B receives the rename from the other connection and shows it at once: the
    // window is ordered by id, so a rename moves nothing, and what the gate holds is
    // the position and the membership of the rows rather than the fields of a record
    // (HIL-793). The user is an entity reference, so the name cell tracks the rename
    // reactively; what is new is that no Apply control is raised behind it, and no
    // press is needed to make the screen true. First two-window test on the React layer.
    await expect(tabB.locator('tbody tr', { hasText: newName })).toHaveCount(1)
    await expect(tabB.getByTestId('hilos-table-apply')).toHaveCount(0)
    await expect(
      tabB.locator('tbody tr', { hasText: newName }),
    ).not.toHaveClass(/table-warning/)
  },
)

usersTest(
  'opens a refused Users page on grant and closes it again on revoke',
  async ({
    page,
    browser,
    baseURL,
    ignoreHTTPSErrors,
    locale,
    timezoneId,
    usersCleanup,
  }) => {
    const { userId } = await createTestUser(expect, page, {
      email: `hil2128-fgm-${uniqueEmail()}`,
    })
    let needsRevoke = false
    usersCleanup.defer(async () => {
      if (needsRevoke) await setAdmin(userId, false)
    })
    // Revocation has its own second administrator, also on a fresh stand.
    await leaveSecondAdmin(browser, (page) => grantAdminToSelf(expect, page), {
      baseURL,
      ignoreHTTPSErrors,
      locale,
      timezoneId,
    })

    await usersTest.step(
      'opens the refused users admin the moment the grant lands',
      async () => {
        await gotoPage(expect, page, '/hilos/users', PAGE_REFUSED)
        await expect(page.getByTestId('hilos-viewport-table')).toHaveCount(0)

        needsRevoke = true
        await setAdmin(userId, true)

        // The grant must re-answer the open refusal without another navigation.
        await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
        await expect(
          page
            .locator('[data-id^="hilos-users-open-"]')
            .filter({ visible: true }),
        ).not.toHaveCount(0)
        await expect(page.getByTestId('page-error')).toHaveCount(0)
      },
    )

    await usersTest.step(
      'a revoked admin loses the gear and the door',
      async () => {
        await setAdmin(userId, false)
        needsRevoke = false

        // The open page loses access live, without mistaking its person for a guest.
        await expect(page.getByTestId('nav-admin')).toHaveCount(0)
        const refusal = page.getByTestId('page-error')
        await expect(refusal).toBeVisible()
        await expect(refusal).toHaveAttribute('data-error-code', '403')
        await expect(refusal).not.toContainText(/guest/i)

        await gotoPage(expect, page, '/hilos/users', PAGE_REFUSED)
        await expect(page.getByTestId('hilos-viewport-table')).toHaveCount(0)
      },
    )
  },
)

// HIL-824: the takeover is drawn by the SDK page, not by this project — on the person's
// card since HIL-1170 (on the users list before). The backend route is one shared framework
// path for all three demos and is covered where it lives
// (demo/binance-btc-tracker/tests/e2e/tests/users.spec.ts, HIL-1219), so what is worth a case
// here is the only thing that differs — the markup: the card's button opens this framework's
// window, and its confirm reaching the server is proved by the shell's strip, which arrives
// with the rebound session rather than with an ack the view acted on.
test('takes a person over from the framework card that is not your own', async ({
  page,
  browser,
}) => {
  // A second visitor, so there is a card other than the admin's own: on your own card the
  // control stands switched off, since taking yourself over is refused server-side.
  const other = await openSecondVisitor(browser)
  const otherId = await grantAdminToSelf(expect, other)

  await grantAdminToSelf(expect, page)
  await gotoPage(expect, page, `/hilos/user/${otherId}`)
  await expect(page.getByTestId('conn-state')).toHaveText('connected')

  // The impersonation step is declared off (step-up.md), so the window opens at once.
  await clickSubmit(expect, page.getByTestId('hilos-user-impersonate-open'))
  await clickSubmit(expect, page.getByTestId('hilos-user-impersonate-confirm'))

  await expect(page.getByTestId('impersonation-banner')).toBeVisible()
})

// The Vue chat merge spec proves the shared server path
// (demo/chat/tests/e2e/tests/account-merge-chat.spec.ts). This case proves that
// the React SDK cards receive the outcome live from the server. Before HIL-1999,
// this project refused the merge as “not wired”. Here merges start through
// the account:merge command.
test('shows a merge started from account:merge on the framework card without a reload', async ({
  page,
}) => {
  await grantAdminToSelf(expect, page)
  const survivor = await seedTestUser({
    email: `merge-survivor-${uniqueEmail()}`,
  })
  const loser = await seedTestUser({ email: `merge-loser-${uniqueEmail()}` })

  await gotoPage(
    expect,
    page,
    resolveHilosPath(HilosPages.USER, { userId: String(survivor.userId) }),
  )
  await expect(page.getByTestId('hilos-user-name')).toHaveText(survivor.name)

  const loserCard = await page.context().newPage()
  try {
    await gotoPage(
      expect,
      loserCard,
      resolveHilosPath(HilosPages.USER, { userId: String(loser.userId) }),
    )
    await expect(loserCard.getByTestId('hilos-user-name')).toHaveText(
      loser.name,
    )
    await Promise.all([page, loserCard].map(markDocument))

    await mergeAccounts({
      survivorUserId: survivor.userId,
      loserUserId: loser.userId,
    })

    await expect(page.getByTestId('hilos-user-merge-status')).toHaveText(
      'Merge completed',
    )
    await expect(loserCard.getByTestId('hilos-user-merged')).toContainText(
      survivor.name,
    )
    await expect(
      loserCard.getByTestId('hilos-user-merged-link'),
    ).toHaveAttribute('href', `/hilos/user/${survivor.userId}`)
    await expectDocumentUnchanged(expect, page)
    await expectDocumentUnchanged(expect, loserCard)
  } finally {
    await loserCard.close()
  }
})

test('closes an idle block confirmation when another tab changes the block', async ({
  page,
  browser,
}) => {
  const { baseURL, ignoreHTTPSErrors } = test.info().project.use
  const personContext = await browser.newContext({
    baseURL,
    ignoreHTTPSErrors,
  })
  const personPage = await personContext.newPage()
  const pageB = await page.context().newPage()

  try {
    await grantAdminToSelf(expect, page)
    const targetId = await grantAdminToSelf(expect, personPage)
    await setAdmin(targetId, false)

    await gotoPage(expect, page, `/hilos/user/${targetId}`)
    await gotoPage(expect, pageB, `/hilos/user/${targetId}`)

    await clickSubmit(expect, page.getByTestId('hilos-user-block-open'))
    await expect(page.getByTestId('modal')).toBeVisible()
    await clickSubmit(expect, pageB.getByTestId('hilos-user-block-open'))
    await expect(pageB.getByTestId('modal')).toBeVisible()

    await clickSubmit(expect, pageB.getByTestId('hilos-user-lifecycle-confirm'))
    await expectHilosUserBlockSettled(expect, pageB, true)
    await expectHilosUserBlockSettled(expect, page, true)

    await dismissToasts(pageB)

    await clickSubmit(expect, page.getByTestId('hilos-user-block-open'))
    await expect(page.getByTestId('modal')).toBeVisible()
    await clickSubmit(expect, pageB.getByTestId('hilos-user-block-open'))
    await expect(pageB.getByTestId('modal')).toBeVisible()

    await clickSubmit(expect, pageB.getByTestId('hilos-user-lifecycle-confirm'))
    await expectHilosUserBlockSettled(expect, pageB, false)
    await expectHilosUserBlockSettled(expect, page, false)
  } finally {
    await pageB.close()
    await personContext.close()
  }
})
